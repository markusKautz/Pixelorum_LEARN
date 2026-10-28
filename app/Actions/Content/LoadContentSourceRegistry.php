<?php

namespace App\Actions\Content;

use App\Models\ContentSource;

/**
 * Liefert die Quellen aus `content_sources` im Format der Registry (E11).
 *
 * Gegenstück zu `LoadSourceRegistry` für alles, was zur Laufzeit läuft:
 * Admin-Formulare und Freigabe kennen in Produktion kein Quellenarchiv.
 * Die Einträge tragen keine Extrakt- oder Kartenpfade, deshalb gehört dazu
 * `SourceReferences` im Modus `metadataOnly`.
 */
class LoadContentSourceRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function handle(): array
    {
        return ContentSource::query()
            ->orderBy('source_id')
            ->get()
            ->mapWithKeys(fn (ContentSource $source): array => [$source->source_id => $source->toTransportable()])
            ->all();
    }
}
