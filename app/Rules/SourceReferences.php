<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validiert Quellenbelege gegen eine geladene Quellen-Registry.
 *
 * Jeder Beleg muss genau einen zum Quellentyp passenden Locator enthalten:
 * pdf → page, slides → slide, web → section.
 *
 * Mit `metadataOnly` prüft die Rule nur `source_id` und Locator-Typ und liest
 * keine Extrakte oder Quellkarten. So läuft sie gegen Quellen aus der
 * Datenbank oder aus dem `sources`-Block einer Datei, wo es diese Dateien
 * nicht gibt (DEV-3).
 */
class SourceReferences implements ValidationRule
{
    /**
     * @param  array<string, array<string, mixed>>  $registry
     */
    public function __construct(
        private readonly array $registry,
        private readonly bool $allowBlankRows = false,
        private readonly bool $metadataOnly = false,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->errorsFor($value) as $error) {
            $fail($error['message']);
        }
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function errorsFor(mixed $value): array
    {
        $errors = [];
        $fail = function (string $path, string $message) use (&$errors): void {
            $errors[] = ['path' => $path, 'message' => $message];
        };

        if (! is_array($value) || ! array_is_list($value)) {
            $fail('', __('Source references must be a list.'));

            return $errors;
        }

        foreach ($value as $index => $reference) {
            if (! is_array($reference)) {
                $fail((string) $index, __('Source reference :index must be structured.', ['index' => $index + 1]));

                continue;
            }

            $allowed = ['source_id', 'page', 'slide', 'section'];
            $unexpected = array_values(array_diff(array_keys($reference), $allowed));

            if ($unexpected !== []) {
                $fail("{$index}.{$unexpected[0]}", __('Source reference :index contains unexpected fields.', ['index' => $index + 1]));

                continue;
            }

            if ($this->allowBlankRows && $this->allFieldsBlank($reference, $allowed)) {
                continue;
            }

            $sourceId = $reference['source_id'] ?? null;

            if (! is_string($sourceId) || ! isset($this->registry[$sourceId])) {
                $fail("{$index}.source_id", __('Source reference :index uses an unknown source ID.', ['index' => $index + 1]));

                continue;
            }

            $locators = array_filter(
                [
                    'page' => $reference['page'] ?? null,
                    'slide' => $reference['slide'] ?? null,
                    'section' => $reference['section'] ?? null,
                ],
                fn (mixed $locator): bool => filled($locator),
            );

            if (count($locators) !== 1) {
                $fail((string) $index, __('Source reference :index needs exactly one page, slide, or section.', ['index' => $index + 1]));

                continue;
            }

            $this->validateLocator($index, $this->registry[$sourceId], $locators, $fail);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  list<string>  $allowed
     */
    private function allFieldsBlank(array $reference, array $allowed): bool
    {
        foreach ($allowed as $key) {
            if (array_key_exists($key, $reference) && ! blank($reference[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $locators
     * @param  Closure(string, string): void  $fail
     */
    private function validateLocator(int $index, array $source, array $locators, Closure $fail): void
    {
        $locator = array_key_first($locators);
        $kind = is_string($source['kind'] ?? null) ? $source['kind'] : null;
        $expected = match ($kind) {
            'pdf' => 'page',
            'slides' => 'slide',
            'web' => 'section',
            default => null,
        };

        if ($locator !== $expected) {
            $path = $expected === null ? "{$index}.source_id" : "{$index}.{$locator}";
            $fail($path, __('Source reference :index needs a :locator locator.', [
                'index' => $index + 1,
                'locator' => $expected ?? 'valid',
            ]));

            return;
        }

        if ($this->metadataOnly) {
            return;
        }

        if ($locator === 'section') {
            $this->validateWebSection($index, $source, $locators, $fail);

            return;
        }

        $this->validateLocalPage($index, $source, $locators, $fail);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $locators
     * @param  Closure(string, string): void  $fail
     */
    private function validateWebSection(int $index, array $source, array $locators, Closure $fail): void
    {
        $snapshotPath = $this->resolvePath($source['snapshot_path'] ?? null);
        $section = is_string($locators['section'] ?? null) ? trim($locators['section']) : '';

        if ($section === '') {
            $fail("{$index}.section", __('Source reference :index has no approved source card.', ['index' => $index + 1]));

            return;
        }

        if ($snapshotPath === null || ! is_file($snapshotPath) || ! is_readable($snapshotPath)) {
            $fail("{$index}.source_id", __('Source reference :index has no approved source card.', ['index' => $index + 1]));

            return;
        }

        $card = @file_get_contents($snapshotPath);

        if ($card === false) {
            $fail("{$index}.source_id", __('Source reference :index points to an unknown section.', ['index' => $index + 1]));

            return;
        }

        if (preg_match('/^#{1,6}\s+'.preg_quote($section, '/').'\s*$/m', $card) !== 1) {
            $fail("{$index}.section", __('Source reference :index points to an unknown section.', ['index' => $index + 1]));
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $locators
     * @param  Closure(string, string): void  $fail
     */
    private function validateLocalPage(int $index, array $source, array $locators, Closure $fail): void
    {
        $locator = array_key_first($locators);
        $page = filter_var($locators[$locator] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $extractPath = $this->resolvePath($source['extract_path'] ?? null);

        if ($extractPath === null || ! is_file($extractPath) || ! is_readable($extractPath)) {
            $fail("{$index}.source_id", __('Source reference :index has no versioned extract.', ['index' => $index + 1]));

            return;
        }

        $extract = @file_get_contents($extractPath);
        preg_match_all('/^=== PAGE (\d+) ===$/m', $extract ?: '', $matches);
        $maximum = $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));

        if ($page === false) {
            $fail("{$index}.{$locator}", __('Source reference :index needs a positive integer locator.', ['index' => $index + 1]));

            return;
        }

        if ($page > $maximum) {
            $fail("{$index}.{$locator}", __('Source reference :index exceeds the source page count.', ['index' => $index + 1]));
        }
    }

    private function resolvePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
