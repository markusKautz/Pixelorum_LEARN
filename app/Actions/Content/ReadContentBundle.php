<?php

namespace App\Actions\Content;

use App\Enums\LessonBlockType;
use finfo;
use JsonException;
use RuntimeException;
use stdClass;
use ZipArchive;

/**
 * Liest und prüft ein hochgeladenes ZIP-Bündel (DEV-14, E10).
 *
 * Das Archiv kommt vom Client und wird wie jede Eingabe misstrauisch gelesen:
 *
 * - Erlaubt sind nur `content.json` und die Dateien im Manifest `media`.
 *   Archivpfade dienen nie als Zielpfad (Zip-Slip); jeder fremde Eintrag
 *   weist das Bündel ab.
 * - Anzahl, Einzel- und Gesamtgröße sind begrenzt (Zip-Bomben). Gelesen wird
 *   höchstens die angekündigte Größe.
 * - Jedes Bild muss seinem Hash und seinem MIME-Typ entsprechen; der Typ
 *   kommt aus dem Inhalt, nicht aus der Endung.
 *
 * Danach zeigen die Bildfelder auf `/storage/lesson-media/<sha256>.<ext>`.
 * Diese Datei geht dann wie ein JSON-Upload durch `ValidateContentFile`.
 * Geschrieben wird hier nichts, das übernimmt {@see StoreBundledImages}.
 */
class ReadContentBundle
{
    public function __construct(private readonly LocateLessonImages $locateImages) {}

