<?php

namespace App\Actions\Learning;

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stellt einem Lehrling ein Übungsquiz aus zufälligen Fragen der gewählten
 * Pfade zusammen (Sprint 5.5, Variante B).
 *
 * **Kern-Idee:** ein echtes `Quiz` materialisieren, statt einen Sonderweg für
 * das Üben zu bauen. Dadurch läuft die komplette Quiz-Durchführung aus
 * Sprint 4 unverändert, und weil echte `quiz_attempts` + `question_responses`
 * entstehen, fließen Übungs-Antworten **automatisch** in die
 * Schwachstellen-Auswertung aus Sprint 5 — genau das gewünschte Tracking.
 *
 * **Variante B:** je Lehrling genau **ein** Übungsquiz, das wiederverwendet
 * wird. Jede neue Sitzung ersetzt seinen Fragen-Pivot. Das korrumpiert keine
 * vergangenen Versuche, weil die ihre eigene Fragenliste festhalten
 * ({@see Quiz::freezesQuestionOrder()}) — und es erspart einen Aufräum-Job für
 * Behälter-Zeilen, die sonst pro Sitzung entstünden.
 */
class BuildPracticeQuiz
{
    /**
     * Standard-Fragenzahl für eine Übungsrunde. An einer gemeinsamen Stelle,
     * damit Schwachstellen-Übung und Builder denselben Wert lesen (F2).
     */
    public const DEFAULT_QUESTION_COUNT = 10;

    /**
     * Obergrenze, die der Lehrling für eine Übungsrunde wählen kann (DEV-10).
     */
    public const MAX_QUESTION_COUNT = 100;

    /**
     * Build (or refill) the personal practice quiz of the given apprentice.
     *
     * @param  array<int, int>  $pathIds  gewünschte Pfade; ungültige oder für
     *                                    Lehrlinge unsichtbare werden verworfen
     * @param  int  $count  gewünschte Fragenzahl, auf das Verfügbare gekappt
     * @param  array<int, int>  $pinnedQuestionIds  diese Fragen werden fest
     *                                              gesetzt und fehlen damit in
     *                                              der Zufallsziehung (F2)
     */
    public function handle(User $learner, array $pathIds, int $count, array $pinnedQuestionIds = []): Quiz
    {
        $questionIds = $this->drawQuestionIds($pathIds, $count, $pinnedQuestionIds);

        return DB::transaction(function () use ($learner, $questionIds): Quiz {
            $quiz = $this->practiceQuizOf($learner);

            /*
             * detach()+attach() statt sync(): sync() hängt jede neue Zeile
             * einzeln an (attachNew() ruft attach() je ID), attach() mit
             * einem Array schreibt sie in **einem** Insert. Bei 20 gezogenen
             * Fragen sind das 2 Abfragen statt 21.
             */
            $quiz->questions()->detach();
            $quiz->questions()->attach($this->pivotPositions($questionIds));

            return $quiz;
        });
    }

