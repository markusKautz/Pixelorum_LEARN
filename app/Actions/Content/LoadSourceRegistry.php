<?php

namespace App\Actions\Content;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * Lädt und validiert die Quellen-Registry (JSON-Datei mit Lernmaterial-Metadaten).
 *
 * Validiert:
 *   - Struktur (Array von Objekten)
 *   - Vollständige Key-Validierung (keine unbekannten Felder)
 *   - Eindeutige, lowercase-kebab source_id (max 100 Zeichen)
 *   - kind ∈ {pdf, slides, web}
 *   - title/publisher max 255 Zeichen (multibyte-aware)
 *   - optionale version max 100 Zeichen (multibyte-aware)
 *   - Pflichtfelder je Kind
 *   - Snapshot-Datei-Existenz, Lesbarkeit und SHA256-Hash für Web-Quellen
 *   - Gültige http(s)-URL für Web-Quellen
 *   - Datumsformat YYYY-MM-DD
 *   - Keine stille Typ-Castierung: jeder Feldwert wird vor Verwendung auf
 *     erwarteten Typ geprüft.
 */
class LoadSourceRegistry
{
    protected bool $enforceContainment = false;

    /** @var list<string> */
    private const ALLOWED_KINDS = ['pdf', 'slides', 'web'];

    /** @var list<string> */
    private const ALL_KEYS = [
        'source_id', 'kind', 'title', 'publisher',
        'path', 'extract_path',
        'url', 'published_at', 'version',
        'accessed_at', 'approved_at',
        'snapshot_path', 'content_sha256',
    ];

    /** @var list<string> */
    private const COMMON_REQUIRED = ['source_id', 'kind', 'title', 'publisher'];

    /** @var list<string> */
    private const LOCAL_REQUIRED = ['path', 'extract_path'];

    /** @var list<string> */
    private const WEB_REQUIRED = ['url', 'accessed_at', 'approved_at', 'snapshot_path', 'content_sha256'];

    private const KEBAB_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const SHA256_PATTERN = '/^[a-f0-9]{64}$/';

    /**
     * The content root of the local pipeline, relative to the project folder.
     *
     * Liegt außerhalb des Repositories, siehe `config/lapp.php`.
     */
    public static function contentRoot(): string
    {
        return trim((string) config('lapp.content_root'), '/');
    }

    /**
     * The registry the pipeline uses when no other file is given.
     */
    public static function defaultPath(): string
    {
        return base_path(self::contentRoot().'/ressource/sources.json');
    }

    public function enforceContainment(bool $value = true): self
    {
        $this->enforceContainment = $value;

        return $this;
    }

    /**
     * Load and validate the source registry from the given JSON file.
     *
     * @return array<string, array<string, mixed>>
     */
    public function handle(?string $registryPath = null): array
    {
        $path = $registryPath ?? self::defaultPath();

        if ($registryPath === null || $registryPath === self::defaultPath()) {
            $this->enforceContainment = true;
        }

        if (! File::exists($path)) {
            throw new RuntimeException("Source registry not found: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Source registry unreadable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Source registry unreadable: {$path}");
        }

        try {
            $sources = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Source registry malformed JSON: {$path}", 0, $e);
        }

        if (! is_array($sources) || ! array_is_list($sources)) {
            throw new RuntimeException("Source registry malformed JSON: {$path}");
        }

        $seenIds = [];
        $seenExtractPaths = [];
        $seenSnapshotPaths = [];
        $result = [];

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                throw new RuntimeException("Source registry malformed JSON: {$path}");
            }

            $this->validateSource($source, $index, $path);

            $id = $source['source_id'];

            if (isset($seenIds[$id])) {
                throw new RuntimeException("Duplicate source_id '{$id}' in registry: {$path}");
            }

            $seenIds[$id] = true;

