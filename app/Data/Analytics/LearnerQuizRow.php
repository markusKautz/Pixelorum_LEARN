<?php

namespace App\Data\Analytics;

use App\Models\Quiz;
use App\Models\QuizAttempt;

/**
 * Ein Quiz im Teilnehmer_innen-Drilldown, zusammengefasst über alle abgeschlossenen
 * Versuche der Teilnehmer_in.
 */
readonly class LearnerQuizRow
{
    public function __construct(
        public Quiz $quiz,
        public int $attemptCount,
        public QuizAttempt $bestAttempt,
    ) {
        //
    }
}
