<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AsdHarbourTugDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AsdHarbourTugDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_can_be_seeded_twice_and_week_fourteen_is_calculated_from_measurements(): void
    {
        $owner = User::factory()->create();

        config(['monitoring.demo_owner_email' => $owner->email]);
        $this->seed([AsdHarbourTugDemoSeeder::class, AsdHarbourTugDemoSeeder::class]);

        $project = DB::table('projects')->sole();

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('baselines', 1);
        $this->assertDatabaseCount('report_periods', 16);
        $this->assertDatabaseCount('measurements', 2550);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'user_id' => $owner->id, 'code' => 'ASD-TUG-DEMO']);

        $groups = collect(json_decode(DB::table('baselines')->value('structure'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(9, $groups);
        $this->assertCount(306, $groups->pluck('items')->collapse());
        $this->assertEqualsWithDelta(100, $groups->pluck('items')->collapse()->sum('weight'), 0.0000001);
        foreach ($groups as $group) {
            $this->assertEqualsWithDelta(100, array_sum(array_column($group['stages'], 'weight')), 0.0000001);
        }

        $periods = DB::table('report_periods')->orderBy('end')->get();
        $weekFourteen = $periods[13];
        $this->assertNull($weekFourteen->reported_actual);
        $this->assertNotNull($weekFourteen->closed_at);
        $this->assertSame(395, DB::table('measurements')->where('period_id', $weekFourteen->id)->where('value', 0)->count());
        $this->assertDatabaseMissing('measurements', ['period_id' => $periods[12]->id]);
        $this->assertDatabaseMissing('measurements', ['period_id' => $periods[14]->id]);

        $this->actingAs($owner)->get(route('dashboard', ['project' => $project->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.is_demo', true)
                ->where('period.id', $weekFourteen->id)
                ->where('period.is_simulated', true)
                ->where('period.target', fn ($value) => (float) $value === 98.50)
                ->where('report.actual', fn (float $value) => round($value, 2) === 79.83)
                ->where('period.deviation', fn (float $value) => round($value, 2) === -18.67)
                ->where('report.measured', 2550)
                ->where('history.0.actual', 3.09)
                ->where('history.12.actual', 78.96)
                ->where('history.14.actual', null)
                ->where('history.15.actual', null));

        $this->get(route('dashboard', ['project' => $project->id, 'period' => $periods[12]->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.is_simulated', false)
                ->where('report.actual', 78.96)
                ->where('report.measured', 0)
                ->where('report.groups.0.contribution', null));
    }
}
