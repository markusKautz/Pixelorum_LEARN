<?php

use Symfony\Component\Finder\Finder;

/**
 * Flux-Modals lauschen auf `modal-close` (siehe flux/modal/index.blade.php und
 * Flux\Concerns\InteractsWithComponents). Ein `dispatch('close-modal', …)` läuft
 * ins Leere: die Aktion wirkt, das Modal bleibt aber offen stehen. Genau dieses
 * Muster hatte sich per Copy-Paste über 18 Stellen verbreitet, ohne dass ein
 * Test es sehen konnte (Browser-Test S3, F2). Dieser Wächter fängt den Rückfall.
 */
test('no component dispatches the non-existent close-modal event', function () {
    $offenders = [];

    $files = Finder::create()
        ->files()
        ->in([resource_path('views'), app_path()])
        ->name(['*.blade.php', '*.php']);

    foreach ($files as $file) {
        if (str_contains($file->getContents(), "'close-modal'")) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders).' — stattdessen $this->modal($name)->close() verwenden.');
});
