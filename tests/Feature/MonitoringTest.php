<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_download_the_form_builder_template(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.formBuilderTemplate'))
            ->assertOk()
            ->assertDownload('Template-Form-Builder.xlsx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[TestWith(['projects.formBuilderTemplate'])]
    #[TestWith(['projects.formBuilderExample'])]
    public function test_form_builder_download_requires_a_verified_account(string $route): void
    {
        $url = route($route);

        $this->get($url)->assertRedirectToRoute('login');

        $this->actingAs(User::factory()->unverified()->create());

        $this->get($url)->assertRedirectToRoute('verification.notice');
    }

    private function vessel(string $code, ?int $source = null): int
    {
        $this->post(route('projects.store'), ['code' => $code, 'name' => $code, 'yard' => 'Galangan', 'start' => '2026-01-01', 'end' => '2026-12-31', 'source' => $source])->assertSessionHasNoErrors();

        return DB::table('projects')->where('code', $code)->value('id');
    }

    private function row(string $name, int $weight): array
    {
        return ['id' => (string) Str::uuid(), 'name' => $name, 'weight' => $weight];
    }

    public function test_owner_can_edit_project_identity_without_changing_monitoring_data(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = $this->vessel('Tug');
        DB::table('projects')->where('id', $project)->update(['is_demo' => true]);

        $item = $this->row('Lambung', 100);
        $stage = $this->row('Pengecatan', 100);
        $group = ['id' => (string) Str::uuid(), 'name' => 'Pengecatan', 'items' => [$item], 'stages' => [$stage]];
        $this->put(route('projects.structure', $project), ['groups' => [$group], 'publish' => true])->assertSessionHasNoErrors();
        $this->post(route('projects.period', $project), ['start' => '2026-01-01', 'end' => '2026-01-07', 'target' => 20])->assertSessionHasNoErrors();
        $period = DB::table('report_periods')->value('id');
        $this->patch(route('projects.update', [$project, $period]), ['item' => $item['id'], 'values' => [$stage['id'] => 15]])->assertSessionHasNoErrors();

        $this->get(route('projects.edit', $project))->assertInertia(fn (Assert $page) => $page
            ->component('projects/edit')
            ->where('project.id', $project)
            ->where('project.code', 'Tug')
            ->where('project.name', 'Tug')
            ->where('project.yard', 'Galangan')
            ->where('project.start', '2026-01-01')
            ->where('project.end', '2026-12-31'));

        $identity = ['code' => 'Tug-02', 'name' => 'Kapal Tunda', 'yard' => 'Galangan Baru', 'start' => '2026-02-01', 'end' => '2026-02-01'];
        $summary = route('workspace', ['section' => 'ringkasan', 'project' => $project]);
        $this->from(route('projects.edit', $project))
            ->patch(route('projects.updateIdentity', $project), $identity + ['user_id' => User::factory()->create()->id, 'is_demo' => false, 'draft' => '[]'])
            ->assertRedirect($summary)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Perubahan proyek disimpan.']);

        $this->assertDatabaseHas('projects', $identity + ['id' => $project, 'user_id' => $owner->id, 'is_demo' => true]);
        $this->get($summary)->assertInertia(fn (Assert $page) => $page
            ->where('project.name', 'Kapal Tunda')
            ->where('projects.0.name', 'Kapal Tunda')
            ->where('draft.0.id', $group['id'])
            ->where('revision', 1)
            ->where('period.id', $period)
            ->where('period.start', '2026-01-01')
            ->where('period.end', '2026-01-07')
            ->where('report.actual', 15));
    }

    public function test_project_identity_validates_dates_and_code_within_the_owner_scope(): void
    {
        $this->actingAs(User::factory()->create());
        $this->vessel('Shared');
        $this->actingAs(User::factory()->create());
        $project = $this->vessel('Tug');
        $this->vessel('Ferry');
        $edit = route('projects.edit', $project);
        $update = route('projects.updateIdentity', $project);
        $identity = ['code' => 'Tug', 'name' => 'Kapal Tunda', 'yard' => 'Galangan', 'start' => '2026-01-01', 'end' => '2026-12-31'];

        $this->from($edit)->patch($update, array_replace($identity, ['code' => 'Ferry', 'end' => '2025-12-31']))
            ->assertRedirect($edit)
            ->assertSessionHasErrors(['code', 'end']);
        $this->assertDatabaseHas('projects', ['id' => $project, 'code' => 'Tug', 'name' => 'Tug', 'end' => '2026-12-31']);

        $this->from($edit)->patch($update, $identity)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', ['id' => $project, 'code' => 'Tug', 'name' => 'Kapal Tunda']);

        $this->from($edit)->patch($update, array_replace($identity, ['code' => 'Shared']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', ['id' => $project, 'code' => 'Shared']);
    }

    public function test_project_identity_routes_require_ownership(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->vessel('Tug');
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.edit', $project))->assertNotFound();
        $this->patch(route('projects.updateIdentity', $project), [])->assertNotFound();
        $this->get(route('projects.edit', $project + 1))->assertNotFound();
        $this->patch(route('projects.updateIdentity', $project + 1), [])->assertNotFound();
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
