<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Enums\ReviewStatus;
use Carbon\CarbonInterface;
use Database\Factories\LearningPathFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property array<int, string> $objectives
 * @property int $position
 * @property string|null $difficulty
 * @property int|null $estimated_minutes
 * @property int $pass_threshold
 * @property PathStatus $status
 * @property ReviewStatus $review_status
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Lesson> $lessons
 * @property-read Collection<int, Quiz> $quizzes
 * @property-read Quiz|null $finalQuiz
 * @property-read Collection<int, Question> $questions
 * @property-read Collection<int, LessonBlock> $lessonBlocks
 * @property-read Collection<int, QuestionOption> $questionOptions
 * @property-read User|null $verifier
 * @property-read Collection<int, Team> $teams
 */
#[Fillable([
    'title',
    'slug',
    'summary',
    'objectives',
    'position',
    'difficulty',
    'estimated_minutes',
    'pass_threshold',
    'status',
    'review_status',
])]
class LearningPath extends Model
{
    /** @use HasFactory<LearningPathFactory> */
    use GeneratesUniqueSlugs, HasFactory, SoftDeletes;

    /**
     * Die Relationen, deren jüngstes `updated_at` mit darüber entscheidet, ob
     * sich der Inhalt seit der Freigabe geändert hat — der Pfad selbst kommt
     * in `changedSinceVerification()` dazu.
     *
     * @var list<string>
     */
    protected const CONTENT_RELATIONS = ['lessons', 'lessonBlocks', 'questions', 'questionOptions'];

    /**
     * Attribute des Pfades, die den **Ablauf** steuern und nicht den Inhalt.
     *
     * Wer eines davon ändert, ändert nichts an dem, was der Lehrling liest.
     * `changedSinceVerification()` vergleicht aber das `updated_at` des Pfades
     * gegen `verified_at`, also würde schon das Veröffentlichen eines gerade
     * freigegebenen Pfades als „seit Freigabe geändert" erscheinen. Die
     * Schreibstellen für diese Attribute laufen deshalb in
     * `LearningPath::withoutTimestamps()`.
     *
     * @var list<string>
     */
    public const WORKFLOW_ATTRIBUTES = ['status', 'review_status', 'verified_at', 'verified_by'];

    /**
     * Spiegelt die DB-Defaults, damit die Enum-Casts auch auf frisch
     * erstellten Instanzen ohne refresh() greifen und ein create() ohne
     * objectives nicht an NOT NULL scheitert (Muster aus Sprint 1: User).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'objectives' => '[]',
        'position' => 0,
        'pass_threshold' => 80,
        'status' => PathStatus::Draft->value,
        'review_status' => ReviewStatus::Unreviewed->value,
    ];

    /**
     * Bootstrap the model and its traits.
     *
     * Anders als bei Team wird der Slug **nicht** bei Titeländerungen
     * erneuert: er ist der Route-Key der Lehrlinge (geteilte Links sollen
     * gültig bleiben) und hält Export und Upload derselben Datei
     * zusammen. Wer den Slug ändern will, setzt ihn explizit (er ist
     * fillable).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (LearningPath $path) {
            if (empty($path->slug)) {
                $path->slug = static::generateUniqueSlug($path->title, $path->id);
            }
        });
    }

    /**
     * Get the lessons of this path, in reading order.
     *
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    /**
     * Get the quizzes belonging to this path.
     *
     * @return HasMany<Quiz, $this>
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Get the final quiz of this path, if one exists.
     *
     * HasOne statt HasMany-Filter: die Sprint-3-Validierung lässt höchstens
     * ein Abschluss-Quiz je Pfad zu, und so ist die Relation eager-ladbar
     * (`with('finalQuiz')`) statt je Karte einzeln abgefragt.
     *
     * @return HasOne<Quiz, $this>
     */
    public function finalQuiz(): HasOne
    {
        return $this->hasOne(Quiz::class)->where('kind', QuizKind::PathFinal);
    }

