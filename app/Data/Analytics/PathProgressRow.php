<?php

namespace App\Data\Analytics;

use App\Actions\Analytics\ComputePathProgress;
use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use Illuminate\Support\Carbon;

/**
 * Der Fortschritt **einer** Teilnehmer_in auf **einem** Pfad — das Atom, aus dem
 * jede Auswertung dieses Sprints besteht: die Gruppen-Aggregate mitteln
 * darüber, der Admin-Drilldown und die Selbstsicht der Teilnehmer_innen zeigen die Zeilen
 * direkt.
 *
 * Berechnet wird sie ausschließlich von
 * {@see ComputePathProgress} (Modell A).
 */
readonly class PathProgressRow
{
    public function __construct(
        public LearningPath $path,
        public int $totalLessons,
        public int $completedLessons,
        public int $percent,
        public ProgressStatus $status,
        public bool $hasQuiz,
        public bool $quizPassed,
        public ?Carbon $lastActivityAt,
    ) {
        //
    }

    /**
     * Whether the reading part is done but the final quiz is still open —
     * der Fall, in dem der Balken 100 % zeigt und der Pfad trotzdem nicht
     * abgeschlossen ist (bewusste Folge von Modell A).
     */
    public function quizOutstanding(): bool
    {
        return $this->hasQuiz && ! $this->quizPassed;
    }
}
