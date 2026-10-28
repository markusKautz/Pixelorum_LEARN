<?php

namespace App\Actions\Analytics;

use App\Data\Analytics\LearnerReport;
use App\Data\Analytics\PathProgressRow;
use App\Data\Analytics\WeakSpot;
use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Die Auswertung eines einzelnen Lehrlings — Pfad-Status, Quiz-Historie und
 * Schwachstellen, **live** aus `lesson_completions`, `quiz_attempts` und
 * `question_responses` gerechnet (Sprint-5-Entscheidung 1: kein Schreiben in
 * `path_progress`).
 *
 * Bewusst **eine** Klasse für zwei Zielgruppen: Der Admin-Drilldown (S5.3)
 * zeigt davon die schlanke Diagnose-Sicht, „Mein Fortschritt" (S5.4) die
 * volle Übe-Sicht. Zwei Reports würden früher oder später zwei Wahrheiten
 * über denselben Lehrling erzeugen.
 *
 * Der Abfrage-Aufwand ist konstant: alles läuft über wenige `GROUP BY`s, deren
 * Ergebnisse nach ID indiziert und in PHP verrechnet werden — nichts wächst
 * mit der Zahl der Pfade (Entscheidung 9).
 */
class LearnerProgressReport
{
    /**
     * Wie viele Schwachstellen die Auswertungen zeigen.
     *
     * Ein harter Deckel, kein Aufklappen: die Liste ist nach Fehlerquote
     * sortiert, die Plätze dahinter sind der lange Schwanz. Eine handhabbare
     * nächste Aufgabe fördert (Merkmal 7), eine Wand aus Fehlern liest sich
     * als Urteil (Merkmal 3) — für den Lehrling wie für den Ausbildner, der
     * vor einem Gespräch die wiederkehrenden Muster braucht, nicht jede
     * einzelne Fehlantwort.
     */
    public const TOP_WEAK_SPOTS = 10;

    /**
     * Abklingfaktor λ für die zeitlich gewichtete Fehlerquote (O1).
     *
     * Jüngere Antworten wiegen schwerer: Antwort Nummer n (jüngste = 1) trägt
     * λ^(n−1). λ = 0,7 bedeutet, dass die jüngste Antwort allein ~30 % der
     * Gesamtmasse ausmacht, während eine vor sechs Wochen falsche Antwort auf
     * unter 6 % fällt.
     */
    public const DECAY_FACTOR = 0.7;

    public function __construct(protected ComputePathProgress $computePathProgress)
    {
        //
    }

    /**
     * Build the full report for the given apprentice.
     */
    public function handle(User $learner): LearnerReport
    {
        $rows = $this->pathProgress($learner);
        $pathIds = array_keys($rows);

        return new LearnerReport(
            learner: $learner,
            paths: array_values($rows),
            attempts: $this->finalQuizAttempts($learner, $pathIds),
            // Begrenzt schon in SQL: ohne Limit lud der Report auch dann alle
            // Schwachstellen samt Fragen und Optionen, wenn nur zehn davon
            // angezeigt werden.
            weakSpots: $this->weakSpots($learner, limit: self::TOP_WEAK_SPOTS),
            lastActivityAt: $this->latestOf(
                array_map(fn (PathProgressRow $row): ?Carbon => $row->lastActivityAt, $rows),
            ),
        );
    }

    /**
     * Der Fortschritt des Lehrlings auf allen für ihn sichtbaren Pfaden,
     * **nach Pfad-ID indiziert**.
     *
     * Öffentlich, weil außer `handle()` inzwischen zwei weitere Verbraucher
     * dieselben Zeilen brauchen: die Karten des Lehrling-Dashboards und
     * `ResolveContinuationPoint` (F5). Vorher beantwortete das Dashboard „ist
     * dieser Pfad fertig?" mit eigenen Mitteln und kam zu einer anderen
     * Antwort als `/my-progress` — die Beanstandung „steht immer noch
     * ‚Fortsetzen'" war genau dieser Unterschied.
     *
     * @return array<int, PathProgressRow>
     */
    public function pathProgress(User $learner): array
    {
        $paths = $this->visiblePaths($learner);
        $pathIds = $paths->modelKeys();

        $quizStats = $this->finalQuizStats($learner, $pathIds);
        $lessonActivity = $this->lessonActivity($learner, $pathIds);

        return $paths->mapWithKeys(function (LearningPath $path) use ($quizStats, $lessonActivity): array {
            $stats = $quizStats[$path->id] ?? null;

            return [$path->id => $this->computePathProgress->handle(
                path: $path,
                totalLessons: (int) $path->lessons_count,
                completedLessons: (int) $path->getAttribute('completed_lessons_count'),
                hasQuiz: $path->finalQuiz !== null,
                quizPassed: $stats['passed'] ?? false,
                completedAttempts: $stats['attempts'] ?? 0,
                lastActivityAt: $this->latestOf([
                    $lessonActivity[$path->id] ?? null,
                    $stats['last_activity_at'] ?? null,
                ]),
            )];
        })->all();
    }

