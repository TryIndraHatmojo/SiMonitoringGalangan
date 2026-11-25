<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ConstructionWbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_construction_uses_group_cost_and_block_tonnage_and_snapshots_progress_weights(): void
    {
        $project = $this->createProject();
        $construction = $this->construction(['39.72', '1284.28'], '1324');
        $standard = $this->standard();
        $groups = [$construction, $standard];
        $this->saveStructure($project, $groups)->assertSessionHasNoErrors();

        $groupCost = ['wbs_group_id' => $construction['id']];
        $standardCost = ['wbs_item_id' => $standard['items'][0]['id']];
        $this->saveEstimate($project, [
            $this->cost('400', $groupCost),
            $this->cost('455', $groupCost),
            $this->cost('9145', $standardCost),
        ])->assertSessionHasNoErrors();
        $this->get(route('workspace', ['section' => 'builder', 'project' => $project]))
            ->assertInertia(fn (Assert $page) => $page->where('estimateWeights.group:'.$construction['id'], 8.55));
        $this->saveStructure($project, $groups, publish: true)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('engineering_estimate_lines', ['wbs_group_id' => $construction['id'], 'wbs_item_id' => null]);

        $this->post(route('projects.period', $project), ['start' => '2026-10-01', 'end' => '2026-10-07'])
            ->assertSessionHasNoErrors();
        $firstPeriod = DB::table('report_periods')->value('id');
        $this->patch(route('projects.update', [$project, $firstPeriod]), [
            'item' => $construction['items'][0]['id'],
            'values' => [$construction['stages'][0]['id'] => 50],
        ])->assertSessionHasNoErrors();

        $this->saveEstimate($project, [
            $this->cost('1710', $groupCost),
            $this->cost('8290', $standardCost),
        ])->assertSessionHasNoErrors();
        $this->saveStructure($project, $groups, publish: true)->assertSessionHasNoErrors();
        $this->post(route('projects.period', $project), ['start' => '2026-10-08', 'end' => '2026-10-14'])
            ->assertSessionHasNoErrors();

        $this->get(route('dashboard', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.revision', 2)
                ->where('report.groups.0.weight', 17.1)
                ->where('report.groups.0.items.0.weight', 0.513));
        $this->get(route('dashboard', ['project' => $project, 'period' => $firstPeriod]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.revision', 1)
                ->where('bac', '10000.00')
                ->where('report.groups.0.weight', 8.55)
                ->where('report.groups.0.items.0.name', '101')
                ->where('report.groups.0.items.0.group_weight', 3)
                ->where('report.groups.0.items.0.weight', 0.2565)
                ->where('report.groups.0.progress', 1.5)
                ->where('report.groups.0.contribution', 0.12825)
                ->where('report.groups.1.items.0.group_weight', 100)
                ->where('report.actual', 0.12825));
    }

    public function test_manual_construction_weights_round_to_the_group_and_project_totals(): void
    {
        $project = $this->createProject();
        $construction = $this->construction(['1', '1', '1'], '3') + ['manual_project_weight' => 10, 'project_weight' => 50];
        $construction['items'][0] += ['group_weight' => 90, 'weight' => 90];

        $this->saveStructure($project, [$construction, $this->standard(90)], publish: true)
            ->assertSessionHasNoErrors();

        $stored = json_decode(DB::table('baselines')->value('structure'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(10, $stored[0]['project_weight']);
        $this->assertSame([33.333334, 33.333333, 33.333333], array_column($stored[0]['items'], 'group_weight'));
        $this->assertSame([3.333334, 3.333333, 3.333333], array_column($stored[0]['items'], 'weight'));
        $this->assertSame(90, $stored[1]['items'][0]['weight']);
    }

    #[TestWith([null, '1', 10, 90, 'Isi estimasi berat kapal lebih dari nol untuk Konstruksi.'])]
    #[TestWith(['1', '0', 10, 90, 'Isi estimasi berat lebih dari nol untuk blok 101.'])]
    #[TestWith(['2', '1', 10, 90, 'Total berat blok Konstruksi harus sama dengan estimasi berat kapal.'])]
    #[TestWith(['1', '1', null, 90, 'Isi bobot proyek kelompok Konstruksi lebih dari 0% untuk menetapkan baseline tanpa EE.'])]
    #[TestWith(['1', '1', 10, 80, 'Total bobot item dan kelompok Konstruksi seluruh proyek harus 100%.'])]
    public function test_invalid_construction_inputs_can_be_saved_as_draft_but_cannot_be_published(
        ?string $shipTons,
        string $blockTons,
        ?int $manualWeight,
        int $standardWeight,
        string $error,
    ): void {
        $project = $this->createProject();
        $groups = [$this->construction([$blockTons], $shipTons) + ['manual_project_weight' => $manualWeight], $this->standard($standardWeight)];

        $this->saveStructure($project, $groups)->assertSessionHasNoErrors();
        $this->saveStructure($project, $groups, publish: true)->assertSessionHasErrors(['groups' => $error]);

        $this->assertDatabaseCount('baselines', 0);
    }

    public function test_estimate_rejects_block_and_invalid_group_mappings_and_requires_construction_cost(): void
    {
        $project = $this->createProject();
        $construction = $this->construction(['1'], '1');
        $standard = $this->standard();
        $groups = [$construction, $standard];
        $this->saveStructure($project, $groups)->assertSessionHasNoErrors();
        $item = ['wbs_item_id' => $standard['items'][0]['id']];
        $group = ['wbs_group_id' => $construction['id']];

        $this->saveEstimate($project, [$this->cost('1', ['wbs_item_id' => $construction['items'][0]['id']])])
            ->assertSessionHasErrors(['lines.0.wbs_item_id']);
        $this->saveEstimate($project, [$this->cost('1', ['wbs_group_id' => $standard['id']])])
            ->assertSessionHasErrors(['lines.0.wbs_group_id']);
        $this->saveEstimate($project, [$this->cost('1', $group + $item)])
            ->assertSessionHasErrors(['lines.0.wbs_group_id']);
        $this->assertDatabaseCount('engineering_estimates', 0);

        $this->saveEstimate($project, [$this->cost('1', $item), $this->cost('0', $group)])->assertSessionHasNoErrors();
        $this->saveStructure($project, $groups, publish: true)
            ->assertSessionHasErrors(['estimate' => 'Petakan biaya EE lebih dari nol langsung ke kelompok Konstruksi.']);
        $this->assertDatabaseCount('baselines', 0);
    }

    public function test_converting_existing_items_to_blocks_requires_remapping_the_estimate_to_the_group(): void
    {
        $project = $this->createProject();
        $group = $this->standard();
        $this->saveStructure($project, [$group])->assertSessionHasNoErrors();
        $this->saveEstimate($project, [$this->cost('1', ['wbs_item_id' => $group['items'][0]['id']])])
            ->assertSessionHasNoErrors();
        $group += ['type' => 'construction', 'ship_weight_tons' => '1'];
        $group['items'][0]['weight_tons'] = '1';

        $this->saveStructure($project, [$group], publish: true)
            ->assertSessionHasErrors(['estimate' => 'Petakan baris EE 1 ke item Standar atau langsung ke kelompok Konstruksi.']);
        $this->assertDatabaseCount('baselines', 0);

        $this->saveStructure($project, [$group])->assertSessionHasNoErrors();
        $this->saveEstimate($project, [$this->cost('1', ['wbs_group_id' => $group['id']])])->assertSessionHasNoErrors();
        $this->saveStructure($project, [$group], publish: true)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('baselines', 1);
    }

    private function createProject(): int
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('projects.store'), [
            'code' => 'FERRY-1500', 'name' => 'Ferry 1500 GT', 'yard' => 'Galangan',
            'start' => '2026-01-01', 'end' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        return DB::table('projects')->value('id');
    }

    private function construction(array $blockTons, ?string $shipTons): array
    {
        $group = $this->standard();
        $group['name'] = 'Konstruksi';
        $group['type'] = 'construction';
        $group['ship_weight_tons'] = $shipTons;
        $group['items'] = [];
        foreach ($blockTons as $index => $tons) {
            $group['items'][] = ['id' => (string) Str::uuid(), 'name' => (string) (101 + $index), 'weight_tons' => $tons];
        }

        return $group;
    }

    private function standard(?int $weight = null): array
    {
        return [
            'id' => (string) Str::uuid(), 'name' => 'Pengadaan',
            'items' => [['id' => (string) Str::uuid(), 'name' => 'Peralatan', 'weight' => $weight]],
            'stages' => [['id' => (string) Str::uuid(), 'name' => 'Realisasi', 'weight' => 100]],
        ];
    }

    private function cost(string $value, array $target): array
    {
        return $target + ['description' => 'Biaya', 'local_price_idr' => $value, 'local_price_is_lump_sum' => true];
    }

    private function saveStructure(int $project, array $groups, bool $publish = false): TestResponse
    {
        return $this->put(route('projects.structure', $project), ['groups' => $groups, 'publish' => $publish]);
    }

    private function saveEstimate(int $project, array $lines): TestResponse
    {
        return $this->put(route('projects.estimate.update', $project), ['exchange_rate_is_assumption' => true, 'lines' => $lines]);
    }
}
