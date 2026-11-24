<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EngineeringEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_costs_determine_weights_and_published_bac_stays_with_its_period(): void
    {
        [$project, $group] = $this->createProject();
        [$first, $second, $third] = array_column($group['items'], 'id');
        $lines = [
            $this->line($first, ['local_price_idr' => '0.25']),
            $this->line($first, ['quantity' => '4000', 'local_price_idr' => '0.75', 'local_price_is_lump_sum' => true]),
            $this->line($second, ['usd_unit_price' => '0.1']),
            $this->line($third, ['local_price_idr' => '1']),
        ];
        $edit = route('workspace', ['section' => 'estimasi', 'project' => $project]);
        $this->from($edit)->put(route('projects.estimate.update', $project), $this->draft($lines, '10'))
            ->assertRedirect($edit)->assertSessionHasNoErrors();

        $this->get($edit)->assertInertia(fn (Assert $page) => $page
            ->where('estimate.local_idr', '2.00')
            ->where('estimate.import_usd', '0.10')
            ->where('estimate.equivalent_idr', '3.00')
            ->where('estimate.lines.1.local_idr', '0.75'));

        $this->publish($project, $group)->assertSessionHasNoErrors();
        $baseline = DB::table('baselines')->where('revision', 1)->sole();
        $groups = json_decode($baseline->structure, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([33.333334, 33.333333, 33.333333], array_column($groups[0]['items'], 'weight'));

        $this->get(route('dashboard', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page->where('period', null)->where('bac', '3.00'));
        $this->post(route('projects.period', $project), ['start' => '2026-01-01', 'end' => '2026-01-07'])->assertSessionHasNoErrors();
        $firstPeriod = DB::table('report_periods')->value('id');

        $this->from($edit)->put(route('projects.estimate.update', $project), $this->draft($lines, '20'))->assertSessionHasNoErrors();
        $this->publish($project, $group)->assertSessionHasNoErrors();
        $this->post(route('projects.period', $project), ['start' => '2026-01-08', 'end' => '2026-01-14'])->assertSessionHasNoErrors();

        $this->get(route('dashboard', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page->where('period.revision', 2)->where('bac', '4.00'));
        $this->get(route('dashboard', ['project' => $project, 'period' => $firstPeriod]))
            ->assertInertia(fn (Assert $page) => $page->where('period.revision', 1)->where('bac', '3.00'));

        $this->assertDatabaseHas('engineering_estimates', [
            'project_id' => $project, 'revision' => 1, 'exchange_rate_idr_per_usd' => '10', 'bac_idr' => '3.00',
        ]);
        $this->assertDatabaseCount('engineering_estimates', 3);
        $this->assertDatabaseCount('engineering_estimate_lines', 12);
    }

    public function test_publishing_rejects_incomplete_costs_and_stale_wbs_mappings_atomically(): void
    {
        [$project, $group] = $this->createProject();
        $item = $group['items'][0]['id'];
        $url = route('projects.estimate.update', $project);

        $this->put($url, $this->draft([$this->line((string) Str::uuid(), ['usd_unit_price' => '1'])], '1'))
            ->assertSessionHasErrors(['lines.0.wbs_item_id' => 'Pilih item dari draft WBS proyek ini.']);
        $this->assertDatabaseCount('engineering_estimates', 0);

        $this->put($url, $this->draft([$this->line($item, ['usd_unit_price' => '1'])], '0'))->assertSessionHasNoErrors();
        $this->publish($project, $group)
            ->assertSessionHasErrors(['estimate' => 'Isi kurs positif untuk biaya impor sebelum menetapkan baseline.']);

        $this->put($url, $this->draft([$this->line($item, ['quantity' => null, 'local_price_idr' => '1'])]))->assertSessionHasNoErrors();
        $this->publish($project, $group)
            ->assertSessionHasErrors(['estimate' => 'Lengkapi volume dan satuan untuk harga satuan pada baris EE 1.']);

        $this->put($url, $this->draft([$this->line($item, ['local_price_idr' => '1'])]))->assertSessionHasNoErrors();
        $staleGroup = $group;
        array_shift($staleGroup['items']);
        $this->publish($project, $staleGroup)
            ->assertSessionHasErrors(['estimate' => 'Petakan baris EE 1 ke item WBS yang masih ada.']);
        $this->assertDatabaseCount('baselines', 0);
        $this->assertDatabaseCount('engineering_estimates', 1);

        $this->put($url, $this->draft([]))->assertSessionHasNoErrors();
        $group['items'][0]['weight'] = 100;
        $group['items'][1]['weight'] = 0;
        $group['items'][2]['weight'] = 0;
        $this->publish($project, $group)->assertSessionHasNoErrors();
        $this->get(route('dashboard', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page->where('bac', null)->where('report.groups.0.items.0.weight', 100));
    }

    public function test_saving_an_existing_estimate_preserves_its_source_metadata(): void
    {
        [$project, $group] = $this->createProject();
        $item = $group['items'][0]['id'];
        $url = route('projects.estimate.update', $project);
        $this->put($url, $this->draft([$this->line($item, ['source_reference' => 'EE!10'])], '10') + [
            'source_reference' => 'Summary EE!F18',
            'exchange_rate_source' => 'Summary EE!A4',
        ])->assertSessionHasNoErrors();
        $lineId = DB::table('engineering_estimate_lines')->value('id');

        $this->put($url, $this->draft([$this->line($item, ['id' => $lineId, 'local_price_idr' => '25'])], '10'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('engineering_estimates', [
            'project_id' => $project, 'revision' => 0,
            'source_reference' => 'Summary EE!F18', 'exchange_rate_source' => 'Summary EE!A4',
        ]);
        $this->assertDatabaseHas('engineering_estimate_lines', [
            'wbs_item_id' => $item, 'local_price_idr' => '25', 'source_reference' => 'EE!10',
        ]);
    }

    public function test_estimate_reads_and_writes_are_scoped_to_the_owner(): void
    {
        [$project] = $this->createProject();

        $this->actingAs(User::factory()->create());
        $this->get(route('workspace', ['section' => 'estimasi', 'project' => $project]))->assertNotFound();
        $this->put(route('projects.estimate.update', $project), $this->draft([]))->assertNotFound();
        $this->assertDatabaseCount('engineering_estimates', 0);
    }

    private function createProject(): array
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('projects.store'), [
            'code' => 'EE-TUG', 'name' => 'Kapal Tunda', 'yard' => 'Galangan',
            'start' => '2026-01-01', 'end' => '2026-12-31',
        ])->assertSessionHasNoErrors();
        $project = DB::table('projects')->value('id');
        $group = [
            'id' => (string) Str::uuid(), 'name' => 'Konstruksi',
            'items' => array_map(fn (string $name): array => ['id' => (string) Str::uuid(), 'name' => $name, 'weight' => null], ['A', 'B', 'C']),
            'stages' => [['id' => (string) Str::uuid(), 'name' => 'Realisasi', 'weight' => 100]],
        ];
        $this->put(route('projects.structure', $project), ['groups' => [$group]])->assertSessionHasNoErrors();

        return [$project, $group];
    }

    private function line(string $item, array $fields): array
    {
        return $fields + [
            'description' => 'Material', 'quantity' => '1', 'unit' => 'unit',
            'local_price_is_lump_sum' => false, 'wbs_item_id' => $item,
        ];
    }

    private function draft(array $lines, ?string $rate = null): array
    {
        return ['exchange_rate_is_assumption' => true, 'exchange_rate_idr_per_usd' => $rate, 'lines' => $lines];
    }

    private function publish(int $project, array $group): TestResponse
    {
        return $this->put(route('projects.structure', $project), ['groups' => [$group], 'publish' => true]);
    }
}
