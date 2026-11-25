<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * @phpstan-import-type WorkGroup from WorkBreakdown
 */
class EngineeringEstimate
{
    public static function find(int $project, int $revision): ?stdClass
    {
        return DB::table('engineering_estimates')->where([
            'project_id' => $project,
            'revision' => $revision,
        ])->first();
    }

    /** @return list<array<string, mixed>> */
    public static function lines(int $estimate): array
    {
        $rows = DB::table('engineering_estimate_lines')->where('engineering_estimate_id', $estimate)
            ->orderBy('position')->get();

        return array_values($rows->map(fn (stdClass $row): array => (array) $row)->all());
    }

    /** @return array<string, mixed> */
    public static function settings(stdClass $estimate): array
    {
        return [
            ...Arr::only((array) $estimate, ['exchange_rate_date', 'exchange_rate_source', 'source_reference']),
            'exchange_rate_idr_per_usd' => $estimate->exchange_rate_idr_per_usd === null ? null : (string) $estimate->exchange_rate_idr_per_usd,
            'exchange_rate_is_assumption' => (bool) $estimate->exchange_rate_is_assumption,
        ];
    }

    /**
     * The caller holds the project lock inside a transaction.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $lines
     */
    public static function store(int $project, int $revision, array $settings, array $lines, ?string $bac = null): void
    {
        $estimate = self::find($project, $revision);
        if ($estimate !== null && $revision !== 0) {
            throw new LogicException('A published engineering estimate cannot be replaced.');
        }

        $attributes = $settings + ['bac_idr' => $bac];
        if ($estimate === null) {
            $id = DB::table('engineering_estimates')->insertGetId($attributes + ['project_id' => $project, 'revision' => $revision]);
        } else {
            $id = $estimate->id;
            DB::table('engineering_estimates')->where('id', $id)->update($attributes);
            DB::table('engineering_estimate_lines')->where('engineering_estimate_id', $id)->delete();
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            $rows[] = Arr::only($line, [
                'description', 'quantity', 'unit', 'local_price_idr', 'local_price_is_lump_sum',
                'usd_unit_price', 'wbs_item_id', 'wbs_group_id', 'source_reference', 'source_work_group',
            ]) + ['wbs_group_id' => null, 'position' => $index + 1, 'engineering_estimate_id' => $id];
        }
        foreach (array_chunk($rows, 75) as $chunk) {
            DB::table('engineering_estimate_lines')->insert($chunk);
        }
    }