    /**
     * Get the pool questions originating from this path, in pool order.
     *
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /**
     * Get every content block of this path, across all of its lessons.
     *
     * Nur zum Aggregieren gedacht (siehe `changedSinceVerification()`), nicht
     * zum Anzeigen — die Blöcke einer Lektion holt man über `$lesson->blocks`.
     *
     * @return HasManyThrough<LessonBlock, Lesson, $this>
     */
    public function lessonBlocks(): HasManyThrough
    {
        return $this->hasManyThrough(LessonBlock::class, Lesson::class);
    }

    /**
     * Get every answer option of this path's questions.
     *
     * Reicht wie `questions()` nur so weit wie die Spalte
     * `questions.learning_path_id`: eine Frage ohne Pfadbezug bleibt außen vor.
     *
     * @return HasManyThrough<QuestionOption, Question, $this>
     */
    public function questionOptions(): HasManyThrough
    {
        return $this->hasManyThrough(QuestionOption::class, Question::class);
    }

    /**
     * Get the admin who verified this path against its sources (Konzept e).
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the groups this path is limited to (#9).
     *
     * Leer heißt „für keine Gruppe“ (E12): ein Pfad wird erst mit einer
     * Zuweisung für Lernende sichtbar. Betriebsdaten der Installation, deshalb nicht Teil von Export und
     * Import (E11).
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'learning_path_team')->withTimestamps();
    }

    /**
     * Scope a query to the paths apprentices may see.
     *
     * Grounding-Gate (Konzept e): ein Pfad wird erst sichtbar, wenn er
     * veröffentlicht *und* gegen die Quelle geprüft ist.
     *
     * @param  Builder<LearningPath>  $query
     */
    #[Scope]
    protected function visibleToLearners(Builder $query): void
    {
        $query->where('status', PathStatus::Published)
            ->where('review_status', ReviewStatus::Verified);
    }

    /**
     * Scope a query to the paths this apprentice may see (#9).
     *
     * Das Grounding-Gate aus {@see self::visibleToLearners()} und dazu die
     * Gruppenregel (E12): sichtbar nur für die zugewiesenen Gruppen, ohne
     * Zuweisung für niemanden. Persönliche Teams sind keine Lehrlingsgruppen und
     * taugen nicht als Zugang (wie {@see Quiz::assignedTo()}). Entzieht man
     * eine Zuweisung, bleibt der Fortschritt erhalten, der Pfad ist nur nicht
     * mehr sichtbar (E9).
     *
     * @param  Builder<LearningPath>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $learner): void
    {
        $query->visibleToLearners()
            ->whereHas('teams', fn (Builder $teams) => $teams
                ->where('teams.is_personal', false)
                ->whereIn('teams.id', $learner->teams()->select('teams.id')));
    }

    /**
     * Scope a query to load each path's lesson count alongside how many of
     * those lessons the given learner has finished.
     *
     * Ein gefilterter Sub-Count statt einer Zählung je Pfad — je Karte oder
     * Zeile einzeln zu zählen wäre der N+1-Klassiker. Der steht hier am
     * Modell, weil ihn zwei Stellen brauchen (Lehrling-Report und
     * Lern-Dashboard) und die verschachtelten Closures beim Abschreiben leicht
     * verrutschen; der Gruppen-Report zählt bewusst anders und nimmt nur
     * `withCount('lessons')`.
     *
     * @param  Builder<LearningPath>  $query
     */
    #[Scope]
    protected function withLessonProgressFor(Builder $query, User $learner): void
    {
        $query->withCount([
            'lessons',
            'lessons as completed_lessons_count' => fn (Builder $lessons) => $lessons->whereHas(
                'completions',
                fn (Builder $completions) => $completions->where('user_id', $learner->id),
            ),
        ]);
    }

