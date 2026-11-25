<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type Weight int|float|numeric-string|null
 * @phpstan-type WorkRow array{id: string, name: ?string, weight?: Weight, weight_tons?: Weight, group_weight?: Weight}
 * @phpstan-type WorkGroup array{id: string, name: ?string, items: list<WorkRow>, stages: list<WorkRow>, type?: ?string, ship_weight_tons?: Weight, manual_project_weight?: Weight, project_weight?: Weight}
 */
class WorkBreakdown
{
    private const UNITS_PER_PERCENT = 1_000_000;

    public const FULL_WEIGHT = 100 * self::UNITS_PER_PERCENT;

    /** @param WorkGroup $group */
    public static function isConstruction(array $group): bool
    {
        return ($group['type'] ?? null) === 'construction';
    }

    public static function percent(int $units): float
    {
        return $units / self::UNITS_PER_PERCENT;
    }

    /** @param Weight $weight */
    private static function units(int|float|string|null $weight): int
    {
        return (int) round(($weight ?? 0) * self::UNITS_PER_PERCENT);
    }

    /** @param list<WorkGroup> $groups */
    public static function validateBaseline(array $groups, bool $useManualWeights): void
    {
        $total = 0;
        $hasConstruction = false;
        foreach ($groups as $group) {
            $name = $group['name'];
            if (array_sum(array_map(self::units(...), array_column($group['stages'], 'weight'))) !== self::FULL_WEIGHT) {
                throw ValidationException::withMessages(['groups' => 'Total bobot tahap '.$name.' harus 100%.']);
            }

            if (self::isConstruction($group)) {
                $hasConstruction = true;
                if ($useManualWeights) {
                    $weight = self::units($group['manual_project_weight'] ?? null);
                    if ($weight <= 0) {
                        throw ValidationException::withMessages(['groups' => 'Isi bobot proyek kelompok '.$name.' lebih dari 0% untuk menetapkan baseline tanpa EE.']);
                    }
                    $total += $weight;
                }

                continue;
            }

            if ($useManualWeights) {
                foreach ($group['items'] as $item) {
                    if (! isset($item['weight'])) {
                        throw ValidationException::withMessages(['groups' => 'Isi bobot setiap item untuk menetapkan baseline tanpa EE.']);
                    }
                    $total += self::units($item['weight']);
                }
            }
        }
        if ($useManualWeights && $total !== self::FULL_WEIGHT) {
            throw ValidationException::withMessages([
                'groups' => $hasConstruction
                    ? 'Total bobot item dan kelompok Konstruksi seluruh proyek harus 100%.'
                    : 'Total bobot item seluruh proyek harus 100%.',
            ]);
        }
    }

    /**
     * @param  list<WorkGroup>  $groups
     * @return list<WorkGroup>
     */
    public static function weightConstructionBlocks(array $groups, bool $useManualWeights): array
    {
        foreach ($groups as &$group) {
            if (! self::isConstruction($group)) {
                continue;
            }

            $name = $group['name'];
            $shipWeight = BigDecimal::of((string) ($group['ship_weight_tons'] ?? 0));
            if (! $shipWeight->isPositive()) {
                throw ValidationException::withMessages(['groups' => 'Isi estimasi berat kapal lebih dari nol untuk '.$name.'.']);
            }
            $tons = [];
            $blockTotal = BigDecimal::zero();
            foreach ($group['items'] as $block) {
                $blockWeight = BigDecimal::of((string) ($block['weight_tons'] ?? 0));
                if (! $blockWeight->isPositive()) {
                    throw ValidationException::withMessages(['groups' => 'Isi estimasi berat lebih dari nol untuk blok '.$block['name'].'.']);
                }
                $tons[] = $blockWeight;
                $blockTotal = $blockTotal->plus($blockWeight);
            }
            if (! $blockTotal->isEqualTo($shipWeight)) {
                throw ValidationException::withMessages(['groups' => 'Total berat blok '.$name.' harus sama dengan estimasi berat kapal.']);
            }

            $projectUnits = self::units($group[$useManualWeights ? 'manual_project_weight' : 'project_weight'] ?? null);
            $group['project_weight'] = self::percent($projectUnits);
            $groupUnits = self::allocate($tons, $shipWeight, self::FULL_WEIGHT);
            $blockProjectUnits = self::allocate($tons, $shipWeight, $projectUnits);
            foreach ($group['items'] as $index => &$block) {
                $block['group_weight'] = self::percent($groupUnits[$index]);
                $block['weight'] = self::percent($blockProjectUnits[$index]);
            }
            unset($block);
        }
        unset($group);

        return $groups;
    }

    /**
     * Distribute the units left by rounding down to the largest remainders.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, BigDecimal>  $values
     * @return array<TKey, int>
     */
    public static function allocate(array $values, BigDecimal $total, int $availableUnits): array
    {
        $units = [];
        $remainders = [];
        foreach ($values as $key => $value) {
            $scaled = $value->multipliedBy($availableUnits);
            $units[$key] = $scaled->dividedBy($total, 0, RoundingMode::Down)->toInt();
            $remainders[$key] = $scaled->minus($total->multipliedBy($units[$key]));
        }
        uasort($remainders, fn (BigDecimal $left, BigDecimal $right): int => $right->compareTo($left));
        $remaining = $availableUnits - array_sum($units);
        foreach (array_slice(array_keys($remainders), 0, $remaining) as $key) {
            $units[$key]++;
        }

        return $units;
    }
}
