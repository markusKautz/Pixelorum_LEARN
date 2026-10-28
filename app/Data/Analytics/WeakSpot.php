<?php

namespace App\Data\Analytics;

use App\Models\Question;

/**
 * Eine wiederholt falsch beantwortete Frage (Konzept h, Merkmal 7).
 *
 * Bewusst **eine** Datenform für beide Zielgruppen: der Admin-Drilldown (S5.3)
 * rendert nur `question->prompt`, die Zähler und den Pfad — er diagnostiziert.
 * Die Lehrling-Selbstsicht (S5.4) rendert zusätzlich `correctLabels`,
 * `question->explanation` und den Wiederholen-Link über `quizId` — er übt.
 */
readonly class WeakSpot
{
    /**
     * @param  int  $wrongCount  wie oft falsch beantwortet
     * @param  int  $askedCount  wie oft überhaupt beantwortet
     * @param  int|null  $quizId  Quiz des jüngsten falschen Versuchs (Wiederholen-Link)
     * @param  array<int, string>  $correctLabels  die richtigen Antwortoptionen im Klartext
     */
    public function __construct(
        public Question $question,
        public int $wrongCount,
        public int $askedCount,
        public ?string $pathTitle,
        public ?int $quizId,
        public array $correctLabels,
    ) {
        //
    }
}