    /**
     * @param  list<array{items: list<array{id: string}>}>  $groups
     * @return array<string, true>
     */
    public static function itemIds(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $ids[strtolower($item['id'])] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  list<WorkGroup>  $groups
     * @return array<string, true>
     */
    public static function mappingTargets(array $groups): array
    {
        $targets = [];
        foreach ($groups as $group) {
            if (WorkBreakdown::isConstruction($group)) {
                $targets['group:'.strtolower($group['id'])] = true;

                continue;
            }
            foreach ($group['items'] as $item) {
                $targets['item:'.strtolower($item['id'])] = true;
            }
        }

        return $targets;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, local_idr: ?string, import_usd: ?string, equivalent_idr: ?string}
     */
    public static function preview(array $lines, ?string $rate): array
    {
        $totals = ['local_idr' => BigDecimal::zero(), 'import_usd' => BigDecimal::zero(), 'equivalent_idr' => BigDecimal::zero()];
        $rows = [];
        foreach ($lines as $line) {
            $amounts = self::lineAmounts($line, $rate);
            foreach ($amounts as $key => $amount) {
                $totals[$key] = $totals[$key] !== null && $amount !== null ? $totals[$key]->plus($amount) : null;
            }
            foreach (['quantity', 'local_price_idr', 'usd_unit_price'] as $key) {
                $line[$key] = $line[$key] === null ? null : (string) $line[$key];
            }
            $line['local_price_is_lump_sum'] = (bool) $line['local_price_is_lump_sum'];
            $rows[] = Arr::except($line, ['engineering_estimate_id', 'source_reference', 'source_work_group']) + array_map(self::displayAmount(...), $amounts);
        }

        return ['lines' => $rows] + array_map(self::displayAmount(...), $totals);
    }

    /**
     * @param  list<WorkGroup>  $groups
     * @param  list<array<string, mixed>>  $lines
     * @return array{groups: list<WorkGroup>, bac: string}
     */
    public static function baseline(array $groups, array $lines, ?string $rate): array
    {
        ['weights' => $weights, 'bac' => $bac] = self::costWeights($groups, $lines, $rate);
        foreach ($groups as &$group) {
            if (WorkBreakdown::isConstruction($group)) {
                $group['project_weight'] = $weights['group:'.strtolower($group['id'])];

                continue;
            }
            foreach ($group['items'] as &$item) {
                $item['weight'] = $weights['item:'.strtolower($item['id'])];
            }
            unset($item);
        }
        unset($group);

        return ['groups' => WorkBreakdown::weightConstructionBlocks($groups, useManualWeights: false), 'bac' => $bac];
    }

    /**
     * @param  list<WorkGroup>  $groups
     * @param  list<array<string, mixed>>  $lines
     * @return array{weights: array<string, float>, bac: string}
     */
    public static function costWeights(array $groups, array $lines, ?string $rate): array
    {
        $costs = array_fill_keys(array_keys(self::mappingTargets($groups)), BigDecimal::zero());
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $amounts = self::lineAmounts($line, $rate);
            if ($amounts['local_idr'] === null || $amounts['import_usd'] === null) {
                throw ValidationException::withMessages(['estimate' => 'Lengkapi volume dan satuan untuk harga satuan pada baris EE '.$line['position'].'.']);
            }
            $cost = $amounts['equivalent_idr'];
            if ($cost === null) {
                throw ValidationException::withMessages(['estimate' => 'Isi kurs positif untuk biaya impor sebelum menetapkan baseline.']);
            }
            if ($cost->isPositive()) {
                $item = strtolower($line['wbs_item_id'] ?? '');
                $group = strtolower($line['wbs_group_id'] ?? '');
                $target = $group === '' ? 'item:'.$item : 'group:'.$group;
                if ($group !== '' && ($item !== '' || ! isset($costs[$target]))) {
                    throw ValidationException::withMessages(['estimate' => 'Petakan baris EE '.$line['position'].' ke kelompok Konstruksi yang masih ada.']);
                }
                if (! isset($costs[$target])) {
                    $targetDescription = array_any($groups, WorkBreakdown::isConstruction(...))
                        ? 'item Standar atau langsung ke kelompok Konstruksi'
                        : 'item WBS yang masih ada';
                    throw ValidationException::withMessages(['estimate' => 'Petakan baris EE '.$line['position'].' ke '.$targetDescription.'.']);
                }
                $costs[$target] = $costs[$target]->plus($cost);
                $total = $total->plus($cost);
            }
        }

        $bac = $total->toScale(2, RoundingMode::HalfUp);
        if ($bac->isZero()) {
            throw ValidationException::withMessages(['estimate' => 'Total EE harus lebih besar dari nol untuk menghitung bobot WBS.']);
        }
        if ($bac->isGreaterThan('9999999999999999999999.99')) {
            throw ValidationException::withMessages(['estimate' => 'Total EE melebihi kapasitas penyimpanan BAC.']);
        }

        foreach ($groups as $group) {
            if (WorkBreakdown::isConstruction($group) && $costs['group:'.strtolower($group['id'])]->isZero()) {
                throw ValidationException::withMessages(['estimate' => 'Petakan biaya EE lebih dari nol langsung ke kelompok '.$group['name'].'.']);
            }
        }

        $units = WorkBreakdown::allocate($costs, $total, WorkBreakdown::FULL_WEIGHT);

        return ['weights' => array_map(WorkBreakdown::percent(...), $units), 'bac' => (string) $bac];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{local_idr: ?BigDecimal, import_usd: ?BigDecimal, equivalent_idr: ?BigDecimal}
     */
    private static function lineAmounts(array $line, ?string $rate): array
    {
        $quantity = $line['quantity'] === null ? null : BigDecimal::of((string) $line['quantity']);
        if ($quantity?->isPositive() !== true || blank($line['unit'])) {
            $quantity = null;
        }
        $local = self::subtotal($line['local_price_idr'], $line['local_price_is_lump_sum'] ? BigDecimal::one() : $quantity);
        $import = self::subtotal($line['usd_unit_price'], $quantity);
        $exchangeRate = $rate === null ? BigDecimal::zero() : BigDecimal::of($rate);
        $equivalent = null;
        if ($local !== null && $import !== null && ($import->isZero() || $exchangeRate->isPositive())) {
            $equivalent = $local->plus($import->multipliedBy($exchangeRate));
        }

        return ['local_idr' => $local, 'import_usd' => $import, 'equivalent_idr' => $equivalent];
    }

    private static function subtotal(int|float|string|null $price, ?BigDecimal $quantity): ?BigDecimal
    {
        if ($price === null) {
            return BigDecimal::zero();
        }

        return $quantity?->multipliedBy((string) $price);
    }

    public static function formatAmount(int|float|string $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    private static function displayAmount(?BigDecimal $value): ?string
    {
        return $value === null ? null : (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