    /**
     * Count how many questions are available for practice per path.
     *
     * Dieselbe Filterung wie beim Ziehen — die Builder-Seite zeigt damit
     * genau die Zahl an, die sie auch bekommt.
     *
     * @param  array<int, int>  $pathIds
     * @return array<int, int> Pfad-ID => Fragenzahl
     */
    public function availableCounts(array $pathIds): array
    {
        if ($pathIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = $this->practicableQuestions($pathIds)
            ->groupBy('learning_path_id')
            ->select('learning_path_id')
            ->selectRaw('COUNT(*) as question_count')
            ->pluck('question_count', 'learning_path_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    /**
     * Draw the question ids for one practice session.
     *
     * Fest gesetzte Fragen werden vorab geprüft und vom Pool ausgeschlossen,
     * damit sie nicht doppelt auftauchen. Sind weniger übrige Fragen verfügbar
     * als noch gezogen werden sollen, wird wie üblich gekappt (F2).
     *
     * @param  array<int, int>  $pathIds
     * @param  array<int, int>  $pinnedQuestionIds
     * @return array<int, int>
     */
    protected function drawQuestionIds(array $pathIds, int $count, array $pinnedQuestionIds): array
    {
        if ($pathIds === [] || $count < 1) {
            return [];
        }

        $pinned = $this->validPinnedQuestionIds($pathIds, $pinnedQuestionIds);
        $remaining = max(0, $count - count($pinned));

        if ($remaining === 0) {
            return $pinned;
        }

        $query = $this->practicableQuestions($pathIds);

        if ($pinned !== []) {
            $query->whereNotIn('id', $pinned);
        }

        /** @var array<int, int> $drawn */
        $drawn = $query->inRandomOrder()
            ->limit($remaining)
            ->pluck('id')
            ->all();

        return array_merge($pinned, $drawn);
    }

    /**
     * Prüft, welche der fest gewünschten Fragen tatsächlich aus den gewählten
     * Pfaden übbar sind. Unsichtbare oder fremde IDs fallen still heraus — der
     * Knopf soll nicht wegen einer inzwischen zurückgezogenen Frage scheitern.
     *
     * @param  array<int, int>  $pathIds
     * @param  array<int, int>  $pinnedQuestionIds
     * @return array<int, int>
     */
    protected function validPinnedQuestionIds(array $pathIds, array $pinnedQuestionIds): array
    {
        if ($pinnedQuestionIds === []) {
            return [];
        }

        /** @var array<int, int> $valid */
        $valid = $this->practicableQuestions($pathIds)
            ->whereIn('id', $pinnedQuestionIds)
            ->pluck('id')
            ->all();

        return $valid;
    }

    /**
     * Questions an apprentice may practise on.
     *
     * Das Grounding-Gate aus Konzept e) gilt auch beim Üben: gezogen wird
     * ausschließlich aus **freigegebenen** Pfaden — auf ungeprüftem Stoff soll
     * niemand üben. Fragen ohne Optionen fallen heraus, weil sie unbeantwortbar
     * wären und den Versuch blockieren würden.
     *
     * @param  array<int, int>  $pathIds
     * @return Builder<Question>
     */
    protected function practicableQuestions(array $pathIds): Builder
    {
        return Question::query()
            ->whereIn('learning_path_id', LearningPath::query()
                ->visibleToLearners()
                ->whereIn('id', $pathIds)
                ->select('id'))
            ->whereHas('options');
    }

    /**
     * Get or create the one practice quiz of this apprentice.
     *
     * `firstOrCreate` statt lesen-dann-schreiben, weil zwischen beiden Schritten
     * ein Fenster liegt: zwei Tabs desselben Lehrlings könnten beide „keiner
     * vorhanden" lesen und beide anlegen. Die Garantie gibt der partielle
     * Unique-Index auf `quizzes (created_by) WHERE kind = 'practice'`;
     * `firstOrCreate` fängt dessen Verletzung ab und liest den Behälter des
     * Gewinners nach, statt den Verlierer in einen 500 laufen zu lassen.
     *
     * Wichtig für den Aufrufer: das passiert innerhalb der `DB::transaction`
     * von {@see self::handle()}. Laravel setzt dafür einen SAVEPOINT
     * (`createOrFirst()`), sonst risse der fehlgeschlagene Insert auf
     * PostgreSQL die **ganze** Transaktion in den Abort-Zustand.
     */
    protected function practiceQuizOf(User $learner): Quiz
    {
        return Quiz::firstOrCreate(
            [
                'kind' => QuizKind::Practice,
                'created_by' => $learner->id,
            ],
            [
                'title' => __('Practice quiz'),
                // Pfadlos wie ein freies Quiz — der saving-Guard am Model besteht
                // darauf, weil ein Übungsquiz quer durch die Pfade zieht.
                'learning_path_id' => null,
                // Bewusst Entwurf: sichtbar macht den Behälter allein die
                // QuizPolicy, und so taucht er nirgends auf, wo nach
                // veröffentlichten Quizzen gesucht wird.
                'status' => PathStatus::Draft,
                // Gemischt, damit ein zweiter Durchgang derselben Auswahl nicht
                // in derselben Reihenfolge kommt.
                'shuffle_questions' => true,
            ],
        );
    }

    /**
     * Turn the drawn ids into the pivot payload sync() expects.
     *
     * @param  array<int, int>  $questionIds
     * @return array<int, array{position: int}>
     */
    protected function pivotPositions(array $questionIds): array
    {
        $payload = [];

        foreach ($questionIds as $index => $questionId) {
            $payload[$questionId] = ['position' => $index + 1];
        }

        return $payload;
    }
}
