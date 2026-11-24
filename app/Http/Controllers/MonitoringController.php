<?php

namespace App\Http\Controllers;

use App\Support\CostReport;
use App\Support\ProgressReport;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    private function owned(Request $request): Builder
    {
        return DB::table('projects')->where('user_id', $request->user()->id);
    }

    private function periods(int $project): Builder
    {
        return DB::table('report_periods as p')->join('baselines as b', 'b.id', '=', 'p.baseline_id')
            ->where('b.project_id', $project)->select('p.*', 'b.revision', 'b.structure', 'b.estimate');
    }

    public function index(Request $request): Response
    {
        $section = $request->route('section') ?? 'ringkasan';
        $projects = $this->owned($request)->orderBy('name')->get(['id', 'name']);
        $project = $request->filled('project') ? $this->owned($request)->where('id', $request->integer('project'))->firstOrFail() : $this->owned($request)->first();
        if ($project) {
            $project->is_demo = (bool) $project->is_demo;
        }
        if ($project) {
            $project->estimate = json_decode($project->estimate ?? 'null', true);
        }
        $draft = $project ? json_decode($project->draft, true) : [];
        $periods = $this->periods($project->id ?? 0)->orderBy('p.end')->get();
        $measuredPeriods = DB::table('measurements')->whereIn('period_id', $periods->pluck('id'))->distinct()->pluck('period_id')->flip();
        $defaultPeriod = $periods->last(fn ($row) => $row->closed_at || $row->actual_cost !== null || $row->reported_actual !== null || $measuredPeriods->has($row->id)) ?? $periods->first();
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
            $estimate = json_decode($row->estimate ?? 'null', true);
            $row->metrics = CostReport::metrics($estimate ? (float) $estimate['bac'] : null, $row->target === null ? null : (float) $row->target, $row->actual, $row->actual_cost === null ? null : (float) $row->actual_cost);
            unset($row->estimate);
            $row->is_simulated = (bool) $row->is_simulated;
            $row->partial = $row->reported_actual === null && $entry['measured'] < $entry['expected'];
            $row->deviation = $row->target !== null && $row->actual !== null ? $row->actual - $row->target : null;
            unset($row->structure);
            $history[] = $row;
            if ($row->id === $period?->id) {
                $report = $entry;
            }
        }

        return Inertia::render('dashboard', compact('projects', 'project', 'draft', 'period', 'history', 'section', 'report') + ['revision' => $baseline->revision ?? 0]);
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

    public function estimate(Request $request, int $project): RedirectResponse
    {
        DB::transaction(function () use ($request, $project) {
            $record = $this->owned($request)->where('id', $project)->lockForUpdate()->firstOrFail();
            $data = $request->validate([
                'rate' => 'required|numeric|gt:0|decimal:0,6', 'date' => 'required|date_format:Y-m-d', 'source' => 'required|string|max:255', 'assumption' => 'required|boolean',
                'lines' => 'required|array|list|min:1', 'lines.*' => 'array:code,name,unit,quantity,local,usd,item',
                'lines.*.code' => 'required|string|distinct|max:100', 'lines.*.name' => 'required|string|max:255', 'lines.*.unit' => 'required|string|max:40',
                'lines.*.quantity' => 'required|numeric|gt:0|decimal:0,6', 'lines.*.local' => 'required|numeric|min:0|decimal:0,6', 'lines.*.usd' => 'required|numeric|min:0|decimal:0,6', 'lines.*.item' => 'required|uuid',
            ]);
            $estimate = CostReport::estimate($data, json_decode($record->draft, true));
            DB::table('projects')->where('id', $project)->update(['estimate' => json_encode($estimate, JSON_THROW_ON_ERROR)]);
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
            $rules += [$path => $list, "$path.*" => 'array:id,name,weight', "$path.*.id" => 'required|uuid|distinct:ignore_case', "$path.*.name" => $name, "$path.*.weight" => $weight];
        }
        $groups = $request->validate($rules)['groups'];
        $estimate = $this->owned($request)->where('id', $project)->value('estimate');
        if ($publish && $estimate) {
            $weights = json_decode($estimate, true)['weights'];
            $itemIds = collect($groups)->flatMap(fn ($group) => array_column($group['items'], 'id'))->all();
            if (array_diff(array_keys($weights), $itemIds)) {
                throw ValidationException::withMessages(['groups' => 'Perbarui pemetaan EE untuk item yang dihapus.']);
            }
            foreach ($groups as &$group) {
                foreach ($group['items'] as &$item) {
                    $item['weight'] = $weights[$item['id']] ?? 0;
                }
                unset($item);
            }
            unset($group);
        }
        if ($publish) {
            $units = fn (array $rows): int => array_sum(array_map(fn ($row) => (int) round($row['weight'] * 1000000), $rows));
            $total = 0;
            foreach ($groups as $group) {
                if ($units($group['stages']) !== 100000000) {
                    throw ValidationException::withMessages(['groups' => 'Total bobot tahap '.$group['name'].' harus 100%.']);
                }
                $total += $units($group['items']);
            }
            if ($total !== 100000000) {
                throw ValidationException::withMessages(['groups' => 'Total bobot item seluruh proyek harus 100%.']);
            }
        }
        DB::transaction(function () use ($request, $project, $groups, $publish, $estimate) {
            $record = $this->owned($request)->lockForUpdate()->where('id', $project)->firstOrFail();
            if ($publish && $record->estimate !== $estimate) {
                throw ValidationException::withMessages(['groups' => 'Estimasi berubah. Muat ulang sebelum menerbitkan.']);
            }
            $json = json_encode($groups, JSON_THROW_ON_ERROR);
            DB::table('projects')->where('id', $project)->update(['draft' => $json]);
            if ($publish) {
                $query = DB::table('baselines')->where('project_id', $project);
                $query->insert(['project_id' => $project, 'revision' => 1 + $query->max('revision'), 'structure' => $json, 'estimate' => $estimate]);
            }
        });

        return back();
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
            if ($request->has('actual_cost')) {
                $cost = $request->validate(['actual_cost' => 'nullable|numeric|min:0|max:9999999999999999|decimal:0,2', 'cost_source' => 'nullable|required_with:actual_cost|string|max:255', 'verify' => 'sometimes|boolean']);
                $same = $report->actual_cost !== null && $cost['actual_cost'] !== null && BigDecimal::of((string) $report->actual_cost)->isEqualTo((string) $cost['actual_cost']) && $report->cost_source === ($cost['cost_source'] ?? null);
                $verified = $same && $request->boolean('verify');
                DB::table('report_periods')->where('id', $period)->update(['actual_cost' => $cost['actual_cost'], 'cost_source' => $cost['cost_source'] ?? null, 'cost_simulated' => false, 'verified_at' => $verified ? now() : null, 'verified_by' => $verified ? $request->user()->id : null]);

                return;
            }
            if ($request->boolean('close')) {
                $progress = ProgressReport::calculate(json_decode($report->structure, true), DB::table('measurements')->where('period_id', $period)->get());
                if ($progress['measured'] < $progress['expected'] || ($report->estimate && ($report->target === null || ! $report->verified_at || $report->cost_simulated))) {
                    throw ValidationException::withMessages(['period' => 'Lengkapi semua tahap serta target dan biaya terverifikasi untuk baseline EE.']);
                }
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
