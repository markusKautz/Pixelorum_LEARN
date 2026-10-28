<?php

namespace App\Console\Commands;

use App\Actions\Content\CollectContentSources;
use App\Actions\Content\ExportLearningPath;
use App\Actions\Content\LoadSourceRegistry;
use App\Actions\Content\ValidateContentFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('lapp:content:upgrade {file* : Content-Dateien einer älteren Schema-Version}')]
#[Description('Hebt Content-Dateien auf die aktuelle Schema-Version und ergänzt den sources-Block')]
class UpgradeContent extends Command
{
    /**
     * Execute the console command.
     *
     * Die Dateien sind teils von Hand formatiert. Damit der Diff prüfbar
     * bleibt, fügt der Befehl nur zwei Stellen in den vorhandenen Text ein:
     * `schema_version` als erstes Feld und `sources` hinter `status`, wie im
     * Export. Der Rest der Datei bleibt Byte für Byte erhalten. Geschrieben
     * wird nur, wenn die Datei danach samt Erdung gültig ist.
     *
     * Ab Version 2 ist `sources` schon da; Version 3 bringt nur den
     * optionalen Block `media` (DEV-14), es ändert sich also allein die Zahl.
     */
    public function handle(
        LoadSourceRegistry $loadRegistry,
        ValidateContentFile $validate,
        CollectContentSources $collectSources,
        ExportLearningPath $export,
    ): int {
        try {
            $registry = $loadRegistry->handle();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        /** @var list<string> $files */
        $files = (array) $this->argument('file');

        foreach ($files as $file) {
            try {
                $content = $validate->handle($file, $registry);

                if ($validate->schemaVersionOf($content) >= ValidateContentFile::SCHEMA_VERSION_CURRENT) {
                    $this->components->info("Bereits aktuell: {$file}");

                    continue;
                }

                if ($validate->schemaVersionOf($content) >= 2) {
                    $json = $this->replaceVersionInText(File::get($file));
                } else {
                    $sources = $collectSources->fromRegistry($registry, $collectSources->usedSourceIds($content));
                    $json = $this->insertIntoText(File::get($file), $sources, $export);
                }

                $validate->handleContents($json, $file, $registry);
                File::put($file, $json);

                $this->components->info("Aktualisiert: {$file}");
            } catch (RuntimeException $exception) {
                $this->components->error($exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Insert `schema_version` and `sources` into the file text.
     *
     * @param  list<array<string, mixed>>  $sources
     *
     * @throws RuntimeException wenn die Datei nicht die erwartete Form hat
     */
    protected function insertIntoText(string $text, array $sources, ExportLearningPath $export): string
    {
        if (preg_match('/\A\{\n( +)"/', $text, $match) !== 1) {
            throw new RuntimeException('Unerwartetes Format: Die Datei beginnt nicht mit einem eingerückten Root-Feld.');
        }

        $indent = $match[1];
        $statusLine = '/^'.preg_quote($indent, '/').'"status": "draft",\n/m';

        if (preg_match($statusLine, $text) !== 1) {
            throw new RuntimeException('Unerwartetes Format: Das Root-Feld "status" fehlt oder ist nicht das letzte Feld vor einem weiteren Eintrag.');
        }

        // Der Block kommt aus derselben Kodierung wie der Export und wird auf
        // die Einrückung der Datei gebracht.
        $block = $this->reindent(substr($export->encode(['sources' => $sources]), 2, -3), strlen($indent));

        $text = "{\n{$indent}\"schema_version\": ".ValidateContentFile::SCHEMA_VERSION_CURRENT.",\n".substr($text, 2);

        return (string) preg_replace_callback(
            $statusLine,
            static fn (array $line): string => $line[0].$block.",\n",
            $text,
            1,
        );
    }

    /**
     * Raise the `schema_version` of a file that already has one.
     *
     * @throws RuntimeException wenn die Datei nicht die erwartete Form hat
     */
    protected function replaceVersionInText(string $text): string
    {
        $replaced = preg_replace(
            '/\A(\{\n +"schema_version": )\d+,/',
            '${1}'.ValidateContentFile::SCHEMA_VERSION_CURRENT.',',
            $text,
            1,
            $count,
        );

        if ($count !== 1 || ! is_string($replaced)) {
            throw new RuntimeException('Unerwartetes Format: "schema_version" ist nicht das erste Root-Feld.');
        }

        return $replaced;
    }

    /**
     * Scale the two-space indentation of the export to the file's unit.
     */
    protected function reindent(string $block, int $unit): string
    {
        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2) * $unit),
            $block,
        );
    }
}
