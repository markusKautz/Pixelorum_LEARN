<?php

namespace App\Actions\Content;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Legt die geprüften Bilder eines ZIP-Bündels auf der `public`-Disk ab (DEV-14).
 *
 * Der Dateiname ist der Hash des Inhalts. Eine vorhandene Datei hat also
 * denselben Inhalt und bleibt unberührt; nur neue Dateien werden geschrieben
 * und gemeldet. Schlägt der Import danach fehl, räumt {@see discard()} genau
 * diese wieder weg, nie eine Datei, die schon vorher da war.
 */
class StoreBundledImages
{
    /**
     * Store the images and return the paths that did not exist before.
     *
     * @param  array<string, string>  $images  Pfad auf der `public`-Disk => Inhalt
     * @return list<string>
     *
     * @throws RuntimeException wenn eine Datei nicht geschrieben werden kann
     */
    public function handle(array $images): array
    {
        $disk = Storage::disk('public');
        $written = [];

        foreach ($images as $path => $bytes) {
            if ($disk->exists($path)) {
                continue;
            }

            if (! $disk->put($path, $bytes)) {
                $this->discard($written);

                throw new RuntimeException("Das Bild {$path} konnte nicht gespeichert werden.");
            }

            $written[] = $path;
        }

        return $written;
    }

    /**
     * Remove the files a failed import wrote.
     *
     * @param  list<string>  $paths
     */
    public function discard(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
