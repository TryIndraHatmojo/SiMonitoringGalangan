<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class MonitoringWorkbook
{
    public const SHEETS = [
        'Kurs' => ['rate', 'date', 'source', 'assumption'],
        'Grup' => ['code', 'name'],
        'Item' => ['group', 'code', 'name', 'weight'],
        'Tahap' => ['group', 'code', 'name', 'weight'],
        'EE' => ['code', 'name', 'unit', 'quantity', 'local', 'usd', 'item', 'source_group'],
        'Jadwal' => ['start', 'end', 'target'],
        'Histori' => ['end', 'item', 'stage', 'value', 'reported_actual', 'simulated'],
    ];

    public static function template(string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $sheets = $links = $types = '';
        foreach (array_keys(self::SHEETS) as $index => $name) {
            $id = $index + 1;
            $sheets .= "<sheet name=\"$name\" sheetId=\"$id\" r:id=\"rId$id\"/>";
            $links .= "<Relationship Id=\"rId$id\" Type=\"$rel/worksheet\" Target=\"worksheets/sheet$id.xml\"/>";
            $types .= "<Override PartName=\"/xl/worksheets/sheet$id.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $cells = '';
            foreach (self::SHEETS[$name] as $i => $key) {
                $column = chr(65 + $i);
                $cells .= "<c r=\"{$column}1\" t=\"inlineStr\"><is><t>$key</t></is></c>";
            }
            $zip->addFromString("xl/worksheets/sheet$id.xml", "<worksheet xmlns=\"$main\"><cols><col min=\"1\" max=\"8\" width=\"22\" customWidth=\"1\"/></cols><sheetData><row r=\"1\">$cells</row></sheetData></worksheet>");
        }
        $zip->addFromString('xl/workbook.xml', "<workbook xmlns=\"$main\" xmlns:r=\"$rel\"><sheets>$sheets</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', "<Relationships xmlns=\"$package\">$links</Relationships>");
        $zip->addFromString('_rels/.rels', "<Relationships xmlns=\"$package\"><Relationship Id=\"rId1\" Type=\"$rel/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>");
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
        $zip->close();
    }

    /** @param array<string,array<int,array<string,string>>> $sheets */
    private static function validateRows(array $sheets): void
    {
        $text = 'required|string|max:255';
        $number = 'required|numeric|min:0|decimal:0,6';
        $percent = 'required|numeric|between:0,100|decimal:0,6';
        $date = 'required|date_format:Y-m-d';
        $rules = [
            'Kurs' => ['rate' => 'required|numeric|gt:0|decimal:0,6', 'date' => $date, 'source' => $text, 'assumption' => 'required|in:0,1'],
            'Grup' => ['code' => $text, 'name' => $text],
            'Item' => ['group' => $text, 'code' => $text, 'name' => $text, 'weight' => $sheets['EE'] ? 'nullable|numeric|between:0,100|decimal:0,6' : $percent],
            'Tahap' => ['group' => $text, 'code' => $text, 'name' => $text, 'weight' => $percent],
            'EE' => ['code' => 'required|string|max:100', 'name' => $text, 'unit' => 'required|string|max:40', 'quantity' => 'required|numeric|gt:0|decimal:0,6', 'local' => $number, 'usd' => $number, 'item' => $text, 'source_group' => 'nullable|string|max:255'],
            'Jadwal' => ['start' => $date, 'end' => $date.'|after_or_equal:start', 'target' => 'required|numeric|between:0,100|decimal:0,4'],
            'Histori' => ['end' => $date, 'simulated' => 'required|in:0,1', 'reported_actual' => 'nullable|numeric|between:0,100|decimal:0,4', 'value' => 'required_without:reported_actual|nullable|numeric|between:0,100|decimal:0,4'],
        ];
        $errors = [];
        foreach ($sheets as $name => $rows) {
            $seen = [];
            foreach ($rows as $n => $row) {
                $messages = Validator::make($row, $rules[$name])->errors()->toArray();
                if (isset($row['code'])) {
                    if (isset($seen[$row['code']])) {
                        $messages['code'] = ['Kode sumber sudah dipakai pada baris '.$seen[$row['code']].'.'];
                    }
                    $seen[$row['code']] = $n;
                }
                foreach ($messages as $key => $messagesForField) {
                    $cell = $name.'!'.chr(65 + array_search($key, self::SHEETS[$name], true)).$n;
                    $errors[$cell] = $messagesForField;
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string,array<int,array<string,string>>> */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Gunakan file XLSX dari template resmi.']);
        }
        try {
            $load = function (string $entry) use ($zip): SimpleXMLElement {
                $stat = $zip->statName($entry);
                if (! $stat || $stat['size'] > 8000000) {
                    throw ValidationException::withMessages(['file' => 'Bagian workbook hilang atau terlalu besar.']);
                }
                $xml = $zip->getFromName($entry);
                if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || ! ($root = @simplexml_load_string($xml, options: LIBXML_NONET))) {
                    throw ValidationException::withMessages(['file' => 'Struktur XML workbook tidak valid.']);
                }

                return $root;
            };
            $paths = [];
            foreach ($load('xl/_rels/workbook.xml.rels')->Relationship as $link) {
                $target = (string) $link['Target'];
                $paths[(string) $link['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($load('xl/sharedStrings.xml')->si as $string) {
                    $strings[] = implode('', array_map('strval', $string->xpath('.//*[local-name()="t"]')));
                }
            }
            $result = [];
            $book = $load('xl/workbook.xml');
            $epoch = in_array((string) ($book->workbookPr['date1904'] ?? ''), ['1', 'true'], true) ? '1904-01-01' : '1899-12-30';
            foreach ($book->sheets->sheet as $sheet) {
                $name = (string) $sheet['name'];
                if (! isset(self::SHEETS[$name])) {
                    continue;
                }
                $columns = self::SHEETS[$name];
                $result[$name] = [];
                $part = $paths[(string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']] ?? '';
                $header = false;
                foreach ($load($part)->sheetData->row as $row) {
                    $number = (int) $row['r'];
                    $values = array_fill_keys($columns, '');
                    foreach ($row->c as $cell) {
                        $ref = (string) $cell['r'];
                        if (! preg_match('/^([A-Z])\d+$/', $ref, $match) || ! isset($columns[ord($match[1]) - 65])) {
                            continue;
                        }
                        if (isset($cell->f)) {
                            throw ValidationException::withMessages(['file' => "$name!$ref — masukkan nilai langsung."]);
                        }
                        $value = match ((string) $cell['t']) {
                            'inlineStr' => implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]'))),
                            's' => $strings[(int) $cell->v] ?? '',
                            default => (string) $cell->v,
                        };
                        $values[$columns[ord($match[1]) - 65]] = trim($value);
                    }
                    if ($number === 1) {
                        $header = array_values($values) === $columns;
                    } elseif (array_filter($values, fn ($v) => $v !== '')) {
                        if (in_array($name, ['Kurs', 'Jadwal', 'Histori'], true)) {
                            foreach (['date', 'start', 'end'] as $key) {
                                if (isset($values[$key]) && ctype_digit($values[$key]) && (int) $values[$key] <= 2958465) {
                                    $values[$key] = (new \DateTimeImmutable($epoch))->modify('+'.$values[$key].' days')->format('Y-m-d');
                                }
                            }
                        }
                        $result[$name][$number] = $values;
                    }
                }
                if (! $header) {
                    throw ValidationException::withMessages(['file' => "$name!A1 — judul kolom harus sesuai template."]);
                }
            }
            if (array_diff_key(self::SHEETS, $result)) {
                throw ValidationException::withMessages(['file' => 'Lengkapi seluruh lembar template.']);
            }

            self::validateRows($result);

            return $result;
        } finally {
            $zip->close();
        }
    }
}
