<?php

namespace App\Data\Analytics;

use App\Models\Team;

/**
 * Die vollständige Auswertung **einer** Gruppe (S5.2).
 */
readonly class GroupReport
{
    /**
     * @param  array<int, GroupPathRow>  $paths  in Pfad-Reihenfolge
     * @param  array<int, GroupQuizRow>  $quizzes  die ausgegebenen freien Quizze (Sprint 5.6)
     * @param  array<int, GroupMemberRow>  $members  alphabetisch
     */
    public function __construct(
        public Team $team,
        public array $paths,
        public array $quizzes,
        public array $members,
        public GroupSummary $summary,
    ) {
        //
    }
}