    /**
     * Die für Lehrlinge freigegebenen Pfade samt eigenem Lektions-Zähler.
     *
     * Der gefilterte Sub-Count hält beides bei **einer** Abfrage — je Pfad
     * einzeln zu zählen wäre der N+1-Klassiker. Ein unveröffentlichtes
     * Abschluss-Quiz wird wie „kein Quiz" behandelt: der Lehrling kann es
     * nicht ablegen, es darf ihm den Pfad also nicht offen halten.
     *
     * @return EloquentCollection<int, LearningPath>
     */
    protected function visiblePaths(User $learner): EloquentCollection
    {
        return LearningPath::query()
            ->visibleToLearners()
            ->withLessonProgressFor($learner)
            ->withPlayableFinalQuiz()
            ->orderBy('position')
            ->get();
    }

    /**
     * Bestanden-Flag, Versuchszahl und letzte Quiz-Aktivität je Pfad — eine
     * Abfrage.
     *
     * Gewertet wird nur über **abgeschlossene** Versuche; „letzte Aktivität"
     * zählt dagegen auch einen noch laufenden (Entscheidung 5:
     * `max(quiz_attempts.updated_at)`). Die bedingte Aggregation hält beide
     * Semantiken in derselben Abfrage.
     *
     * @param  array<int, int|string>  $pathIds
     * @return array<int, array{passed: bool, attempts: int, last_activity_at: string|null}>
     */
    protected function finalQuizStats(User $learner, array $pathIds): array
    {
        if ($pathIds === []) {
            return [];
        }

        $rows = DB::table('quiz_attempts')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->where('quiz_attempts.user_id', $learner->id)
            ->where('quizzes.status', PathStatus::Published->value)
            ->whereIn('quizzes.learning_path_id', $pathIds)
            ->groupBy('quizzes.learning_path_id')
            ->select('quizzes.learning_path_id')
            // Beide CASE-Zweige liefern eine Zahl. Die frühere Form
            // (`… THEN 0 ELSE quiz_attempts.passed END`) mischte Integer und
            // Boolean — PostgreSQL lehnt das ab („CASE types integer and
            // boolean cannot be matched"), SQLite nicht.
            ->selectRaw('MAX(CASE WHEN quiz_attempts.completed_at IS NOT NULL AND quiz_attempts.passed THEN 1 ELSE 0 END) as ever_passed')
            ->selectRaw('SUM(CASE WHEN quiz_attempts.completed_at IS NULL THEN 0 ELSE 1 END) as attempts_count')
            ->selectRaw('MAX(quiz_attempts.updated_at) as last_attempt_at')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $stats[(int) $values['learning_path_id']] = [
                'passed' => (bool) $values['ever_passed'],
                'attempts' => (int) $values['attempts_count'],
                'last_activity_at' => is_string($values['last_attempt_at']) ? $values['last_attempt_at'] : null,
            ];
        }

