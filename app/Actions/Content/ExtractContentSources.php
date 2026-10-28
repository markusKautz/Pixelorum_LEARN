<?php

namespace App\Actions\Content;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ExtractContentSources
{
    /**
     * Extract text from local PDF/slides sources.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @return array<string, string>
     */
    public function handle(array $registry): array
    {
        $results = [];

        foreach ($registry as $sourceId => $source) {
            if ($source['kind'] === 'web') {
                continue;
            }

            $inputPath = $this->resolvePath($source['path']);
            $outputPath = $this->resolvePath($source['extract_path']);

            if (! File::exists($inputPath)) {
                throw new RuntimeException("Originaldatei nicht gefunden: {$inputPath}");
            }

            $pageCount = $this->runPdfinfo($inputPath);
            $rawText = $this->runPdftotext($inputPath);

            $pages = $this->splitPages($rawText);

            // Drop exactly one terminal segment when it is whitespace-only
            if ($pages !== [] && trim((string) end($pages)) === '') {
                array_pop($pages);
            }

            if (count($pages) !== $pageCount) {
                throw new RuntimeException(
                    "Seitenzahl stimmt nicht überein für {$sourceId}: pdfinfo meldet {$pageCount}, pdftotext liefert ".count($pages).' Seiten'
                );
            }

            $allBlank = true;
            foreach ($pages as $pageContent) {
                if (trim($pageContent) !== '') {
                    $allBlank = false;

                    break;
                }
            }

            if ($allBlank) {
                throw new RuntimeException(
                    "Quelle {$sourceId} enthält keinen verwendbaren Text (nur leere Seiten). Bitte OCR entscheiden."
                );
            }

            $output = $this->formatOutput($pages);

            $dir = dirname($outputPath);
            if (! File::exists($dir)) {
                File::makeDirectory($dir, recursive: true);
            }

            File::replace($outputPath, $output);

            $results[$sourceId] = $outputPath;
        }

        return $results;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function runPdfinfo(string $inputPath): int
    {
        $result = Process::timeout(30)->run(['pdfinfo', $inputPath]);

        if (! $result->successful()) {
            $error = trim($result->errorOutput());

            throw new RuntimeException("pdfinfo fehlgeschlagen für {$inputPath}: {$error}");
        }

        if (! preg_match('/Pages:\s*(\d+)/', $result->output(), $matches)) {
            throw new RuntimeException("pdfinfo lieferte keine Seitenzahl für {$inputPath}");
        }

        return (int) $matches[1];
    }

    private function runPdftotext(string $inputPath): string
    {
        $result = Process::timeout(120)->run(['pdftotext', '-layout', $inputPath, '-']);

        if (! $result->successful()) {
            $error = $result->errorOutput();

            throw new RuntimeException("pdftotext fehlgeschlagen: {$error}");
        }

        return $result->output();
    }

    /**
     * @return list<string>
     */
    private function splitPages(string $rawText): array
    {
        $normalized = str_replace("\r\n", "\n", $rawText);

        return explode("\f", $normalized);
    }

    /**
     * @param  list<string>  $pages
     */
    private function formatOutput(array $pages): string
    {
        $formattedPages = [];

        foreach ($pages as $index => $pageContent) {
            $pageNumber = $index + 1;
            $lines = explode("\n", $pageContent);
            $cleanedLines = [];

            foreach ($lines as $line) {
                $cleanedLines[] = preg_replace('/[ \t]+$/m', '', $line);
            }

            $cleanedContent = implode("\n", $cleanedLines);
            $formattedPages[] = "=== PAGE {$pageNumber} ===\n".$cleanedContent;
        }

        // Blank line between pages, one final newline
        $output = implode("\n\n", $formattedPages);

        return rtrim($output, "\n")."\n";
    }
}
