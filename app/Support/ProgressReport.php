<?php

namespace App\Support;

use Illuminate\Support\Collection;
use stdClass;

/** @phpstan-import-type WorkGroup from WorkBreakdown */
class ProgressReport
{
    /**
     * @param  list<WorkGroup>  $groups
     * @param  Collection<int, stdClass>  $measurements
     * @return array{groups: list<array<string, mixed>>, actual: int|float|null, measured: int, expected: int}
     */
    public static function calculate(array $groups, Collection $measurements): array
    {
        $values = $measurements->groupBy('item')->map(fn ($rows) => $rows->pluck('value', 'stage'));
        $actual = 0;
        $measured = 0;
        $expected = 0;
        foreach ($groups as &$group) {
            $construction = WorkBreakdown::isConstruction($group);
            $group['weight'] = $construction
                ? (float) ($group['project_weight'] ?? 0)
                : array_sum(array_column($group['items'], 'weight'));
            $group['contribution'] = null;
            $group['progress'] = null;
            foreach ($group['items'] as &$item) {
                $projectWeight = $item['weight'] ?? 0;
                if (! $construction) {
                    $item['group_weight'] = $group['weight'] > 0
                        ? round(100 * $projectWeight / $group['weight'], 6)
                        : null;
                }
                $item['values'] = [];
                $item['progress'] = null;
                foreach ($group['stages'] as $stage) {
                    $expected++;
                    $value = $values->get($item['id'])?->get($stage['id']);
                    $item['values'][$stage['id']] = $value;
                    if ($value !== null) {
                        $measured++;
                        $item['progress'] += ($stage['weight'] ?? 0) * $value / 100;
                    }
                }
                $item['contribution'] = null;
                if ($item['progress'] !== null) {
                    $item['contribution'] = $projectWeight * $item['progress'] / 100;
                    $group['contribution'] += $item['contribution'];
                    if ($construction) {
                        $group['progress'] += ($item['group_weight'] ?? 0) * $item['progress'] / 100;
                    }
                    $actual += $item['contribution'];
                }
            }
            unset($item);
            if (! $construction && $group['contribution'] !== null && $group['weight'] > 0) {
                $group['progress'] = 100 * $group['contribution'] / $group['weight'];
            }
        }

        return ['groups' => $groups, 'actual' => $measured ? $actual : null, 'measured' => $measured, 'expected' => $expected];
    }
}
