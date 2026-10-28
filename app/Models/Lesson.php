<?php

namespace App\Models;

use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine Lektion hat kein `body`-Feld: der Inhalt ist die geordnete Liste
 * ihrer `blocks` (Konzept d). Auch keinen Slug — Routing läuft über den
 * Pfad-Slug.
 *
 * @property int $id
 * @property int $learning_path_id
 * @property string $title
 * @property int $position
 * @property list<array{source_id: string, page?: int, slide?: int, section?: string}>|null $source_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, LessonBlock> $blocks
 * @property-read Collection<int, Question> $questions
 * @property-read Collection<int, LessonCompletion> $completions
 * @property-read LearningPath|null $learningPath  null, wenn der Pfad soft-gelöscht ist
 */
#[Fillable(['learning_path_id', 'title', 'position', 'source_reference'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * Get the path this lesson belongs to.
     *
     * @return BelongsTo<LearningPath, $this>
     */
    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    /**
     * Get the content blocks of this lesson, in reading order.
     *
     * @return HasMany<LessonBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)->orderBy('position');
    }

    /**
     * Get the inline check questions of this lesson (Verwendung 1 aus
     * Konzept d), in display order.
     *
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /**
     * Get the completions apprentices recorded for this lesson.
     *
     * Basis aller Fortschrittsanzeigen: sie zählen live über einen
     * user-gefilterten Sub-Count, statt `path_progress` zu lesen — die
     * Tabelle bleibt ungenutzt (Sprint-5-Entscheidung 1).
     *
     * @return HasMany<LessonCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(LessonCompletion::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_reference' => 'array',
        ];
    }
}
