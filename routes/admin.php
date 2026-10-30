<?php

use App\Http\Controllers\Admin\DownloadInvitationsController;
use App\Http\Controllers\Admin\ExportLearningPathController;
use Illuminate\Support\Facades\Route;

/*
 * Admin-Bereich (Konzept g): bewusst ohne EnsureTeamMembership — der Admin
 * agiert global über alle Gruppen, seine Autorität kommt aus Gate::before
 * und nicht aus Team-Rollen. Die can:admin-Middleware schützt das initiale
 * Laden der Seiten; jede Livewire-Aktion autorisiert zusätzlich einzeln.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'can:admin'])
    ->group(function () {
        // Seit S5.5 gibt es ein Admin-Dashboard — der Einstieg zeigt dorthin
        // statt mitten in die Inhaltsverwaltung (Review N4).
        Route::redirect('/', '/admin/dashboard');

        // Der Routenname `dashboard` bleibt dem Teilnehmer_innen-Dashboard (Login,
        // Sidebar und Team-Switcher hängen daran); Admins bekommen ihr eigenes
        // Ziel, auf das die Teilnehmer_innen-Seite sie weiterleitet (Entscheidung 8).
        Route::livewire('dashboard', 'pages::admin.dashboard')
            ->name('dashboard');

        Route::livewire('learning-paths', 'pages::admin.learning-paths.index')
            ->name('learning-paths.index');

        Route::livewire('learning-paths/{learningPath}', 'pages::admin.learning-paths.edit')
            ->name('learning-paths.edit');

        Route::livewire('learning-paths/{learningPath}/review', 'pages::admin.learning-paths.review')
            ->name('learning-paths.review');

        Route::get('learning-paths/{learningPath}/export.json', ExportLearningPathController::class)
            ->name('learning-paths.export');

        Route::livewire('learning-paths/{learningPath}/lessons/{lesson}', 'pages::admin.lessons.edit')
            ->name('lessons.edit')
            ->scopeBindings();

        Route::livewire('questions', 'pages::admin.questions.index')
            ->name('questions.index');

        // Vor der {question}-Route registriert, damit „create" nicht bindet
        Route::livewire('questions/create', 'pages::admin.questions.form')
            ->name('questions.create');

        Route::livewire('questions/{question}', 'pages::admin.questions.form')
            ->name('questions.edit');

        Route::livewire('quizzes', 'pages::admin.quizzes.index')
            ->name('quizzes.index');

        Route::livewire('quizzes/{quiz}', 'pages::admin.quizzes.edit')
            ->name('quizzes.edit');

        Route::livewire('groups', 'pages::admin.groups.index')
            ->name('groups.index');

        /*
         * Die Nutzerliste (S5.7.3) liegt bewusst **neben** den Gruppen und nicht
         * darunter: sie ist der einzige Weg zu einer Teilnehmer_in, der keiner Gruppe
         * angehört — genau der Zustand, den eine Gruppenlöschung hinterlässt.
         */
        Route::livewire('users', 'pages::admin.users.index')
            ->name('users.index');

        Route::livewire('groups/{team}', 'pages::admin.groups.show')
            ->name('groups.show');

        /*
         * Die Auswertung hängt unter der Gruppe statt in einem eigenen
         * Sidebar-Bereich (Sprint-5-Entscheidung 2): der Admin denkt in
         * Gruppen, nicht in „Analytics".
         *
         * Der Drilldown-Parameter heißt `{member}`, obwohl das URL-Segment
         * „learners" lautet: scopeBindings leitet den Relationsnamen aus dem
         * Parameter ab (`member` → `Team::members()`), und ein_e Teilnehmer_in einer
         * fremden Gruppe ergibt damit 404 statt einer Seite mit
         * widersprüchlichem Kopf.
         */
        /*
         * Ein gewöhnlicher Controller statt einer Livewire-Seite: hier wird
         * eine Datei ausgeliefert, keine Oberfläche gerendert. Die Route
         * autorisiert zusätzlich über die TeamPolicy, weil `can:admin` nur das
         * Betreten des Bereichs regelt.
         */
        Route::get('groups/{team}/invitations.csv', DownloadInvitationsController::class)
            ->name('groups.invitations');

        Route::livewire('groups/{team}/analytics', 'pages::admin.analytics.group')
            ->name('groups.analytics');

        Route::livewire('groups/{team}/learners/{member}', 'pages::admin.analytics.learner')
            ->name('groups.learner')
            ->scopeBindings();
    });
