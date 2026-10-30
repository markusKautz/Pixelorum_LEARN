<?php

namespace App\Data\Analytics;

use App\Models\Quiz;

/**
 * Ein **freies** Quiz, aggregiert über die Gruppe, der es ausgegeben wurde
 * (Sprint 5.6, Block „Ausgegebene Quizze").
 *
 * Bewusst eine eigene Datenform statt einer Erweiterung von
 * {@see GroupPathRow}: ein freies Quiz hat keinen Lesefortschritt und keinen
 * Pfad, dafür aber eine Antritts-Quote, die es beim Abschluss-Quiz nicht
 * getrennt gibt (dort ist „nicht angetreten" schon im Pfad-Fortschritt
 * sichtbar).
 */
readonly class GroupQuizRow
{
    /**
     * @param  int  $memberCount  alle Mitglieder der Gruppe — auch die ohne jeden Versuch
     * @param  int  $attemptedCount  Mitglieder mit mindestens einem abgeschlossenen Versuch
     * @param  int  $passedCount  Mitglieder, die das Quiz bestanden haben
     * @param  int|null  $averagePercent  Ø über den **besten** Versuch der Angetretenen;
     *                                    null, wenn noch niemand angetreten ist
     */
    public function __construct(
        public Quiz $quiz,
        public int $memberCount,
        public int $attemptedCount,
        public int $passedCount,
        public ?int $averagePercent,
    ) {
        //
    }

    /**
     * Die Bestehensquote — Nenner sind **alle** Mitglieder (Entscheidung 4 aus
     * Sprint 5), damit die noch nicht Angetretenen nicht aus der Zahl fallen.
     */
    public function passRate(): int
    {
        return $this->memberCount > 0 ? intdiv($this->passedCount * 100, $this->memberCount) : 0;
    }

    /**
     * Ob überhaupt jemand angetreten ist — trennt „alle durchgefallen" von
     * „noch niemand hat es angefasst", zwei sehr verschiedene Befunde für den
     * Admin.
     */
    public function wasAttempted(): bool
    {
        return $this->attemptedCount > 0;
    }
}
