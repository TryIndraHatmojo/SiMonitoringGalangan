<?php

namespace App\Support;

use Illuminate\Support\Collection;

class ProgressReport
{
    public static function calculate(array $groups, Collection $measurements): array
    {
        $values = $measurements->groupBy('item')->map(fn ($rows) => $rows->pluck('value', 'stage'));
        $actual = 0;
        $measured = 0;
        $expected = 0;
        foreach ($groups as &$group) {
            $group['weight'] = array_sum(array_column($group['items'], 'weight'));
            $group['contribution'] = null;
            foreach ($group['items'] as &$item) {
                $item['values'] = [];
                $item['progress'] = null;
                foreach ($group['stages'] as $stage) {
                    $expected++;
                    $value = $values->get($item['id'])?->get($stage['id']);
                    $item['values'][$stage['id']] = $value;
                    if ($value !== null) {
                        $measured++;
                        $item['progress'] += $stage['weight'] * $value / 100;
                    }
                }
                $item['contribution'] = null;
                if ($item['progress'] !== null) {
                    $item['contribution'] = $item['weight'] * $item['progress'] / 100;
                    $group['contribution'] += $item['contribution'];
                    $actual += $item['contribution'];
                }
            }
            unset($item);
        }

        return ['groups' => $groups, 'actual' => $measured ? $actual : null, 'measured' => $measured, 'expected' => $expected];
    }
}
