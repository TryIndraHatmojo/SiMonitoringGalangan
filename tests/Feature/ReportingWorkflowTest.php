<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MonitoringWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class ReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function project(): int
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('projects.store'), ['code' => 'T', 'name' => 'Tug', 'yard' => 'Galangan', 'start' => '2026-01-01', 'end' => '2026-12-31'])->assertSessionHasNoErrors();

        return DB::table('projects')->value('id');
    }

    private function workbook(): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'testmonitor-');
        MonitoringWorkbook::template($file);
        $zip = new ZipArchive;
        $zip->open($file);
        $rows = [['13000', '2026-01-01', 'Penawaran', '1'], ['G', 'Lambung'], ['G', 'I', 'Pelat', '100'], ['G', 'S', 'Uji', '100'], ['B', 'Baja', 'ton', '2', '100', '1', 'I'], ['2026-01-01', '2026-01-07', '30'], ['2026-01-07', 'I', 'S', '25', '', '0']];
        foreach ($rows as $index => $values) {
            $xml = '<row r="2">';
            foreach ($values as $i => $value) {
                $xml .= '<c r="'.chr(65 + $i).'2" t="inlineStr"><is><t>'.$value.'</t></is></c>';
            }
            $path = 'xl/worksheets/sheet'.($index + 1).'.xml';
            $zip->addFromString($path, str_replace('</sheetData>', $xml.'</row></sheetData>', $zip->getFromName($path)));
        }
        $zip->close();
        $upload = UploadedFile::fake()->createWithContent('monitoring.xlsx', file_get_contents($file));
        unlink($file);

        return $upload;
    }

    public function test_import_is_previewed_then_reconciled_and_cost_report_can_be_closed_and_corrected(): void
    {
        $project = $this->project();
        $upload = $this->workbook();
        $preview = route('projects.previewImport', $project);
        $apply = route('projects.applyImport', $project);
        $this->post($preview, ['file' => $upload])->assertSessionHasNoErrors()->assertSessionHas('import_preview');
        $this->assertDatabaseCount('baselines', 0);
        $this->post($apply, ['token' => session('import_preview.token')])->assertSessionHasNoErrors();
        $this->post($preview, ['file' => $upload])->assertSessionHasNoErrors();
        $this->post($apply, ['token' => session('import_preview.token')])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('baselines', 1);
        $this->assertDatabaseCount('measurements', 1);
        $period = DB::table('report_periods')->value('id');
        $url = route('projects.update', [$project, $period]);
        $this->patch($url, ['close' => true])->assertSessionHasErrors('period');
        $cost = ['actual_cost' => '0', 'cost_source' => 'Buku Januari'];
        $this->patch($url, $cost)->assertSessionHasNoErrors();
        $this->patch($url, $cost + ['verify' => true])->assertSessionHasNoErrors();
        $this->patch($url, ['close' => true])->assertSessionHasNoErrors();
        $this->post(route('projects.correct', [$project, $period]), ['actual_cost' => '100', 'cost_source' => 'Revisi Januari', 'reason' => 'Penyesuaian buku'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('period_corrections', ['period_id' => $period, 'reason' => 'Penyesuaian buku']);
        $this->assertDatabaseHas('report_periods', ['id' => $period, 'actual_cost' => 100]);
        $this->put(route('projects.schedule', $project), ['revision' => 1, 'rows' => [['start' => '2026-01-01', 'end' => '2026-01-07', 'target' => 35]]])->assertSessionHasErrors('rows');
        $this->post($preview, ['file' => $upload])->assertSessionHasNoErrors();
        $token = session('import_preview.token');
        $this->put(route('projects.schedule', $project), ['revision' => 1, 'rows' => [['start' => '2026-01-08', 'end' => '2026-01-14', 'target' => 65]]])->assertSessionHasNoErrors();
        $this->post($apply, ['token' => $token])->assertSessionHasErrors('file');
        $this->actingAs(User::factory()->create());
        $this->post($preview,['file' => $upload])->assertNotFound();
    }
}
