<?php

namespace App\Actions\Content;

use App\Enums\PathStatus;
use App\Models\LearningPath;

/**
 * Veröffentlichen und Zurückziehen eines Lernpfads, aufgerufen aus der
 * Bearbeiten-Seite und aus der Liste.
 *
 * Gemeinsam, weil beide Stellen dieselbe Feinheit brauchen: der Statuswechsel
 * darf `updated_at` nicht bewegen. Sonst läse `changedSinceVerification()` die
 * Freigabe als Inhaltsänderung und der Pfad trüge sofort das Badge „seit
 * Freigabe geändert" (Befund der Browserabnahme vom 2026-08-10).
 */
class SetPathStatus
{
    public function handle(LearningPath $path, PathStatus $status): void
    {
        LearningPath::withoutTimestamps(
            fn () => $path->update(['status' => $status]),
        );
    }
}
