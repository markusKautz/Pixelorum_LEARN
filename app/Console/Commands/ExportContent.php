<?php

namespace App\Console\Commands;

use App\Actions\Content\BundleContentExport;
use App\Actions\Content\ExportLearningPath;
use App\Models\LearningPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('lapp:content:export
    {slug? : Slug eines Lernpfades; auch ein Pfad im Papierkorb}
    {--all : Alle Lernpfade außerhalb des Papierkorbs exportieren}
    {--dir= : Zielverzeichnis, Standard storage/app/private/content-export}')]
#[Description('Exportiert Lernpfade aus der Datenbank als Content-JSON')]
class ExportContent extends Command
{
    /**
     * Execute the console command.
     *
     * Warnungen ändern den Exit-Code nicht: Die Datei ist auch dann eine
     * brauchbare Sicherung, wenn sie so nicht wieder hochladbar wäre (E3).
     * Ein Pfad mit hochgeladenen Bildern wird zum ZIP-Bündel (DEV-14).
     */
    public function handle(ExportLearningPath $export, BundleContentExport $bundle): int
    {
        $slug = $this->argument('slug');

        if (is_string($slug) === (bool) $this->option('all')) {
            $this->components->error('Entweder einen Slug oder --all angeben.');

            return self::FAILURE;
        }

        if (is_string($slug)) {
            // Ein soft-gelöschter Pfad nur bei ausdrücklicher Angabe (E6).
            $path = LearningPath::withTrashed()->where('slug', $slug)->first();

            if ($path === null) {
                $this->components->error("Lernpfad nicht gefunden: {$slug}");

                return self::FAILURE;
            }

            $paths = collect([$path]);
        } else {
            $paths = LearningPath::query()->orderBy('position')->get();
        }

        $directory = $this->option('dir');
        $directory = is_string($directory) && $directory !== ''
            ? $directory
            : storage_path('app/private/content-export');
        File::ensureDirectoryExists($directory);

        foreach ($paths as $path) {
            $result = $export->handle($path);
            $target = rtrim($directory, '/').'/'.$result['filename'];

            if ($result['media'] === []) {
                File::put($target, $result['json']);
            } else {
                File::move($bundle->handle($result['json'], $result['media']), $target);
            }

            $this->components->info("Exportiert: {$target}");

            foreach ($result['warnings'] as $warning) {
                $this->components->warn($warning);
            }
        }

        return self::SUCCESS;
    }
}
