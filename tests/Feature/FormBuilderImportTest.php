<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class FormBuilderImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $workbookPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->workbookPaths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_downloaded_example_previews_the_harbour_tug_demo_estimate(): void
    {
        $project = $this->createProject();
        $filename = 'Contoh-Form-Builder-ASD-Harbour-Tug.xlsx';

        $download = $this->get(route('projects.formBuilderExample'))
            ->assertOk()
            ->assertDownload($filename)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertInstanceOf(BinaryFileResponse::class, $download->baseResponse);
        $file = new UploadedFile($download->baseResponse->getFile()->getPathname(), $filename, test: true);

        $this->preview($project, $file)->assertOk()
            ->assertJsonCount(9, 'groups')
            ->assertJsonPath('groups.0', ['name' => 'Design & Approval Drawing', 'item_count' => 4])
            ->assertJsonPath('item_count', 309)
            ->assertJsonPath('exchange_rate_idr_per_usd', '13138')
            ->assertJsonPath('exchange_rate_date', null)
            ->assertJsonPath('exchange_rate_is_assumption', true)
            ->assertJsonPath('equivalent_idr', '60023612409.10');
    }

    public function test_import_previews_then_saves_linked_wbs_and_estimate_drafts(): void
    {
        $project = $this->createProject();
        $builder = route('workspace', ['section' => 'builder', 'project' => $project]);
        $existingBaseline = DB::table('baselines')->insertGetId(['project_id' => $project, 'revision' => 1, 'structure' => '[]']);
        $period = DB::table('report_periods')->insertGetId(['baseline_id' => $existingBaseline, 'start' => '2026-01-01', 'end' => '2026-01-07']);
        $file = $this->workbook([
            'C6' => 20, 'E6' => 45292, 'G6' => 'Tidak',
            'B9' => 'Perpipaan', 'C9' => 'Pompa', 'D9' => 2, 'E9' => 'unit', 'F9' => 50, 'J9' => 9_999_999,
            'B10' => 'Perpipaan', 'C10' => 'Pompa', 'F10' => 100, 'G10' => 'Total (lump sum)', 'I10' => 'Jasa pemasangan',
            'B25' => 'Listrik', 'C25' => 'Panel', 'D25' => 2, 'E25' => 'unit', 'F25' => 25.000000000000004, 'G25' => 'Harga satuan', 'H25' => 1.25,
            'B26' => 'Perpipaan', 'C26' => 'Pipa', 'F26' => 0, 'G26' => 'Total (lump sum)',
        ]);

        $this->get($builder)->assertInertia(fn (Assert $page) => $page->where('canImportFormBuilder', true));
        $preview = $this->preview($project, $file)->assertOk()
            ->assertJsonPath('item_count', 4)
            ->assertJsonPath('groups', [['name' => 'Perpipaan', 'item_count' => 3], ['name' => 'Listrik', 'item_count' => 1]])
            ->assertJsonPath('exchange_rate_idr_per_usd', '20')
            ->assertJsonPath('exchange_rate_date', '2024-01-01')
            ->assertJsonPath('exchange_rate_is_assumption', false)
            ->assertJsonPath('local_idr', '250.00')
            ->assertJsonPath('import_usd', '2.50')
            ->assertJsonPath('equivalent_idr', '300.00');
        $this->assertDatabaseHas('projects', ['id' => $project, 'draft' => '[]']);
        $this->assertDatabaseCount('engineering_estimates', 0);

        $token = $preview->json('preview_token');
        $this->from($builder)->applyImport($project, $file, $token)
            ->assertRedirect($builder)->assertSessionHasNoErrors();
        $groups = json_decode(DB::table('projects')->where('id', $project)->value('draft'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['Pompa', 'Pompa', 'Pipa'], array_column($groups[0]['items'], 'name'));
        $this->assertSame([33.333334, 33.333333, 0], array_column($groups[0]['items'], 'weight'));
        $this->assertSame(33.333333, $groups[1]['items'][0]['weight']);
        $this->assertSame([[], []], array_column($groups, 'stages'));

        $this->assertDatabaseHas('engineering_estimates', [
            'project_id' => $project, 'revision' => 0, 'bac_idr' => null, 'exchange_rate_date' => '2024-01-01',
            'exchange_rate_is_assumption' => false, 'source_reference' => 'Rencana Kerja.xlsx',
        ]);
        $this->assertDatabaseHas('engineering_estimate_lines', [
            'position' => 1, 'wbs_item_id' => $groups[0]['items'][0]['id'], 'description' => 'Pompa',
        ]);
        $this->assertDatabaseHas('engineering_estimate_lines', [
            'position' => 2, 'wbs_item_id' => $groups[0]['items'][1]['id'], 'description' => 'Jasa pemasangan',
            'local_price_is_lump_sum' => true, 'source_work_group' => 'Perpipaan', 'source_reference' => 'Item Pekerjaan!B10:I10',
        ]);
        $this->assertDatabaseHas('engineering_estimate_lines', [
            'position' => 3, 'wbs_item_id' => $groups[1]['items'][0]['id'], 'usd_unit_price' => '1.25',
        ]);
        $this->assertDatabaseCount('engineering_estimate_lines', 4);
        $this->assertDatabaseCount('baselines', 1);
        $this->assertDatabaseHas('report_periods', ['id' => $period, 'baseline_id' => $existingBaseline]);
        $this->get($builder)->assertInertia(fn (Assert $page) => $page->where('canImportFormBuilder', false)->where('hasEstimate', true));

        $this->from($builder)->applyImport($project, $file, $token)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('engineering_estimate_lines', 4);
        foreach ($groups as &$group) {
            $group['stages'] = [['id' => (string) Str::uuid(), 'name' => 'Pemasangan selesai', 'weight' => 100]];
        }
        unset($group);
        $this->from($builder)->put(route('projects.structure', $project), ['groups' => $groups, 'publish' => true])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('engineering_estimates', ['project_id' => $project, 'revision' => 2, 'bac_idr' => '300.00']);
        $this->assertDatabaseHas('report_periods', ['id' => $period, 'baseline_id' => $existingBaseline]);
    }

    public function test_preview_reports_the_excel_cells_that_need_correction(): void
    {
        $project = $this->createProject();
        $file = $this->workbook([
            'C6' => null, 'E6' => 60, 'B9' => 'Listrik', 'F9' => '<formula>', 'H9' => 1,
            'B10' => 'Listrik', 'C10' => 'Kabel', 'F10' => -1, 'G10' => 'Lump Sum',
        ]);

        $this->preview($project, $file)->assertUnprocessable()
            ->assertJsonValidationErrors(['cells.C6', 'cells.E6', 'cells.C9', 'cells.D9', 'cells.E9', 'cells.F9', 'cells.F10', 'cells.G10'])
            ->assertSee('Item Pekerjaan!F9', escape: false);
        $this->assertDatabaseHas('projects', ['id' => $project, 'draft' => '[]']);
        $this->assertDatabaseCount('engineering_estimates', 0);
    }

    public function test_preview_rejects_a_corrupt_archive_and_an_unfilled_template(): void
    {
        $project = $this->createProject();

        $this->preview($project, UploadedFile::fake()->createWithContent('rusak.xlsx', 'Invalid workbook'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->preview($project, $this->workbook([]))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Isi minimal satu baris pada lembar Item Pekerjaan mulai dari baris 9.');
        $this->preview($project, $this->workbook(['C8' => 'Judul diubah']))
            ->assertUnprocessable()->assertSee('Item Pekerjaan!C8', escape: false);
        $this->preview($project, UploadedFile::fake()->create('besar.xlsx', 5121))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Ukuran file Excel maksimal 5 MB.');
    }

    public function test_import_requires_the_same_file_project_and_a_current_preview(): void
    {
        $project = $this->createProject();
        $owner = auth()->user();
        $otherProject = $this->createProject($owner);
        $file = $this->validWorkbook();
        $differentFile = $this->validWorkbook(price: 11);
        $token = $this->preview($project, $file)->assertOk()->json('preview_token');

        $this->applyImport($project, $file, 'invalid-token')->assertSessionHasErrors('preview_token');
        $this->applyImport($project, $differentFile, $token)->assertSessionHasErrors('preview_token');
        $this->applyImport($otherProject, $file, $token)->assertSessionHasErrors('preview_token');
        $this->travel(15)->minutes();
        $this->applyImport($project, $file, $token)->assertSessionHasErrors('preview_token');

        $this->assertDatabaseHas('projects', ['id' => $project, 'draft' => '[]']);
        $this->assertDatabaseCount('engineering_estimates', 0);
    }

    public function test_import_rechecks_ownership_on_both_requests(): void
    {
        $project = $this->createProject();
        $file = $this->validWorkbook();
        $token = $this->preview($project, $file)->assertOk()->json('preview_token');
        $this->actingAs(User::factory()->create());

        $this->preview($project, $file)->assertNotFound();
        $this->applyImport($project, $file, $token)->assertNotFound();

        $this->assertDatabaseCount('engineering_estimates', 0);
    }

    public function test_apply_rejects_work_added_after_preview(): void
    {
        $project = $this->createProject();
        $file = $this->validWorkbook();
        $token = $this->preview($project, $file)->assertOk()->json('preview_token');
        $group = ['id' => (string) Str::uuid(), 'name' => 'Draft yang dibuat manual', 'items' => [], 'stages' => []];
        $this->put(route('projects.structure', $project), ['groups' => [$group]])->assertSessionHasNoErrors();

        $this->applyImport($project, $file, $token)->assertSessionHasErrors('file');

        $this->assertSame([$group], json_decode(DB::table('projects')->where('id', $project)->value('draft'), true));
        $this->assertDatabaseCount('engineering_estimates', 0);
    }

    public function test_saved_exchange_rate_blocks_import_even_without_estimate_lines(): void
    {
        $project = $this->createProject();
        $file = $this->validWorkbook();
        $this->put(route('projects.estimate.update', $project), [
            'lines' => [], 'exchange_rate_is_assumption' => true, 'exchange_rate_idr_per_usd' => '18050',
        ])->assertSessionHasNoErrors();

        $this->preview($project, $file)->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseHas('engineering_estimates', ['project_id' => $project, 'exchange_rate_idr_per_usd' => '18050']);
        $this->assertDatabaseCount('engineering_estimate_lines', 0);
    }

    public function test_a_save_failure_rolls_back_both_drafts(): void
    {
        $project = $this->createProject();
        $file = $this->validWorkbook();
        $token = $this->preview($project, $file)->assertOk()->json('preview_token');
        DB::unprepared("CREATE TEMP TRIGGER fail_import BEFORE INSERT ON engineering_estimate_lines BEGIN SELECT RAISE(ABORT, 'Import write failed'); END");

        try {
            $this->applyImport($project, $file, $token)->assertInternalServerError();
        } finally {
            DB::unprepared('DROP TRIGGER fail_import');
        }

        $this->assertDatabaseHas('projects', ['id' => $project, 'draft' => '[]']);
        $this->assertDatabaseCount('engineering_estimates', 0);
        $this->assertDatabaseCount('engineering_estimate_lines', 0);
    }

    private function createProject(?User $owner = null): int
    {
        $this->actingAs($owner ?? User::factory()->create());
        $code = (string) Str::uuid();
        $this->post(route('projects.store'), [
            'code' => $code, 'name' => 'Kapal Tunda', 'yard' => 'Galangan', 'start' => '2026-01-01', 'end' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        return DB::table('projects')->where('code', $code)->value('id');
    }

    private function preview(int $project, UploadedFile $file): TestResponse
    {
        return $this->post(route('projects.formBuilderImport.preview', $project), ['file' => $file], ['Accept' => 'application/json']);
    }

    private function applyImport(int $project, UploadedFile $file, string $token): TestResponse
    {
        return $this->post(route('projects.formBuilderImport.apply', $project), ['file' => $file, 'preview_token' => $token]);
    }

    private function validWorkbook(int $price = 10): UploadedFile
    {
        return $this->workbook(['B9' => 'Kelistrikan', 'C9' => 'Kabel', 'F9' => $price, 'G9' => 'Total (lump sum)']);
    }

    /** @param  array<string, string|int|float|null>  $cells */
    private function workbook(array $cells): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'form-builder-');
        $this->assertNotFalse($path);
        $this->workbookPaths[] = $path;
        $this->assertTrue(copy(resource_path('form-builder-template.xlsx'), $path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $part = 'xl/worksheets/sheet1.xml';
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($zip->getFromName($part)));
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', $namespace);
        $sheetData = $xpath->query('/s:worksheet/s:sheetData')->item(0);
        foreach ($cells as $address => $value) {
            $number = substr($address, 1);
            $row = $xpath->query("s:row[@r='{$number}']", $sheetData)->item(0);
            if (! $row instanceof DOMElement) {
                $row = $document->createElementNS($namespace, 'row');
                $row->setAttribute('r', $number);
                $sheetData->appendChild($row);
            }
            $cell = $xpath->query("s:c[@r='{$address}']", $row)->item(0);
            if (! $cell instanceof DOMElement) {
                $cell = $document->createElementNS($namespace, 'c');
                $cell->setAttribute('r', $address);
                $row->appendChild($cell);
            }
            $cell->textContent = '';
            $cell->removeAttribute('t');
            if ($value === '<formula>') {
                $cell->appendChild($document->createElementNS($namespace, 'f', '1+1'));
                $cell->appendChild($document->createElementNS($namespace, 'v', '2'));
            } elseif (is_string($value)) {
                $cell->setAttribute('t', 'inlineStr');
                $cell->appendChild($document->createElementNS($namespace, 'is'))
                    ->appendChild($document->createElementNS($namespace, 't'))
                    ->appendChild($document->createTextNode($value));
            } elseif ($value !== null) {
                $cell->appendChild($document->createElementNS($namespace, 'v', json_encode($value, JSON_THROW_ON_ERROR)));
            }
        }
        $zip->addFromString($part, $document->saveXML());
        $this->assertTrue($zip->close());

        return new UploadedFile($path, 'Rencana Kerja.xlsx', test: true);
    }
}
