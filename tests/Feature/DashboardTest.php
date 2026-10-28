<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('the dashboard route branches by role', function () {
    // Der Routenname bleibt für beide Rollen derselbe (Entscheidung 8) —
    // Login-Redirect, Sidebar und Team-Switcher hängen daran.
    $apprentice = User::factory()->apprentice()->create();

    $this->actingAs($apprentice)
        ->get(learnRoute('dashboard', $apprentice))
        ->assertOk();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('an admin logging in ends up on the admin dashboard', function () {
    $admin = User::factory()->admin()->create(['password' => 'password']);

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    // Der Login-Redirect zeigt weiter auf `dashboard`; die Verzweigung
    // passiert dort, damit nur eine Stelle die Rolle kennt.
    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});
