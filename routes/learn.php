<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

/*
 * Teilnehmer_innen-Bereich (Konzept g). Anders als der Admin-Bereich liegt er
 * team-scoped unter /{current_team}: die Inhalte selbst bleiben global
 * (Konzept c), das Präfix partitioniert nur die Teilnehmer_innen und hält
 * `current_team` gepflegt — EnsureTeamMembership schaltet bei Bedarf um.
 *
 * Die Gruppe trägt bewusst *kein* ->name('learn.')-Präfix: der Routenname
 * `dashboard` muss unverändert bleiben (Sidebar, Fortify-Redirects,
 * Team-Switcher hängen daran). Die übrigen Routen benennen sich deshalb
 * einzeln als `learn.*`.
 *
 * Die Middleware schützt nur das initiale Laden; jede Livewire-Aktion
 * autorisiert zusätzlich selbst (Livewire-Aktionen sind eigene Endpunkte).
 */
Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::learn.dashboard')
            ->name('dashboard');

        // Die Selbstauswertung trägt bewusst keinen {user}-Parameter
        // (Sprint-5-Entscheidung 7): sie zeigt ausschließlich Auth::user().
        Route::livewire('my-progress', 'pages::learn.progress')
            ->name('learn.progress');

        Route::livewire('learning-paths/{learningPath}', 'pages::learn.paths.show')
            ->name('learn.paths.show');

        // scopeBindings: eine Lektion eines fremden Pfades ergibt 404 statt
        // einer Ansicht mit widersprüchlichem Kopf.
        Route::livewire('learning-paths/{learningPath}/lessons/{lesson}', 'pages::learn.lessons.show')
            ->name('learn.lessons.show')
            ->scopeBindings();

        Route::livewire('quizzes/{quiz}', 'pages::learn.quizzes.show')
            ->name('learn.quizzes.show');

        // Der Übungsquiz-Builder trägt wie die Selbstauswertung keinen
        // {user}-Parameter: er baut ausschließlich für Auth::user().
        Route::livewire('practice', 'pages::learn.practice.create')
            ->name('learn.practice.create');
    });
