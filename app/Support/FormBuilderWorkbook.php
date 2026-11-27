<?php

namespace App\Support;

use DOMElement;
use Generator;
use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/**
 * @phpstan-type Cell array{type: string, value: string, formula: bool}
 */
class FormBuilderWorkbook
{
    private const SHEET = 'Item Pekerjaan';

    private const BLOCK_SHEET = 'Blok Konstruksi';

    private const SPREADSHEET = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const DOCUMENT_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const METADATA_LIMIT = 256 * 1024;

    private const SHEET_LIMIT = 32 * 1024 * 1024;

    private const STRINGS_LIMIT = 8 * 1024 * 1024;

    /**
     * `blockCells` is null when the workbook has no block sheet, as in older templates.
     *
     * @return array{cells: array<string, Cell>, blockCells: array<string, Cell>|null, uses1904Dates: bool}
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalidWorkbook();
        }

        try {
            $this->assertXlsx($zip);
            ['sheets' => $sheets, 'uses1904Dates' => $uses1904Dates] = $this->sheetPaths($zip);
            $strings = $this->sharedStrings($zip);
            $cells = $this->cells($zip, $sheets[self::SHEET], $strings, '/\A(?:[CEG]6|[B-J][1-9]\d{0,6})\z/', ['C6', 'E6', 'G6']);
            $blockCells = isset($sheets[self::BLOCK_SHEET])
                ? $this->cells($zip, $sheets[self::BLOCK_SHEET], $strings, '/\A[B-D][1-9]\d{0,6}\z/')
                : null;

            return ['cells' => $cells, 'blockCells' => $blockCells, 'uses1904Dates' => $uses1904Dates];
        } finally {
            $zip->close();
        }
    }

    /**
     * Filled cells from the header row (8) down, plus the listed settings cells.
     *
     * @param  list<string>  $strings
     * @param  list<string>  $settings
     * @return array<string, Cell>
     */
    private function cells(ZipArchive $zip, string $sheet, array $strings, string $pattern, array $settings = []): array
    {
        $cells = [];
        $rows = [];
        foreach ($this->elements($zip, $sheet, self::SHEET_LIMIT, self::SPREADSHEET, 'worksheet', ['c' => 3], $pattern) as $element) {
            $address = $element->getAttribute('r');
            $row = (int) substr($address, 1);
            if ($row < 8 && ! in_array($address, $settings, true)) {
                continue;
            }

            $cell = $this->readCell($element, $strings);
            if ($cell['value'] === '' && ! $cell['formula']) {
                continue;
            }
            if (isset($cells[$address])) {
                $this->invalidWorkbook();
            }
            if ($row >= 9 && ! isset($rows[$row])) {
                $rows[$row] = true;
                if (count($rows) > 5000) {
                    $this->invalidWorkbook('Template memuat lebih dari 5.000 baris data.');
                }
            }
            $cells[$address] = $cell;
        }

        return $cells;
    }

    private function assertXlsx(ZipArchive $zip): void
    {
        $isXlsx = false;
        foreach ($this->elements($zip, '[Content_Types].xml', self::METADATA_LIMIT, self::CONTENT_TYPES, 'Types', ['Override' => 1]) as $element) {
            if ($element->getAttribute('PartName') === '/xl/workbook.xml') {
                $isXlsx = $element->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
            }
        }
        if (! $isXlsx) {
            $this->invalidWorkbook();
        }
    }