            if (($source['kind'] ?? null) !== 'web') {
                $extractPath = $source['extract_path'] ?? null;
                if ($extractPath !== null) {
                    if (isset($seenExtractPaths[$extractPath])) {
                        throw new RuntimeException("Duplicate extract_path '{$extractPath}' at index {$index} in: {$path}");
                    }
                    $seenExtractPaths[$extractPath] = true;
                }
            } else {
                $snapshotPath = $source['snapshot_path'] ?? null;
                if ($snapshotPath !== null) {
                    if (isset($seenSnapshotPaths[$snapshotPath])) {
                        throw new RuntimeException("Duplicate snapshot_path '{$snapshotPath}' at index {$index} in: {$path}");
                    }
                    $seenSnapshotPaths[$snapshotPath] = true;
                }
            }

            $result[$id] = $source;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function validateSource(array $source, int $index, string $path): void
    {
        // Exhaustive key validation
        $unknownKeys = array_diff(array_keys($source), self::ALL_KEYS);

        if ($unknownKeys !== []) {
            throw new RuntimeException(
                "Unknown field(s) '".implode(', ', $unknownKeys)."' at index {$index} in: {$path}"
            );
        }

        // Required common fields (including kind, so missing kind says "missing" not "invalid")
        foreach (self::COMMON_REQUIRED as $key) {
            if (! array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
                throw new RuntimeException("Missing required field '{$key}' at index {$index} in: {$path}");
            }

            $this->assertString($source[$key], $key, $index, $path);
        }

        $kind = $source['kind'];

        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new RuntimeException("Invalid kind '{$kind}' at index {$index} in: {$path}");
        }

        // source_id format: lowercase kebab
        if (! preg_match(self::KEBAB_PATTERN, $source['source_id'])) {
            throw new RuntimeException(
                "Invalid source_id '{$source['source_id']}' at index {$index} in: {$path}"
            );
        }

        // source_id length
        if (mb_strlen($source['source_id']) > 100) {
            throw new RuntimeException(
                "source_id exceeds 100 characters at index {$index} in: {$path}"
            );
        }

        // title length
        if (mb_strlen($source['title']) > 255) {
            throw new RuntimeException(
                "title exceeds 255 characters at index {$index} in: {$path}"
            );
        }

        // publisher length
        if (mb_strlen($source['publisher']) > 255) {
            throw new RuntimeException(
                "publisher exceeds 255 characters at index {$index} in: {$path}"
            );
        }

        // Optional version validation
        if (array_key_exists('version', $source) && $source['version'] !== null && $source['version'] !== '') {
            $this->assertString($source['version'], 'version', $index, $path);

            if (mb_strlen($source['version']) > 100) {
                throw new RuntimeException(
                    "version exceeds 100 characters at index {$index} in: {$path}"
                );
            }
        }

        // Optional date validation for published_at
        if (array_key_exists('published_at', $source) && $source['published_at'] !== null && $source['published_at'] !== '') {
            $this->assertString($source['published_at'], 'published_at', $index, $path);

            if (! $this->isValidDate($source['published_at'])) {
                throw new RuntimeException(
                    "Invalid published_at '{$source['published_at']}' at index {$index} in: {$path}"
                );
            }
        }

