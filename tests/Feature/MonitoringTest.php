<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function vessel(string $code, ?int $source = null): int
    {
        $this->post(route('projects.store'), ['code' => $code, 'name' => $code, 'yard' => 'Galangan', 'start' => '2026-01-01', 'end' => '2026-12-31', 'source' => $source])->assertSessionHasNoErrors();

        return DB::table('projects')->where('code', $code)->value('id');
    }

    private function row(string $name, int $weight): array
    {
        return ['id' => (string) Str::uuid(), 'name' => $name, 'weight' => $weight];
    }

    public function test_project_baselines_measurements_and_history_are_isolated(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->vessel('Tug');
        $item = $this->row('Lambung', 10);
        $prep = $this->row('Persiapan', 60);
        $paint = $this->row('Pengecatan', 40);
        $group = ['id' => (string) Str::uuid(), 'name' => 'Painting', 'items' => [$item], 'stages' => [$prep, $paint]];
        $structure = route('projects.structure', $a);
        $this->put($structure, ['groups' => [$group]])->assertSessionHasNoErrors();
        $this->put($structure, ['groups' => [$group], 'publish' => true])->assertSessionHasErrors('groups');
        $group['items'][] = $this->row('Dek', 90);
        $bad = $group;
        $bad['stages'] = [$prep];
        $this->put($structure, ['groups' => [$bad], 'publish' => true])->assertSessionHasErrors('groups');
        $this->put($structure, ['groups' => [$group], 'publish' => true])->assertSessionHasNoErrors();
        $week = ['start' => '2026-01-01', 'end' => '2026-01-07', 'target' => 20];
        $this->post(route('projects.period', $a), $week)->assertSessionHasNoErrors();
        $p = DB::table('report_periods')->value('id');
        $url = route('projects.update', [$a, $p]);
        $summary = route('dashboard', ['project' => $a, 'period' => $p]);
        $this->get($summary)->assertInertia(fn (Assert $page) => $page->where('report.actual', null));
        $this->patch($url, ['item' => $item['id'], 'values' => [$prep['id'] => 0, $paint['id'] => null]])->assertSessionHasNoErrors();
        $this->get($summary)->assertInertia(fn (Assert $page) => $page->where('report.actual', 0)->where('report.measured', 1)->where('report.expected', 4));
        $this->patch($url, ['item' => $item['id'], 'values' => [$prep['id'] => 100]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('measurements', 1);
        $this->get($summary)->assertInertia(fn (Assert $page) => $page->where('report.actual', 6)->where('period.partial', true)->where('period.deviation', -14));
        $this->patch($url, ['close' => true])->assertSessionHasNoErrors();
        $this->patch($url, ['target' => 75])->assertSessionHasErrors('period');
        $b = $this->vessel('Ferry', $a);
        $this->get(route('workspace', ['section' => 'builder', 'project' => $b]))->assertInertia(fn (Assert $page) => $page->where('draft.0.stages.0.name', 'Persiapan')->where('report.measured', 0));
        $group['stages'] = [$this->row('Pengujian mesin', 100)];
        $this->put(route('projects.structure', $b), ['groups' => [$group], 'publish' => true])->assertSessionHasNoErrors();
        $this->get(route('dashboard', ['project' => $b]))->assertInertia(fn (Assert $page) => $page->where('report.groups.0.stages.0.name', 'Pengujian mesin'));
        $this->patch(route('projects.update', [$b, $p]), ['target' => 1])->assertNotFound();
        $this->put($structure, ['groups' => [$group], 'publish' => true])->assertSessionHasNoErrors();
        $this->post(route('projects.period', $a), ['start' => '2026-01-08', 'end' => '2026-01-14'])->assertSessionHasNoErrors();
        $this->get($summary)->assertInertia(fn (Assert $page) => $page->where('period.revision', 1)->where('report.actual', 6)->where('history.1.revision', 2)->where('history.1.actual', null));
        $this->actingAs(User::factory()->create());
        $this->get($summary)->assertNotFound();
        $this->put($structure, ['groups' => []])->assertNotFound();
        $this->patch($url, ['target' => 1])->assertNotFound();
        $this->post(route('projects.store'), ['code' => 'X', 'name' => 'X', 'yard' => 'X', 'start' => $week['start'], 'end' => $week['end'], 'source' => $a])->assertNotFound();
    }
}
