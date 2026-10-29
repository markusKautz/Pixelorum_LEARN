<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('an admin creates another admin from the user list', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('newAdminName', 'Bruno Ausbildner')
        ->set('newAdminEmail', 'Bruno@Example.com')
        ->set('newAdminPassword', 'startpasswort')
        ->call('createAdmin')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertSee('Bruno Ausbildner');

    $created = User::query()->where('email', 'bruno@example.com')->firstOrFail();

    expect($created->is_admin)->toBeTrue()
        ->and($created->email_verified_at)->not->toBeNull()
        ->and(Hash::check('startpasswort', $created->password))->toBeTrue()
        ->and($created->personalTeam())->not->toBeNull();

    $this->actingAs($created)->get(route('admin.users.index'))->assertOk();
});

test('invalid input creates no admin', function (array $input, string $errorField) {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('newAdminName', $input['name'] ?? 'Bruno Ausbildner')
        ->set('newAdminEmail', $input['email'] ?? 'bruno@example.com')
        ->set('newAdminPassword', $input['password'] ?? 'startpasswort')
        ->call('createAdmin')
        ->assertHasErrors($errorField);

    expect(User::query()->where('is_admin', true)->count())->toBe(1);
})->with([
    'missing name' => [['name' => ''], 'newAdminName'],
    'invalid email' => [['email' => 'kein-mail'], 'newAdminEmail'],
    'too short password' => [['password' => 'kurz'], 'newAdminPassword'],
]);

test('an email already in use is rejected, even in different case', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice()->create(['email' => 'lena@example.com']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('newAdminName', 'Lena Lehrling')
        ->set('newAdminEmail', 'Lena@Example.com')
        ->set('newAdminPassword', 'startpasswort')
        ->call('createAdmin')
        ->assertHasErrors('newAdminEmail');

    // Befördern bleibt bei `lapp:make-admin` (E3a).
    expect($learner->fresh()->is_admin)->toBeFalse();
    $this->assertDatabaseCount('users', 2);
});

test('apprentices cannot create admins', function () {
    $learner = User::factory()->apprentice()->create();

    Livewire::actingAs($learner)
        ->test('pages::admin.users.index')
        ->assertForbidden();

    expect(User::query()->where('is_admin', true)->exists())->toBeFalse();
});
