<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Member->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);
});

test('team invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Member->value)
        ->call('createInvitation')
        ->assertForbidden();
});

test('team invitations can be cancelled by owner', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.cancel-invitation-modal', ['team' => $team])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertRedirect(route('dashboard'));

    expect(session('team-invitation-accepted'))->toBeTrue();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
});

test('accepted invitation toast is shown on the dashboard', function () {
    $user = User::factory()->create();

    session()->flash('team-invitation-accepted', true);

    $this->actingAs($user);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDispatched('toast-show');
});

test('pending invitations excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Expired Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDontSee('Expired Team');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by user that wasnt invited', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($uninvitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

function createPendingInvitation(string $email = 'invited@example.com'): TeamInvitation
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);
}

test('unsigned accept url is rejected', function () {
    $invitation = createPendingInvitation();

    $this->get(route('invitations.accept', $invitation))->assertForbidden();
});

test('signed accept url shows registration form to guests', function () {
    $invitation = createPendingInvitation();

    $this->get(URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertSee('data-test="register-user-button"', false)
        ->assertSee($invitation->team->name)
        ->assertSee('Einladung annehmen');
});

test('invitation emails are stored lowercase', function () {
    $invitation = createPendingInvitation('Neuer.Lehrling@Example.COM');

    expect($invitation->email)->toBe('neuer.lehrling@example.com');
});

test('guests with an existing account in different casing are redirected to login', function () {
    $invitation = createPendingInvitation('Invited@Example.COM');
    User::factory()->create(['email' => 'invited@example.com']);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->assertRedirect(route('login', ['invitation' => $invitation->code]));
});

test('registering with empty fields shows german server errors (no native required)', function () {
    // F6: ohne das native required blockt der Browser den Submit nicht mehr —
    // die deutsche Server-Validierung ist der einzige und sichtbare Pfad.
    $invitation = createPendingInvitation();

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->set('name', '')
        ->set('password', '')
        ->call('register')
        ->assertHasErrors(['name' => 'required', 'password' => 'required'])
        ->assertSee('muss ausgefüllt werden');
});

test('guests can register through an invitation', function () {
    $invitation = createPendingInvitation();

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'Neuer Lehrling')
        ->set('password', 'sicheres-passwort')
        ->set('password_confirmation', 'sicheres-passwort')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'invited@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('sicheres-passwort', $user->password))->toBeTrue()
        ->and($user->teamRole($invitation->team))->toBe(TeamRole::Member)
        ->and($user->teams()->count())->toBe(1)
        ->and($user->personalTeam())->toBeNull()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('guests with an existing account are redirected to login and auto-accept after login', function () {
    $invitation = createPendingInvitation();
    $existingUser = User::factory()->create(['email' => 'invited@example.com']);

    $signedUrl = URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]);

    $this->get($signedUrl)
        ->assertRedirect(route('login', ['invitation' => $invitation->code]));

    expect(session('url.intended'))->toBe($signedUrl);

    $this->post(route('login.store'), [
        'email' => 'invited@example.com',
        'password' => 'password',
    ])->assertRedirect($signedUrl);

    $this->get($signedUrl)->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($existingUser->fresh()->belongsToTeam($invitation->team))->toBeTrue();
});

test('guests see a friendly message for expired invitations and cannot register', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->get(URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertSee('data-test="invitation-expired-callout"', false);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'Neuer Lehrling')
        ->set('password', 'sicheres-passwort')
        ->set('password_confirmation', 'sicheres-passwort')
        ->call('register')
        ->assertHasErrors(['invitation']);

    expect(User::where('email', 'invited@example.com')->exists())->toBeFalse();
});

test('guests see a friendly message for accepted invitations and cannot register', function () {
    $invitation = createPendingInvitation();
    $invitation->update(['accepted_at' => now()]);

    $this->get(URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertSee('data-test="invitation-accepted-callout"', false);

    Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'Neuer Lehrling')
        ->set('password', 'sicheres-passwort')
        ->set('password_confirmation', 'sicheres-passwort')
        ->call('register')
        ->assertHasErrors(['invitation']);

    expect(User::where('email', 'invited@example.com')->exists())->toBeFalse();
});

test('registration race with an existing account creates no duplicate user', function () {
    $invitation = createPendingInvitation();

    $component = Livewire::test('pages::teams.accept-invitation', ['invitation' => $invitation]);

    User::factory()->create(['email' => 'invited@example.com']);

    $component
        ->set('name', 'Neuer Lehrling')
        ->set('password', 'sicheres-passwort')
        ->set('password_confirmation', 'sicheres-passwort')
        ->call('register')
        ->assertRedirect(route('login', ['invitation' => $invitation->code]));

    expect(User::where('email', 'invited@example.com')->count())->toBe(1);
});

test('pending invitations modal redirects to a signed accept url', function () {
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $invitation = createPendingInvitation();

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertRedirect(URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]));
});

test('invitations stay valid for thirty days so exported links survive until the first day of class', function () {
    Notification::fake();
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->call('createInvitation')
        ->assertHasNoErrors();

    expect(TeamInvitation::firstWhere('email', 'invited@example.com')->expires_at)
        ->toBeBetween(now()->addDays(29), now()->addDays(31));
});

test('a failing mail transport does not undo the invitation', function () {
    Storage::fake('local');

    Notification::shouldReceive('route')->andThrow(new RuntimeException('Connection refused'));

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->call('createInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', ['email' => 'invited@example.com']);

    expect(Storage::disk('local')->files('invitations'))->toHaveCount(1);
});
