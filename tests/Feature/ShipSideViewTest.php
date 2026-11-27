<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShipSideViewTest extends TestCase
{
    use RefreshDatabase;

    private const array TRIANGLE = [[0.1, 0.1], [0.4, 0.1], [0.4, 0.8]];

    private int $project;

    /** @var array<string, mixed> */
    private array $group;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->post(route('projects.store'), [
            'code' => 'FERRY-1500', 'name' => 'Ferry 1500 GT', 'yard' => 'Galangan',
            'start' => '2026-01-01', 'end' => '2026-12-31',
        ])->assertSessionHasNoErrors();
        $this->project = DB::table('projects')->value('id');
        $this->group = [
            'id' => (string) Str::uuid(), 'name' => 'Konstruksi', 'type' => 'construction', 'manual_project_weight' => 100,
            'items' => [
                ['id' => (string) Str::uuid(), 'name' => '101', 'weight_tons' => '10'],
                ['id' => (string) Str::uuid(), 'name' => '102', 'weight_tons' => '10'],
            ],
            'stages' => [['id' => (string) Str::uuid(), 'name' => 'Erection', 'weight' => 100]],
        ];
        $this->put(route('projects.structure', $this->project), ['groups' => [$this->group], 'publish' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_upload_stores_one_image_per_project_and_replaces_the_previous_file(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $first = DB::table('projects')->value('side_view_path');
        Storage::disk('local')->assertExists($first);

        $this->upload(UploadedFile::fake()->image('new.png', 1000, 200))->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($first);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('projects', ['side_view_width' => 1000, 'side_view_height' => 200]);
        $this->get(route('projects.sideView.show', $this->project))->assertOk();
    }

    public function test_upload_rejects_files_that_are_not_images(): void
    {
        $this->upload(UploadedFile::fake()->create('drawing.pdf', 10, 'application/pdf'))
            ->assertSessionHasErrors('image');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_another_user_cannot_view_or_change_the_image_and_areas(): void
    {
        $this->upload();
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.sideView.show', $this->project))->assertNotFound();
        $this->upload()->assertNotFound();
        $this->saveArea($this->block(0), self::TRIANGLE)->assertNotFound();
        $this->delete(route('projects.sideView.destroy', $this->project))->assertNotFound();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_block_has_one_area_which_can_be_replaced_reassigned_and_removed(): void
    {
        $this->upload();
        $this->saveArea($this->block(0), self::TRIANGLE)->assertSessionHasNoErrors();
        $this->saveArea($this->block(0), [[0, 0], [1, 0], [1, 1]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('block_areas', 1);
        $this->assertSame('[[0,0],[1,0],[1,1]]', DB::table('block_areas')->value('points'));

        $this->saveArea($this->block(1), self::TRIANGLE, previous: $this->block(0))->assertSessionHasNoErrors();
        $this->assertSame([$this->block(1)], DB::table('block_areas')->pluck('item')->all());

        $this->saveArea($this->block(1), null)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('block_areas', 0);
    }

    #[TestWith([[[0.1, 0.1], [0.4, 0.1]], 'points'])]
    #[TestWith([[[0.1, 0.1], [1.4, 0.1], [0.4, 0.8]], 'points.1.0'])]
    #[TestWith([[[0.1, 0.1], [0.4], [0.4, 0.8]], 'points.1'])]
    public function test_invalid_polygons_are_rejected(array $points, string $error): void
    {
        $this->upload();

        $this->saveArea($this->block(0), $points)->assertSessionHasErrors($error);

        $this->assertDatabaseCount('block_areas', 0);
    }

    public function test_areas_require_an_image_and_a_construction_block(): void
    {
        $this->saveArea($this->block(0), self::TRIANGLE)->assertSessionHasErrors('points');

        $this->upload();
        $this->saveArea((string) Str::uuid(), self::TRIANGLE)->assertNotFound();
        $this->assertDatabaseCount('block_areas', 0);
    }

    public function test_deleting_the_image_removes_the_file_and_all_areas(): void
    {
        $this->upload();
        $this->saveArea($this->block(0), self::TRIANGLE);

        $this->delete(route('projects.sideView.destroy', $this->project))->assertSessionHasNoErrors();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('block_areas', 0);
        $this->assertDatabaseHas('projects', ['side_view_path' => null]);
    }

    public function test_summary_shows_the_latest_saved_progress_up_to_the_selected_period(): void
    {
        $this->upload();
        $this->saveArea($this->block(0), self::TRIANGLE);
        $first = $this->period('2026-10-01', '2026-10-07', 'W1', [0 => 40]);
        $second = $this->period('2026-10-08', '2026-10-14', 'W2', [1 => 0]);
        $this->period('2026-10-15', '2026-10-21', 'W3', [0 => 100]);

        $this->get(route('dashboard', ['project' => $this->project, 'period' => $second]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sideView.width', 2000)
                ->where('blocks.0.name', '101')
                ->where('blockAreas.'.$this->block(0), self::TRIANGLE)
                ->where('blockStatus.'.$this->block(0).'.progress', 40)
                ->where('blockStatus.'.$this->block(0).'.period_label', 'W1')
                ->where('blockStatus.'.$this->block(1).'.progress', 0)
                ->missing('project.side_view_path'));
        $this->get(route('dashboard', ['project' => $this->project, 'period' => $first]))
            ->assertInertia(fn (Assert $page) => $page->missing('blockStatus.'.$this->block(1)));
        $this->get(route('dashboard', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('blockStatus.'.$this->block(0).'.progress', 100));
    }

    private function block(int $index): string
    {
        return $this->group['items'][$index]['id'];
    }

    private function upload(?UploadedFile $file = null): TestResponse
    {
        return $this->post(route('projects.sideView.store', $this->project), [
            'image' => $file ?? UploadedFile::fake()->image('side-view.png', 2000, 320),
        ]);
    }

    private function saveArea(string $item, ?array $points, ?string $previous = null): TestResponse
    {
        return $this->put(route('projects.blockAreas.update', $this->project), compact('item', 'points', 'previous'));
    }

    /** @param  array<int, int>  $values  Realization keyed by block index. */
    private function period(string $start, string $end, string $label, array $values): int
    {
        $this->post(route('projects.period', $this->project), compact('start', 'end', 'label'))->assertSessionHasNoErrors();
        $period = DB::table('report_periods')->where('start', $start)->value('id');
        foreach ($values as $index => $value) {
            $this->patch(route('projects.update', [$this->project, $period]), [
                'item' => $this->block($index),
                'values' => [$this->group['stages'][0]['id'] => $value],
            ])->assertSessionHasNoErrors();
        }

        return $period;
    }
}
