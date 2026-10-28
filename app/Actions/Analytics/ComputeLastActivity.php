<?php

namespace App\Actions\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Die **einzige** Stelle, die „wann war dieser Nutzer zuletzt aktiv"
 * beantwortet (Sprint-5-Entscheidung 5): das jüngste aus
 * `max(lesson_completions.completed_at)` und `max(quiz_attempts.updated_at)`.
 *
 * Ein noch laufender Versuch zählt mit — wer gerade ein Quiz schreibt, ist
 * nicht inaktiv.
 *
 * Aus {@see GroupProgressReport} herausgezogen (S5.7.3), als die
 * Admin-Nutzerliste dieselbe Auskunft für eine Seite Nutzer brauchte. Ein
 * zweiter Nachbau hätte die Regel verdoppelt, und Duplikate driften: irgendwann
 * zählt eine Seite laufende Versuche mit und die andere nicht.
 *
 * Zwei `GROUP BY`-Abfragen für beliebig viele Nutzer, verrechnet in PHP — der
 * Aufwand wächst nicht mit der Zahl der Nutzer (Entscheidung 9).
 */
class ComputeLastActivity
{
    /**
     * Build the last-activity lookup for the given users.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, Carbon> nach Nutzer-ID; wer nie aktiv war, fehlt
     */
    public function handle(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $lessons = DB::table('lesson_completions')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('MAX(completed_at) as last_at')
            ->pluck('last_at', 'user_id');

        $attempts = DB::table('quiz_attempts')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('MAX(updated_at) as last_at')
            ->pluck('last_at', 'user_id');

        $activity = [];

        foreach ($userIds as $userId) {
            $latest = Collection::make([$lessons->get($userId), $attempts->get($userId)])
                ->filter(fn (mixed $value): bool => is_string($value))
                ->map(fn (mixed $value): Carbon => Carbon::parse((string) $value))
                ->max();

            if ($latest instanceof Carbon) {
                $activity[$userId] = $latest;
            }
        }

        return $activity;
    }
}
