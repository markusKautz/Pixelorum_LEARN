<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')
    ->middleware(['signed', 'throttle:10,1'])
    ->name('invitations.accept');

Route::livewire('about', 'pages::about')
    ->middleware('auth')
    ->name('about');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// Zuletzt: der Lehrling-Bereich beginnt mit dem Platzhalter {current_team} und
// würde sonst auch /admin/… und /settings/… auf sich ziehen (erste passende
// Route gewinnt). Die konkreten Präfixe müssen deshalb vorher registriert sein.
require __DIR__.'/learn.php';
