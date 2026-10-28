<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('guests are redirected to the login page', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with([
    'einstieg' => '/admin',
    'lernpfade' => '/admin/learning-paths',
]);

test('apprentices are forbidden on admin routes', function (string $uri) {
    $apprentice = User::factory()->apprentice()->create();

    $this->actingAs($apprentice)->get($uri)->assertForbidden();
})->with([
    'einstieg' => '/admin',
    'lernpfade' => '/admin/learning-paths',
]);

test('admins are redirected from the admin entry point to their dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect('/admin/dashboard');
});

/*
 * Der Lehrling-Bereich liegt unter /{gruppe}/… . Ein Admin gehört den Gruppen
 * der Lehrlinge nicht an, lief also bis S5.6 in den 403 von
 * EnsureTeamMembership — die mount()-Redirects der Seiten kamen nie zum Zug,
 * weil die Komponente gar nicht gemountet wurde (F3).
 */
test('an admin on a learner route of a foreign group is sent to the admin dashboard', function (string $route) {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);

    $this->actingAs($admin)
        ->get(route($route, ['current_team' => $group->slug]))
        ->assertRedirect(route('admin.dashboard'));
})->with([
    'dashboard' => 'dashboard',
    'mein fortschritt' => 'learn.progress',
    'übungsquiz' => 'learn.practice.create',
]);

test('the admin switch does not drag the current team along', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $before = $admin->current_team_id;

    $this->actingAs($admin)->get(route('dashboard', ['current_team' => $group->slug]));

    expect($admin->fresh()->current_team_id)->toBe($before);
});

/*
 * Gegenprobe zur Weiche oben (aus O3): sie steht **vor** der
 * Mitgliedsprüfung. Eine negierte Bedingung ließe damit jeden Lehrling in jede
 * fremde Gruppe — dieser Test ist der einzige, der das bemerken würde.
 */
test('an apprentice on the slug of a foreign group still gets a 403', function () {
    $apprentice = User::factory()->apprentice()->create();
    $foreign = Team::factory()->create();

    $this->actingAs($apprentice)
        ->get(route('dashboard', ['current_team' => $foreign->slug]))
        ->assertForbidden();
});

/*
 * Die Weiche gilt nur für den Lehrling-Bereich. `settings/teams/{team}` trägt
 * dieselbe Middleware, gehört aber dem Admin — ein pauschaler Kurzschluss
 * hätte ihn von seiner eigenen Gruppenverwaltung weggeleitet.
 */
test('the admin switch leaves the group detail page alone', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $group->members()->attach($admin, ['role' => TeamRole::Owner->value]);

    $this->actingAs($admin)
        ->get(route('teams.edit', $group))
        ->assertOk();
});

test('admins can visit the learning path list', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.learning-paths.index'))
        ->assertOk();
});

test('the sidebar shows the administration sections only to admins', function () {
    $admin = User::factory()->admin()->create();

    // Seit S5.5 leitet `dashboard` Admins auf ihr eigenes Dashboard weiter.
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(__('Content'))
        ->assertSee(__('User management'));
});

test('the sidebar hides the administration sections from apprentices', function () {
    $apprentice = User::factory()->apprentice()->create();

    $this->actingAs($apprentice)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('Content'))
        ->assertDontSee(__('User management'));
});

// Die Reste des Starter-Kits: die Links auf fremdes Repo und fremde Doku sowie
// die Überschrift „Plattform“ über der ersten Nav-Gruppe. Für beide Rollen weg.
test('the sidebar carries no starter kit leftovers', function (string $factoryState, string $routeName) {
    $user = User::factory()->{$factoryState}()->create();

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertOk()
        ->assertDontSee(__('Platform'))
        ->assertDontSee(__('Repository'))
        ->assertDontSee(__('Documentation'))
        ->assertDontSee('livewire-starter-kit');
})->with([
    'admin' => ['admin', 'admin.dashboard'],
    'lehrling' => ['apprentice', 'dashboard'],
]);