    /** @return array{sheets: array<string, string>, uses1904Dates: bool} */
    private function sheetPaths(ZipArchive $zip): array
    {
        $ids = [];
        $uses1904Dates = false;
        foreach ($this->elements($zip, 'xl/workbook.xml', self::METADATA_LIMIT, self::SPREADSHEET, 'workbook', ['workbookPr' => 1, 'sheet' => 2]) as $element) {
            if ($element->localName === 'workbookPr') {
                $uses1904Dates = in_array($element->getAttribute('date1904'), ['1', 'true'], true);
            } elseif (in_array($element->getAttribute('name'), [self::SHEET, self::BLOCK_SHEET], true)) {
                $ids[$element->getAttribute('name')] = $element->getAttributeNS(self::DOCUMENT_RELATIONSHIPS, 'id');
            }
        }
        if (empty($ids[self::SHEET])) {
            $this->invalidWorkbook('Lembar Item Pekerjaan tidak ditemukan.');
        }

        $targets = [];
        foreach ($this->elements($zip, 'xl/_rels/workbook.xml.rels', self::METADATA_LIMIT, self::RELATIONSHIPS, 'Relationships', ['Relationship' => 1]) as $element) {
            if ($element->getAttribute('Type') === self::DOCUMENT_RELATIONSHIPS.'/worksheet'
                && in_array($element->getAttribute('TargetMode'), ['', 'Internal'], true)
                && preg_match('~\A(?:/xl/)?(worksheets/[A-Za-z0-9_.-]+\.xml)\z~', $element->getAttribute('Target'), $matches)) {
                $targets[$element->getAttribute('Id')] = 'xl/'.$matches[1];
            }
        }

        $sheets = [];
        foreach ($ids as $name => $id) {
            $sheets[$name] = $targets[$id] ?? $this->invalidWorkbook();
        }

        return ['sheets' => $sheets, 'uses1904Dates' => $uses1904Dates];
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];
        foreach ($this->elements($zip, 'xl/sharedStrings.xml', self::STRINGS_LIMIT, self::SPREADSHEET, 'sst', ['si' => 1]) as $element) {
            $strings[] = $this->text($element);
            if (count($strings) > 50_000) {
                $this->invalidWorkbook();
            }
        }

        return $strings;
    }

    /** @param list<string> $strings
     * @return Cell
     */
    private function readCell(DOMElement $element, array $strings): array
    {
        $type = $element->getAttribute('t');
        $formula = $element->getElementsByTagNameNS(self::SPREADSHEET, 'f')->length > 0;
        $value = $element->getElementsByTagNameNS(self::SPREADSHEET, 'v')->item(0)->textContent ?? '';
        if ($type === 's' && $value !== '') {
            if (! ctype_digit($value) || ! isset($strings[(int) $value])) {
                $this->invalidWorkbook();
            }
            $value = $strings[(int) $value];
        } elseif ($type === 'inlineStr') {
            $value = $this->text($element->getElementsByTagNameNS(self::SPREADSHEET, 'is')->item(0));
        }

        return ['type' => $type, 'value' => $value, 'formula' => $formula];
    }

    private function text(?DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes ?? [] as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::SPREADSHEET) {
                if ($child->localName === 't') {
                    $text .= $child->textContent;
                } elseif ($child->localName === 'r') {
                    $text .= $child->getElementsByTagNameNS(self::SPREADSHEET, 't')->item(0)->textContent ?? '';
                }
            }
        }

        return $text;
    }

    /**
     * @param  array<string, int>  $depths
     * @return Generator<int, DOMElement>
     */
    private function elements(
        ZipArchive $zip,
        string $part,
        int $limit,
        string $namespace,
        string $root,
        array $depths,
        ?string $addressPattern = null,
    ): Generator {
        $info = $zip->statName($part);
        if ($info === false || $info['size'] > $limit || ($stream = $zip->getStream($part)) === false) {
            $this->invalidWorkbook();
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        $foundRoot = false;
        try {
            $reader = XMLReader::fromStream($stream, flags: LIBXML_NONET);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    $this->invalidWorkbook();
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->depth === 0) {
                    if ($reader->namespaceURI !== $namespace || $reader->localName !== $root) {
                        $this->invalidWorkbook();
                    }
                    $foundRoot = true;
                }
                if ($reader->namespaceURI === $namespace
                    && ($depths[$reader->localName] ?? -1) === $reader->depth
                    && ($addressPattern === null || preg_match($addressPattern, $reader->getAttribute('r') ?? ''))) {
                    $element = $reader->expand();
                    if (! $element instanceof DOMElement) {
                        $this->invalidWorkbook();
                    }
                    yield $element;
                }
            }
            if (! $foundRoot || libxml_get_last_error() !== false) {
                $this->invalidWorkbook();
            }
        } finally {
            $reader?->close();
            if (is_resource($stream)) {
                fclose($stream);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function invalidWorkbook(string $message = 'File Excel tidak dapat dibaca. Gunakan template Form Builder (.xlsx).'): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
