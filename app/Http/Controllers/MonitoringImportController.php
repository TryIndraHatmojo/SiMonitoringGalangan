<?php

namespace App\Http\Controllers;

use App\Support\MonitoringWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonitoringImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'monitoring-');
        MonitoringWorkbook::template($path);

        return response()->download($path, 'Template-Monitoring.xlsx')->deleteFileAfterSend(true);
    }

    public function preview(Request $request, int $project): RedirectResponse
    {
        $this->owned($request, $project);
        $request->validate(['file' => 'required|file|max:5120|extensions:xlsx']);
        $sheets = MonitoringWorkbook::read($request->file('file')->getRealPath());
        $revision = $this->revision($project);
        DB::beginTransaction();
        try {
            $this->applySheets($request, $project, $sheets);
            $baseline = DB::table('baselines')->where('project_id', $project)->orderByDesc('revision')->firstOrFail();
            $estimate = json_decode($baseline->estimate ?? 'null', true);
            $sources = [];
            foreach (json_decode($baseline->structure, true) as $group) {
                foreach ($group['items'] as $item) {
                    $sources[$item['id']] = $group['name'];
                }
            }
            $warnings = [];
            foreach ($estimate['lines'] ?? [] as $line) {
                if (! empty($line['source_group']) && strcasecmp($line['source_group'], $sources[$line['item']] ?? '') !== 0) {
                    $warnings[] = $line['code'].' · '.$line['source_group'].' → '.($sources[$line['item']] ?? '');
                }
            }
            $summary = ['bac' => $estimate['bac'] ?? null, 'warnings' => $warnings];
        } finally {
            DB::rollBack();
        }
        $token = (string) Str::uuid();
        Cache::put("monitoring-import:$token", [$request->user()->id, $project, $revision, $sheets], now()->addMinutes(30));

        return back()->with('import_preview', ['token' => $token, 'counts' => array_map('count', $sheets), 'summary' => $summary]);
    }

    public function apply(Request $request, int $project): RedirectResponse
    {
        $token = $request->validate(['token' => 'required|uuid'])['token'];
        $pending = Cache::get("monitoring-import:$token");
        if (! $pending || $pending[0] !== $request->user()->id || $pending[1] !== $project) {
            throw ValidationException::withMessages(['file' => 'Unggah kembali untuk mendapatkan pratinjau.']);
        }
        DB::transaction(function () use ($request, $project, $pending) {
            $this->owned($request, $project);
            if ($this->revision($project) !== $pending[2]) {
                throw ValidationException::withMessages(['file' => 'Revisi berubah. Periksa pratinjau kembali.']);
            }
            $this->applySheets($request, $project, $pending[3]);
        });
        Cache::forget("monitoring-import:$token");

        return back()->with('import_done', true);
    }

    private function owned(Request $request, int $project): \stdClass
    {
        return DB::table('projects')->where('user_id', $request->user()->id)->where('id', $project)->lockForUpdate()->firstOrFail();
    }

    private function revision(int $project): int
    {
        return (int) DB::table('baselines')->where('project_id', $project)->max('revision');
    }

    /** @param array<string,array<int,array<string,string>>> $sheets */
    private function applySheets(Request $request, int $project, array $sheets): void
    {
        $owner = $this->owned($request, $project);
        $controller = app(MonitoringController::class);
        $input = function (array $values) use ($request): Request {
            $form = Request::create('/', 'POST', $values);
            $form->setUserResolver($request->getUserResolver());

            return $form;
        };
        $fail = function (string $cell, string $message): never {
            throw ValidationException::withMessages(['file' => "$cell — $message"]);
        };
        $id = fn (string $type, string $code): string => Str::isUuid($code) ? $code : Uuid::uuid5(Uuid::NAMESPACE_URL, "project/$project/$type/$code")->toString();
        $groups = [];
        foreach ($sheets['Grup'] as $n => $r) {
            if ($r['code'] === '' || isset($groups[$r['code']])) {
                $fail("Grup!A$n", 'Kode wajib dan unik.');
            }
            $groups[$r['code']] = ['id' => $id('group', $r['code']), 'name' => $r['name'], 'items' => [], 'stages' => []];
        }
        foreach (['Item' => 'items', 'Tahap' => 'stages'] as $sheet => $key) {
            $seen = [];
            foreach ($sheets[$sheet] as $n => $r) {
                if (! isset($groups[$r['group']])) {
                    $fail("$sheet!A$n", 'Grup tidak ditemukan.');
                }
                if ($r['code'] === '' || isset($seen[$r['code']])) {
                    $fail("$sheet!B$n", 'Kode wajib dan unik.');
                }
                $seen[$r['code']] = true;
                $groups[$r['group']][$key][] = ['id' => $id($key, $r['code']), 'name' => $r['name'], 'weight' => $r['weight']];
            }
        }
        $baseline = DB::table('baselines')->where('project_id', $project)->orderByDesc('revision')->first();
        $structure = array_values($groups);
        $current = json_decode($owner->draft, true);
        if ($structure && $current && ! $baseline && $structure != $current) {
            $fail('Grup!A2', 'Draft proyek berbeda.');
        }
        if ($structure && $baseline) {
            $published = json_decode($baseline->structure, true);
            $weights = json_decode($baseline->estimate ?? 'null', true)['weights'] ?? null;
            if ($weights !== null) {
                foreach ($structure as &$group) {
                    foreach ($group['items'] as &$item) {
                        $item['weight'] = $weights[$item['id']] ?? 0;
                    }
                    unset($item);
                }
                unset($group);
            }
            if ($structure != $published) {
                $fail('Grup!A2', 'Struktur berbeda dari baseline proyek.');
            }
        }
        if ($structure && ! $baseline) {
            $controller->structure($input(['groups' => $structure]), $project);
        }
        if ($sheets['EE'] || $sheets['Kurs']) {
            if (count($sheets['Kurs']) !== 1 || ! $sheets['EE']) {
                $fail('Kurs!A2', 'Isi satu kurs dan baris EE.');
            }
            $estimate = array_values($sheets['Kurs'])[0];
            $estimate['lines'] = array_values(array_map(fn ($line) => array_replace($line, ['item' => $id('items', $line['item'])]), $sheets['EE']));
            if ($baseline) {
                $stored = json_decode($baseline->estimate ?? 'null', true);
                $same = $stored && collect($estimate)->except('lines')->every(fn ($v, $k) => $stored[$k] == $v)
                    && count($stored['lines']) === count($estimate['lines']);
                foreach ($estimate['lines'] as $i => $line) {
                    if (! $same || array_diff_assoc($line, $stored['lines'][$i])) {
                        $fail('EE!A'.($i + 2), 'Estimasi berbeda dari baseline.');
                    }
                }
            } else {
                $controller->estimate($input($estimate), $project);
            }
        }
        if (! $baseline) {
            $controller->structure($input(['groups' => $structure ?: $current, 'publish' => true]), $project);
            $baseline = DB::table('baselines')->where('project_id', $project)->orderByDesc('revision')->firstOrFail();
        }
        $periods = DB::table('report_periods')->join('baselines', 'baseline_id', '=', 'baselines.id')->where('project_id', $project)->select('report_periods.*', 'baselines.structure');
        $byEnd = (clone $periods)->get()->keyBy('end');
        $end = '';
        $target = 0;
        foreach ($sheets['Jadwal'] as $n => $r) {
            if ($r['start'] <= $end || $r['target'] < $target) {
                $fail("Jadwal!A$n", 'Tanggal atau target kumulatif tidak berurutan.');
            }
            $end = $r['end'];
            $target = $r['target'];
            if (isset($byEnd[$r['end']])) {
                $p = $byEnd[$r['end']];
                if ($p->start !== $r['start'] || $p->target === null || ! is_numeric($r['target']) || (float) $p->target !== (float) $r['target']) {
                    $fail("Jadwal!C$n", 'Target atau tanggal berbeda.');
                }

                continue;
            }
            $controller->period($input($r), $project);
            $byEnd[$r['end']] = (clone $periods)->where('end', $r['end'])->firstOrFail();
        }
        foreach ($sheets['Histori'] as $n => $r) {
            $p = $byEnd[$r['end']] ?? null;
            if (! $p) {
                $fail("Histori!A$n", 'Periode tidak ditemukan.');
            }
            if (! in_array($r['simulated'], ['0', '1'], true)) {
                $fail("Histori!F$n", 'Gunakan 0 atau 1 untuk simulasi.');
            }
            $simulated = (bool) $r['simulated'];
            $records = DB::table('measurements')->where('period_id', $p->id);
            if (($p->reported_actual !== null || (clone $records)->exists()) && (bool) $p->is_simulated !== $simulated) {
                $fail("Histori!F$n", 'Status sumber berbeda.');
            }
            if ($r['reported_actual'] !== '') {
                if ($r['item'] !== '' || $r['stage'] !== '' || $r['value'] !== '' || (clone $records)->exists()) {
                    $fail("Histori!E$n", 'Pilih satu jenis histori per periode.');
                }
                $input(['value' => $r['reported_actual']])->validate(['value' => 'required|numeric|between:0,100|decimal:0,4']);
                if ($p->reported_actual !== null && (float) $p->reported_actual === (float) $r['reported_actual']) {
                    continue;
                }
                if ($p->closed_at || $p->reported_actual !== null) {
                    $fail("Histori!E$n", 'Aktual agregat berbeda.');
                }
                $p->reported_actual = $r['reported_actual'];
            } else {
                $item = $id('items', $r['item']);
                $stage = $id('stages', $r['stage']);
                $valid = false;
                foreach (json_decode($p->structure, true) as $group) {
                    foreach ($group['items'] as $candidate) {
                        if ($candidate['id'] === $item && in_array($stage, array_column($candidate['stages'] ?? $group['stages'], 'id'), true)) {
                            $valid = true;
                            break 2;
                        }
                    }
                }
                if (! $valid) {
                    $fail("Histori!C$n", 'Pasangan item dan tahap tidak ditemukan pada revisi periode.');
                }
                $input(['value' => $r['value']])->validate(['value' => 'required|numeric|between:0,100|decimal:0,4']);
                $old = (clone $records)->where('item', $item)->where('stage', $stage)->value('value');
                if ($old !== null && (float) $old === (float) $r['value']) {
                    continue;
                }
                if ($p->closed_at || $old !== null || $p->reported_actual !== null) {
                    $fail("Histori!D$n", 'Realisasi berbeda.');
                }
                $controller->update($input(['item' => $item, 'values' => [$stage => $r['value']]]), $project, $p->id);
            }
            $p->is_simulated = $simulated;
            DB::table('report_periods')->where('id', $p->id)->update(['reported_actual' => $p->reported_actual, 'is_simulated' => $simulated]);
        }
    }
}
