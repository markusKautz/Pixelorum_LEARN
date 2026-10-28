<?php

namespace App\Data\Analytics;

use App\Models\LearningPath;

/**
 * Ein Pfad, aggregiert über **eine** Gruppe (Block „Fortschritt je Pfad").
 */
readonly class GroupPathRow
{
    /**
     * @param  int  $memberCount  alle Mitglieder der Gruppe — auch die ohne jede Aktivität
     * @param  int  $completedCount  Mitglieder mit Status `completed`
     * @param  int  $passedCount  Mitglieder mit bestandenem Abschluss-Quiz
     */
    public function __construct(
        public LearningPath $path,
        public int $memberCount,
        public int $averagePercent,
        public int $completedCount,
        public int $passedCount,
        public bool $hasQuiz,
    ) {
        //
    }

    /**
     * Die Bestehensquote des Abschluss-Quiz.
     *
     * Nenner sind **alle** Mitglieder (Entscheidung 4), nicht nur die mit
     * einem Versuch: „5 von 12 haben bestanden" ist die Zahl, die der
     * Ausbildner braucht — „5 von 5 Antretenden" verdeckt genau die sieben,
     * die noch gar nicht angetreten sind.
     */
    public function passRate(): int
    {
        return $this->memberCount > 0 ? intdiv($this->passedCount * 100, $this->memberCount) : 0;
    }
}
