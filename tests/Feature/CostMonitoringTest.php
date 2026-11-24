<?php

namespace Tests\Feature;

use App\Support\CostReport;
use App\Support\ProgressReport;
use PHPUnit\Framework\TestCase;

class CostMonitoringTest extends TestCase
{
    public function test_combined_cost_lines_produce_reconciled_weights(): void
    {
        $line = ['item' => 'a', 'quantity' => '2', 'local' => '3', 'usd' => '0.01'];
        $data = CostReport::estimate(['rate' => '100', 'lines' => [$line, $line, array_replace($line, ['item' => 'b'])]], [['items' => [['id' => 'a'], ['id' => 'b']]]]);
        $this->assertSame('24.00', $data['bac']);
        $this->assertSame(['a' => 66.666667, 'b' => 33.333333], $data['weights']);
    }

    public function test_comparability_follows_item_identity_and_weights(): void
    {
        $group = ['id' => 'g', 'items' => [['id' => 'a', 'weight' => 60], ['id' => 'b', 'weight' => 40]], 'stages' => [['id' => 's', 'weight' => 100]]];
        $basis = fn (array $g): array => ProgressReport::basis(json_encode([$g], JSON_THROW_ON_ERROR));
        $before = $basis($group);
        $group['items'] = array_reverse($group['items']);
        $group['items'][0]['name'] = 'Nama baru';
        $this->assertEquals($before, $basis($group));
        $group['items'][0]['weight'] = 35;
        $this->assertNotEquals($before, $basis($group));
    }

    public function test_zero_and_missing_cost_preserve_independent_metrics(): void
    {
        $metrics = CostReport::metrics(100, 50, 40, 0);
        $this->assertNull($metrics['CPI']);
        $this->assertSame(40.0, $metrics['CV']);
        $this->assertSame(0.8, $metrics['SPI']);
        $this->assertNull(CostReport::metrics(100, 50, 40, null)['CV']);
    }
}
