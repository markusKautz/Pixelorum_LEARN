<?php

namespace App\Actions\Learning;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Die **einzige** Stelle, die über die Korrektheit einer Antwort entscheidet
 * (Sprint-4-Entscheidung 4). Zwischen-Check (S4.3) und Quiz-Versuch (S4.4)
 * unterscheiden sich nur in der Persistenz, nicht in der Wertung — Konzept f
 * ist verbindlich und Konzept i nennt Scoring den kritischsten Testbereich.
 *
 * Reine Mengenlogik über normalisierte int-Arrays: keine Query im Inneren,
 * die Optionen kommen eager-geladen herein.
 */
class EvaluateAnswer
{
    /**
     * Determine whether the given selection answers the question correctly.
     *
     * - Single/Boolean: genau **eine** Auswahl, und die ist korrekt.
     * - Multiple: die Menge der gewählten IDs ist **identisch** zur Menge der
     *   korrekten — keine fehlende, keine falsche (Konzept f). Echte
     *   Teilmengen gelten als falsch, Teilpunkte gibt es nicht.
     *
     * @param  array<int|string, int|string>  $selectedOptionIds
     */
    public function handle(Question $question, array $selectedOptionIds): bool
    {
        $selected = $this->normalize($selectedOptionIds);

        $correct = $this->normalize(
            $question->options
                ->where('is_correct', true)
                ->map(fn (QuestionOption $option): int => $option->id)
                ->all(),
        );

        // Frage ohne korrekte Option: die Speicher-Validierung verhindert das;
        // ohne diesen Riegel wäre hier jede Antwort „richtig".
        if ($correct === []) {
            return false;
        }

        return match ($question->type) {
            QuestionType::Single, QuestionType::Boolean => count($selected) === 1
                && in_array($selected[0], $correct, strict: true),
            QuestionType::Multiple => $selected === $correct,
        };
    }

    /**
     * Sort and deduplicate the ids so both sides compare as plain int lists.
     *
     * Für die **korrekten** IDs ist das zwingend: sie kommen in DB-Reihenfolge
     * aus der Relation, und `Multiple` vergleicht mit `===`, das auf Arrays
     * reihenfolgeabhängig ist. Für die Auswahl ist es Verteidigung — auf dem
     * echten Weg hat `Question::sanitizeSelection()` das schon getan.
     *
     * Diese drei Zeilen stehen bewusst ein zweites Mal in
     * `Question::sanitizeSelection()`, statt geteilt zu werden: dort prüfen
     * sie Client-Eingaben ab, hier machen sie zwei Mengen vergleichbar. Zwei
     * Regeln, zwei Änderungsgründe — eine Prüfregel (etwa „IDs ≤ 0 abweisen")
     * gehört dorthin und **nicht** hierher. Dass beide dieselbe Normalform
     * liefern, hält `EvaluateAnswerTest` fest; wer eine davon anfasst, sollte
     * dort nachsehen.
     *
     * @param  array<int|string, int|string>  $optionIds
     * @return array<int, int>
     */
    protected function normalize(array $optionIds): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $optionIds)));

        sort($ids);

        return $ids;
    }
}
