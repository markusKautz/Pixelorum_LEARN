<?php

namespace App\Models;

use App\Enums\QuestionType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine Frage aus dem wiederverwendbaren Pool. `learning_path_id` und
 * `lesson_id` sind bloße Herkunftsangaben; ist `lesson_id` gesetzt, wird die
 * Frage als Zwischen-Check an der Lektion gezeigt (Konzept d).
 *
 * @property int $id
 * @property int|null $learning_path_id
 * @property int|null $lesson_id
 * @property QuestionType $type
 * @property string $prompt
 * @property string $explanation Begründung zur korrekten Antwort (Konzept f)
 * @property list<array{source_id: string, page?: int, slide?: int, section?: string}>|null $source_reference
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, QuestionOption> $options
 * @property-read Collection<int, Quiz> $quizzes
 * @property-read LearningPath|null $learningPath
 * @property-read Lesson|null $lesson
 */
#[Fillable([
    'learning_path_id',
    'lesson_id',
    'type',
    'prompt',
    'explanation',
    'source_reference',
    'position',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /**
     * Filterwert der Lektions-Auswahl für „nur Fragen ohne Lektion“ — eine
     * Lektions-ID kann das nicht ausdrücken, `null` bedeutet bereits
     * „Filter aus“.
     */
    public const NO_LESSON = 'none';

    /**
     * Get the answer options of this question, in display order.
     *
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /**
     * Get answer options in a stable learner-facing order.
     *
     * @return Collection<int, QuestionOption>
     */
    public function optionsForDisplay(): Collection
    {
        $options = $this->options;

        if ($options->count() < 2) {
            return $options;
        }

        return $options->slice($this->id % $options->count())
            ->concat($options->take($this->id % $options->count()))
            ->values();
    }

    /**
     * Get the quizzes this question has been bundled into.
     *
     * @return BelongsToMany<Quiz, $this>
     */
    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_questions')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * Get the path this question originated from.
     *
     * @return BelongsTo<LearningPath, $this>
     */
    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    /**
     * Get the lesson this question is an inline check for.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Determine whether this question may sit in the final quiz of the given path.
     *
     * Nur Pool-Fragen des Pfades, also mit diesem `learning_path_id` und ohne
     * Lektion (DEV-15, E1). Gegenstück als Abfrage ist
     * {@see Quiz::questionsOutsidePoolOf()}.
     */
    public function isPoolQuestionOf(int $pathId): bool
    {
        return $this->learning_path_id === $pathId && $this->lesson_id === null;
    }

    /**
     * Normalise an incoming selection, or reject it as not belonging here.
     *
     * Die Auswahl kommt aus dem Client und wird deshalb serverseitig gegen
     * **diese** Frage geprüft (Sprint-4-Entscheidung 5): untergeschobene
     * Option-IDs fremder Fragen und eine zum Typ unpassende Anzahl werden
     * abgewiesen, nicht stillschweigend als „falsch" gewertet. Die Optionen
     * müssen dafür geladen sein.
     *
     * Die ersten drei Zeilen sind identisch mit `EvaluateAnswer::normalize()`
     * und bleiben es bewusst: dort machen sie zwei Mengen vergleichbar, hier
     * prüfen sie Eingaben ab. Neue Prüfregeln gehören hierher und dürfen
     * nicht dorthin wandern. `EvaluateAnswerTest` prüft, dass beide dieselbe
     * Normalform liefern.
     *
     * @param  array<int|string, int|string>  $selectedOptionIds
     * @return array<int, int>|null null, wenn die Auswahl nicht zur Frage passt
     */
    public function sanitizeSelection(array $selectedOptionIds): ?array
    {
        $selected = array_values(array_unique(array_map(intval(...), $selectedOptionIds)));

        sort($selected);

        if ($selected === []) {
            return null;
        }

        $ownIds = $this->options->map(fn (QuestionOption $option): int => $option->id)->all();

        if (array_diff($selected, $ownIds) !== []) {
            return null;
        }

        $expectsExactlyOne = $this->type === QuestionType::Single || $this->type === QuestionType::Boolean;

        if ($expectsExactlyOne && count($selected) !== 1) {
            return null;
        }

        return $selected;
    }

    /**
     * Scope a query to questions originating from the given path;
     * null lässt den Filter aus (Filterleiste in S3.4/S3.5).
     *
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function forPath(Builder $query, ?int $pathId): void
    {
        $query->when($pathId !== null, fn (Builder $query) => $query->where('learning_path_id', $pathId));
    }

    /**
     * Scope a query to the inline checks of the given lesson; null lässt den
     * Filter aus, self::NO_LESSON liefert die Pool-Fragen ohne Lektion.
     * Ein nicht-numerischer Wert läuft wie bei forPath() ins Leere statt in
     * eine Exception.
     *
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function forLesson(Builder $query, int|string|null $lesson): void
    {
        if (blank($lesson)) {
            return;
        }

        if ($lesson === self::NO_LESSON) {
            $query->whereNull('lesson_id');

            return;
        }

        $query->where('lesson_id', (int) $lesson);
    }

    /**
     * Scope a query to questions of the given type; null lässt den Filter aus.
     * Wird noch vom Fragen-Pool des Quiz-Builders benutzt (S3.5).
     *
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, ?QuestionType $type): void
    {
        $query->when($type !== null, fn (Builder $query) => $query->where('type', $type));
    }

    /**
     * Scope a query to questions whose prompt matches the given term.
     *
     * `whereLike` statt `where(..., 'like', ...)`: SQLites LIKE ist von Haus
     * aus schreibungsunabhängig, PostgreSQLs nicht. Wer im Fragenpool nach
     * „gehört" suchte, fand „Gehört zum Pfad?" lokal, in der Auslieferung aber
     * nicht — ein Fehler, der nur auf der Zielengine sichtbar war und den erst
     * der PostgreSQL-Lauf der CI zutage gefördert hat. `whereLike` übersetzt
     * das je Engine (`ilike` auf PostgreSQL, `like` auf SQLite).
     *
     * Ein Rest Unterschied bleibt: SQLite ignoriert die Schreibung nur bei
     * ASCII. „ÖFFNEN" findet lokal kein „öffnen", produktiv schon. Das ist
     * hinnehmbar — die Suche ist eine Admin-Bequemlichkeit, und die
     * Auslieferung ist die großzügigere Seite.
     *
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when(filled($term), function (Builder $query) use ($term) {
            $escaped = addcslashes(trim((string) $term), '%_\\');

            $query->whereLike('prompt', "%{$escaped}%");
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'source_reference' => 'array',
        ];
    }
}
