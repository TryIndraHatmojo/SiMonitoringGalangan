<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\EngineeringEstimate;
use Illuminate\Database\Seeder;
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
        $estimate = json_decode(File::get(__DIR__.'/asd-engineering-estimate.json'), true, flags: JSON_THROW_ON_ERROR);
        $workRow = fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'weight' => $row['weight']];
        $groups = array_map(fn (array $group): array => [
            'id' => $group['id'],
            'name' => $group['name'],
            'items' => array_map($workRow, array_values($group['items'])),
            'stages' => array_map($workRow, array_values($group['stages'])),
        ], array_values($fixture['groups']));
        $estimateLines = [];
        foreach ($estimate['lines'] as $line) {
            $estimateLines[] = $line + ['position' => count($estimateLines) + 1];
        }
        $published = EngineeringEstimate::baseline($groups, $estimateLines, $estimate['settings']['exchange_rate_idr_per_usd']);
        if ($published['bac'] !== EngineeringEstimate::formatAmount($fixture['engineering_estimate']['amount_idr'])) {
            throw new RuntimeException('BAC Engineering Estimate demo berbeda dengan Summary EE!F18.');
        }

        DB::transaction(function () use ($ownerEmail, $fixture, $project, $estimate, $published): void {
            $owner = User::query()->where('email', $ownerEmail)->lockForUpdate()->first();
            if (! $owner) {
                throw new RuntimeException('Akun pemilik demo belum terdaftar di Monitoring Galangan.');
            }

            $existing = DB::table('projects')->where('user_id', $owner->id)->where('code', $project['code'])->lockForUpdate()->first();
            if ($existing) {
                if (! $existing->is_demo) {
                    throw new RuntimeException('Kode ASD-TUG-DEMO sudah dipakai oleh proyek pengguna.');
                }

                $baseline = DB::table('baselines')->where('project_id', $existing->id)->where('revision', 1)->value('structure');
                $this->seedMissingEstimate($existing->id, 0, $existing->draft, $estimate);
                $this->seedMissingEstimate($existing->id, 1, $baseline, $estimate, $published['bac']);

                return;
            }

            $structure = json_encode($published['groups'], JSON_THROW_ON_ERROR);

            $projectId = DB::table('projects')->insertGetId([
                ...$project,
                'yard' => $project['yard'] ?? 'Belum tercatat',
                'user_id' => $owner->id,
                'draft' => $structure,
                'is_demo' => true,
            ]);
            $baselineId = DB::table('baselines')->insertGetId([
                'project_id' => $projectId,
                'revision' => 1,
                'structure' => $structure,
            ]);

            EngineeringEstimate::store($projectId, 0, $estimate['settings'], $estimate['lines']);
            EngineeringEstimate::store($projectId, 1, $estimate['settings'], $estimate['lines'], $published['bac']);

            $importedAt = now();
            foreach ($fixture['weeks'] as $week) {
                $hasStageDetail = $week['actual_kind'] === 'simulation_from_stages';
                $periodId = DB::table('report_periods')->insertGetId([
                    'baseline_id' => $baselineId,
                    'start' => $week['start'],
                    'end' => $week['end'],
                    'target' => $week['plan_percent'],
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

    /** @param array{settings: array<string, mixed>, lines: list<array<string, mixed>>} $estimate */
    private function seedMissingEstimate(int $project, int $revision, ?string $structure, array $estimate, ?string $bac = null): void
    {
        if ($structure === null || EngineeringEstimate::find($project, $revision)) {
            return;
        }

        $itemIds = EngineeringEstimate::itemIds(json_decode($structure, true, flags: JSON_THROW_ON_ERROR));
        if (array_diff(array_column($estimate['lines'], 'wbs_item_id'), array_keys($itemIds)) === []) {
            EngineeringEstimate::store($project, $revision, $estimate['settings'], $estimate['lines'], $bac);
        }
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
