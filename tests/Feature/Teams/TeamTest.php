<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('teams index page can be rendered', function () {
    $user = User::factory()->admin()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('a personal team makes its user the owner and becomes the current team', function () {
    $user = User::factory()->admin()->create();

    $team = app(CreateTeam::class)->handle($user, 'Eigenes Team', isPersonal: true);

    expect($team->is_personal)->toBeTrue()
        ->and($team->memberships()->count())->toBe(1)
        ->and($user->fresh()->current_team_id)->toBe($team->id)
        ->and($user->teamRole($team))->toBe(TeamRole::Owner);
});

test('a group gets no owner membership and does not switch the creator', function () {
    // Entscheidung 2, jetzt in CreateTeam selbst statt nur im Admin-Weg: eine
    // Gruppe hat keinen Owner. Hing der Admin drin, zählte ihn
    // jede Auswertung als Teilnehmer_in mit 0 % mit.
    $admin = User::factory()->admin()->create();
    $currentTeamBefore = $admin->current_team_id;

    $group = app(CreateTeam::class)->handle($admin, 'Lehrjahr 2026');

    expect($group->is_personal)->toBeFalse()
        ->and($group->memberships()->count())->toBe(0)
        ->and($admin->fresh()->current_team_id)->toBe($currentTeamBefore);
});

test('team slug uses next available suffix', function () {
    $user = User::factory()->admin()->create();

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this->actingAs($user);

    // Über den einen verbliebenen Anlegeweg statt über die Team-Einstellungen.
    Livewire::test('pages::admin.groups.index')
        ->set('name', 'Acme')
        ->call('createGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

/*
 * F4: Die Factory setzte den Slug aus dem *erwürfelten* Namen. Ein
 * `create(['name' => …])` ist nur ein Merge auf das fertige Array, rechnet
 * also nichts neu — und der creating-Hook schweigt bei nicht-leerem Slug. Die
 * Demo-Gruppe „Lehrjahr 2026" lag dadurch unter /schnabl-ogh/… .
 */
test('a factory group with an overridden name gets the matching slug', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);

    expect($group->slug)->toBe('lehrjahr-2026');
});

test('two factory groups with the same name still get distinct slugs', function () {
    $first = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $second = Team::factory()->create(['name' => 'Lehrjahr 2026']);

    expect($first->slug)->toBe('lehrjahr-2026')
        ->and($second->slug)->toBe('lehrjahr-2026-1');
});

test('team edit page can be rendered', function () {
    $user = User::factory()->admin()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.edit', $team));

    $response->assertOk();
});

test('teams can be updated by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Updated Name')
        ->call('updateTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Updated Name')
        ->call('updateTeam')
        ->assertForbidden();
});

test('teams can be deleted by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('teams', [
        'id' => $team->id,
    ]);
});

test('team deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', 'Wrong Name')
        ->call('deleteTeam')
        ->assertHasErrors(['deleteName']);

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $zuluTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $zuluTeam])
        ->set('deleteName', $zuluTeam->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting current team falls back to personal team when alphabetically first', function () {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create(['name' => 'Zulu Team']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $team->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('deleting non current team leaves current team unchanged', function () {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $personalTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('members can leave non personal teams', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $team->id)
        ->assertHasNoErrors();

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('leaving current team switches to alphabetically first remaining team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $zuluTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $zuluTeam->id]);

    $this->actingAs($member);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $zuluTeam->id)
        ->assertHasNoErrors();

    expect($member->fresh()->belongsToTeam($zuluTeam))->toBeFalse();
    expect($member->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('leaving the only team nulls the current team instead of orphaning it', function () {
    // Teilnehmer_innen haben kein persönliches Team (Konzept c) — ohne den
    // Null-Fallback bliebe eine verwaiste current_team_id zurück.
    $apprentice = User::factory()->apprentice()->create();
    $group = $apprentice->currentTeam;

    $this->actingAs($apprentice);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $group->id)
        ->assertHasNoErrors();

    expect($apprentice->fresh()->belongsToTeam($group))->toBeFalse();
    expect($apprentice->fresh()->current_team_id)->toBeNull();
});

test('personal teams cannot be left', function () {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $personalTeam->id)
        ->assertForbidden();

    expect($user->fresh()->belongsToTeam($personalTeam))->toBeTrue();
});

test('team owners cannot leave their team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $team->id)
        ->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('users cannot leave teams they dont belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::teams.index')
        ->call('leaveTeam', $team->id)
        ->assertForbidden();
});

test('leave control is only rendered for leaveable teams', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $leaveableTeam = Team::factory()->create();

    $leaveableTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $leaveableTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.index')
        ->assertSeeHtml('data-test="team-leave-button"');
});

test('leave control is not rendered for personal or owned teams', function () {
    $user = User::factory()->create();
    $ownedTeam = Team::factory()->create();

    $ownedTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.index')
        ->assertDontSeeHtml('data-test="team-leave-button"');
});

test('deleting team switches other affected users to their personal team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $owner->update(['current_team_id' => $team->id]);
    $member->update(['current_team_id' => $team->id]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    expect($member->fresh()->current_team_id)->toEqual($member->personalTeam()->id);
});

test('personal teams cannot be deleted', function () {
    $user = User::factory()->create();

    $personalTeam = $user->personalTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $personalTeam])
        ->set('deleteName', $personalTeam->name)
        ->call('deleteTeam')
        ->assertForbidden();

    $this->assertDatabaseHas('teams', [
        'id' => $personalTeam->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertForbidden();
});

test('guests cannot access teams', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});
