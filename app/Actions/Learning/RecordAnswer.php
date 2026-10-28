<?php

namespace App\Actions\Learning;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Persistiert eine Quiz-Antwort — und legt beim ersten Mal den Versuch an.
 *
 * Der Versuch entsteht erst mit der ersten Antwort (Sprint-4-Entscheidung 2):
 * das bloße Öffnen der Quiz-Seite soll keine Karteileichen in der
 * Sprint-5-Statistik hinterlassen. Attempt und erste Response entstehen
 * deshalb in **einer** Transaktion.
 */
class RecordAnswer
{
    public function __construct(protected EvaluateAnswer $evaluateAnswer) {}

    /**
     * Record the answer, creating the attempt when this is the first one.
     *
     * Eine bereits beantwortete Frage wird **nie** überschrieben
     * (Entscheidung 1: abgegebene Antworten sind endgültig — wer nach dem
     * Sofort-Feedback korrigieren dürfte, könnte sich jede Wertung
     * erschleichen). Die Werte im zweiten Array von `firstOrCreate` greifen
     * ausschließlich beim Anlegen; eine vorhandene Antwort kommt unverändert
     * zurück.
     *
     * Beide Schreibvorgänge nutzen `firstOrCreate` statt „prüfen, dann
     * anlegen": das Muster liest zuerst (der bisherige Guard) und fängt beim
     * Anlegen die Unique-Verletzung ab, um die Zeile des Gewinners neu zu lesen
     * — nötig, weil zwei echt parallele Requests aus zwei Tabs beide an der
     * Prüfung vorbeikommen. Ohne das endete das Rennen in einem 500 statt in
     * der stillen Rückgabe, die für genau diesen Fall vorgesehen ist. Laravel
     * setzt dafür einen SAVEPOINT; auf PostgreSQL ist der zwingend, weil ein
     * gescheitertes Statement sonst die ganze umgebende Transaktion abbricht.
     *
     * Die beiden Indizes dahinter: `(quiz_attempt_id, question_id)` aus
     * Sprint 2 und `(user_id, quiz_id) WHERE completed_at IS NULL` — je
     * Lehrling und Quiz höchstens **ein** laufender Versuch, abgeschlossene
     * beliebig viele.
     *
     * @param  array<int, int>  $selectedOptionIds  bereits gegen die Frage geprüft
     * @param  array<int, int>  $questionOrder  die angezeigte Reihenfolge; wird nur
     *                                          festgeschrieben, wenn das Quiz sie
     *                                          einfriert (Sprint 5.5)
     */
    public function handle(
        User $user,
        Quiz $quiz,
        Question $question,
        array $selectedOptionIds,
        ?QuizAttempt $attempt = null,
        array $questionOrder = [],
    ): QuizAttempt {
        $isCorrect = $this->evaluateAnswer->handle($question, $selectedOptionIds);

        return DB::transaction(function () use ($user, $quiz, $question, $selectedOptionIds, $attempt, $isCorrect, $questionOrder): QuizAttempt {
            $attempt ??= QuizAttempt::firstOrCreate(
                ['user_id' => $user->id, 'quiz_id' => $quiz->id, 'completed_at' => null],
                [
                    'question_order' => $this->frozenQuestionOrder($quiz, $questionOrder),
                    // score/max_score/passed sind nicht nullable (Migration
                    // 2026_07_17_014705). Ein laufender Versuch trägt deshalb
                    // 0/0/false; die echten Werte schreibt allein FinalizeAttempt.
                    'score' => 0,
                    'max_score' => 0,
                    'passed' => false,
                    'started_at' => now(),
                ],
            );

            $attempt->responses()->firstOrCreate(
                ['question_id' => $question->id],
                [
                    'selected_option_ids' => $selectedOptionIds,
                    'is_correct' => $isCorrect,
                ],
            );

            return $attempt;
        });
    }

    /**
     * Die Fragenliste, die dieser Versuch festhält — oder null.
     *
     * Nur Quizze, deren Reihenfolge oder Fragenmenge sich ändern kann, frieren
     * ein; alle übrigen Versuche bleiben ohne Liste und werden weiterhin gegen
     * den aktuellen Quiz-Stand gewertet. Der Fallback auf den Pivot greift,
     * wenn eine aufrufende Stelle keine Reihenfolge mitgibt — dann steht
     * wenigstens die Fragenmenge fest.
     *
     * @param  array<int, int>  $questionOrder
     * @return array<int, int>|null
     */
    protected function frozenQuestionOrder(Quiz $quiz, array $questionOrder): ?array
    {
        if (! $quiz->freezesQuestionOrder()) {
            return null;
        }

        return $questionOrder !== []
            ? $questionOrder
            : $quiz->questions()->pluck('questions.id')->all();
    }
}
