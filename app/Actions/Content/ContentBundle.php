<?php

namespace App\Actions\Content;

/**
 * Format und Grenzen des ZIP-Bündels aus Content-JSON und Bildern (DEV-14).
 *
 * ```
 * {slug}.zip
 * ├── content.json
 * └── media/<sha256>.<ext>
 * ```
 *
 * `content.json` ist Schema-Version 3 und verweist mit `media/<sha256>.<ext>`
 * auf die Bilder; der Root-Block `media` listet sie mit Hash, MIME-Typ und
 * Größe. Auf der Installation landen sie als `lesson-media/<sha256>.<ext>`
 * auf der `public`-Disk: gleicher Inhalt, gleiche Datei, ein wiederholter
 * Import legt nichts doppelt an.
 *
 * Die Grenzen schützen den Upload vor Zip-Bomben. Ein Bild darf so groß sein
 * wie beim Upload im Blockformular (`max:4096`); das Archiv bleibt unter dem
 * Standardlimit für Livewire-Uploads (12 MB).
 */
final class ContentBundle
{
    public const string CONTENT_FILE = 'content.json';

    public const string MEDIA_DIRECTORY = 'media';

    public const int MAX_IMAGES = 50;

    public const int MAX_IMAGE_BYTES = 4096 * 1024;

    public const int MAX_ARCHIVE_KILOBYTES = 12288;

    public const int MAX_CONTENT_BYTES = 2048 * 1024;

    /** Summe aller entpackten Einträge. */
    public const int MAX_UNPACKED_BYTES = 24 * 1024 * 1024;

    /**
     * Zugelassene Bildtypen und ihre Endung. Kein SVG: es kann Skript tragen,
     * gleiche Linie wie die `image`-Regel im Blockformular.
     *
     * @var array<string, string>
     */
    public const array MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * The file name of an image inside the bundle and on the disk.
     */
    public static function fileName(string $sha256, string $mime): string
    {
        return $sha256.'.'.self::MIME_EXTENSIONS[$mime];
    }

    /**
     * The path the bundle uses for an image file name.
     */
    public static function bundlePath(string $fileName): string
    {
        return self::MEDIA_DIRECTORY.'/'.$fileName;
    }

    /**
     * The path on the `public` disk for an image file name.
     */
    public static function diskPath(string $fileName): string
    {
        return LocateLessonImages::UPLOAD_DIRECTORY.'/'.$fileName;
    }

    /**
     * Pattern for a valid bundle path; group 1 is the hash, group 2 the extension.
     */
    public static function bundlePathPattern(): string
    {
        $extensions = implode('|', array_unique(self::MIME_EXTENSIONS));

        return '#^'.self::MEDIA_DIRECTORY.'/([a-f0-9]{64})\.('.$extensions.')$#';
    }
}