        // Kind-specific validation
        if ($kind === 'web') {
            $this->validateWebSource($source, $index, $path);
        } else {
            $this->validateLocalSource($source, $index, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function validateWebSource(array $source, int $index, string $path): void
    {
        // Required web fields
        foreach (self::WEB_REQUIRED as $key) {
            if (! array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
                throw new RuntimeException("Missing required field '{$key}' at index {$index} in: {$path}");
            }

            $this->assertString($source[$key], $key, $index, $path);
        }

        // Forbid local fields when filled
        foreach (self::LOCAL_REQUIRED as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                throw new RuntimeException(
                    "Field '{$key}' not allowed for web sources at index {$index} in: {$path}"
                );
            }
        }

        // URL validation
        $scheme = parse_url($source['url'], PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($source['url'], FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException("Invalid URL '{$source['url']}' at index {$index} in: {$path}");
        }

        // Date validation for accessed_at and approved_at
        foreach (['accessed_at', 'approved_at'] as $dateField) {
            if (! $this->isValidDate($source[$dateField])) {
                throw new RuntimeException(
                    "Invalid {$dateField} '{$source[$dateField]}' at index {$index} in: {$path}"
                );
            }
        }

        // Snapshot path resolution and existence
        $snapshotPath = $source['snapshot_path'];
        if (! str_starts_with($snapshotPath, '/')) {
            $snapshotPath = base_path($snapshotPath);
        }

        // Containment check for snapshot_path
        if ($this->enforceContainment) {
            $this->assertContained('snapshot_path', $source['snapshot_path'], self::contentRoot().'/ressource/web/', 'md', $index, $path);
        }

        if (! File::exists($snapshotPath)) {
            throw new RuntimeException(
                "Snapshot file not found '{$source['snapshot_path']}' at index {$index} in: {$path}"
            );
        }

        if (! is_readable($snapshotPath)) {
            throw new RuntimeException(
                "Snapshot file unreadable '{$source['snapshot_path']}' at index {$index} in: {$path}"
            );
        }

        // SHA256 shape validation
        if (! preg_match(self::SHA256_PATTERN, $source['content_sha256'])) {
            throw new RuntimeException(
                "Invalid content_sha256 '{$source['content_sha256']}' at index {$index} in: {$path}"
            );
        }

        $actualHash = @hash_file('sha256', $snapshotPath);

        if ($actualHash === false) {
            throw new RuntimeException(
                "Snapshot file unhashable '{$source['snapshot_path']}' at index {$index} in: {$path}"
            );
        }

        if (! hash_equals($source['content_sha256'], $actualHash)) {
            throw new RuntimeException(
                "SHA256 mismatch for snapshot '{$source['snapshot_path']}' at index {$index} in: {$path}"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function validateLocalSource(array $source, int $index, string $path): void
    {
        // Required local fields
        foreach (self::LOCAL_REQUIRED as $key) {
            if (! array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
                throw new RuntimeException("Missing required field '{$key}' at index {$index} in: {$path}");
            }

            $this->assertString($source[$key], $key, $index, $path);
        }

        // Containment checks
        if ($this->enforceContainment) {
            $this->assertContained('path', $source['path'], self::contentRoot().'/ressource/', null, $index, $path);
            $this->assertContained('extract_path', $source['extract_path'], self::contentRoot().'/ressource/extract/', 'txt', $index, $path);
        }

        // Forbid web-only fields when filled
        foreach (self::WEB_REQUIRED as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                throw new RuntimeException(
                    "Field '{$key}' not allowed for local sources at index {$index} in: {$path}"
                );
            }
        }
    }

    private function assertString(mixed $value, string $field, int $index, string $path): void
    {
        if (! is_string($value)) {
            throw new RuntimeException(
                "Invalid type for field '{$field}' at index {$index} in: {$path}"
            );
        }
    }

    private function isValidDate(string $date): bool
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        if (! preg_match($pattern, $date)) {
            return false;
        }

        [$year, $month, $day] = explode('-', $date);

        return checkdate((int) $month, (int) $day, (int) $year);
    }

    /**
     * Resolve a path relative to base_path, normalizing . and .. segments.
     */
    private function normalizePath(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }

            if ($part === '..') {
                array_pop($normalized);

                continue;
            }

            $normalized[] = $part;
        }

        return '/'.implode('/', $normalized);
    }

    /**
     * Assert that a path field is contained under the approved directory
     * and optionally has the required file extension.
     */
    private function assertContained(string $field, string $value, string $containmentDir, ?string $extension, int $index, string $path): void
    {
        $normalized = $this->normalizePath($value);
        $containment = $this->normalizePath($containmentDir).'/';

        if (! str_starts_with($normalized.'/', $containment)) {
            $fieldDir = rtrim($containmentDir, '/');

            throw new RuntimeException(
                "Field '{$field}' must be under {$fieldDir} at index {$index} in: {$path}"
            );
        }

        if ($extension !== null && ! str_ends_with($normalized, '.'.$extension)) {
            throw new RuntimeException(
                "Field '{$field}' must end with .{$extension} at index {$index} in: {$path}"
            );
        }
    }
}
