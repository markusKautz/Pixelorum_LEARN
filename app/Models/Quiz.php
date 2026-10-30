<?php

namespace App\Models;

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Policies\QuizPolicy;
use Database\Factories\QuizFactory;
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
use InvalidArgumentException;

/**
 * Ein Quiz bündelt beliebige Fragen aus dem Pool (Konzept d).
 *
 * `status` nutzt bewusst PathStatus: das Konzept nennt keinen eigenen
 * Quiz-Status-Enum und die Werte sind identisch (draft/published).
 *
 * **Invariante:** `learning_path_id` gesetzt ⇔ `kind = path_final`. Der Pfad
 * ist das Merkmal des Abschluss-Quiz; ein freies Quiz bündelt Fragen
 * ausdrücklich pfadübergreifend und trägt deshalb keinen. Hinge doch einer
 * dran, legte QuizPolicy::view() das Grounding-Gate dieses Pfads über ein
 * Quiz, das gar nicht dazugehört. Erzwungen wird die Invariante
 * ausschließlich vom saving-Guard unten — es gibt keinen DB-CHECK.
 * Schreibende Stellen dürfen den Guard deshalb nicht umgehen: kein
 * `saveQuietly()`, kein `Model::withoutEvents()`, keine Seeder mit
 * `WithoutModelEvents`; sonst droht ein stiller Invarianten-Bruch.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property QuizKind $kind
 * @property int|null $learning_path_id
 * @property int $pass_threshold
 * @property bool $shuffle_questions
 * @property int|null $created_by
 * @property PathStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Question> $questions
 * @property-read Collection<int, QuizAttempt> $attempts
 * @property-read Collection<int, Team> $teams
 * @property-read LearningPath|null $learningPath
 * @property-read User|null $creator
 */
/*
 * `created_by` steht bewusst in der Liste, obwohl es **nie** aus Nutzereingabe
 * stammt: die schreibenden Stellen setzen es aus `Auth::id()` (Admin legt an)
 * bzw. `$learner->id` (BuildPracticeQuiz). Beide bauen explizite Arrays aus
 * validierten Feldern — kein Pfad reicht eine Request-Payload durch.
 *
 * Es fillable zu lassen ist kein Versehen, sondern nötig: BuildPracticeQuiz
 * legt den Übungsbehälter per `firstOrCreate()` an, und das füllt die
 * Übereinstimmungs-Attribute über `fill()`. Wäre `created_by` nicht fillable,
 * fiele es beim Anlegen still heraus — der Behälter bekäme `created_by = null`
 * und wäre damit für niemanden mehr sichtbar, weil QuizPolicy den Practice-Arm
 * genau an dieser Spalte festmacht. Ein stiller Datenfehler statt eines
 * Fehlers.
 *
 * **Falle für die nächste Überarbeitung:** ein zusätzliches
 * `protected $guarded = ['created_by']` wäre wirkungslos. Laravels
 * `isFillable()` gibt `true` zurück, sobald der Schlüssel in der
 * Fillable-Liste steht, und erreicht `$guarded` gar nicht erst. Die Zeile
 * suggerierte Schutz, den es nicht gibt.
 *
 * Die tatsächliche Absicherung sitzt woanders: beide Quiz-Formulare validieren
 * `kind` über `Rule::enum(...)->only(QuizKind::editorialCases())`. Ein
 * manipulierter Payload kann also gar kein `practice`-Quiz erzeugen — und ohne
 * `kind = practice` greift der Policy-Arm nicht, für den `created_by` das
 * Autorisierungsmerkmal ist.
 */
