<?php

namespace App\Data\Analytics;

use App\Enums\ProgressStatus;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Die vollständige Auswertung **eines** Lehrlings, gelesen vom
 * Admin-Drilldown (S5.3) und von „Mein Fortschritt" (S5.4).
 */
readonly class LearnerReport
{
    /**
     * @param  array<int, PathProgressRow>  $paths  in Pfad-Reihenfolge
     * @param  Collection<int, QuizAttempt>  $attempts  abgeschlossene Abschluss-Versuche, jüngster zuerst
     * @param  array<int, WeakSpot>  $weakSpots  absteigend nach Fehlerzahl
     */
    public function __construct(
        public User $learner,
        public array $paths,
        public Collection $attempts,
        public array $weakSpots,
        public ?Carbon $lastActivityAt,
    ) {
        //
    }

    /**
     * Der Ø-Lesefortschritt über alle sichtbaren Pfade.
     */
    public function averagePercent(): int
    {
        if ($this->paths === []) {
            return 0;
        }

        $sum = array_sum(array_map(fn (PathProgressRow $row): int => $row->percent, $this->paths));

        return intdiv($sum, count($this->paths));
    }

    /**
     * Wie viele Pfade nach Modell A abgeschlossen sind.
     */
    public function completedPaths(): int
    {
        return count(array_filter(
            $this->paths,
            fn (PathProgressRow $row): bool => $row->status === ProgressStatus::Completed,
        ));
    }

    /**
     * Abgeschlossene Versuche je Quiz aggregiert, jüngster Versuch zuerst.
     *
     * Der „beste" Versuch ist derselbe Begriff wie im Gruppen-Report: höchster
     * Prozentwert, bei Gleichstand höherer Score, sonst der jüngere Versuch.
     *
     * Die Reihenfolge der Zeilen kommt aus der Quelle: `$attempts` ist nach
     * `completed_at` absteigend geladen, und `groupBy()` erhält die Reihenfolge
     * des jeweils ersten Treffers. Oben steht damit das Quiz mit dem jüngsten
     * Versuch — nicht das mit dem jüngsten *besten* Versuch, was bei einem
     * misslungenen Wiederholungsversuch etwas anderes wäre.
     *
     * @return array<int, LearnerQuizRow>
     */
    public function attemptsByQuiz(): array
    {
        /** @var Collection<int, QuizAttempt> $attempts */
        $attempts = $this->attempts;

        return $attempts
            ->groupBy(fn (QuizAttempt $attempt): int => $attempt->quiz_id)
            ->map(function (Collection $group): LearnerQuizRow {
                /** @var QuizAttempt $best */
                $best = $group->sortByDesc(fn (QuizAttempt $attempt): array => [
                    $attempt->percent,
                    $attempt->score,
                    $attempt->completed_at->getTimestamp(),
                ])->first();

                return new LearnerQuizRow(
                    quiz: $best->quiz,
                    attemptCount: $group->count(),
                    bestAttempt: $best,
                );
            })
            ->values()
            ->all();
    }
}
