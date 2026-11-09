<?php

use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('the dialog is prefilled with the current name and lock', function (bool $locked) {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->create(['name' => 'Anna Muster', 'name_locked' => $locked]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $learner->id)
        ->assertSet('editingName', 'Anna Muster')
        ->assertSet('nameChangeable', ! $locked);
})->with(['locked' => true, 'free' => false]);

test('an admin renames an account and the lock stays as it was', function (bool $locked) {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->create(['name_locked' => $locked]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $learner->id)
        ->set('editingName', '  Neuer Name ')
        ->call('updateName')
        ->assertHasNoErrors();

    $learner->refresh();

    expect($learner->name)->toBe('Neuer Name')
        ->and($learner->name_locked)->toBe($locked);
})->with(['locked' => true, 'free' => false]);

test('the checkbox releases a locked name', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->nameLocked()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $learner->id)
        ->set('nameChangeable', true)
        ->call('updateName')
        ->assertHasNoErrors();

    expect($learner->fresh()->name_locked)->toBeFalse();
});

test('the checkbox locks a free name', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $learner->id)
        ->set('nameChangeable', false)
        ->call('updateName')
        ->assertHasNoErrors();

    expect($learner->fresh()->name_locked)->toBeTrue();
});

test('the checkbox is offered for participants but not for admins', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.users.index');

    $component->call('editName', $learner->id)
        ->assertSeeHtml('data-test="edit-user-name-changeable"');

    $component->call('editName', $admin->id)
        ->assertDontSeeHtml('data-test="edit-user-name-changeable"');
});

test('saving an admin account leaves its lock flag alone', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->nameLocked()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $otherAdmin->id)
        ->set('editingName', 'Neuer Name')
        ->set('nameChangeable', true)
        ->call('updateName')
        ->assertHasNoErrors();

    $otherAdmin->refresh();

    expect($otherAdmin->name)->toBe('Neuer Name')
        ->and($otherAdmin->name_locked)->toBeTrue();
});

test('an empty name is rejected and changes nothing', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice(Team::factory()->create())->create(['name' => 'Anna Muster']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('editName', $learner->id)
        ->set('editingName', '   ')
        ->call('updateName')
        ->assertHasErrors(['editingName' => 'required']);

    expect($learner->fresh()->name)->toBe('Anna Muster');
});

test('participants cannot reach the name dialog', function () {
    $learner = User::factory()->apprentice(Team::factory()->create())->create();

    Livewire::actingAs($learner)
        ->test('pages::admin.users.index')
        ->assertForbidden();
});
