<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * @phpstan-import-type Cell from FormBuilderWorkbook
 * @phpstan-import-type WorkGroup from EngineeringEstimate
 */
class FormBuilderImport
{
    public const UPLOAD_RULES = ['required', 'file', 'max:5120', 'extensions:xlsx'];

    public const UPLOAD_MESSAGES = [
        'file.required' => 'Pilih file template Form Builder yang sudah diisi.',
        'file.file' => 'Unggah file Excel yang dapat dibaca.',
        'file.max' => 'Ukuran file Excel maksimal 5 MB.',
        'file.extensions' => 'Pilih file Excel dengan ekstensi .xlsx.',
    ];

    private const HEADERS = [
        'B8' => 'Kelompok pekerjaan',
        'C8' => 'Nama item pekerjaan',
        'D8' => 'Volume',
        'E8' => 'Satuan',
        'F8' => 'Harga lokal (Rp)',
        'G8' => 'Jenis harga lokal',
        'H8' => 'Harga satuan impor (USD)',
        'I8' => 'Uraian EE',
    ];

    private const BLANK_CELL = ['type' => '', 'value' => '', 'formula' => false];

    public function __construct(private FormBuilderWorkbook $workbook) {}

    /** @param  list<WorkGroup>  $groups */
    public static function draftIsEmpty(array $groups, ?stdClass $estimate, bool $hasLines): bool
    {
        if ($groups !== [] || $hasLines) {
            return false;
        }
        if ($estimate === null) {
            return true;
        }

        return $estimate->exchange_rate_idr_per_usd === null
            && $estimate->exchange_rate_date === null
            && blank($estimate->exchange_rate_source)
            && blank($estimate->source_reference)
            && (bool) $estimate->exchange_rate_is_assumption;
    }