        return $stats;
    }

    /**
     * Jüngste eigene Lektions-Erledigung je Pfad — eine Abfrage.
     *
     * @param  array<int, int|string>  $pathIds
     * @return array<int, string>
     */
    protected function lessonActivity(User $learner, array $pathIds): array
    {
        if ($pathIds === []) {
            return [];
        }

        $rows = DB::table('lesson_completions')
            ->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
            ->where('lesson_completions.user_id', $learner->id)
            ->whereIn('lessons.learning_path_id', $pathIds)
            ->groupBy('lessons.learning_path_id')
            ->select('lessons.learning_path_id')
            ->selectRaw('MAX(lesson_completions.completed_at) as last_completed_at')
            ->get();

        $activity = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            if (is_string($values['last_completed_at'])) {
                $activity[(int) $values['learning_path_id']] = $values['last_completed_at'];
            }
        }

        return $activity;
    }

    /**
     * Die abgeschlossenen eigenen Versuche, jüngster zuerst — zwei Quellen:
     * die Abschluss-Quizze der sichtbaren Pfade und die freien Quizze
     * (Sprint 5.6).
     *
     * **Freie Quizze werden bewusst *nicht* über die aktuelle Zuweisung
     * gefiltert.** Diese Liste ist eine Leistungshistorie, keine Aufgabenliste:
     * zieht der Ausbildner ein Quiz von der Gruppe zurück, bleiben die bereits
     * abgelegten Versuche sichtbar — sonst löschte ein Klick optisch geleistete
     * Arbeit. Wer sehen will, was *aktuell* ausgegeben ist, liest den Block
     * „Ausgegebene Quizze" der Gruppen-Auswertung; der filtert weiterhin über
     * die Zuweisung, weil er die andere Frage beantwortet.
     *
     * **Für Pfade gilt bewusst die andere Regel** (Auftraggeber, 2026-07-25):
     * ein zurückgezogener Lernpfad ist nicht mehr in der Wertung, seine
     * Abschluss-Versuche verschwinden also mit ihm.
     *
     * Übungsquizze bleiben draußen: sie sind Selbststudium, kein Ergebnis, das
     * jemandem vorgelegt wird — ihre Wirkung zeigt sich in den Schwachstellen.
     *
     * @param  array<int, int|string>  $pathIds
     * @return EloquentCollection<int, QuizAttempt>
     */
    protected function finalQuizAttempts(User $learner, array $pathIds): EloquentCollection
    {
        return QuizAttempt::query()
            ->where('user_id', $learner->id)
            ->whereNotNull('completed_at')
            ->whereHas('quiz', fn (Builder $query) => $query
                ->where('status', PathStatus::Published)
                // Veröffentlicht UND (freies Quiz ODER Abschluss-Quiz eines
                // sichtbaren Pfads). Der unbedingte Arm steht bewusst vorn: so
                // ist die erste Klausel der Gruppe immer ein `where` und der
                // bedingte ein `orWhere`. Andersherum stünde bei leerem
                // $pathIds ein `orWhere` als erste Klausel — korrekt, weil
                // Laravel das führende OR verwirft, aber unnötig rätselhaft.
                ->where(function (Builder $visible) use ($pathIds): void {
                    $visible->where('kind', QuizKind::Custom);

                    if ($pathIds !== []) {
                        $visible->orWhereIn('learning_path_id', $pathIds);
                    }
                }))
            ->with('quiz.learningPath')
            ->latest('completed_at')
            ->get();
    }

    /**
     * Die wiederholt falsch beantworteten Fragen, absteigend nach
     * gewichteter Fehlerquote.
     *
     * Gezählt wird über **abgeschlossene** Versuche des Lehrlings — ein
     * laufender Versuch soll nicht schon während des Quiz als Schwachstelle
     * erscheinen. Übungs-Versuche zählen ausdrücklich mit (Sprint-5.5-E3):
     * mehr Daten ergeben die bessere Diagnose.
     *
     * Der Wiederholen-Link zeigt auf das Quiz des jüngsten falschen Versuchs,
     * dabei aber **nur** auf redaktionelle Quizze: der persönliche
     * Übungsbehälter trägt bei jeder Sitzung eine andere Fragenauswahl, ein
     * Link dorthin führte also zu irgendwelchen Fragen statt zu dieser. Die
     * bedingte Aggregation liefert ihn in derselben Abfrage wie die Zähler.
     *
     * Öffentlich und einzeln aufrufbar, damit die Dashboard-Karte (S5.4) ihre
     * Top 3 holen kann, ohne den ganzen Report zu rechnen — Pfad-Aggregate und
     * Versuchsliste braucht sie nicht.
     *
     * @param  int|null  $limit  begrenzt bereits die Abfrage, nicht erst das Ergebnis
     * @return array<int, WeakSpot>
     */
    public function weakSpots(User $learner, ?int $limit = null): array
    {
        $decay = self::DECAY_FACTOR;

        /*
         * Jüngste Antwort zuerst, dann die Reihenfolge im Versuch als
         * stabilisierendes Kriterium. Die Zeilennummer ist der Exponent für
         * λ^(n−1), beginnend bei 1 für die jüngste Antwort.
         */
        $ranked = DB::table('question_responses')
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'question_responses.quiz_attempt_id')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('questions', 'questions.id', '=', 'question_responses.question_id')
            ->where('quiz_attempts.user_id', $learner->id)
            ->whereNotNull('quiz_attempts.completed_at')
            /*
             * Ein zurückgezogener Pfad ist nicht mehr in der Wertung
             * (Auftraggeber, 2026-07-25) — samt der Schwachstellen, die aus
             * seinen Fragen stammen. Sonst zeigte die Auswertung Lücken zu
             * Stoff, den der Lehrling gerade gar nicht bearbeiten soll, und
             * der Wiederholen-Knopf liefe in einen 403.
             *
             * Fragen **ohne** Herkunftspfad bleiben drin: `learning_path_id`
             * ist laut Konzept d) eine bloße Herkunftsangabe und darf leer
             * sein — so eine Frage hat keinen Pfad, der zurückgezogen sein
             * könnte.
             */
            ->where(fn (QueryBuilder $origin) => $origin
                ->whereNull('questions.learning_path_id')
                ->orWhereIn(
                    'questions.learning_path_id',
                    LearningPath::query()->visibleToLearners()->select('id'),
                ))
            ->select('question_responses.question_id')
            ->selectRaw('question_responses.is_correct')
            ->selectRaw('quizzes.kind as quiz_kind')
            ->selectRaw('quiz_attempts.id as attempt_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY question_responses.question_id ORDER BY quiz_attempts.completed_at DESC, question_responses.id DESC) as rn');

        $rows = DB::query()->fromSub($ranked, 'ranked')
            ->select('question_id')
            ->selectRaw('COUNT(*) as asked_count')
            ->selectRaw('SUM(CASE WHEN is_correct = false THEN 1 ELSE 0 END) as wrong_count')
            /*
             * Rangfolge nach zeitgewichteter Fehlerquote:
             * (Σ gewicht·falsch + 1) / (Σ gewicht + 2). Die Laplace-Glättung
             * bleibt auf dem gewichteten Zähler/Nenner stehen, damit eine
             * einzelne kürzliche Fehlantwort nicht eine echte, längst
             * beherrschte Wissenslücke überholt. Jüngere Antworten drücken
             * bzw. heben den Score schneller, was den Lernstand abbildet.
             *
             * Der Score steht als eigene Spalte statt im ORDER BY: PostgreSQL
             * erlaubt dort einen Alias nur **allein**, nicht innerhalb eines
             * Ausdrucks (SQLite schon). So steht die Formel einmal da und
             * läuft auf beiden Engines.
             */
            ->selectRaw(
                '(SUM(pow(?, rn - 1) * CASE WHEN is_correct = false THEN 1 ELSE 0 END) + 1.0)'
                .' / (SUM(pow(?, rn - 1)) + 2) as weak_score',
                [$decay, $decay],
            )
            ->selectRaw(
                'MAX(CASE WHEN is_correct = false AND quiz_kind <> ? THEN attempt_id END) as latest_wrong_attempt_id',
                [QuizKind::Practice->value],
            )
            ->groupBy('question_id')
            ->havingRaw('SUM(CASE WHEN is_correct = false THEN 1 ELSE 0 END) > 0')
            ->orderByDesc('weak_score')
            ->orderByDesc('wrong_count')
            ->orderBy('question_id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        /** @var array<int, array{wrong: int, asked: int, attempt: int|null}> $stats */
        $stats = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $attemptId = $values['latest_wrong_attempt_id'];

            $stats[(int) $values['question_id']] = [
                'wrong' => (int) $values['wrong_count'],
                'asked' => (int) $values['asked_count'],
                'attempt' => $attemptId === null ? null : (int) $attemptId,
            ];
        }

        if ($stats === []) {
            return [];
        }

        $questions = Question::query()
            ->whereIn('id', array_keys($stats))
            ->with(['options', 'learningPath'])
            ->get()
            ->keyBy('id');

        $quizIds = DB::table('quiz_attempts')
            ->whereIn('id', array_filter(array_column($stats, 'attempt')))
            ->pluck('quiz_id', 'id');

        $weakSpots = [];

        foreach ($stats as $questionId => $counts) {
            $question = $questions->get($questionId);

            if (! $question instanceof Question) {
                continue;
            }

            $quizId = $counts['attempt'] === null ? null : $quizIds->get($counts['attempt']);

            $weakSpots[] = new WeakSpot(
                question: $question,
                wrongCount: $counts['wrong'],
                askedCount: $counts['asked'],
                pathTitle: $question->learningPath?->title,
                quizId: $quizId === null ? null : (int) $quizId,
                correctLabels: $question->options
                    ->where('is_correct', true)
                    ->map(fn (QuestionOption $option): string => $option->label)
                    ->values()
                    ->all(),
            );
        }

        return $weakSpots;
    }

    /**
     * Der späteste der gegebenen Zeitpunkte, oder null.
     *
     * @param  array<int, Carbon|string|null>  $candidates
     */
    protected function latestOf(array $candidates): ?Carbon
    {
        return collect($candidates)
            ->filter()
            ->map(fn (Carbon|string $value): Carbon => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->max();
    }
}
