<?php

namespace App\Support;

use Illuminate\Support\Collection;

class ProgressReport
{
    /** @return array<string,array<string,array<string,int>>> */
    public static function basis(string $structure): array
    {
        $basis = [];
        foreach (json_decode($structure, true, flags: JSON_THROW_ON_ERROR) as $group) {
            foreach ($group['items'] as $item) {
                $weights = ['item' => (int) round($item['weight'] * 1000000)];
                foreach ($item['stages'] ?? $group['stages'] as $stage) {
                    $weights[$stage['id']] = (int) round($stage['weight'] * 1000000);
                }
                $basis[$group['id']][$item['id']] = $weights;
            }
        }

        return $basis;
    }

    /**
     * @param  array<int,array<string,mixed>>  $groups
     * @param  Collection<int,\stdClass>  $measurements
     * @return array{groups:array<int,array<string,mixed>>,actual:float|int|null,measured:int,expected:int}
     */
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
                foreach ($item['stages'] ?? $group['stages'] as $stage) {
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
