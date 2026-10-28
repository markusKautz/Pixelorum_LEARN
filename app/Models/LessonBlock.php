<?php

namespace App\Models;

use App\Enums\LessonBlockType;
use App\Rules\LessonBlockContent;
use Database\Factories\LessonBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Ein typisierter Inhaltsblock einer Lektion. Die Nutzlast in `content` ist
 * je `type` unterschiedlich geformt (Konzept d):
 *
 * - Markdown:    {"body": "markdown-text"}
 * - Accordion:   {"items": [{"heading": "…", "body": "markdown"}, …]}
 * - Callout:     {"variant": "info|warning|danger|highlight|exam", "body": "markdown"}
 * - CodeSnippet: {"language": "php|bash|js|…", "code": "…", "filename": null}
 * - Diagram:     {"format": "mermaid|image", "source": "mermaid-code ODER image-url", "caption": null}
 * - Media:       {"kind": "video|image", "url": "…", "caption": null, "alt": null}
 * - Glossary:    {"terms": [{"term": "…", "definition": "…"}, …]}
 *
 * Erzwungen werden die Shapes ab Sprint 3 durch die Block-Editor-Validierung;
 * die Factory liefert sie ab jetzt shape-konform.
 *
 * @property int $id
 * @property int $lesson_id
 * @property LessonBlockType $type
 * @property array<string, mixed> $content
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lesson $lesson
 */
#[Fillable(['lesson_id', 'type', 'content', 'position'])]
class LessonBlock extends Model
{
    /** @use HasFactory<LessonBlockFactory> */
    use HasFactory;

    /**
     * Get the lesson this block belongs to.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Whether the content passes the shape rule of its type. Neue Blöcke
     * starten leer (Sprint-3-Abweichung 5) — der Editor markiert solche
     * Blöcke als unvollständig, der Reader (S4.3) überspringt sie.
     */
    public function isComplete(): bool
    {
        return Validator::make(
            ['content' => $this->content],
            ['content' => [new LessonBlockContent($this->type)]],
        )->passes();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LessonBlockType::class,
            'content' => 'array',
        ];
    }
}
