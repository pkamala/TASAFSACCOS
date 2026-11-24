<?php

namespace App;

use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use SplFileObject;
use SplTempFileObject;
use ZipArchive;

class ImportTable
{
    public static function open(UploadedFile $upload, string $field): SplFileObject
    {
        $file = $upload->openFile();
        $signature = $file->fread(4);
        $file->rewind();
        if ($signature !== "PK\x03\x04") {
            return $file;
        }

        $zip = new ZipArchive;
        if ($zip->open($upload->getPathname(), ZipArchive::RDONLY) !== true) {
            self::invalid($field, 'The Excel workbook could not be opened.');
        }

        try {
            $workbook = self::xml($zip, 'xl/workbook.xml', $field);
            if (count($workbook->sheets->sheet) !== 1) {
                self::invalid($field, 'Use one worksheet per import file.');
            }
            $sheet = self::xml($zip, 'xl/worksheets/sheet1.xml', $field);
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach (self::xml($zip, 'xl/sharedStrings.xml', $field)->si as $item) {
                    $strings[] = self::cellText($item);
                }
            }
            $dates1904 = in_array((string) ($workbook->workbookPr['date1904'] ?? '0'), ['1', 'true'], true);
            $file = new SplTempFileObject;
            $header = [];

            foreach ($sheet->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    if (isset($cell->f) || (string) $cell['t'] === 'e') {
                        self::invalid($field, 'Replace formulas and error cells with reviewed values before importing.');
                    }
                    if (! preg_match('/\A([A-Z]{1,2})[1-9][0-9]*\z/', (string) $cell['r'], $coordinates)) {
                        self::invalid($field, 'The workbook contains an invalid cell reference.');
                    }
                    $column = 0;
                    foreach (str_split($coordinates[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 30 || count($values) >= 30) {
                        self::invalid($field, 'Use only the columns from the import template.');
                    }
                    $index = $column - 1;
                    $value = match ((string) $cell['t']) {
                        's' => $strings[(int) $cell->v] ?? '',
                        'inlineStr' => self::cellText($cell->is),
                        default => (string) $cell->v,
                    };
                    if (in_array($header[$index] ?? null, ['as_of', 'joining_date', 'due_date'], true) && preg_match('/\A[0-9]{1,6}\z/', $value)) {
                        $epoch = new DateTimeImmutable($dates1904 ? '1904-01-01' : '1899-12-30');
                        $value = $epoch->modify('+'.(int) $value.' days')->format('Y-m-d');
                    }
                    $values[$index] = $value;
                }
                if ($values === [] || count(array_filter($values, fn (string $value): bool => $value !== '')) === 0) {
                    continue;
                }
                $record = array_replace(array_fill(0, max(count($header), max(array_keys($values)) + 1), ''), $values);
                if ($header === []) {
                    $header = array_map('trim', $record);
                }
                $file->fputcsv($record, escape: '');
            }

            $file->rewind();

            return $file;
        } finally {
            $zip->close();
        }
    }

    private static function cellText(SimpleXMLElement $cell): string
    {
        $text = (string) $cell->t;
        foreach ($cell->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private static function xml(ZipArchive $zip, string $path, string $field): SimpleXMLElement
    {
        $size = $zip->statName($path)['size'] ?? null;
        if ($size === null || $size > 8 * 1024 * 1024) {
            self::invalid($field, 'The workbook worksheet is missing or too large.');
        }
        $content = $zip->getFromName($path);
        $xml = $content !== false && preg_match('//u', $content) === 1 && ! str_contains($content, "\0") && stripos($content, '<!DOCTYPE') === false && stripos($content, '<!ENTITY') === false
            ? @simplexml_load_string($content, options: LIBXML_NONET) : false;
        if ($xml === false) {
            self::invalid($field, 'The workbook contains invalid or unsupported XML.');
        }

        return $xml;
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
