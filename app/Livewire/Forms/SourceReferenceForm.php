<?php

namespace App\Livewire\Forms;

use App\Actions\Content\LoadContentSourceRegistry;
use App\Rules\SourceReferences;
use Illuminate\Support\Str;
use Livewire\Form;

/**
 * Formular-Objekt des Quellenbelegs (source_reference) von Lektionen und
 * Fragen — geteilt zwischen Block-Editor (S3.3) und Fragen-Formular (S3.4).
 * Unterstützt mehrere Referenzen je Eintrag; jede Zeile braucht genau einen
 * zum Quellentyp passenden Locator (page, slide oder section).
 *
 * Geprüft wird gegen `content_sources`, nicht gegen das Quellregister (DEV-3,
 * E11): Das Quellenarchiv gibt es in Produktion nicht. Die Tiefenprüfung
 * gegen Extrakt und Quellkarte bleibt den Autorenwerkzeugen vorbehalten.
 */
class SourceReferenceForm extends Form
{
    /**
     * @var array<int, mixed>
     */
    public array $references = [];

    /**
     * @var list<string>
     */
    public array $rowKeys = [];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $hasInput = $this->references !== [] && $this->anyRowHasInput();

        $registry = $hasInput
            ? app(LoadContentSourceRegistry::class)->handle()
            : [];

        return [
            'references' => ['array', 'list', new SourceReferences($registry, allowBlankRows: true, metadataOnly: true)],
            'references.*' => ['array:source_id,page,slide,section'],
            'references.*.source_id' => ['nullable', 'string', 'max:100'],
            'references.*.page' => ['nullable', 'integer', 'min:1'],
            'references.*.slide' => ['nullable', 'integer', 'min:1'],
            'references.*.section' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Detect whether any row has non-blank input defensively.
     */
    private function anyRowHasInput(): bool
    {
        foreach ($this->references as $row) {
            if (! is_array($row)) {
                return true;
            }

            foreach ($row as $value) {
                if (! blank($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get a blank reference template with all expected keys.
     *
     * @return array{source_id: string, page: null, slide: null, section: string}
     */
    private function emptyReference(): array
    {
        return ['source_id' => '', 'page' => null, 'slide' => null, 'section' => ''];
    }

    /**
     * Fill the fields from a stored reference payload.
     *
     * @param  list<array{source_id:string,page?:int,slide?:int,section?:string}>|null  $references
     */
    public function fillFromReferences(?array $references): void
    {
        $this->references = [];
        $this->rowKeys = [];

        if ($references === null || $references === []) {
            $this->addReference();

            return;
        }

        foreach ($references as $stored) {
            $this->references[] = array_merge($this->emptyReference(), $stored);
            $this->rowKeys[] = $this->newKey();
        }
    }

    public function addReference(): void
    {
        $this->references[] = $this->emptyReference();
        $this->rowKeys[] = $this->newKey();
    }

    public function removeReference(int $index): void
    {
        if (! array_key_exists($index, $this->references)) {
            return;
        }

        array_splice($this->references, $index, 1);
        array_splice($this->rowKeys, $index, 1);

        if ($this->references === []) {
            $this->addReference();
        }
    }

    /**
     * Normalize the fields before validation.
     */
    public function normalize(): void
    {
        foreach ($this->references as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->references[$index]['source_id'] = isset($row['source_id']) && is_string($row['source_id'])
                ? trim($row['source_id'])
                : ($row['source_id'] ?? '');
            $this->references[$index]['section'] = isset($row['section']) && is_string($row['section'])
                ? trim($row['section'])
                : ($row['section'] ?? null);

            foreach (['page', 'slide'] as $key) {
                $value = $row[$key] ?? null;
                $this->references[$index][$key] = $value === '' ? null : $value;
            }
        }
    }

    /**
     * Build the source_reference payload from the validated fields.
     *
     * @return list<array{source_id:string,page?:int,slide?:int,section?:string}>|null
     */
    public function toReferences(): ?array
    {
        $result = [];

        foreach ($this->references as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sourceId = isset($row['source_id']) && is_string($row['source_id'])
                ? trim($row['source_id'])
                : '';

            if ($sourceId === '') {
                continue;
            }

            $reference = ['source_id' => $sourceId];

            if (! blank($row['page'] ?? null)) {
                $reference['page'] = is_numeric($row['page']) ? (int) $row['page'] : $row['page'];
            } elseif (! blank($row['slide'] ?? null)) {
                $reference['slide'] = is_numeric($row['slide']) ? (int) $row['slide'] : $row['slide'];
            } elseif (! blank($row['section'] ?? null)) {
                $reference['section'] = $row['section'];
            }

            $result[] = $reference;
        }

        return $result === [] ? null : $result;
    }

    private function newKey(): string
    {
        return (string) Str::uuid();
    }
}
