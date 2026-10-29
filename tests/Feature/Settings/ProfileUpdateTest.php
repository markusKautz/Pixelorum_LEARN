<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('emails are stored lowercase and case-only changes keep the verification', function () {
    $user = User::factory()->create(['email' => 'lehrling@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'Lehrling@Example.COM')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('lehrling@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    $this->assertGuest();
});

test('deleting the own account leaves no personal data behind', function () {
    $user = User::factory()->create();
    $personalTeamId = $user->personalTeam()->id;

    TeamInvitation::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'invited_by' => $user->id,
        'email' => $user->email,
    ]);
    Password::createToken($user);

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    // Dieselben Reste, die `DeleteUser` räumt; ein bloßes `->delete()` ließe sie stehen.
    $this->assertDatabaseMissing('team_invitations', ['email' => $user->email]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    expect(Team::withTrashed()->whereKey($personalTeamId)->exists())->toBeFalse();
});

test('the last admin does not see the account deletion', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::settings.profile')
        ->assertDontSeeHtml('data-test="delete-user-button"')
        ->assertSeeHtml('data-test="last-admin-callout"');
});

test('an admin with a fellow admin sees the account deletion', function () {
    User::factory()->admin()->create();

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::settings.profile')
        ->assertSeeHtml('data-test="delete-user-button"')
        ->assertDontSeeHtml('data-test="last-admin-callout"');
});

test('the last admin cannot delete their own account and stays logged in', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertForbidden();

    expect($admin->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($admin);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
