<?php

namespace App\Actions\Content;

/**
 * Baut den `sources`-Block einer Content-Datei (Schema-Version 2, DEV-3).
 *
 * Der Block enthält genau die Quellen, auf die Lektionen und Fragen der Datei
 * verweisen, mit ihren transportierbaren Metadaten. Lokale Felder der
 * Registry (`path`, `extract_path`, `snapshot_path`, `approved_at`) bleiben
 * draußen: Sie zeigen auf Dateien, die es nur im Autorenrepository gibt.
 */
class CollectContentSources
{
    /** @var list<string> */
    public const TRANSPORTABLE_FIELDS = [
        'source_id', 'kind', 'title', 'publisher', 'url',
        'version', 'published_at', 'accessed_at', 'content_sha256',
    ];

    /**
     * The source IDs referenced anywhere in a content file, sorted.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public function usedSourceIds(array $content): array
    {
        $references = [];

        foreach ($content['lessons'] ?? [] as $lesson) {
            array_push($references, ...($lesson['source_reference'] ?? []));

            foreach ($lesson['questions'] ?? [] as $question) {
                array_push($references, ...($question['source_reference'] ?? []));
            }
        }

        foreach ($content['questions'] ?? [] as $question) {
            array_push($references, ...($question['source_reference'] ?? []));
        }

        $ids = [];

        foreach ($references as $reference) {
            if (is_array($reference) && is_string($reference['source_id'] ?? null)) {
                $ids[$reference['source_id']] = true;
            }
        }

        $ids = array_map('strval', array_keys($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The `sources` block for the given IDs, taken from the source registry.
     *
     * Unbekannte IDs werden übersprungen; die Erdungsprüfung meldet sie ohnehin.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    public function fromRegistry(array $registry, array $sourceIds): array
    {
        $sources = [];

        foreach ($sourceIds as $sourceId) {
            if (isset($registry[$sourceId])) {
                $sources[] = $this->transportable($registry[$sourceId]);
            }
        }

        return $sources;
    }

    /**
     * Reduce a source entry to its transportable fields, in a fixed order.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function transportable(array $source): array
    {
        $result = [];

        foreach (self::TRANSPORTABLE_FIELDS as $field) {
            $result[$field] = $source[$field] ?? null;
        }

        return $result;
    }
}
