<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Livewire\Livewire;

test('teams settings page is forbidden for non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertForbidden();
});

test('team edit page is forbidden for non-admin members', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)
        ->get(route('teams.edit', $team))
        ->assertForbidden();
});

/**
 * Die drei folgenden Tests hielten früher fest, *wer* über die
 * Team-Einstellungen und den Gruppen-Umschalter Gruppen anlegen darf. Diesen
 * zweiten Anlegeweg gibt es nicht mehr: er hängte den Anleger als Owner an
 * (anders als `admin.groups`, siehe Entscheidung 2) und machte den Ausbildner
 * damit zum Phantom-Lehrling in seiner eigenen Auswertung. Sie prüfen jetzt,
 * dass der Weg **verschwunden** ist — die Aussage ist stärker als vorher,
 * denn sie gilt für alle Rollen.
 */
test('the teams page offers no way to create a team', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertDontSee('data-test="teams-new-team-button"', false);

    expect(method_exists(Livewire::test('pages::teams.index')->instance(), 'createTeam'))->toBeFalse();
});

test('the team switcher offers no way to create a team, not even for admins', function () {
    // Ergänzt „new team entry in switcher is hidden for non-admins" weiter
    // unten um die Rolle, für die der Eintrag bisher sichtbar war.
    $admin = User::factory()->admin()->create();

    // Admins landen auf ihrem eigenen Dashboard; der Umschalter steht in
    // beiden Layouts (sidebar/header) und damit auch dort.
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-test="team-switcher-trigger"', false)
        ->assertDontSee('data-test="team-switcher-new-team"', false);
});

test('groups are created under admin groups, and without a membership', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::admin.groups.index')
        ->set('name', 'Lehrlinge 2026')
        ->call('createGroup')
        ->assertHasNoErrors();

    $group = Team::where('name', 'Lehrlinge 2026')->sole();

    expect($group->is_personal)->toBeFalse()
        ->and($group->memberships()->count())->toBe(0);
});

test('teams settings navigation is hidden for non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('settings/teams');
});

test('teams settings navigation is visible for admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('settings/teams');
});

test('new team entry in switcher is hidden for non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="team-switcher-new-team"', false);
});

test('apprentice factory state creates a user with exactly one group and no personal team', function () {
    $user = User::factory()->apprentice()->create();

    expect($user->teams()->count())->toBe(1)
        ->and($user->personalTeam())->toBeNull()
        ->and($user->currentTeam->is_personal)->toBeFalse();
});

test('apprentice factory state joins the given group as member', function () {
    $group = Team::factory()->create();

    $user = User::factory()->apprentice($group)->create();

    expect($user->belongsToTeam($group))->toBeTrue()
        ->and($user->teamRole($group))->toBe(TeamRole::Member);
});

test('members of a group cannot accept an invitation to a second group', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create(['email' => 'lehrling@example.com']);

    $groupA = Team::factory()->create();
    $groupA->members()->attach($user, ['role' => TeamRole::Member->value]);

    $groupB = Team::factory()->create();
    $groupB->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $groupB->id,
        'email' => 'lehrling@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->assertHasErrors(['invitation']);

    expect($user->fresh()->belongsToTeam($groupB))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('admins can accept invitations to additional groups', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

    $groupA = Team::factory()->create();
    $groupA->members()->attach($admin, ['role' => TeamRole::Member->value]);

    $groupB = Team::factory()->create();
    $groupB->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $groupB->id,
        'email' => 'admin@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->assertRedirect(route('dashboard'));

    expect($admin->fresh()->belongsToTeam($groupB))->toBeTrue();
});