    /**
     * Scope a query to eager-load only a final quiz apprentices could take.
     *
     * „Unveröffentlicht zählt wie kein Quiz": ein Entwurf kann von niemandem
     * abgelegt werden, darf also weder einen Pfad offen halten noch eine Quote
     * drücken. Beide Auswertungen setzen die Regel als Filter. Das
     * Lern-Dashboard beantwortet dieselbe Frage über `can('view', $quiz)` und
     * lädt deshalb bewusst ungefiltert — gleiche Regel, andere Stelle.
     *
     * @param  Builder<LearningPath>  $query
     */
    #[Scope]
    protected function withPlayableFinalQuiz(Builder $query): void
    {
        $query->with(['finalQuiz' => fn ($finalQuiz) => $finalQuiz->where('status', PathStatus::Published)]);
    }

    /**
     * Determine whether apprentices may see this path — die instanzseitige
     * Entsprechung zum visibleToLearners-Scope.
     */
    public function isVisibleToLearners(): bool
    {
        return $this->status === PathStatus::Published
            && $this->review_status === ReviewStatus::Verified;
    }

    /**
     * Determine whether this apprentice may see the path — die
     * instanzseitige Entsprechung zum visibleTo-Scope.
     */
    public function isVisibleTo(User $learner): bool
    {
        if (! $this->isVisibleToLearners()) {
            return false;
        }

        return $this->teams()
            ->where('teams.is_personal', false)
            ->whereIn('teams.id', $learner->teams()->select('teams.id'))
            ->exists();
    }

    /**
     * Determine whether the content changed after the verification
     * (Entscheidung 8, Sprint 3): jüngstes updated_at über Pfad, Lektionen,
     * Blöcke sowie Fragen/Optionen des Pfades liegt nach verified_at. Kein
     * Auto-Invalidieren — nur Grundlage für das Hinweis-Badge
     * „seit Freigabe geändert".
     *
     * Die vier Maxima kommen aus Aggregat-Subselects, nicht aus vier eigenen
     * Abfragen. Wer die Methode über eine Liste laufen lässt, lädt sie mit
     * `withContentTimestamps()` gleich mit — sonst holt `loadMax()` sie hier
     * in **einer** Abfrage nach. Vorher stand an dieser Stelle ein
     * Aggregat-N+1: vier Queries je Zeile, in der Pfadliste also gut hundert
     * (S3-N1). Die Rechenregel steht trotzdem nur einmal da — vorgeladen oder
     * nachgeladen unterscheidet sich allein darin, *wann* die Subselects
     * laufen.
     */
    public function changedSinceVerification(): bool
    {
        if ($this->verified_at === null) {
            return false;
        }

        // Die vier Attribute entstehen immer gemeinsam; eines zu prüfen genügt.
        if (! array_key_exists(self::contentTimestampAttribute(self::CONTENT_RELATIONS[0]), $this->attributes)) {
            $this->loadMax(self::CONTENT_RELATIONS, 'updated_at');
        }

        $latestChange = collect(self::CONTENT_RELATIONS)
            ->map(fn (string $relation): mixed => $this->getAttribute(self::contentTimestampAttribute($relation)))
            ->push($this->updated_at)
            ->filter()
            // CarbonInterface, nicht Carbon: `Date` ist im Projekt auf
            // CarbonImmutable eingestellt.
            ->map(fn (mixed $timestamp): CarbonInterface => Date::parse($timestamp))
            ->max();

        return $latestChange instanceof CarbonInterface
            && $latestChange->greaterThan($this->verified_at);
    }

    /**
     * Eager-load the timestamps `changedSinceVerification()` compares.
     *
     * @param  Builder<LearningPath>  $query
     */
    #[Scope]
    protected function withContentTimestamps(Builder $query): void
    {
        $query->withMax(self::CONTENT_RELATIONS, 'updated_at');
    }

    /**
     * Der Attributname, unter dem `withMax`/`loadMax` das Maximum ablegt.
     */
    protected static function contentTimestampAttribute(string $relation): string
    {
        return Str::snake($relation).'_max_updated_at';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'status' => PathStatus::class,
            'review_status' => ReviewStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
