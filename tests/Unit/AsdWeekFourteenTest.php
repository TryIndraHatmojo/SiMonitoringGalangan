<?php

namespace Tests\Unit;

use App\Support\ProgressReport;
use PHPUnit\Framework\TestCase;

class AsdWeekFourteenTest extends TestCase
{
    public function test_simulated_asd_weights_reproduce_week_fourteen(): void
    {
        // Rows in each worksheet share the same simulated stage readings, so their project weights can be combined.
        $fixture = json_decode(file_get_contents(__DIR__.'/asd-week-14.json'), true, flags: JSON_THROW_ON_ERROR);
        $groups = [];
        $values = collect();
        foreach ($fixture as $row) {
            $stages = [];
            foreach ($row['stages'] as [$name,$weight,$value]) {
                $stages[] = ['id' => $name, 'weight' => $weight];
                $values->push((object) ['item' => $row['sheet'], 'stage' => $name, 'value' => $value]);
            }
            $groups[] = ['items' => [['id' => $row['sheet'], 'weight' => $row['project_weight']]], 'stages' => $stages];
        }
        $actual = ProgressReport::calculate($groups, $values)['actual'];
        $plan = 98.50; // EVM!B19
        $this->assertEquals(79.83, round($actual, 2));
        $this->assertEquals(-18.67, round($actual - $plan, 2));
    }
}
