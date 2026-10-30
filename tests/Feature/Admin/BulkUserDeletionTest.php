<?php

use App\Actions\Users\DeleteUsers;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('an admin deletes several learners with their progress', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $learners = User::factory()->apprentice($group)->count(2)->create();
    $keeper = User::factory()->apprentice($group)->create();

    foreach ($learners as $learner) {
        LessonCompletion::factory()->create(['user_id' => $learner->id, 'lesson_id' => Lesson::factory()->create()->id]);
    }

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('selected', $learners->modelKeys())
        ->call('confirmBulkDelete')
        ->set('bulkDeleteConfirmation', '2')
        ->call('bulkDeleteUsers')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->assertDispatched('toast-show');

    foreach ($learners as $learner) {
        $this->assertDatabaseMissing('users', ['id' => $learner->id]);
        $this->assertDatabaseMissing('lesson_completions', ['user_id' => $learner->id]);
    }

    $this->assertDatabaseHas('users', ['id' => $keeper->id]);
});

test('admins and the acting admin are never part of a bulk deletion', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice()->create();

    // Der Dialog zählt nur, was tatsächlich gelöscht wird: hier ein_e Teilnehmer_in.
    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('selected', [$admin->id, $otherAdmin->id, $learner->id])
        ->call('confirmBulkDelete')
        ->set('bulkDeleteConfirmation', '1')
        ->call('bulkDeleteUsers')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('users', ['id' => $learner->id]);
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
    $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
});

test('the action skips admins and the actor', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice()->create();

    $deleted = app(DeleteUsers::class)->handle([$admin->id, $otherAdmin->id, $learner->id], $admin);

    expect($deleted)->toBe(1);
    $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    $this->assertDatabaseMissing('users', ['id' => $learner->id]);
});

test('a wrong count deletes nothing', function () {
    $admin = User::factory()->admin()->create();
    $learners = User::factory()->apprentice()->count(2)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('selected', $learners->modelKeys())
        ->call('confirmBulkDelete')
        ->set('bulkDeleteConfirmation', '3')
        ->call('bulkDeleteUsers')
        ->assertHasErrors('bulkDeleteConfirmation');

    $this->assertDatabaseCount('users', 3);
});

test('the dialog names up to ten learners and counts the rest', function () {
    $admin = User::factory()->admin()->create();
    $learners = User::factory()->apprentice()->count(12)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('selected', $learners->modelKeys())
        ->call('confirmBulkDelete')
        ->assertSeeHtmlInOrder(['data-test="bulk-delete-names"', __('and :count more', ['count' => 2])])
        ->assertCount('selectedUsers', 12);
});

test('changing the filter clears the selection', function () {
    $admin = User::factory()->admin()->create();
    $learner = User::factory()->apprentice()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->set('selected', [$learner->id])
        ->set('search', 'irgendwer')
        ->assertSet('selected', []);
});