#[Fillable([
    'title',
    'description',
    'kind',
    'learning_path_id',
    'pass_threshold',
    'shuffle_questions',
    'created_by',
    'status',
])]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    /**
     * Spiegelt den DB-Default, damit `shuffle_questions` auch auf einem noch
     * nicht neu geladenen Quiz false statt null ist (Muster aus Team).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'shuffle_questions' => false,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Quiz $quiz) {
            if ($quiz->kind === QuizKind::PathFinal && $quiz->learning_path_id === null) {
                throw new InvalidArgumentException(
                    'Ein Abschluss-Quiz (kind = path_final) muss einem Lernpfad zugeordnet sein.'
                );
            }

            // Alles außer path_final ist pfadlos — freie Quizze bündeln
            // ausdrücklich pfadübergreifend, Übungsquizze ziehen quer durch
            // die gewählten Pfade.
            if ($quiz->kind !== QuizKind::PathFinal && $quiz->learning_path_id !== null) {
                throw new InvalidArgumentException(
                    "Ein Quiz der Art „{$quiz->kind->value}\" darf keinem Lernpfad zugeordnet sein."
                );
            }
        });
    }

    /**
     * Scope a query to the quizzes an admin curates.
     *
     * Übungsquizze sind persönliche Behälter der Teilnehmer_innen (Sprint 5.5): sie
     * gehören weder in Redaktionslisten noch in Verwendungs-Zähler („diese
     * Frage steckt in N Quizzen"), sonst zählte jede Übungssitzung mit.
     *
     * @param  Builder<Quiz>  $query
     */
    #[Scope]
    protected function editorial(Builder $query): void
    {
        $query->whereIn(
            'kind',
            collect(QuizKind::cases())->filter->isEditorial()->pluck('value'),
        );
    }

    /**
     * Scope a query to the free quizzes handed out to this apprentice's groups.
     *
     * Die Regel „welche freien Quizze gehören dieser Teilnehmer_in" steht damit an
     * **einer** Stelle: die Üben-Seite listet sie, die Sidebar zählt die noch
     * offenen. Persönliche Teams sind keine Gruppen und taugen
     * deshalb nicht als Zugang.
     *
     * @param  Builder<Quiz>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, User $learner): void
    {
        $query->where('kind', QuizKind::Custom)
            ->where('status', PathStatus::Published)
            ->whereHas('teams', fn (Builder $teams) => $teams
                ->where('teams.is_personal', false)
                ->whereIn('teams.id', $learner->teams()->select('teams.id')));
    }

    /**
     * Get the questions of this quiz, in the configured order.
     *
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'quiz_questions')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Get the questions of this quiz that may not sit in a final quiz of the given path.
     *
     * Ein Abschluss-Quiz enthält nur Pool-Fragen seines Pfades (DEV-15, E1):
     * keine Fragen anderer Pfade, keine freien Fragen und keine Zwischen-Checks,
     * die zählen nach Konzept f nicht in die Wertung. Genau diese Fragen kann
     * `question_positions` im Content-JSON adressieren. Gegenstück für eine
     * einzelne Frage ist {@see Question::isPoolQuestionOf()}.
     *
     * @return BelongsToMany<Question, $this>
     */
    public function questionsOutsidePoolOf(int $pathId): BelongsToMany
    {
        return $this->questions()->where(fn (Builder $query) => $query
            ->where('questions.learning_path_id', '!=', $pathId)
            ->orWhereNull('questions.learning_path_id')
            ->orWhereNotNull('questions.lesson_id'));
    }

    /**
     * Get the questions that break the pool rule of this final quiz; empty for any other quiz.
     *
     * Ohne DB-Constraint (die Regel läuft über zwei Tabellen) kann Altbestand
     * aus der Zeit vor DEV-15 dagegen verstoßen; der Quiz-Editor zeigt ihn an.
     *
     * @return BelongsToMany<Question, $this>
     */
    public function questionsOutsidePathPool(): BelongsToMany
    {
        if ($this->kind !== QuizKind::PathFinal || $this->learning_path_id === null) {
            return $this->questions()->whereRaw('1 = 0');
        }

        return $this->questionsOutsidePoolOf($this->learning_path_id);
    }

    /**
     * Get the groups this quiz has been handed out to (Sprint 5.6).
     *
     * Nur für freie Quizze belegt: das Abschluss-Quiz erbt seine Zielgruppe
     * vom Pfad, das Übungsquiz gehört einer einzelnen Teilnehmer_in. Die Zuweisung
     * ist zugleich das Sichtbarkeits-Gate ({@see QuizPolicy}).
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'quiz_team')->withTimestamps();
    }

    /**
     * Get the path this quiz concludes (only set for path_final quizzes).
     *
     * @return BelongsTo<LearningPath, $this>
     */
    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    /**
     * Get the admin who created this quiz.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attempts apprentices made on this quiz.
     *
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Determine whether the given score passes this quiz.
     *
     * Ganzzahl-Arithmetik statt Float-Rundung (Sprint-4-Entscheidung 7):
     * `floor(score/max*100) >= threshold` ist äquivalent zu
     * `score*100 >= threshold*max` und kommt ohne Rundung aus. Mit
     * gerundeten Prozenten könnte sonst „80 %" neben „nicht bestanden"
     * stehen (79,6 % auf 80 gerundet).
     */
    public function isPassing(int $score, int $maxScore): bool
    {
        if ($maxScore <= 0) {
            return false;
        }

        return $score * 100 >= $this->pass_threshold * $maxScore;
    }

    /**
     * Get the lowest score that still passes this quiz — die Bestehensregel
     * im Klartext („7 von 8 Fragen"), Umkehrung von isPassing().
     */
    public function passingScore(int $maxScore): int
    {
        if ($maxScore <= 0) {
            return 0;
        }

        return intdiv($this->pass_threshold * $maxScore + 99, 100);
    }

    /**
     * Get the achieved percentage of the given score, floored (Entscheidung 7).
     *
     * Static, anders als `isPassing()` und `passingScore()` daneben — und das
     * bleibt so (S4-N3). Die beiden lesen `$this->pass_threshold` und brauchen
     * deshalb ein Quiz; diese Division braucht keines. Sie zur Instanzmethode
     * zu vereinheitlichen, hätte einen Preis: `GroupProgressReport` rechnet
     * über Aggregatzeilen einer GROUP-BY-Abfrage, in denen gar kein Quiz
     * steckt. Wer die Prozente eines Versuchs will, nimmt
     * {@see QuizAttempt::$percent}.
     */
    public static function percentOf(int $score, int $maxScore): int
    {
        return $maxScore > 0 ? intdiv($score * 100, $maxScore) : 0;
    }

    /**
     * Determine whether an attempt on this quiz must freeze its question list
     * in `quiz_attempts.question_order` (Sprint 5.5).
     *
     * Nötig, sobald die Reihenfolge oder die Fragenmenge nicht mehr allein aus
     * dem Pivot ableitbar ist. Zwei unabhängige Gründe:
     * - **gemischt:** die Reihenfolge wäre nach jedem Reload eine andere;
     * - **Übungsquiz:** die nächste Übungssitzung **ersetzt** den Pivot, alte
     *   Versuche würden sonst rückwirkend gegen fremde Fragen gewertet.
     *
     * Ungemischte Abschluss-Quizze frieren bewusst nicht ein — sie behalten
     * das Sprint-4-Verhalten und ihre Versuche bleiben ohne gespeicherte Liste.
     */
    public function freezesQuestionOrder(): bool
    {
        return $this->shuffle_questions || $this->kind === QuizKind::Practice;
    }

    /**
     * Determine whether this quiz reports a pass/fail verdict.
     *
     * Übungsquizze nicht (Entscheidung E2): reines Üben soll nicht bestrafen —
     * „6 von 8 richtig" mit Erklärungen genügt, ein „nicht bestanden" davor
     * macht aus der Übung eine Prüfung (Merkmal 3).
     */
    public function reportsVerdict(): bool
    {
        return $this->kind !== QuizKind::Practice;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => QuizKind::class,
            'shuffle_questions' => 'boolean',
            'status' => PathStatus::class,
        ];
    }
}
