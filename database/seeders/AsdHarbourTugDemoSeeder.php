<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\CostReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AsdHarbourTugDemoSeeder extends Seeder
{
    public function run(?string $ownerEmail = null): void
    {
        $ownerEmail ??= config('monitoring.demo_owner_email');
        if (! $ownerEmail) {
            throw new RuntimeException('Isi DOKPANTAU_DEMO_OWNER_EMAIL di file .env dengan email akun Monitoring Galangan yang sudah terdaftar.');
        }

        $fixture = json_decode(File::get(__DIR__.'/asd-harbour-tug.json'), true, flags: JSON_THROW_ON_ERROR);
        $project = $fixture['project'];

        DB::transaction(function () use ($ownerEmail, $fixture, $project): void {
            $owner = User::query()->where('email', $ownerEmail)->lockForUpdate()->first();
            if (! $owner) {
                throw new RuntimeException('Akun pemilik demo belum terdaftar di Monitoring Galangan.');
            }

            $existing = DB::table('projects')->where('user_id', $owner->id)->where('code', $project['code'])->first();
            if ($existing && ! $existing->is_demo) {
                throw new RuntimeException('Kode ASD-TUG-DEMO sudah dipakai oleh proyek pengguna.');
            }

            $structure = json_encode(array_map(fn (array $group): array => [
                ...Arr::only($group, ['id', 'name']),
                'items' => array_map(fn (array $item): array => Arr::only($item, ['id', 'name', 'weight']), $group['items']),
                'stages' => array_map(fn (array $stage): array => Arr::only($stage, ['id', 'name', 'weight']), $group['stages']),
            ], $fixture['groups']), JSON_THROW_ON_ERROR);

            $costData = json_decode(File::get(__DIR__.'/asd-costs.json'), true, flags: JSON_THROW_ON_ERROR);
            $estimate = json_encode(CostReport::estimate($costData['estimate'], $fixture['groups']), JSON_THROW_ON_ERROR);
            if ($existing) {
                $baseline = DB::table('baselines')->where('project_id', $existing->id)->where('revision', 1)->lockForUpdate()->first();
                if (! $baseline || $baseline->estimate !== null || json_decode($baseline->structure, true) != json_decode($structure, true)) {
                    return;
                }
                DB::table('baselines')->where('id', $baseline->id)->update(['estimate' => $estimate]);
                if ($existing->estimate === null && json_decode($existing->draft, true) == json_decode($structure, true)) {
                    DB::table('projects')->where('id', $existing->id)->update(['estimate' => $estimate]);
                }
                foreach ($fixture['weeks'] as $week) {
                    if ($week['actual_percent'] === null) {
                        continue;
                    }
                    DB::table('report_periods')->where('baseline_id', $baseline->id)->where('start', $week['start'])->where('end', $week['end'])->whereNull('actual_cost')->whereNotNull('closed_at')->update(['actual_cost' => $costData['costs'][$week['week'] - 1], 'cost_simulated' => true, 'cost_source' => 'EVM · EV / asumsi CPI']);
                }

                return;
            }
            $projectId = DB::table('projects')->insertGetId([
                ...$project,
                'yard' => $project['yard'] ?? 'Belum tercatat',
                'user_id' => $owner->id,
                'draft' => $structure,
                'estimate' => $estimate,
                'is_demo' => true,
            ]);
            $baselineId = DB::table('baselines')->insertGetId([
                'project_id' => $projectId,
                'revision' => 1,
                'structure' => $structure,
                'estimate' => $estimate,
            ]);

            $importedAt = now();
            foreach ($fixture['weeks'] as $week) {
                $hasStageDetail = $week['actual_kind'] === 'simulation_from_stages';
                $periodId = DB::table('report_periods')->insertGetId([
                    'baseline_id' => $baselineId,
                    'start' => $week['start'],
                    'end' => $week['end'],
                    'target' => $week['plan_percent'],
                    'actual_cost' => $costData['costs'][$week['week'] - 1],
                    'cost_simulated' => $week['actual_percent'] !== null,
                    'cost_source' => $week['actual_percent'] === null ? null : 'EVM · EV / asumsi CPI',
                    'reported_actual' => $hasStageDetail ? null : $week['actual_percent'],
                    'is_simulated' => $hasStageDetail,
                    'closed_at' => $week['actual_percent'] === null ? null : $importedAt,
                ]);

                if ($hasStageDetail) {
                    $this->seedWeekFourteenMeasurements($periodId, $fixture['groups']);
                }
            }
        }, attempts: 3);
    }

    /**
     * @param  list<array{items: list<array{id: string, week_14_stage_values: list<float|int|null>}>, stages: list<array{id: string}>}>  $groups
     */
    private function seedWeekFourteenMeasurements(int $periodId, array $groups): void
    {
        $measurements = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                foreach ($group['stages'] as $index => $stage) {
                    $value = $item['week_14_stage_values'][$index];
                    if ($value !== null) {
                        $measurements[] = [
                            'period_id' => $periodId,
                            'item' => $item['id'],
                            'stage' => $stage['id'],
                            'value' => round($value, 4),
                        ];
                    }
                }
            }
        }

        foreach (array_chunk($measurements, 200) as $chunk) {
            DB::table('measurements')->insert($chunk);
        }
    }
}
