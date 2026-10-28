<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');

    Route::livewire('settings/teams', 'pages::teams.index')
        ->middleware('can:viewAny,App\Models\Team')
        ->name('teams.index');

    Route::middleware([EnsureTeamMembership::class, 'can:viewAny,App\Models\Team'])->group(function () {
        Route::livewire('settings/teams/{team}', 'pages::teams.edit')->name('teams.edit');
    });
});

// Nur registrieren, solange Passkeys aktiv sind: Der Endpunkt weist Browser
// und Passwortmanager auf die Verwaltungsseite hin, und die zeigt bei
// abgeschaltetem Feature keinen Passkey-Bereich.
if (Features::canManagePasskeys()) {
    Route::get('.well-known/passkey-endpoints', function () {
        return response()->json([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
    })->name('well-known.passkeys');
}
