<?php

namespace App\Http\Controllers;

use App\Support\EngineeringEstimate;
use App\Support\FormBuilderImport;
use App\Support\ProgressReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonitoringController extends Controller
{
    private function owned(Request $request): Builder
    {
        return DB::table('projects')->where('user_id', $request->user()->id);
    }

    private function periods(int $project): Builder
    {
        return DB::table('report_periods as p')->join('baselines as b', 'b.id', '=', 'p.baseline_id')
            ->where('b.project_id', $project)->select('p.*', 'b.revision', 'b.structure');
    }

    public function index(Request $request): Response
    {
        $section = $request->route('section') ?? 'ringkasan';
        $projects = $this->owned($request)->orderBy('name')->get(['id', 'name']);
        $project = $request->filled('project') ? $this->owned($request)->where('id', $request->integer('project'))->firstOrFail() : $this->owned($request)->first();
        if ($project) {
            $project->is_demo = (bool) $project->is_demo;
        }
        $draft = $project ? json_decode($project->draft, true) : [];
        $periods = $this->periods($project->id ?? 0)->orderBy('p.end')->get();
        $defaultPeriod = $project?->is_demo && $section === 'ringkasan'
            ? ($periods->whereNotNull('closed_at')->last() ?? $periods->last())
            : $periods->last();
        $period = $request->filled('period') ? $periods->firstWhere('id', $request->integer('period')) : $defaultPeriod;
        abort_if($request->filled('period') && ! $period, 404);
        $baseline = DB::table('baselines')->where('project_id', $project->id ?? 0)->orderByDesc('revision')->first();
        $records = DB::table('measurements')->whereIn('period_id', $periods->pluck('id'))->get()->groupBy('period_id');
        $report = ProgressReport::calculate(json_decode($baseline->structure ?? '[]', true), collect());
        $history = [];
        foreach ($periods as $row) {
            $entry = ProgressReport::calculate(json_decode($row->structure, true), $records->get($row->id, collect()));
            if ($entry['actual'] === null && $row->reported_actual !== null) {
                $entry['actual'] = (float) $row->reported_actual;
            }
            $row->actual = $entry['actual'];
            $row->is_simulated = (bool) $row->is_simulated;
            $row->partial = $row->reported_actual === null && $entry['measured'] < $entry['expected'];
            $row->deviation = $row->target !== null && $row->actual !== null ? $row->actual - $row->target : null;
            unset($row->structure);
            $history[] = $row;
            if ($row->id === $period?->id) {
                $report = $entry;
            }
        }

        $estimate = null;
        $bac = null;
        $hasEstimate = false;
        $canImportFormBuilder = false;
        if ($project && in_array($section, ['estimasi', 'builder'], true)) {
            $estimateDraft = EngineeringEstimate::find($project->id, 0);
            if ($section === 'estimasi' && $estimateDraft) {
                $settings = EngineeringEstimate::settings($estimateDraft);
                $estimate = Arr::except($settings, ['source_reference', 'exchange_rate_source']) + EngineeringEstimate::preview(
                    EngineeringEstimate::lines($estimateDraft->id),
                    $settings['exchange_rate_idr_per_usd'],
                );
            }
            if ($section === 'builder') {
                $hasEstimate = $this->hasEstimateLines($estimateDraft);
                $canImportFormBuilder = FormBuilderImport::draftIsEmpty($draft, $estimateDraft, $hasEstimate);
            }
        }
        if ($project && $section === 'ringkasan' && ($reportRevision = $period->revision ?? $baseline->revision ?? null)) {
            $published = EngineeringEstimate::find($project->id, $reportRevision);
            if ($published?->bac_idr !== null) {
                $bac = EngineeringEstimate::formatAmount($published->bac_idr);
            }
        }

        return Inertia::render('dashboard', compact('projects', 'project', 'draft', 'period', 'history', 'section', 'report', 'estimate', 'bac', 'hasEstimate', 'canImportFormBuilder') + ['revision' => $baseline->revision ?? 0]);
    }

    public function downloadFormBuilderTemplate(): BinaryFileResponse
    {
        return response()->download(resource_path('form-builder-template.xlsx'), 'Template-Form-Builder.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function previewFormBuilderImport(Request $request, int $project, FormBuilderImport $import): JsonResponse
    {
        $this->ensureFormBuilderImportAvailable($this->owned($request)->where('id', $project)->firstOrFail());
        $data = $request->validate(['file' => FormBuilderImport::UPLOAD_RULES], FormBuilderImport::UPLOAD_MESSAGES);
        $file = $data['file'];
        ['groups' => $groups, 'settings' => $settings, 'lines' => $lines] = $import->prepare($file);
        $totals = EngineeringEstimate::preview($lines, $settings['exchange_rate_idr_per_usd']);

        return response()->json([
            'preview_token' => $import->token($file, (int) $request->user()->id, $project),
            'groups' => array_map(fn (array $group): array => [
                'name' => $group['name'],
                'item_count' => count($group['items']),
            ], $groups),
            'item_count' => count($lines),
            'exchange_rate_idr_per_usd' => $settings['exchange_rate_idr_per_usd'],
            'exchange_rate_date' => $settings['exchange_rate_date'],
            'exchange_rate_is_assumption' => $settings['exchange_rate_is_assumption'],
            'local_idr' => $totals['local_idr'],
            'import_usd' => $totals['import_usd'],
            'equivalent_idr' => $totals['equivalent_idr'],
        ]);
    }

    public function applyFormBuilderImport(Request $request, int $project, FormBuilderImport $import): RedirectResponse
    {
        $ownedProject = $this->owned($request)->where('id', $project);
        $this->ensureFormBuilderImportAvailable($ownedProject->firstOrFail());
        $data = $request->validate([
            'file' => FormBuilderImport::UPLOAD_RULES,
            'preview_token' => ['required', 'string', 'max:2048'],
        ], FormBuilderImport::UPLOAD_MESSAGES + [
            'preview_token.required' => 'Tinjau file sebelum menerapkan impor.',
        ]);
        $file = $data['file'];
        $import->validateToken($data['preview_token'], $file, (int) $request->user()->id, $project);
        ['groups' => $groups, 'settings' => $settings, 'lines' => $lines] = $import->prepare($file);

        DB::transaction(function () use ($ownedProject, $project, $groups, $settings, $lines): void {
            $this->ensureFormBuilderImportAvailable($ownedProject->lockForUpdate()->firstOrFail());
            $ownedProject->update(['draft' => json_encode($groups, JSON_THROW_ON_ERROR)]);
            EngineeringEstimate::store($project, 0, $settings, $lines);
        }, attempts: 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft Form Builder dan EE berhasil diimpor. Lengkapi tahap pengukuran sebelum menetapkan baseline.']);

        return to_route('workspace', ['section' => 'builder', 'project' => $project]);
    }

    private function ensureFormBuilderImportAvailable(stdClass $project): void
    {
        $estimate = EngineeringEstimate::find($project->id, 0);
        $groups = json_decode($project->draft, true, flags: JSON_THROW_ON_ERROR);
        if (! FormBuilderImport::draftIsEmpty($groups, $estimate, $this->hasEstimateLines($estimate))) {
            throw ValidationException::withMessages(['file' => 'Impor hanya tersedia jika draft Form Builder dan EE proyek masih kosong.']);
        }
    }

    private function hasEstimateLines(?stdClass $estimate): bool
    {
        return $estimate !== null && DB::table('engineering_estimate_lines')->where('engineering_estimate_id', $estimate->id)->exists();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->identityRules($request) + ['source' => 'nullable|integer']);
        $draft = empty($data['source']) ? '[]' : $this->owned($request)->where('id', $data['source'])->firstOrFail()->draft;
        unset($data['source']);
        $id = DB::table('projects')->insertGetId($data + ['user_id' => $request->user()->id, 'draft' => $draft]);

        return to_route('workspace', ['section' => 'builder', 'project' => $id]);
    }

    public function edit(Request $request, int $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => $this->owned($request)->where('id', $project)->firstOrFail(['id', 'code', 'name', 'yard', 'start', 'end']),
            'projects' => $this->owned($request)->orderBy('name')->get(['id', 'name']),
            'section' => 'ringkasan',
        ]);
    }

    public function updateIdentity(Request $request, int $project): RedirectResponse
    {
        $ownedProject = $this->owned($request)->where('id', $project);
        $ownedProject->firstOrFail(['id']);

        $duplicateCode = 'Kode pembangunan sudah digunakan pada proyek lain.';
        $data = $request->validate($this->identityRules($request, $project), [
            'code.unique' => $duplicateCode,
        ]);

        try {
            $ownedProject->update($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => $duplicateCode]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan proyek disimpan.']);

        return to_route('workspace', ['section' => 'ringkasan', 'project' => $project]);
    }

    /** @return array<string, list<string|Unique>> */
    private function identityRules(Request $request, ?int $project = null): array
    {
        $uniqueCode = Rule::unique('projects')->where('user_id', $request->user()->id);
        if ($project !== null) {
            $uniqueCode->ignore($project);
        }

        return [
            'code' => ['required', 'string', 'max:100', $uniqueCode],
            'name' => ['required', 'string', 'max:255'],
            'yard' => ['required', 'string', 'max:255'],
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    public function updateEstimate(Request $request, int $project): RedirectResponse
    {
        DB::transaction(function () use ($request, $project): void {
            $owned = $this->owned($request)->where('id', $project)->lockForUpdate()->firstOrFail();
            $quantity = ['nullable', 'regex:/\A\d{1,10}(?:\.\d{1,6})?\z/'];
            $price = ['nullable', 'regex:/\A\d{1,14}(?:\.\d{1,6})?\z/'];
            $data = $request->validate([
                'exchange_rate_idr_per_usd' => $quantity,
                'exchange_rate_date' => 'nullable|date_format:Y-m-d',
                'exchange_rate_source' => 'nullable|string|max:255',
                'exchange_rate_is_assumption' => 'required|boolean',
                'source_reference' => 'nullable|string|max:255',
                'lines' => 'present|array|list',
                'lines.*' => 'array:id,description,quantity,unit,local_price_idr,local_price_is_lump_sum,usd_unit_price,wbs_item_id,source_reference',
                'lines.*.id' => 'nullable|integer|distinct',
                'lines.*.description' => 'required|string|max:255',
                'lines.*.quantity' => $quantity,
                'lines.*.unit' => 'nullable|string|max:50',
                'lines.*.local_price_idr' => $price,
                'lines.*.local_price_is_lump_sum' => 'required|boolean',
                'lines.*.usd_unit_price' => $price,
                'lines.*.wbs_item_id' => 'nullable|uuid',
                'lines.*.source_reference' => 'nullable|string|max:255',
            ], [
                'regex' => 'Masukkan angka nonnegatif dengan maksimal enam angka desimal dan tanpa pemisah ribuan.',
                'lines.*.description.required' => 'Isi uraian biaya.',
            ]);

            $groups = json_decode($owned->draft, true, flags: JSON_THROW_ON_ERROR);
            $itemIds = EngineeringEstimate::itemIds($groups);
            $draft = EngineeringEstimate::find($project, 0);
            $original = $draft ? array_column(EngineeringEstimate::lines($draft->id), null, 'id') : [];
            $lines = [];
            foreach ($data['lines'] as $index => $line) {
                $id = $line['id'] ?? null;
                if ($id !== null && ! isset($original[$id])) {
                    throw ValidationException::withMessages(['lines' => 'Draft EE telah berubah. Muat ulang sebelum menyimpan perubahan Anda.']);
                }
                $item = isset($line['wbs_item_id']) ? strtolower($line['wbs_item_id']) : null;
                if ($item !== null && ! isset($itemIds[$item])) {
                    throw ValidationException::withMessages(["lines.$index.wbs_item_id" => 'Pilih item dari draft WBS proyek ini.']);
                }
                $lines[] = [
                    ...Arr::except($line, ['id', 'wbs_item_id']),
                    'wbs_item_id' => $item,
                    'source_work_group' => $original[$id]['source_work_group'] ?? null,
                ] + ['source_reference' => $original[$id]['source_reference'] ?? null]
                    + array_fill_keys(['quantity', 'unit', 'local_price_idr', 'usd_unit_price'], null);
            }
            $settings = Arr::except($data, ['lines']) + array_fill_keys([
                'exchange_rate_idr_per_usd', 'exchange_rate_date',
            ], null);
            EngineeringEstimate::store($project, 0, $settings, $lines);
        });

        return back();
    }

    public function structure(Request $request, int $project): RedirectResponse
    {
        $this->owned($request)->where('id', $project)->firstOrFail();
        $publish = $request->boolean('publish');
        $list = $publish ? 'required|array|list|min:1' : 'present|array|list';
        $name = ($publish ? 'required' : 'nullable').'|string|max:255';
        $weight = ($publish ? 'required' : 'nullable').'|numeric|between:0,100|decimal:0,6';
        $rules = ['groups' => $list, 'groups.*' => 'array:id,name,items,stages', 'groups.*.id' => 'required|uuid|distinct:ignore_case', 'groups.*.name' => $name];
        foreach (['items', 'stages'] as $key) {
            $path = "groups.*.$key";
            $rules += [
                $path => $list,
                "$path.*" => 'array:id,name,weight',
                "$path.*.id" => 'required|uuid|distinct:ignore_case',
                "$path.*.name" => $name,
                "$path.*.weight" => $key === 'items' ? 'nullable|numeric|between:0,100|decimal:0,6' : $weight,
            ];
        }
        $groups = $request->validate($rules)['groups'];
        DB::transaction(function () use ($request, $project, $groups, $publish): void {
            $this->owned($request)->lockForUpdate()->where('id', $project)->firstOrFail();
            $estimate = $publish ? EngineeringEstimate::find($project, 0) : null;
            $lines = $estimate ? EngineeringEstimate::lines($estimate->id) : [];
            $bac = null;
            if ($publish) {
                $this->validateBaselineWeights($groups, $lines === []);
                if ($estimate && $lines !== []) {
                    $result = EngineeringEstimate::baseline($groups, $lines, EngineeringEstimate::settings($estimate)['exchange_rate_idr_per_usd']);
                    $groups = $result['groups'];
                    $bac = $result['bac'];
                }
            }

            $json = json_encode($groups, JSON_THROW_ON_ERROR);
            DB::table('projects')->where('id', $project)->update(['draft' => $json]);
            if ($publish) {
                $query = DB::table('baselines')->where('project_id', $project);
                $revision = 1 + $query->max('revision');
                $query->insert(['project_id' => $project, 'revision' => $revision, 'structure' => $json]);
                if ($estimate && $bac !== null) {
                    EngineeringEstimate::store($project, $revision, EngineeringEstimate::settings($estimate), $lines, $bac);
                }
            }
        });

        return back();
    }

    /** @param list<array{name: string, items: list<array{weight?: int|float|numeric-string|null}>, stages: list<array{weight: int|float|numeric-string}>}> $groups */
    private function validateBaselineWeights(array $groups, bool $useManualWeights): void
    {
        $units = fn (array $rows): int => array_sum(array_map(fn ($row) => (int) round(($row['weight'] ?? 0) * 1_000_000), $rows));
        $total = 0;
        foreach ($groups as $group) {
            if ($units($group['stages']) !== 100_000_000) {
                throw ValidationException::withMessages(['groups' => 'Total bobot tahap '.$group['name'].' harus 100%.']);
            }
            if ($useManualWeights) {
                foreach ($group['items'] as $item) {
                    if (! isset($item['weight'])) {
                        throw ValidationException::withMessages(['groups' => 'Isi bobot setiap item untuk menetapkan baseline tanpa EE.']);
                    }
                }
                $total += $units($group['items']);
            }
        }
        if ($useManualWeights && $total !== 100_000_000) {
            throw ValidationException::withMessages(['groups' => 'Total bobot item seluruh proyek harus 100%.']);
        }
    }

    public function period(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate(['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start', 'target' => 'nullable|numeric|between:0,100|decimal:0,4']);
        $id = DB::transaction(function () use ($request, $project, $data) {
            $this->owned($request)->lockForUpdate()->where('id', $project)->firstOrFail();
            $baseline = DB::table('baselines')->where('project_id', $project)->orderByDesc('revision')->value('id');
            if (! $baseline || $this->periods($project)->where('p.start', '<=', $data['end'])->where('p.end', '>=', $data['start'])->exists()) {
                throw ValidationException::withMessages(['start' => 'Tetapkan baseline dan pilih rentang yang belum digunakan.']);
            }

            return DB::table('report_periods')->insertGetId($data + ['baseline_id' => $baseline]);
        });

        return to_route('workspace', ['section' => 'input', 'project' => $project, 'period' => $id]);
    }

    public function update(Request $request, int $project, int $period): RedirectResponse
    {
        DB::transaction(function () use ($request, $project, $period) {
            $this->owned($request)->lockForUpdate()->where('id', $project)->firstOrFail();
            $report = $this->periods($project)->where('p.id', $period)->firstOrFail();
            if ($report->closed_at) {
                throw ValidationException::withMessages(['period' => 'Periode telah ditutup.']);
            }
            if ($request->boolean('close')) {
                DB::table('report_periods')->where('id', $period)->update(['closed_at' => now()]);

                return;
            }
            if ($request->has('target')) {
                $target = $request->validate(['target' => 'nullable|numeric|between:0,100|decimal:0,4']);
                DB::table('report_periods')->where('id', $period)->update($target);

                return;
            }
            $data = $request->validate(['item' => 'required|uuid', 'values' => 'present|array', 'values.*' => 'nullable|numeric|between:0,100|decimal:0,4']);
            $groups = collect(json_decode($report->structure, true));
            $group = $groups->first(fn ($group) => in_array($data['item'], array_column($group['items'], 'id'), true));
            abort_if(! $group || array_diff(array_keys($data['values']), array_column($group['stages'], 'id')), 404);
            foreach ($data['values'] as $stage => $value) {
                $key = ['period_id' => $period, 'item' => $data['item'], 'stage' => $stage];
                if ($value === null) {
                    DB::table('measurements')->where($key)->delete();
                } else {
                    DB::table('measurements')->updateOrInsert($key, ['value' => $value]);
                }
            }
        });

        return back();
    }
}
