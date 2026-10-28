<?php

namespace App\Actions\Analytics;

use App\Data\Analytics\PathProgressRow;
use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use Illuminate\Support\Carbon;

/**
 * Die **einzige** Stelle, die „Fortschritt" definiert (Sprint-5-Entscheidung 3,
 * „Modell A"). Gruppen-Aggregat, Admin-Drilldown und Lehrling-Selbstsicht
 * lesen alle hierdurch — sonst stünden auf zwei Seiten zwei Zahlen für
 * dieselbe Sache.
 *
 * **Modell A — Fortschritt ist reiner Lesefortschritt, das Quiz ist ein
 * eigener Meilenstein:**
 * - `percent` = `intdiv(erledigteLektionen * 100, alleLektionen)`, ganzzahlig
 *   abgerundet; ein Pfad ohne Lektionen steht auf 0. Das Quiz geht **nicht**
 *   in den Prozentwert ein.
 * - `not_started` ⇔ keine Lektion erledigt **und** kein abgeschlossener
 *   Quiz-Versuch. Ein Pfad ganz ohne Inhalt bleibt damit „nicht begonnen"
 *   statt scheinbar fertig zu sein.
 * - `completed` ⇔ alle Lektionen erledigt **und** (Quiz bestanden **oder**
 *   kein Quiz vorhanden).
 * - sonst `in_progress`.
 *
 * Bewusste Folge: der Balken kann 100 % zeigen, während der Pfad noch
 * `in_progress` ist, weil das Abschluss-Quiz offen ist. Genau so zeigt es
 * schon die Lehrling-Oberfläche aus Sprint 4 (Balken + eigenes Quiz-Badge).
 *
 * Reine Rechnung ohne Query: die Zähler kommen aus den `GROUP BY`-Abfragen
 * der Report-Klassen herein.
 */
class ComputePathProgress
{
    /**
     * Build the progress row for one apprentice on one path.
     *
     * @param  int  $completedAttempts  abgeschlossene Versuche auf dem Abschluss-Quiz
     */
    public function handle(
        LearningPath $path,
        int $totalLessons,
        int $completedLessons,
        bool $hasQuiz,
        bool $quizPassed,
        int $completedAttempts = 0,
        ?Carbon $lastActivityAt = null,
    ): PathProgressRow {
        $percent = $totalLessons > 0 ? intdiv($completedLessons * 100, $totalLessons) : 0;

        $status = match (true) {
            $completedLessons === 0 && $completedAttempts === 0 => ProgressStatus::NotStarted,
            $completedLessons >= $totalLessons && (! $hasQuiz || $quizPassed) => ProgressStatus::Completed,
            default => ProgressStatus::InProgress,
        };

        return new PathProgressRow(
            path: $path,
            totalLessons: $totalLessons,
            completedLessons: $completedLessons,
            percent: $percent,
            status: $status,
            hasQuiz: $hasQuiz,
            quizPassed: $quizPassed,
            lastActivityAt: $lastActivityAt,
        );
    }
}
