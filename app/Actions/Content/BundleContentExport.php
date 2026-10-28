<?php

namespace App\Actions\Content;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Packt einen Export mit Bildern in ein ZIP-Bündel (DEV-14, {@see ContentBundle}).
 */
class BundleContentExport
{
    /**
     * Write the bundle to a temporary file and return its path.
     *
     * Der Aufrufer löscht die Datei nach dem Ausliefern oder Kopieren.
     *
     * @param  array<string, string>  $media  Bündel-Pfad => Datei auf der `public`-Disk
     *
     * @throws RuntimeException wenn das Archiv nicht geschrieben werden kann
     */
    public function handle(string $json, array $media): string
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'lapp-bundle-');
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das ZIP-Bündel konnte nicht angelegt werden.');
        }

        $zip->addFromString(ContentBundle::CONTENT_FILE, $json);
        $disk = Storage::disk('public');

        foreach ($media as $bundlePath => $source) {
            // Bilder sind schon komprimiert; noch einmal zu packen kostet nur Zeit.
            $zip->addFile($disk->path($source), $bundlePath);
            $zip->setCompressionName($bundlePath, ZipArchive::CM_STORE);
        }

        if (! $zip->close()) {
            throw new RuntimeException('Das ZIP-Bündel konnte nicht geschrieben werden.');
        }

        return $target;
    }
}
