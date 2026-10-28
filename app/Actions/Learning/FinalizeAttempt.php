<?php

namespace App\Actions\Learning;

use App\Models\Question;
use App\Models\QuizAttempt;
use Illuminate\Support\Collection;

/**
 * Schließt einen Quiz-Versuch ab und schreibt seine Wertung.
 *
 * Gewertet wird gegen die **eigene** Fragenliste des Versuchs
 * (`question_order`, Sprint 5.5), sonst — wie seit Sprint 4 — gegen die
 * aktuellen Fragen des Quiz. Beide Wege zählen ausschließlich Fragen, die es
 * noch gibt: `max_score` darf nie kleiner werden als der erreichte `score`,
 * sonst entstünde ein Prozentwert über 100.
 *
 * Der Fall „Admin ändert ein Quiz während eines laufenden Versuchs" ist damit
 * für eingefrorene Versuche gelöst; ungemischte Abschluss-Quizze tragen
 * bewusst keine Liste und behalten das Sprint-4-Verhalten (Wertung gegen den
 * aktuellen Stand).
 */
class FinalizeAttempt
{
    /**
     * Score the attempt and mark it completed.
     */
    public function handle(QuizAttempt $attempt): QuizAttempt
    {
        $quiz = $attempt->quiz;

        $questionIds = $this->scoredQuestionIds($attempt);

        $maxScore = $questionIds->count();

        $score = $attempt->responses()
            ->whereIn('question_id', $questionIds)
            ->where('is_correct', true)
            ->count();

        $attempt->update([
            'score' => $score,
            'max_score' => $maxScore,
            // Ein Quiz ohne Bestehen-Urteil (Übungsquiz, Entscheidung E2)
            // behauptet auch keines in der Datenbank: score/max_score tragen
            // die Rückmeldung, `passed` bliebe sonst ein Wert ohne Leser, den
            // eine spätere Aggregation arglos mitzählte.
            'passed' => $quiz->reportsVerdict() && $quiz->isPassing($score, $maxScore),
            'completed_at' => now(),
        ]);

        return $attempt;
    }

    /**
     * Die Fragen, gegen die dieser Versuch gewertet wird.
     *
     * Bei eingefrorener Liste wird sie gegen den Fragen-Pool gefiltert: eine
     * zwischenzeitlich gelöschte Frage kann der Lehrling nicht beantwortet
     * haben und darf ihm deshalb auch nicht als Fehler angerechnet werden.
     *
     * @return Collection<int, int>
     */
    protected function scoredQuestionIds(QuizAttempt $attempt): Collection
    {
        if ($attempt->question_order === null) {
            return $attempt->quiz->questions()->pluck('questions.id');
        }

        return Question::query()
            ->whereIn('id', $attempt->question_order)
            ->pluck('id');
    }
}
