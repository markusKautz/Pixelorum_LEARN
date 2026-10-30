<?php

namespace App\Actions\Learning;

use App\Actions\Analytics\ComputePathProgress;
use App\Actions\Analytics\LearnerProgressReport;
use App\Data\Analytics\PathProgressRow;
use App\Data\ContinuationPoint;
use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liefert die nächste sinnvolle Stelle zum Weiterlernen (Konzept h,
 * Merkmal 2: hoher Anteil echter Lernzeit — ein Klick vom Login zum
 * Weiterlernen).
 *
 * Regelkaskade, erste Regel mit Treffer gewinnt:
 *  1. offener Quiz-Versuch → „Quiz fortsetzen"
 *  2. jüngste eigene Lektions-Erledigung → nächste offene Lektion desselben
 *     Pfades, sonst dessen Abschluss-Quiz — **sofern der Pfad noch nicht
 *     abgeschlossen ist**
 *  3. ist er abgeschlossen → erster sichtbarer, noch nicht abgeschlossener
 *     Pfad (nach `position`), ebenfalls über {@see withinPath()}
 *  4. noch nichts gelernt → erster sichtbarer Pfad, „Jetzt starten"
 *  5. alles abgeschlossen → eine Übungsrunde
 *  6. keine sichtbaren Pfade → null (das Dashboard zeigt seinen Empty-State)
 *
 * Regeln 3 und 5 kamen mit F5 dazu. Vorher blieb die Karte im zuletzt
 * bearbeiteten Pfad stehen, auch wenn dessen Quiz längst bestanden war: sie
 * bot dauerhaft „Abschluss-Quiz machen" für ein bestandenes Quiz an und schlug
 * den nächsten offenen Pfad nie vor.
 *
 * Was „abgeschlossen" heißt, entscheidet nicht diese Klasse, sondern
 * {@see ComputePathProgress} über {@see LearnerProgressReport::pathProgress()}
 * — dieselbe Quelle, aus der auch die Dashboard-Karten und `/my-progress`
 * lesen (Sprint-5-Entscheidung 3).
 *
 * Durchgehend gilt: was nicht mehr sichtbar ist — zurückgezogene Freigabe,
 * gelöschter Pfad —, wird übersprungen statt verlinkt; die Kaskade fällt
 * dann auf die nächste Regel.
 */
class ResolveContinuationPoint
{
    public function __construct(protected LearnerProgressReport $report)
    {
        //
    }

    /**
     * @param  array<int, PathProgressRow>|null  $pathProgress  nach Pfad-ID; das
     *                                                          Dashboard reicht die Zeilen herein, die es für seine Karten ohnehin
     *                                                          geladen hat — sonst rechnete dieselbe Seite sie zweimal.
     */
    public function handle(User $user, ?array $pathProgress = null): ?ContinuationPoint
    {
        $rows = $pathProgress ?? $this->report->pathProgress($user);

        $openAttempt = $this->fromOpenAttempt($user);
        if ($openAttempt instanceof ContinuationPoint) {
            return $openAttempt;
        }

        $lastPath = $this->fromLastCompletion($user);

        if ($lastPath instanceof LearningPath) {
            // Regel 2: letzter Pfad noch nicht abgeschlossen.
            if (! $this->isCompleted($rows, $lastPath)) {
                return $this->withinPath($user, $lastPath);
            }

            // Regel 3: letzter Pfad abgeschlossen → nächster offener Pfad.
            $point = $this->fromFirstOpenPath($user, $rows);
            if ($point instanceof ContinuationPoint) {
                return $point;
            }
        } else {
            // Regel 4: noch nichts gelernt → erster sichtbarer Pfad.
            $point = $this->fromFirstVisiblePath($user);
            if ($point instanceof ContinuationPoint) {
                return $point;
            }
        }

        // Regel 5: alles abgeschlossen.
        return $this->fromPracticeRound($rows);
    }

    /**
     * Regel 3: der erste sichtbare Pfad, der noch offen ist.
     *
     * `$rows` kommt nach `position` sortiert aus `pathProgress()` — die
     * Reihenfolge ist dieselbe, in der die Karten darunter stehen.
     *
     * @param  array<int, PathProgressRow>  $rows
     */
    protected function fromFirstOpenPath(User $user, array $rows): ?ContinuationPoint
    {
        foreach ($rows as $row) {
            if ($row->status === ProgressStatus::Completed) {
                continue;
            }

            return $this->withinPath($user, $row->path);
        }

        return null;
    }

    /**
     * Regel 5: alles abgeschlossen — Wiederholen statt Neues.
     *
     * Bewusst ein Link auf den Übungs-Builder und keine hier gebaute Runde:
     * ein Einstiegspunkt wird beim Rendern gelesen, er darf nichts schreiben.
     *
     * @param  array<int, PathProgressRow>  $rows
     */
    protected function fromPracticeRound(array $rows): ?ContinuationPoint
    {
        if ($rows === []) {
            return null;
        }

        return new ContinuationPoint(
            label: __('Practise'),
            title: __('All learning paths completed'),
            context: null,
            routeName: 'learn.practice.create',
            routeParameters: [],
        );
    }

    /**
     * @param  array<int, PathProgressRow>  $rows
     */
    protected function isCompleted(array $rows, LearningPath $path): bool
    {
        return ($rows[$path->id] ?? null)?->status === ProgressStatus::Completed;
    }