    /**
     * @return array{groups: list<WorkGroup>, settings: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function prepare(UploadedFile $file): array
    {
        ['cells' => $cells, 'uses1904Dates' => $uses1904Dates] = $this->workbook->read($file->getPathname());
        foreach (self::HEADERS as $address => $label) {
            $header = $cells[$address] ?? self::BLANK_CELL;
            if ($header['formula'] || trim($header['value']) !== $label) {
                throw ValidationException::withMessages([
                    'file' => "Judul kolom di Item Pekerjaan!{$address} harus “{$label}”. Unduh template Form Builder terbaru.",
                ]);
            }
        }

        $errors = [];
        $rows = [];
        foreach ($cells as $address => $cell) {
            $row = (int) substr($address, 1);
            if ($row >= 9) {
                $rows[$row][$address[0]] = $cell;
            }
            if ($cell['formula']) {
                $errors[$address] = 'Isi nilai langsung di sel ini.';
            }
        }

        $rate = $this->number($cells['C6'] ?? self::BLANK_CELL, 10, 'C6', $errors);
        $date = $this->date($cells['E6'] ?? self::BLANK_CELL, $uses1904Dates, $errors);
        $assumption = $this->text($cells['G6'] ?? self::BLANK_CELL, 5, 'G6', $errors, required: true);
        if (! in_array($assumption, ['Ya', 'Tidak'], true)) {
            $errors['G6'] ??= 'Pilih Ya atau Tidak untuk status asumsi kurs.';
        }
        if ($rate?->isZero()) {
            $errors['C6'] ??= 'Isi kurs yang lebih besar dari nol.';
        }

        $groups = [];
        $lines = [];
        ksort($rows, SORT_NUMERIC);
        foreach ($rows as $row => $columns) {
            if (array_all($columns, fn (array $cell): bool => trim($cell['value']) === '' && ! $cell['formula'])) {
                continue;
            }

            $group = $this->text($columns['B'] ?? self::BLANK_CELL, 255, "B{$row}", $errors, required: true);
            $item = $this->text($columns['C'] ?? self::BLANK_CELL, 255, "C{$row}", $errors, required: true);
            $quantity = $this->number($columns['D'] ?? self::BLANK_CELL, 10, "D{$row}", $errors);
            $unit = $this->text($columns['E'] ?? self::BLANK_CELL, 50, "E{$row}", $errors);
            $local = $this->number($columns['F'] ?? self::BLANK_CELL, 14, "F{$row}", $errors);
            $priceType = $this->text($columns['G'] ?? self::BLANK_CELL, 20, "G{$row}", $errors);
            $usd = $this->number($columns['H'] ?? self::BLANK_CELL, 14, "H{$row}", $errors);
            $description = $this->text($columns['I'] ?? self::BLANK_CELL, 255, "I{$row}", $errors);

            if (! in_array($priceType, [null, 'Harga satuan', 'Total (lump sum)'], true)) {
                $errors["G{$row}"] ??= 'Pilih Harga satuan atau Total (lump sum).';
            }
            $lumpSum = $priceType === 'Total (lump sum)';
            if ($local === null && $usd === null && ! isset($errors["H{$row}"])) {
                $errors["F{$row}"] ??= 'Isi harga lokal, harga impor, atau keduanya.';
            }
            if ($usd !== null || ($local !== null && ! $lumpSum)) {
                if ($quantity?->isPositive() !== true) {
                    $errors["D{$row}"] ??= 'Isi volume lebih dari nol untuk harga satuan.';
                }
                if ($unit === null) {
                    $errors["E{$row}"] ??= 'Isi satuan untuk harga satuan.';
                }
            }
            if ($usd?->isPositive() && $rate?->isPositive() !== true) {
                $errors['C6'] ??= 'Isi kurs yang lebih besar dari nol untuk harga impor USD.';
            }
            if ($group === null || $item === null) {
                continue;
            }

            $groups[$group] ??= ['id' => (string) Str::uuid(), 'name' => $group, 'items' => [], 'stages' => []];
            $itemId = (string) Str::uuid();
            $groups[$group]['items'][] = ['id' => $itemId, 'name' => $item, 'weight' => null];
            $lines[] = [
                'position' => count($lines) + 1,
                'wbs_item_id' => $itemId,
                'description' => $description ?? $item,
                'quantity' => $quantity === null ? null : (string) $quantity,
                'unit' => $unit,
                'local_price_idr' => $local === null ? null : (string) $local,
                'local_price_is_lump_sum' => $lumpSum,
                'usd_unit_price' => $usd === null ? null : (string) $usd,
                'source_reference' => "Item Pekerjaan!B{$row}:I{$row}",
                'source_work_group' => $group,
            ];
        }

        if ($errors !== []) {
            $messages = [];
            if (count($errors) > 100) {
                $messages['file'] = 'Lebih dari 100 sel perlu diperiksa. Perbaiki sel yang ditampilkan, lalu tinjau kembali.';
            }
            foreach (array_slice($errors, 0, 100, preserve_keys: true) as $address => $message) {
                $messages["cells.{$address}"] = "Item Pekerjaan!{$address} — {$message}";
            }
            throw ValidationException::withMessages($messages);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'Isi minimal satu baris pada lembar Item Pekerjaan mulai dari baris 9.']);
        }

        $rate = $rate === null ? null : (string) $rate;
        $weighted = EngineeringEstimate::baseline(array_values($groups), $lines, $rate);
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', mb_scrub($file->getClientOriginalName()));

        return [
            'groups' => $weighted['groups'],
            'settings' => [
                'exchange_rate_idr_per_usd' => $rate,
                'exchange_rate_date' => $date,
                'exchange_rate_is_assumption' => $assumption === 'Ya',
                'exchange_rate_source' => $rate === null ? null : 'Item Pekerjaan!C6',
                'source_reference' => mb_substr($filename ?? '', 0, 255),
            ],
            'lines' => $lines,
        ];
    }

    public function token(UploadedFile $file, int $user, int $project): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => 'form-builder-import',
            'user' => $user,
            'project' => $project,
            'file' => hash_file('sha256', $file->getPathname()),
            'expires' => now()->addMinutes(15)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function validateToken(string $token, UploadedFile $file, int $user, int $project): void
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $data = null;
        }

        $fileHash = hash_file('sha256', $file->getPathname());
        if (($data['purpose'] ?? null) !== 'form-builder-import'
            || ($data['user'] ?? null) !== $user
            || ($data['project'] ?? null) !== $project
            || ! is_int($data['expires'] ?? null)
            || $data['expires'] <= now()->getTimestamp()
            || ! is_string($data['file'] ?? null)
            || $fileHash === false
            || ! hash_equals($data['file'], $fileHash)) {
            throw ValidationException::withMessages(['preview_token' => 'Pratinjau tidak berlaku. Tinjau kembali file sebelum menerapkan impor.']);
        }
    }

    /**
     * @param  Cell  $cell
     * @param  array<string, string>  $errors
     */
    private function text(array $cell, int $limit, string $address, array &$errors, bool $required = false): ?string
    {
        $value = trim($cell['value']);
        if ($value === '') {
            if ($required) {
                $errors[$address] ??= 'Sel ini wajib diisi.';
            }

            return null;
        }
        if (! in_array($cell['type'], ['s', 'inlineStr', 'str'], true) || mb_strlen($value) > $limit) {
            $errors[$address] ??= "Isi teks maksimal {$limit} karakter.";

            return null;
        }

        return $value;
    }

