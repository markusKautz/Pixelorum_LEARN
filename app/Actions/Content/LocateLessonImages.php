<?php

namespace App\Actions\Content;

use App\Enums\LessonBlockType;

/**
 * Findet Bilder in Lektionsblöcken, die auf einen eigenen Upload zeigen (DEV-14).
 *
 * Bilder stecken in zwei Blocktypen: `media` mit `kind = image` im Feld `url`,
 * `diagram` mit `format = image` im Feld `source`. Ein eigener Upload liegt
 * auf der `public`-Disk und steht als site-relative `/storage/…`-URL im
 * Content; Alt-Bestände mit absoluter Host-URL zählen dazu, weil nur der
 * Pfadteil geprüft wird. Blockformular, Export und Bündel-Import teilen diese
 * eine Regel.
 */
class LocateLessonImages
{
    /** Der Pfadteil der `public`-Disk-URL. */
    public const string PUBLIC_PREFIX = '/storage/';

    /** Verzeichnis der Uploads auf der `public`-Disk. */
    public const string UPLOAD_DIRECTORY = 'lesson-media';

    /**
     * The content field holding the image URL, if the block currently shows an image.
     *
     * @param  array<string, mixed>  $content
     */
    public function imageField(LessonBlockType $type, array $content): ?string
    {
        return match (true) {
            $type === LessonBlockType::Diagram && ($content['format'] ?? null) === 'image' => 'source',
            $type === LessonBlockType::Media && ($content['kind'] ?? null) === 'image' => 'url',
            default => null,
        };
    }

    /**
     * Whether the URL points to an own upload rather than an external address.
     */
    public function isLocalUpload(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return str_starts_with($path, self::PUBLIC_PREFIX);
    }

    /**
     * The path on the `public` disk an own upload URL refers to, or null.
     *
     * Ein Pfad mit `..` zählt nicht: Der Export liest nur, was wirklich unter
     * der Disk liegt.
     */
    public function diskPath(string $url): ?string
    {
        if (! $this->isLocalUpload($url)) {
            return null;
        }

        $path = substr((string) (parse_url($url, PHP_URL_PATH) ?: $url), strlen(self::PUBLIC_PREFIX));

        if ($path === '' || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return rawurldecode($path);
    }

    /**
     * The site-relative URL of a file on the `public` disk.
     */
    public function publicUrl(string $diskPath): string
    {
        return self::PUBLIC_PREFIX.ltrim($diskPath, '/');
    }
}
