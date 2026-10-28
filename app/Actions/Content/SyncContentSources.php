<?php

namespace App\Actions\Content;

use App\Models\ContentSource;
use RuntimeException;

/**
 * Legt die Quellen eines `sources`-Blocks in `content_sources` an (DEV-3, E8).
 *
 * Eine vorhandene Quelle wird nie überschrieben. Weicht sie in einem
 * identitätsstiftenden Feld ab, wird der ganze Import abgewiesen: Eine
 * geänderte Quelle bekommt in der Content-Pipeline eine neue `source_id`,
 * und eine stille Überschreibung veränderte die Erdung bereits freigegebener
 * Pfade. Läuft innerhalb der Transaktion des Imports.
 */
class SyncContentSources
{
    /** @var list<string> */
    public const IDENTITY_FIELDS = ['kind', 'title', 'url', 'content_sha256'];

    /**
     * Create missing sources and verify existing ones.
     *
     * @param  list<array<string, mixed>>  $sources
     *
     * @throws RuntimeException wenn eine vorhandene Quelle abweicht
     */
    public function handle(array $sources): void
    {
        foreach ($sources as $source) {
            $sourceId = (string) $source['source_id'];
            $existing = ContentSource::where('source_id', $sourceId)->first();

            if ($existing === null) {
                ContentSource::create(array_intersect_key(
                    $source,
                    array_flip(CollectContentSources::TRANSPORTABLE_FIELDS),
                ));

                continue;
            }

            $stored = $existing->toTransportable();

            foreach (self::IDENTITY_FIELDS as $field) {
                if (($source[$field] ?? null) !== $stored[$field]) {
                    throw new RuntimeException(__('Import refused: source :id already exists with a different :field.', [
                        'id' => $sourceId,
                        'field' => $field,
                    ]));
                }
            }
        }
    }
}