    /**
     * Read the bundle; `images` maps the target path on the `public` disk to the bytes.
     *
     * @return array{json: string, images: array<string, string>}
     *
     * @throws RuntimeException mit einer Meldung für den Admin
     */
    public function handle(string $archive, string $label): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("{$label}: Die Datei ist kein lesbares ZIP-Archiv.");
        }

        try {
            return $this->read($zip, $label);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{json: string, images: array<string, string>}
     */
    protected function read(ZipArchive $zip, string $label): array
    {
        $entries = $this->entries($zip, $label);

        if (! isset($entries[ContentBundle::CONTENT_FILE])) {
            throw new RuntimeException("{$label}: Im Archiv fehlt ".ContentBundle::CONTENT_FILE.'.');
        }

        $content = $this->decode($this->readEntry($zip, ContentBundle::CONTENT_FILE, $entries[ContentBundle::CONTENT_FILE], $label), $label);
        $manifest = $this->manifest($content, $label);

        foreach (array_keys($entries) as $name) {
            if ($name !== ContentBundle::CONTENT_FILE && ! isset($manifest[$name])) {
                throw new RuntimeException("{$label}: {$name}: Eintrag steht nicht im media-Block.");
            }
        }

        $images = [];

        foreach ($manifest as $bundlePath => $entry) {
            if (! isset($entries[$bundlePath])) {
                throw new RuntimeException("{$label}: {$bundlePath}: Datei fehlt im Archiv.");
            }

            if ($entries[$bundlePath] !== $entry['bytes']) {
                throw new RuntimeException("{$label}: {$bundlePath}: Größe weicht vom media-Block ab.");
            }

            $bytes = $this->readEntry($zip, $bundlePath, $entry['bytes'], $label);

            if (hash('sha256', $bytes) !== $entry['sha256']) {
                throw new RuntimeException("{$label}: {$bundlePath}: Prüfsumme weicht vom media-Block ab.");
            }

            if ((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $entry['mime']) {
                throw new RuntimeException("{$label}: {$bundlePath}: Der Inhalt ist kein Bild vom Typ {$entry['mime']}.");
            }

            $images[ContentBundle::diskPath(basename($bundlePath))] = $bytes;
        }

        $content = $this->resolveImagePaths($content, $manifest, $label);

        try {
            $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$label}: ".ContentBundle::CONTENT_FILE.": {$exception->getMessage()}", previous: $exception);
        }

        return ['json' => $json, 'images' => $images];
    }

    /**
     * The archive entries and their unpacked sizes, after the structural limits.
     *
     * @return array<string, int>
     */
    protected function entries(ZipArchive $zip, string $label): array
    {
        if ($zip->numFiles > ContentBundle::MAX_IMAGES + 2) {
            throw new RuntimeException("{$label}: Das Archiv enthält zu viele Einträge.");
        }

        $entries = [];
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw new RuntimeException("{$label}: Das Archiv ist beschädigt.");
            }

            $name = $stat['name'];

            // Das Verzeichnis selbst darf als Eintrag vorkommen.
            if ($name === ContentBundle::MEDIA_DIRECTORY.'/') {
                continue;
            }

            if ($name !== ContentBundle::CONTENT_FILE && preg_match(ContentBundle::bundlePathPattern(), $name) !== 1) {
                throw new RuntimeException("{$label}: {$name}: Unerwarteter Eintrag im Archiv.");
            }

            $limit = $name === ContentBundle::CONTENT_FILE ? ContentBundle::MAX_CONTENT_BYTES : ContentBundle::MAX_IMAGE_BYTES;
            $total += $stat['size'];

            if ($stat['size'] > $limit || $total > ContentBundle::MAX_UNPACKED_BYTES) {
                throw new RuntimeException("{$label}: {$name}: Der Eintrag ist zu groß.");
            }

            $entries[$name] = $stat['size'];
        }

        return $entries;
    }

    /**
     * Read at most the announced size of an entry and insist on exactly that.
     */
    protected function readEntry(ZipArchive $zip, string $name, int $size, string $label): string
    {
        $bytes = $size === 0 ? '' : $zip->getFromName($name, $size);

        if ($bytes === false || strlen($bytes) !== $size) {
            throw new RuntimeException("{$label}: {$name}: Der Eintrag lässt sich nicht lesen.");
        }

        return $bytes;
    }

    /**
     * Decode as objects: re-encoding keeps `{}` and `[]` apart, as the validator expects.
     */
    protected function decode(string $json, string $label): stdClass
    {
        try {
            $content = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$label}: ".ContentBundle::CONTENT_FILE.": Ungültiges JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! $content instanceof stdClass) {
            throw new RuntimeException("{$label}: ".ContentBundle::CONTENT_FILE.': Root muss ein JSON-Objekt sein.');
        }

        return $content;
    }

    /**
     * The `media` block keyed by bundle path, checked as far as reading needs it.
     *
     * Die vollständige Prüfung des Blocks übernimmt danach der Validator.
     *
     * @return array<string, array{sha256: string, mime: string, bytes: int}>
     */
    protected function manifest(stdClass $content, string $label): array
    {
        $media = $content->media ?? [];

        if (! is_array($media) || count($media) > ContentBundle::MAX_IMAGES) {
            throw new RuntimeException("{$label}: media: Der Block muss eine Liste von höchstens ".ContentBundle::MAX_IMAGES.' Bildern sein.');
        }

        $manifest = [];

        foreach ($media as $index => $entry) {
            $path = $entry instanceof stdClass ? ($entry->path ?? null) : null;
            $sha256 = $entry instanceof stdClass ? ($entry->sha256 ?? null) : null;
            $mime = $entry instanceof stdClass ? ($entry->mime ?? null) : null;
            $bytes = $entry instanceof stdClass ? ($entry->bytes ?? null) : null;

            if (! is_string($path) || ! is_string($sha256) || ! is_string($mime) || ! is_int($bytes)
                || ! isset(ContentBundle::MIME_EXTENSIONS[$mime])
                || $path !== ContentBundle::bundlePath(ContentBundle::fileName($sha256, $mime))
                || isset($manifest[$path])) {
                throw new RuntimeException("{$label}: media.{$index}: Ungültiger oder doppelter Eintrag.");
            }

            $manifest[$path] = ['sha256' => $sha256, 'mime' => $mime, 'bytes' => $bytes];
        }

        return $manifest;
    }

    /**
     * Point every bundled image field at its future place on the `public` disk.
     *
     * @param  array<string, array{sha256: string, mime: string, bytes: int}>  $manifest
     */
    protected function resolveImagePaths(stdClass $content, array $manifest, string $label): stdClass
    {
        foreach (is_array($content->lessons ?? null) ? $content->lessons : [] as $lessonIndex => $lesson) {
            $blocks = $lesson instanceof stdClass && is_array($lesson->blocks ?? null) ? $lesson->blocks : [];

            foreach ($blocks as $blockIndex => $block) {
                $type = $block instanceof stdClass && is_string($block->type ?? null) ? LessonBlockType::tryFrom($block->type) : null;
                $blockContent = $block instanceof stdClass ? ($block->content ?? null) : null;

                if ($type === null || ! $blockContent instanceof stdClass) {
                    continue;
                }

                $field = $this->locateImages->imageField($type, (array) $blockContent);
                $url = $field !== null ? ($blockContent->{$field} ?? null) : null;

                if (! is_string($url) || ! str_starts_with($url, ContentBundle::MEDIA_DIRECTORY.'/')) {
                    continue;
                }

                if (! isset($manifest[$url])) {
                    throw new RuntimeException("{$label}: lessons.{$lessonIndex}.blocks.{$blockIndex}.content.{$field}: {$url} steht nicht im media-Block.");
                }

                // Objekte werden per Referenz geändert, $content trägt die Änderung.
                $blockContent->{$field} = $this->locateImages->publicUrl(ContentBundle::diskPath(basename($url)));
            }
        }

        return $content;
    }
}
