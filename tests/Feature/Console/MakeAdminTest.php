<?php

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('existing user is promoted to admin without a second team', function () {
    $user = User::factory()->create();

    $this->artisan('lapp:make-admin', ['email' => $user->email])
        ->expectsOutputToContain('zum globalen Admin befördert')
        ->assertSuccessful();

    $user->refresh();

    expect($user->is_admin)->toBeTrue()
        ->and($user->teams()->count())->toBe(1);
});

test('command is idempotent for existing admins', function () {
    $admin = User::factory()->admin()->create();

    $this->artisan('lapp:make-admin', ['email' => $admin->email])
        ->expectsOutputToContain('ist bereits globaler Admin')
        ->assertSuccessful();

    expect($admin->refresh()->is_admin)->toBeTrue();
});

test('new admin account is created with personal team', function () {
    $this->artisan('lapp:make-admin', [
        'email' => 'admin@example.com',
        '--name' => 'Admina Adminson',
        '--password' => 'sicheres-passwort',
    ])->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->firstOrFail();

    expect($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('sicheres-passwort', $user->password))->toBeTrue()
        ->and($user->teams()->count())->toBe(1);

    $team = $user->teams()->first();

    expect($team->is_personal)->toBeTrue()
        ->and($user->teamRole($team))->toBe(TeamRole::Owner);
});

test('existing users are found regardless of email casing', function () {
    $user = User::factory()->create(['email' => 'lehrling@example.com']);

    $this->artisan('lapp:make-admin', ['email' => 'Lehrling@Example.COM'])
        ->expectsOutputToContain('zum globalen Admin befördert')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

test('new admin accounts store the email lowercase', function () {
    $this->artisan('lapp:make-admin', [
        'email' => 'Admin@Example.COM',
        '--name' => 'Admina Adminson',
        '--password' => 'sicheres-passwort',
    ])->assertSuccessful();

    expect(User::where('email', 'admin@example.com')->exists())->toBeTrue();
});

test('missing name and password are prompted interactively', function () {
    $this->artisan('lapp:make-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', 'Admina Adminson')
        ->expectsQuestion('Passwort', 'sicheres-passwort')
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->firstOrFail();

    expect($user->is_admin)->toBeTrue()
        ->and(Hash::check('sicheres-passwort', $user->password))->toBeTrue();
});

test('non-interactive mode without options fails cleanly', function () {
    $this->artisan('lapp:make-admin', [
        'email' => 'admin@example.com',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::where('email', 'admin@example.com')->exists())->toBeFalse();
});

test('non-interactive mode without email fails cleanly', function () {
    $this->artisan('lapp:make-admin', ['--no-interaction' => true])
        ->assertFailed();
});

test('invalid input fails validation', function () {
    $this->artisan('lapp:make-admin', [
        'email' => 'kein-email',
        '--name' => 'Admina Adminson',
        '--password' => 'kurz',
    ])->assertFailed();

    expect(User::where('email', 'kein-email')->exists())->toBeFalse();
});