    /**
     * Regel 1: ein laufender Versuch auf einem noch sichtbaren Quiz.
     */
    protected function fromOpenAttempt(User $user): ?ContinuationPoint
    {
        $attempts = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->with('quiz.learningPath')
            ->latest('updated_at')
            ->get();

        if ($attempts->isEmpty()) {
            return null;
        }

        foreach ($attempts as $attempt) {
            // Der Versuch kann sein Quiz nicht überleben (cascadeOnDelete);
            // zurückgezogen werden kann die Freigabe aber jederzeit.
            $quiz = $attempt->quiz;

            if (! $user->can('view', $quiz)) {
                continue;
            }

            return new ContinuationPoint(
                label: __('Resume quiz'),
                title: $quiz->title,
                context: $quiz->learningPath?->title,
                routeName: 'learn.quizzes.show',
                routeParameters: ['quiz' => $quiz->getRouteKey()],
            );
        }

        return null;
    }

    /**
     * Der Pfad, in dem zuletzt eine Lektion fertig wurde — Grundlage der
     * Regeln 2 bis 4.
     *
     * Die Sichtbarkeit steht in der Query, nicht in einer Schleife darüber.
     * Das ist nicht nur kürzer, es bindet die Regel auch an dieselbe eine
     * Formulierung wie überall sonst im Projekt (den `visibleTo`-Scope)
     * statt an ihre instanzseitige Zwillingsmethode. `whereHas` bringt die
     * globalen Scopes des Ziels mit, ein soft-gelöschter Pfad fällt also
     * genauso heraus wie ein unveröffentlichter — nur eben in SQL statt als
     * `$path === null`. Zurück kommt die eine Zeile, die entscheidet, statt
     * der ganzen Lernhistorie des Lehrlings.
     *
     * Die Lektion kann ihre Erledigung nicht überleben (cascadeOnDelete),
     * `lesson` ist hier also immer besetzt; `learningPath` garantiert das
     * `whereHas`.
     */
    protected function fromLastCompletion(User $user): ?LearningPath
    {
        $completion = LessonCompletion::query()
            ->where('user_id', $user->id)
            ->whereHas('lesson.learningPath', fn (Builder $paths) => $this->nurSichtbarePfade($paths, $user))
            ->with('lesson.learningPath')
            ->latest('completed_at')
            ->latest('id')
            ->first();

        return $completion?->lesson->learningPath;
    }

    /**
     * Das Grounding-Gate als Einschränkung einer Pfad-Query.
     *
     * Eigene Methode statt einer Closure, weil erst ihre Signatur den
     * generischen Typ festlegt: Larastan gibt `whereHas`-Closures pauschal ein
     * `Builder<Model>`, und darauf existiert der Scope nicht.
     *
     * @param  Builder<LearningPath>  $query
     */
    protected function nurSichtbarePfade(Builder $query, User $user): void
    {
        $query->visibleTo($user);
    }

    /**
     * Regel 3: noch nichts gelernt — der erste sichtbare Pfad.
     */
    protected function fromFirstVisiblePath(User $user): ?ContinuationPoint
    {
        $path = LearningPath::query()
            ->visibleTo($user)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($path === null) {
            return null;
        }

        return new ContinuationPoint(
            label: __('Start now'),
            title: $path->title,
            context: null,
            routeName: 'learn.paths.show',
            routeParameters: ['learningPath' => $path->getRouteKey()],
        );
    }

    /**
     * Die nächste offene Lektion des Pfades, sonst sein Abschluss-Quiz,
     * sonst die Pfadansicht.
     *
     * @phpstan-impure
     */
    protected function withinPath(User $user, LearningPath $path): ContinuationPoint
    {
        /*
         * Die Erledigungen als Subquery statt als geholte Liste. Auf den Pfad
         * eingrenzen muss die Subquery sich nicht: die Kandidaten sind mit
         * `$path->lessons()` schon auf ihn beschränkt, eine Lektions-ID aus
         * einem fremden Pfad kann dort nie treffen.
         *
         * `lesson_id` ist NOT NULL — bei einer nullable Spalte liefe das
         * `NOT IN` still leer, sobald ein NULL in der Subquery steht.
         */
        $nextLesson = $path->lessons()
            ->whereNotIn('id', LessonCompletion::query()
                ->where('user_id', $user->id)
                ->select('lesson_id'))
            ->first();

        if ($nextLesson instanceof Lesson) {
            return new ContinuationPoint(
                label: __('Keep learning'),
                title: $nextLesson->title,
                context: $path->title,
                routeName: 'learn.lessons.show',
                routeParameters: [
                    'learningPath' => $path->getRouteKey(),
                    'lesson' => $nextLesson->getRouteKey(),
                ],
            );
        }

        $quiz = $path->finalQuiz;

        if ($quiz instanceof Quiz) {
            // Der Policy den geladenen Pfad mitgeben statt ihn nachladen zu lassen.
            $quiz->setRelation('learningPath', $path);

            if ($user->can('view', $quiz)) {
                return new ContinuationPoint(
                    label: __('Take the final quiz'),
                    title: $quiz->title,
                    context: $path->title,
                    routeName: 'learn.quizzes.show',
                    routeParameters: ['quiz' => $quiz->getRouteKey()],
                );
            }
        }

        return new ContinuationPoint(
            label: __('Back to the path'),
            title: $path->title,
            context: null,
            routeName: 'learn.paths.show',
            routeParameters: ['learningPath' => $path->getRouteKey()],
        );
    }
}
