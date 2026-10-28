<?php

namespace App\Data\Analytics;

use App\Models\Quiz;
use App\Models\QuizAttempt;

/**
 * Ein Quiz im Lehrling-Drilldown, zusammengefasst über alle abgeschlossenen
 * Versuche des Lehrlings.
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
