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
            ->where('b.project_id', $project)->select('p.*', 'b.revision', 'b.structure', 'b.estimate', 'b.schedule');
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
            $project->estimate = $section === 'estimasi' ? json_decode($project->estimate ?? 'null', true) : null;
        }
        $draft = $project ? json_decode($project->draft, true) : [];
        $periods = $this->periods($project->id ?? 0)->orderBy('p.end')->get();
        $measuredPeriods = DB::table('measurements')->whereIn('period_id', $periods->pluck('id'))->distinct()->pluck('period_id')->flip();
        $defaultPeriod = $periods->last(fn ($row) => $row->closed_at || $row->actual_cost !== null || $row->reported_actual !== null || $measuredPeriods->has($row->id)) ?? $periods->first();
        $period = $request->filled('period') ? $periods->firstWhere('id', $request->integer('period')) : $defaultPeriod;
        abort_if($request->filled('period') && ! $period, 404);
        $baseline = DB::table('baselines')->where('project_id', $project->id ?? 0)->orderByDesc('revision')->first();
        $records = DB::table('measurements')->whereIn('period_id', $periods->pluck('id'))->get()->groupBy('period_id');
        $activeEstimate = json_decode($period->estimate ?? $baseline->estimate ?? 'null', true);
        if ($period && $period->estimate === null) {
            $activeEstimate = null;
        }
        $report = ProgressReport::calculate(json_decode($baseline->structure ?? '[]', true), collect());
        $history = [];
        $prior = $period ? $periods->last(fn ($row) => $row->end < $period->start) : null;
        $comparison = $request->filled('compare') ? $periods->firstWhere('id', $request->integer('compare')) : $prior;
        $comparisonReport = null;
        if ($comparison && $period && $comparison->end < $period->start && ProgressReport::basis($comparison->structure) == ProgressReport::basis($period->structure)) {
            $comparisonReport = ProgressReport::calculate(json_decode($comparison->structure, true), $records->get($comparison->id, collect()));
            if ($comparisonReport['measured'] !== $comparisonReport['expected']) {
                $comparisonReport = null;
            }
        }
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
            $row->partial = ($row->reported_actual === null || $entry['measured'] > 0) && $entry['measured'] < $entry['expected'];
            $row->deviation = $row->target !== null && $row->actual !== null ? $row->actual - $row->target : null;
            $row->scheduled = $row->schedule !== null;
            unset($row->structure, $row->schedule);
            $history[] = $row;
            if ($row->id === $period?->id) {
                $report = $entry;
            }
        }

        return Inertia::render('dashboard', compact('activeEstimate', 'comparisonReport', 'comparison', 'projects', 'project', 'draft', 'period', 'history', 'section', 'report') + ['revision' => $baseline->revision ?? 0, 'importPreview' => $request->session()->get('import_preview'), 'importDone' => $request->session()->get('import_done')]);
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
                'lines' => 'required|array|list|min:1', 'lines.*' => 'array:code,name,unit,quantity,local,usd,item,source_group', 'lines.*.source_group' => 'nullable|string|max:255',
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
        $rules['groups.*.items.*'] = 'array:id,name,weight,stages,template_name';
        $rules += ['groups.*.items.*.template_name' => 'sometimes|string|max:255', 'groups.*.items.*.stages' => 'sometimes|array|list|min:1', 'groups.*.items.*.stages.*' => 'array:id,name,weight', 'groups.*.items.*.stages.*.id' => 'required|uuid', 'groups.*.items.*.stages.*.name' => $name, 'groups.*.items.*.stages.*.weight' => $weight];
        $estimate = $this->owned($request)->where('id', $project)->value('estimate');
        if ($estimate) {
            $rules['groups.*.items.*.weight'] = 'nullable|numeric|between:0,100';
        }
        $groups = $request->validate($rules)['groups'];
        if ($publish && $estimate) {
            $weights = json_decode($estimate, true)['weights'];
            $itemIds = array_column(array_merge(...array_column($groups, 'items')), 'id');
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
                foreach ($group['items'] as $item) {
                    if (isset($item['stages']) && ($units($item['stages']) !== 100000000 || count(array_unique(array_column($item['stages'], 'id'))) !== count($item['stages']))) {
                        throw ValidationException::withMessages(['groups' => 'Tahap item harus unik dan berbobot total 100%.']);
                    }
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

    public function schedule(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer', 'rows' => 'required|array|list|min:1', 'rows.*' => 'array:start,end,target', 'rows.*.start' => 'required|date_format:Y-m-d', 'rows.*.end' => 'required|date_format:Y-m-d', 'rows.*.target' => 'required|numeric|between:0,100|decimal:0,4']);
        DB::transaction(function () use ($request, $project, $data) {
            $this->owned($request)->where('id', $project)->lockForUpdate()->firstOrFail();
            $baseline = DB::table('baselines')->where('project_id', $project)->orderByDesc('revision')->first();
            if (! $baseline || $baseline->revision != $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Muat ulang revisi baseline terbaru.']);
            }
            $effective = $data['rows'][0]['start'];
            $existing = $this->periods($project)->orderBy('p.end')->get();
            $previous = $existing->last(fn ($p) => $p->end < $effective);
            $end = $previous->end ?? '';
            $target = $previous->target ?? 0;
            foreach ($data['rows'] as $i => $row) {
                if ($row['start'] <= $end || $row['end'] < $row['start'] || $row['target'] < $target) {
                    throw ValidationException::withMessages(["rows.$i" => 'Periksa urutan tanggal dan target kumulatif.']);
                }
                $end = $row['end'];
                $target = $row['target'];
            }
            $future = $existing->filter(fn ($p) => $p->end >= $effective);
            if ($future->contains(fn ($p) => $p->start < $effective || $p->closed_at || $p->reported_actual !== null || $p->actual_cost !== null) || DB::table('measurements')->whereIn('period_id', $future->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['rows' => 'Pilih tanggal efektif setelah periode yang memiliki realisasi.']);
            }
            $id = DB::table('baselines')->insertGetId(['project_id' => $project, 'revision' => $baseline->revision + 1, 'structure' => $baseline->structure, 'estimate' => $baseline->estimate, 'schedule' => json_encode($data['rows'], JSON_THROW_ON_ERROR)]);
            DB::table('report_periods')->whereIn('id', $future->pluck('id'))->delete();
            foreach ($data['rows'] as $row) {
                DB::table('report_periods')->insert($row + ['baseline_id' => $id]);
            }
        });

        return to_route('workspace', ['section' => 'input', 'project' => $project]);
    }

    public function correct(Request $request, int $project, int $period): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:2000', 'item' => 'required_without:actual_cost|uuid', 'values' => 'required_with:item|array', 'actual_cost' => 'sometimes|required|numeric', 'cost_source' => 'required_with:actual_cost|string|max:255']);
        DB::transaction(function () use ($request, $project, $period, $data) {
            $this->owned($request)->where('id', $project)->lockForUpdate()->firstOrFail();
            $record = $this->periods($project)->where('p.id', $period)->whereNotNull('p.closed_at')->firstOrFail();
            $snapshot = fn () => ['period' => DB::table('report_periods')->find($period), 'values' => DB::table('measurements')->where('period_id', $period)->orderBy('item')->orderBy('stage')->get(['item', 'stage', 'value'])];
            $before = $snapshot();
            DB::table('report_periods')->where('id', $period)->update(['closed_at' => null]);
            $change = Request::create('/', 'PATCH', array_intersect_key($data, array_flip(['item', 'values', 'actual_cost', 'cost_source'])));
            $change->setUserResolver($request->getUserResolver());
            $this->update($change, $project, $period);
            if (isset($data['actual_cost'])) {
                $change->merge(['verify' => true]);
                $this->update($change, $project, $period);
            }
            $close = Request::create('/', 'PATCH', ['close' => true, 'reason' => $data['reason']]);
            $close->setUserResolver($request->getUserResolver());
            $this->update($close, $project, $period);
            DB::table('report_periods')->where('id', $period)->update(['closed_at' => $record->closed_at]);
            DB::table('period_corrections')->insert(['period_id' => $period, 'user_id' => $request->user()->id, 'reason' => $data['reason'], 'changes' => json_encode(['before' => $before, 'after' => $snapshot()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
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
                if ($same && ($report->verified_at || $report->cost_simulated)) {
                    if ($report->cost_simulated && $request->boolean('verify')) {
                        throw ValidationException::withMessages(['cost_source' => 'Gunakan referensi pembukuan riil untuk verifikasi.']);
                    }

                    return;
                }
                if ($report->cost_simulated && $cost['actual_cost'] !== null && $report->cost_source === ($cost['cost_source'] ?? null)) {
                    throw ValidationException::withMessages(['cost_source' => 'Isi referensi pembukuan riil ketika mengganti biaya simulasi.']);
                }
                $verified = $same && $request->boolean('verify');
                DB::table('report_periods')->where('id', $period)->update(['actual_cost' => $cost['actual_cost'], 'cost_source' => $cost['cost_source'] ?? null, 'cost_simulated' => false, 'verified_at' => $verified ? now() : null, 'verified_by' => $verified ? $request->user()->id : null]);

                return;
            }
            if ($request->boolean('close')) {
                $progress = ProgressReport::calculate(json_decode($report->structure, true), DB::table('measurements')->where('period_id', $period)->get());
                if ($progress['measured'] < $progress['expected'] || ($report->estimate && ($report->target === null || ! $report->verified_at || $report->cost_simulated))) {
                    throw ValidationException::withMessages(['period' => 'Lengkapi semua tahap serta target dan biaya terverifikasi untuk baseline EE.']);
                }
                $prior = $this->periods($project)->where('p.end', '<', $report->start)->orderByDesc('p.end')->first();
                $reason = $request->validate(['reason' => 'nullable|string|max:2000'])['reason'] ?? null;
                if ($prior && ProgressReport::basis($prior->structure) == ProgressReport::basis($report->structure)) {
                    $previous = ProgressReport::calculate(json_decode($prior->structure, true), DB::table('measurements')->where('period_id', $prior->id)->get());
                    if ($progress['actual'] < ($previous['actual'] ?? $prior->reported_actual ?? 0) && ! $reason) {
                        throw ValidationException::withMessages(['reason' => 'Isi alasan penurunan progres kumulatif.']);
                    }
                }
                DB::table('report_periods')->where('id', $period)->update(['closed_at' => now(), 'closing_reason' => $reason]);

                return;
            }
            if ($request->has('target')) {
                if ($report->schedule) {
                    throw ValidationException::withMessages(['target' => 'Gunakan revisi jadwal untuk mengubah target baseline.']);
                }
                $target = $request->validate(['target' => 'nullable|numeric|between:0,100|decimal:0,4']);
                DB::table('report_periods')->where('id', $period)->update($target);

                return;
            }
            $data = $request->validate(['item' => 'required|uuid', 'values' => 'present|array', 'values.*' => 'nullable|numeric|between:0,100|decimal:0,4']);
            $allowedStages = [];
            foreach (json_decode($report->structure, true) as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['id'] === $data['item']) {
                        $allowedStages = array_column($item['stages'] ?? $group['stages'], 'id');
                        break 2;
                    }
                }
            }
            abort_if($allowedStages === [] || array_diff(array_keys($data['values']), $allowedStages) !== [], 404);
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
