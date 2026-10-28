<?php

namespace App\Models;

use App\Enums\SourceKind;
use Database\Factories\ContentSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Lernquelle mit ihren transportierbaren Metadaten (DEV-3).
 *
 * Gegenstück zu einem Eintrag im Quellregister `sources.json`, aber ohne die
 * lokalen Pfade zu Extrakt und Quellkarte. Damit bleiben Quellenangaben in
 * Freigabe und Admin-Formular verständlich, auch ohne Quellenarchiv im Image.
 *
 * @property int $id
 * @property string $source_id
 * @property SourceKind $kind
 * @property string $title
 * @property string|null $publisher
 * @property string|null $url
 * @property string|null $version
 * @property Carbon|null $published_at
 * @property Carbon|null $accessed_at
 * @property string|null $content_sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'source_id',
    'kind',
    'title',
    'publisher',
    'url',
    'version',
    'published_at',
    'accessed_at',
    'content_sha256',
])]
class ContentSource extends Model
{
    /** @use HasFactory<ContentSourceFactory> */
    use HasFactory;

    /**
     * The source in the transportable shape of a content file's `sources` block.
     *
     * @return array{source_id: string, kind: string, title: string, publisher: string|null, url: string|null, version: string|null, published_at: string|null, accessed_at: string|null, content_sha256: string|null}
     */
    public function toTransportable(): array
    {
        return [
            'source_id' => $this->source_id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'publisher' => $this->publisher,
            'url' => $this->url,
            'version' => $this->version,
            'published_at' => $this->published_at?->format('Y-m-d'),
            'accessed_at' => $this->accessed_at?->format('Y-m-d'),
            'content_sha256' => $this->content_sha256,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'published_at' => 'date',
            'accessed_at' => 'date',
        ];
    }
}
