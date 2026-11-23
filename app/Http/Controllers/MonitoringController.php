<?php

namespace App\Http\Controllers;

use App\Support\ProgressReport;
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
        DB::transaction(function () use ($request, $project, $groups, $publish) {
            $this->owned($request)->lockForUpdate()->where('id', $project)->firstOrFail();
            $json = json_encode($groups, JSON_THROW_ON_ERROR);
            DB::table('projects')->where('id', $project)->update(['draft' => $json]);
            if ($publish) {
                $query = DB::table('baselines')->where('project_id', $project);
                $query->insert(['project_id' => $project, 'revision' => 1 + $query->max('revision'), 'structure' => $json]);
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
