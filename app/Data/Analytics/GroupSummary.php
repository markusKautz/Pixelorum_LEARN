<?php

namespace App\Data\Analytics;

/**
 * Der Ein-Zeilen-Überblick einer Gruppe: die Ø-Spalte der Gruppen-Liste (S5.2)
 * und der Kohorten-Überblick des Admin-Dashboards (S5.5) lesen ihn.
 *
 * `completedPathCount` / `pathSlotCount` zählen **Pfad-Belegungen**, also
 * Mitglieder × sichtbare Pfade — dieselbe Grundmenge, über die auch
 * `averagePercent` mittelt. „14/75 fertig" heißt damit: von 75 möglichen
 * Pfad-Abschlüssen der Gruppe sind 14 erreicht.
 */
readonly class GroupSummary
{
    /**
     * @param  int  $inactiveCount  Mitglieder über der Schwelle *dieser* Gruppe
     * @param  int  $activeRecentlyCount  Mitglieder mit Aktivität in den letzten
     *                                    {@see GroupProgressReport::ACTIVE_WINDOW_DAYS}
     *                                    Tagen — anders als `inactiveCount` über
     *                                    ein festes Fenster und damit über
     *                                    Gruppen hinweg summierbar (S5.7.2)
     * @param  array{below25: int, below50: int, below75: int, from75: int}  $progressBands
     *                                                                                       Mitglieder nach ihrem Ø-Lesefortschritt
     *                                                                                       über alle sichtbaren Pfade (DEV-11);
     *                                                                                       jedes Mitglied zählt in genau einem
     *                                                                                       Bereich, die Summe ist `memberCount`
     */
    public function __construct(
        public int $teamId,
        public int $memberCount,
        public int $averagePercent,
        public int $completedPathCount,
        public int $pathSlotCount,
        public int $inactiveCount,
        public int $activeRecentlyCount,
        public array $progressBands,
    ) {
        //
    }
}