    /**
     * @param  Cell  $cell
     * @param  array<string, string>  $errors
     */
    private function number(array $cell, int $wholeDigits, string $address, array &$errors): ?BigDecimal
    {
        if (trim($cell['value']) === '') {
            return null;
        }

        $number = $this->readDecimal($cell);
        if ($number !== null && $number->getScale() > 6 && $number->getPrecision() > 15) {
            $excelScale = max(0, $number->getScale() - ($number->getPrecision() - 15));
            $number = $number->toScale($excelScale, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        }
        if ($number === null || $number->isNegative()
            || $number->getScale() > 6 || $number->isGreaterThanOrEqualTo('1'.str_repeat('0', $wholeDigits))) {
            $errors[$address] ??= "Isi angka nonnegatif dengan maksimal {$wholeDigits} digit bulat dan enam angka desimal.";

            return null;
        }

        return $number;
    }

    /** @param  Cell  $cell */
    private function readDecimal(array $cell): ?BigDecimal
    {
        $value = $cell['value'];
        if (! in_array($cell['type'], ['', 'n'], true)
            || strlen($value) > 64
            || ! preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee]([+-]?\d{1,2}))?\z/', $value, $parts)
            || abs((int) ($parts[1] ?? 0)) > 32) {
            return null;
        }

        return BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    /**
     * @param  Cell  $cell
     * @param  array<string, string>  $errors
     */
    private function date(array $cell, bool $uses1904Dates, array &$errors): ?string
    {
        $value = trim($cell['value']);
        if ($value === '') {
            return null;
        }

        if (in_array($cell['type'], ['d', 's', 'inlineStr', 'str'], true)
            && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        $serial = $this->readDecimal($cell);
        if ($serial !== null && $serial->getScale() === 0) {
            if ($uses1904Dates && $serial->isGreaterThanOrEqualTo(0) && $serial->isLessThanOrEqualTo(2_957_003)) {
                return (new DateTimeImmutable('1904-01-01'))->modify('+'.$serial->toInt().' days')->format('Y-m-d');
            }
            if (! $uses1904Dates && $serial->isGreaterThanOrEqualTo(1) && $serial->isLessThanOrEqualTo(2_958_465) && ! $serial->isEqualTo(60)) {
                $days = $serial->toInt() - (int) $serial->isGreaterThan(60);

                return (new DateTimeImmutable('1899-12-31'))->modify("+{$days} days")->format('Y-m-d');
            }
        }

        $errors['E6'] ??= 'Isi tanggal yang valid tanpa jam, atau kosongkan jika tanggal kurs belum diketahui.';

        return null;
    }
}
