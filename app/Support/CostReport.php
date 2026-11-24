<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class CostReport
{
    public static function estimate(array $data, array $groups): array
    {
        $amounts = [];
        $local = BigDecimal::zero();
        $usd = BigDecimal::zero();
        foreach ($data['lines'] as $index => &$line) {
            $quantity = BigDecimal::of((string) $line['quantity']);
            $localLine = $quantity->multipliedBy((string) $line['local']);
            $usdLine = $quantity->multipliedBy((string) $line['usd']);
            $local = $local->plus($localLine);
            $usd = $usd->plus($usdLine);
            $amount = $localLine->plus($usdLine->multipliedBy((string) $data['rate']));
            $line['amount'] = (string) $amount;
            $item = $line['item'];
            if (! collect($groups)->flatMap(fn ($group) => $group['items'])->contains('id', $item)) {
                throw ValidationException::withMessages(["lines.$index.item" => 'Pilih item dalam draft WBS proyek.']);
            }
            $amounts[$item] = ($amounts[$item] ?? BigDecimal::zero())->plus($amount);
        }
        unset($line);
        $total = $local->plus($usd->multipliedBy((string) $data['rate']));
        if ($total->isZero()) {
            throw ValidationException::withMessages(['lines' => 'Total estimasi harus lebih dari nol.']);
        }
        $weights = $remainders = [];
        foreach ($amounts as $id => $amount) {
            [$units, $remainder] = $amount->multipliedBy(100000000)->quotientAndRemainder($total);
            $weights[$id] = $units->toInt();
            $remainders[$id] = $remainder;
        }
        uasort($remainders, fn ($a, $b) => $b->compareTo($a));
        foreach (array_slice(array_keys($remainders), 0, 100000000 - array_sum($weights)) as $id) {
            $weights[$id]++;
        }

        return $data + ['bac' => (string) $total->toScale(2, RoundingMode::HalfUp), 'local_total' => (string) $local, 'usd_total' => (string) $usd, 'weights' => array_map(fn ($units) => $units / 1000000, $weights)];
    }

    public static function metrics(?float $bac, ?float $target, ?float $actual, ?float $ac): array
    {
        $pv = $bac !== null && $target !== null ? $bac * $target / 100 : null;
        $ev = $bac !== null && $actual !== null ? $bac * $actual / 100 : null;
        $spi = $pv && $ev !== null ? $ev / $pv : null;
        $cpi = $ac && $ev !== null ? $ev / $ac : null;
        $eac = $cpi ? $bac / $cpi : null;

        return ['BAC' => $bac, 'PV' => $pv, 'EV' => $ev, 'AC' => $ac, 'SPI' => $spi, 'CPI' => $cpi, 'SV' => $ev !== null && $pv !== null ? $ev - $pv : null, 'CV' => $ev !== null && $ac !== null ? $ev - $ac : null, 'EAC' => $eac, 'ETC' => $eac !== null ? $eac - $ac : null, 'VAC' => $eac !== null ? $bac - $eac : null];
    }
}
