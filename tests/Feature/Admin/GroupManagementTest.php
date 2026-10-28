<?php

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('the list shows only non-personal groups', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    Team::factory()->personal()->create(['name' => 'Persönliches Team']);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.groups.index');

    $ids = $component->instance()->groups->pluck('id')->all();

    expect($ids)->toContain($group->id)
        ->and($component->instance()->groups->pluck('name')->all())->not->toContain('Persönliches Team');
});

test('archiving a year is reversible and deletes nothing', function () {
    $group = Team::factory()->create();
    $member = User::factory()->apprentice($group)->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->call('toggleArchived');

    expect($group->fresh()->isArchived())->toBeTrue()
        ->and($group->fresh()->apprentices()->pluck('users.id')->all())->toContain($member->id);

    $component->call('toggleArchived');

    expect($group->fresh()->isArchived())->toBeFalse();
});

test('an archived group stays listed and reachable, sorted last', function () {
    $archived = Team::factory()->archived()->create(['name' => 'AAA Lehrjahr 1']);
    $running = Team::factory()->create(['name' => 'ZZZ Lehrjahr 3']);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.groups.index');

    // Trotz alphabetischem Vorrang steht der abgeschlossene Jahrgang hinten.
    expect($component->instance()->groups->pluck('id')->all())
        ->toBe([$running->id, $archived->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $archived])
        ->assertOk();
});

test('creating a group attaches no membership and leaves the admin switcher untouched', function () {
    $membershipsBefore = Membership::where('user_id', $this->admin->id)->count();
    $currentTeamBefore = $this->admin->current_team_id;

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.index')
        ->set('name', 'Lehrjahr 2026')
        ->call('createGroup')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.groups.show', ['team' => 'lehrjahr-2026']));

    $group = Team::firstWhere('slug', 'lehrjahr-2026');

    expect($group)->not->toBeNull()
        ->and($group->is_personal)->toBeFalse()
        ->and($group->memberships()->count())->toBe(0)
        ->and($group->owner())->toBeNull()
        ->and(Membership::where('user_id', $this->admin->id)->count())->toBe($membershipsBefore)
        ->and($this->admin->refresh()->current_team_id)->toBe($currentTeamBefore);
});

test('an admin without membership can invite from the group page with admin redirect', function () {
    Notification::fake();

    $group = Team::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::teams.invite-member-modal', ['team' => $group, 'redirectRoute' => 'admin.groups.show'])
        ->set('inviteEmail', 'lehrling@example.test')
        ->call('createInvitation')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.groups.show', ['team' => $group->slug]));

    expect($group->invitations()->where('email', 'lehrling@example.test')->exists())->toBeTrue();

    Notification::assertSentOnDemand(TeamInvitationNotification::class);
});

test('the group page offers no role selection when inviting', function () {
    $group = Team::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->assertDontSeeHtml('data-test="invite-role"');
});

test('group invitations are always created for apprentices even if the role is tampered with', function () {
    Notification::fake();

    $group = Team::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::teams.invite-member-modal', [
            'team' => $group,
            'redirectRoute' => 'admin.groups.show',
            'roleSelectable' => false,
        ])
        ->set('inviteEmail', 'lehrling@example.test')
        ->set('inviteRole', TeamRole::Admin->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    expect($group->invitations()->firstWhere('email', 'lehrling@example.test')->role)
        ->toBe(TeamRole::Member);
});

test('cancelling an invitation from the admin page redirects back to it', function () {
    $group = Team::factory()->create();
    $invitation = $group->invitations()->create([
        'email' => 'lehrling@example.test',
        'role' => TeamRole::Member,
        'invited_by' => $this->admin->id,
        'expires_at' => now()->addDays(3),
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::teams.cancel-invitation-modal', [
            'team' => $group,
            'invitationCode' => $invitation->code,
            'invitationEmail' => $invitation->email,
            'redirectRoute' => 'admin.groups.show',
        ])
        ->call('cancelInvitation')
        ->assertRedirect(route('admin.groups.show', ['team' => $group->slug]));

    expect($group->invitations()->count())->toBe(0);
});

test('removing a member from the admin page redirects back to it', function () {
    $group = Team::factory()->create();
    $apprentice = User::factory()->apprentice($group)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::teams.remove-member-modal', [
            'team' => $group,
            'memberId' => $apprentice->id,
            'memberName' => $apprentice->name,
            'redirectRoute' => 'admin.groups.show',
        ])
        ->call('removeMember')
        ->assertRedirect(route('admin.groups.show', ['team' => $group->slug]));

    expect($group->memberships()->where('user_id', $apprentice->id)->exists())->toBeFalse();
});

test('the existing teams flow keeps its default redirect', function () {
    Notification::fake();

    $group = Team::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::teams.invite-member-modal', ['team' => $group])
        ->set('inviteEmail', 'lehrling@example.test')
        ->call('createInvitation')
        ->assertRedirect(route('teams.edit', ['team' => $group->slug]));
});

test('renaming a group persists and refreshes the slug', function () {
    $group = Team::factory()->create(['name' => 'Alter Name']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('name', 'Neuer Gruppenname')
        ->call('renameGroup')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.groups.show', ['team' => 'neuer-gruppenname']));

    expect($group->refresh()->name)->toBe('Neuer Gruppenname');
});

test('a group name whose slug collides with a reserved route prefix is rejected', function () {
    // „Admin!" passiert die reine Namensprüfung („admin!" ist nicht reserviert),
    // Str::slug('Admin!') ergäbe aber 'admin' — die Rule muss die Slug-Form fangen.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.index')
        ->set('name', 'Admin!')
        ->call('createGroup')
        ->assertHasErrors(['name']);

    expect(Team::firstWhere('slug', 'admin'))->toBeNull();
});

test('a personal team is not reachable as a group page', function () {
    $personal = Team::factory()->personal()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.show', ['team' => $personal->slug]))
        ->assertNotFound();
});

test('apprentices are forbidden on all group routes and actions', function () {
    $group = Team::factory()->create();
    $apprentice = User::factory()->apprentice($group)->create();

    $this->actingAs($apprentice)->get(route('admin.groups.index'))->assertForbidden();
    $this->actingAs($apprentice)->get(route('admin.groups.show', ['team' => $group->slug]))->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.groups.index')
        ->set('name', 'Versuch')
        ->call('createGroup')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('name', 'Umbenannt')
        ->call('renameGroup')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->call('toggleArchived')
        ->assertForbidden();

    expect(Team::firstWhere('name', 'Versuch'))->toBeNull()
        ->and($group->refresh()->name)->not->toBe('Umbenannt')
        ->and($group->refresh()->isArchived())->toBeFalse();
});

test('an admin inside a group is counted nowhere as an apprentice', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);

    User::factory()->apprentice($group)->create(['name' => 'Anna Auer']);
    $group->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);

    // Gruppenliste: die Mitglieder-Spalte zählt Lehrlinge, nicht Mitgliedschaften.
    $list = Livewire::actingAs($this->admin)->test('pages::admin.groups.index');

    expect($list->instance()->groups->firstWhere('id', $group->id)->apprentices_count)->toBe(1);

    // Gruppenseite: der Ausbildner steht nicht in der Mitgliederliste …
    $show = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->assertSee('Anna Auer');

    expect($show->instance()->members->pluck('name')->all())->toBe(['Anna Auer']);

    // … obwohl er sehr wohl Mitglied ist.
    expect($group->members()->count())->toBe(2);
});
