<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser;

class KnowledgeDocumentExtractor
{
    public function __construct(private readonly KnowledgeSettingsService $settings) {}

    public function extract(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('The uploaded file is no longer available for processing.');
        }

        return $this->extractPath($path, $file->getClientOriginalExtension());
    }

    public function extractStoredFile(string $path, string $originalName): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('The private file is no longer available for processing.');
        }

        return $this->extractPath($path, pathinfo($originalName, PATHINFO_EXTENSION));
    }

    private function extractPath(string $path, string $fileExtension): string
    {
        $extension = strtolower($fileExtension);
        $text = match ($extension) {
            'txt', 'md' => $this->readTextFile($path),
            'csv' => $this->readCsv($path),
            'pdf' => $this->readPdf($path),
            'xlsx', 'xls' => $this->readSpreadsheet($path),
            'png', 'jpg', 'jpeg', 'webp' => $this->readImage($path),
            default => throw new RuntimeException('This file type is not supported.'),
        };
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('No readable text was found in the uploaded file.');
        }
        $maximumCharacters = $this->settings->current()['maximum_extracted_characters'];
        if (mb_strlen($text) > $maximumCharacters) {
            throw new RuntimeException("Extracted text exceeds the {$maximumCharacters} character safety limit.");
        }

        return $text;
    }

    private function readTextFile(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false || ! mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('The text file is not valid UTF-8.');
        }

        return $text;
    }

    private function readCsv(string $path): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $rows = [];
        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = implode(' | ', array_map(
                static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '',
                $row,
            ));
            if (count($rows) > 100_000) {
                throw new RuntimeException('The CSV exceeds the supported row limit.');
            }
        }

        return implode("\n", $rows);
    }

    private function readPdf(string $path): string
    {
        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (\Exception $exception) {
            throw new RuntimeException('The PDF could not be extracted: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function readSpreadsheet(string $path): string
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheets = [];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheets[] = 'Sheet: '.$worksheet->getTitle();
            foreach ($worksheet->toArray(null, true, true, false) as $row) {
                $values = array_map(
                    static fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '',
                    $row,
                );
                if (implode('', $values) !== '') {
                    $sheets[] = implode(' | ', $values);
                }
                if (count($sheets) > 100_000) {
                    throw new RuntimeException('The spreadsheet exceeds the supported row limit.');
                }
            }
        }
        $spreadsheet->disconnectWorksheets();

        return implode("\n", $sheets);
    }

    private function readImage(string $path): string
    {
        $binary = (string) config('knowledge.ocr_binary', 'tesseract');
        $result = Process::timeout(30)->run([$binary, $path, 'stdout']);
        if (! $result->successful()) {
            throw new RuntimeException('Image text extraction failed. Install and configure Tesseract OCR, then retry.');
        }

        return $result->output();
    }
}
