<?php

use App\Enums\TeamRole;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('deleting a group removes it for good, along with memberships and invitations', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $group->id,
        'invited_by' => $this->admin->id,
    ]);

    $quiz = Quiz::factory()->published()->create();
    $quiz->teams()->attach($group);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('deleteConfirmation', 'Lehrjahr 2026')
        ->call('deleteGroup')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.groups.index'));

    // Hart, nicht soft: auch withTrashed findet die Zeile nicht mehr.
    expect(Team::withTrashed()->whereKey($group->id)->exists())->toBeFalse();

    $this->assertDatabaseMissing('team_members', ['team_id' => $group->id]);
    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseMissing('quiz_team', ['team_id' => $group->id]);

    // Ohne Häkchen bleibt der Lehrling — gruppenlos, aber vorhanden.
    $this->assertDatabaseHas('users', ['id' => $learner->id]);
    expect($learner->fresh()->current_team_id)->toBeNull();

    // Und er ist über den „ohne Gruppe"-Filter wieder auffindbar.
    $ids = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->set('groupFilter', User::NO_GROUP)
        ->instance()->users->pluck('id')->all();

    expect($ids)->toContain($learner->id);
});

test('the checkbox deletes the apprentice accounts as well', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $first = User::factory()->apprentice($group)->create();
    $second = User::factory()->apprentice($group)->create();

    LessonCompletion::factory()->create(['user_id' => $first->id, 'lesson_id' => $lesson->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('deleteApprentices', true)
        ->set('deleteConfirmation', 'Lehrjahr 2026')
        ->call('deleteGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('users', ['id' => $first->id]);
    $this->assertDatabaseMissing('users', ['id' => $second->id]);
    $this->assertDatabaseMissing('lesson_completions', ['user_id' => $first->id]);

    // Der Lerninhalt selbst bleibt.
    $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
});

test('a trainer inside the group is never deleted with it', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create();

    // Ein Ausbildner, der per Einladung als `member` in der Gruppe steckt —
    // genau der Fall, den eine Filterung über die Pivot-Rolle verpassen würde.
    $trainer = User::factory()->admin()->create();
    $group->members()->attach($trainer, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('deleteApprentices', true)
        ->set('deleteConfirmation', 'Lehrjahr 2026')
        ->call('deleteGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('users', ['id' => $learner->id]);
    $this->assertDatabaseHas('users', ['id' => $trainer->id]);
});

test('a mistyped group name deletes nothing', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('deleteApprentices', true)
        ->set('deleteConfirmation', 'Lehrjahr 2025')
        ->call('deleteGroup')
        ->assertHasErrors('deleteConfirmation');

    $this->assertDatabaseHas('teams', ['id' => $group->id]);
    $this->assertDatabaseHas('users', ['id' => $learner->id]);
});

test('reopening the delete dialog starts from scratch', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    User::factory()->apprentice($group)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        // Der Knopf muss über den Server öffnen. Stünde hier wieder ein
        // `flux:modal.trigger`, liefe der Reset unten nie — der Rest des Tests
        // wäre dann grün, ohne dass die Oberfläche geschützt ist.
        ->assertSee('wire:click="confirmDeleteGroup"', escape: false)
        // Der Dialog steht scharf: Häkchen gesetzt, Name exakt getippt …
        ->set('deleteApprentices', true)
        ->set('deleteConfirmation', 'Lehrjahr 2026')
        // … dann bricht der Admin ab und öffnet ihn erneut.
        ->call('confirmDeleteGroup')
        ->assertHasNoErrors()
        ->assertSet('deleteConfirmation', '')
        ->assertSet('deleteApprentices', false);

    // Der eigentliche Schaden, gegen den das schützt: ein einzelner Klick auf
    // „Endgültig löschen" darf die Gruppe jetzt nicht treffen.
    $this->assertDatabaseHas('teams', ['id' => $group->id]);
});

test('an apprentice cannot open the delete dialog', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create();

    Livewire::actingAs($learner)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->call('confirmDeleteGroup')
        ->assertForbidden();
});

test('an apprentice cannot delete a group', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create();

    Livewire::actingAs($learner)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->set('deleteConfirmation', 'Lehrjahr 2026')
        ->call('deleteGroup')
        ->assertForbidden();

    $this->assertDatabaseHas('teams', ['id' => $group->id]);
});

test('an expired invitation is marked as expired instead of claiming a future date', function () {
    $group = Team::factory()->create();

    TeamInvitation::factory()->expired()->create([
        'team_id' => $group->id,
        'invited_by' => $this->admin->id,
        'email' => 'abgelaufen@example.test',
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group]);

    $component->assertSee('data-test="invitation-expired-badge"', escape: false)
        ->assertSee('Abgelaufen vor')
        ->assertDontSee('Läuft ab vor');
});

test('a pending invitation still reads as expiring in the future', function () {
    $group = Team::factory()->create();

    TeamInvitation::factory()->expiresIn(3)->create([
        'team_id' => $group->id,
        'invited_by' => $this->admin->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->assertDontSee('data-test="invitation-expired-badge"', escape: false)
        ->assertSee('Läuft ab in');
});
