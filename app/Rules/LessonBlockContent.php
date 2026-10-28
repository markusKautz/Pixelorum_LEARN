<?php

namespace App\Rules;

use App\Enums\LessonBlockType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validiert die content-Payload eines LessonBlock exhaustiv gegen den Shape
 * seines Typs (Konzept d; Shapes dokumentiert am LessonBlock-Model). Diese
 * Rule ist die eine Wahrheit über die Shapes; die Block-Formulare validieren
 * zusätzlich feldweise für gute Fehlermeldungen.
 */
class LessonBlockContent implements ValidationRule
{
    /** @var array<int, string> */
    public const array CALLOUT_VARIANTS = ['info', 'warning', 'danger', 'highlight', 'exam'];

    /** @var array<int, string> */
    public const array DIAGRAM_FORMATS = ['mermaid', 'image'];

    /** @var array<int, string> */
    public const array MEDIA_KINDS = ['video', 'image'];

    /** @var array<int, string> */
    public const array CODE_LANGUAGES = ['php', 'bash', 'js', 'html', 'css', 'sql', 'json', 'yaml', 'csharp', 'text'];

    public function __construct(private readonly LessonBlockType $type) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('The block content must be a structured payload.'));

            return;
        }

        $spec = $this->spec();

        $allowed = array_merge(
            $spec['required'] ?? [],
            $spec['optional'] ?? [],
            array_keys($spec['enums'] ?? []),
            array_keys($spec['lists'] ?? []),
        );

        $unknown = array_diff(array_keys($value), $allowed);

        if ($unknown !== []) {
            $fail(__('The block content contains unexpected fields: :fields.', ['fields' => implode(', ', $unknown)]));

            return;
        }

        foreach ($spec['required'] ?? [] as $field) {
            if (! is_string($value[$field] ?? null) || trim($value[$field]) === '') {
                $fail(__('The block content field :field is required.', ['field' => $field]));
            }
        }

        foreach ($spec['optional'] ?? [] as $field) {
            if (array_key_exists($field, $value) && $value[$field] !== null && ! is_string($value[$field])) {
                $fail(__('The block content field :field must be text or empty.', ['field' => $field]));
            }
        }

        foreach ($spec['enums'] ?? [] as $field => $options) {
            if (! in_array($value[$field] ?? null, $options, true)) {
                $fail(__('The block content field :field must be one of: :values.', [
                    'field' => $field,
                    'values' => implode(', ', $options),
                ]));
            }
        }

        foreach ($spec['lists'] ?? [] as $field => $itemFields) {
            $this->validateList($value[$field] ?? null, $field, $itemFields, $fail);
        }

        // URL-tragende Felder erlauben nur http(s) bzw. für Bilder zusätzlich
        // site-relative Pfade (/storage/… aus dem Upload) — verhindert
        // javascript:/data:-Schemata im gespeicherten Content (Stored XSS).
        if ($this->type === LessonBlockType::Media && is_string($value['url'] ?? null)) {
            $this->validateUrlScheme($value['url'], 'url', allowRelative: ($value['kind'] ?? null) === 'image', fail: $fail);
        }

        if ($this->type === LessonBlockType::Diagram
            && ($value['format'] ?? null) === 'image'
            && is_string($value['source'] ?? null)) {
            $this->validateUrlScheme($value['source'], 'source', allowRelative: true, fail: $fail);
        }
    }

    /**
     * Ensure the given URL uses a safe scheme.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    protected function validateUrlScheme(string $url, string $field, bool $allowRelative, Closure $fail): void
    {
        $isHttp = (bool) preg_match('#^https?://#i', $url);
        $isRelative = str_starts_with($url, '/') && ! str_starts_with($url, '//');

        if ($url === '' || $isHttp || ($allowRelative && $isRelative)) {
            return;
        }

        $fail(__('The block content field :field must be an http(s) URL.', ['field' => $field]));
    }

    /**
     * Ensure the given list is a non-empty array of complete items.
     *
     * @param  array<int, string>  $itemFields
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    protected function validateList(mixed $list, string $field, array $itemFields, Closure $fail): void
    {
        if (! is_array($list) || $list === [] || ! array_is_list($list)) {
            $fail(__('The block content field :field must be a non-empty list.', ['field' => $field]));

            return;
        }

        foreach ($list as $item) {
            if (! is_array($item) || array_diff(array_keys($item), $itemFields) !== []) {
                $fail(__('Every entry of :field must consist of the fields :fields.', [
                    'field' => $field,
                    'fields' => implode(', ', $itemFields),
                ]));

                return;
            }

            foreach ($itemFields as $itemField) {
                if (! is_string($item[$itemField] ?? null) || trim($item[$itemField]) === '') {
                    $fail(__('Every entry of :field needs a non-empty :itemField.', [
                        'field' => $field,
                        'itemField' => $itemField,
                    ]));

                    return;
                }
            }
        }
    }

    /**
     * Get the shape specification for the block type.
     *
     * @return array{
     *     required?: array<int, string>,
     *     optional?: array<int, string>,
     *     enums?: array<string, array<int, string>>,
     *     lists?: array<string, array<int, string>>,
     * }
     */
    protected function spec(): array
    {
        return match ($this->type) {
            LessonBlockType::Markdown => [
                'required' => ['body'],
            ],
            LessonBlockType::Accordion => [
                'lists' => ['items' => ['heading', 'body']],
            ],
            LessonBlockType::Callout => [
                'required' => ['body'],
                'enums' => ['variant' => self::CALLOUT_VARIANTS],
            ],
            LessonBlockType::CodeSnippet => [
                'required' => ['code'],
                'optional' => ['filename'],
                'enums' => ['language' => self::CODE_LANGUAGES],
            ],
            LessonBlockType::Diagram => [
                'required' => ['source'],
                'optional' => ['caption'],
                'enums' => ['format' => self::DIAGRAM_FORMATS],
            ],
            LessonBlockType::Media => [
                'required' => ['url'],
                'optional' => ['caption', 'alt'],
                'enums' => ['kind' => self::MEDIA_KINDS],
            ],
            LessonBlockType::Glossary => [
                'lists' => ['terms' => ['term', 'definition']],
            ],
            // Reine Segmentgrenze für den Reader (S4.3) — kein Pflichtinhalt;
            // der optionale Titel wird dort zur Abschnitts-Unterüberschrift.
            LessonBlockType::SectionBreak => [
                'optional' => ['title'],
            ],
        };
    }
}
