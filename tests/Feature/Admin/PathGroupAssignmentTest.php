<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('an admin assigns a path to a group and withdraws it again', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $path = LearningPath::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('toggleGroup', $group->id)
        ->assertDispatched('toast-show');

    expect($path->teams()->pluck('teams.id')->all())->toBe([$group->id]);

    $component->call('toggleGroup', $group->id);

    expect($path->teams()->exists())->toBeFalse();
});

test('a path cannot be assigned to a personal team', function () {
    $admin = User::factory()->admin()->create();
    $path = LearningPath::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('toggleGroup', $admin->personalTeam()->id)
        ->assertNotFound();

    expect($path->teams()->exists())->toBeFalse();
});

test('the path list marks paths limited to groups', function () {
    $admin = User::factory()->admin()->create();
    $limited = LearningPath::factory()->create(['title' => 'Eingeschränkt']);
    LearningPath::factory()->create(['title' => 'Allgemein']);
    $limited->teams()->attach(Team::factory()->create());

    $html = Livewire::actingAs($admin)->test('pages::admin.learning-paths.index')->html();

    expect(substr_count($html, 'data-test="path-limited-badge"'))->toBe(1);
});

test('the group page lists the paths this group sees', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $general = LearningPath::factory()->published()->verified()->create(['title' => 'Für alle']);
    $assigned = LearningPath::factory()->published()->verified()->create(['title' => 'Für diese Gruppe']);
    $foreign = LearningPath::factory()->published()->verified()->create(['title' => 'Für eine andere Gruppe']);
    $assigned->teams()->attach($group);
    $foreign->teams()->attach(Team::factory()->create());

    Livewire::actingAs($admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->assertSee('Für alle')
        ->assertSee('Für diese Gruppe')
        ->assertDontSee('Für eine andere Gruppe')
        ->assertSeeHtml('data-test="group-path-assigned-badge"');

    expect($general->teams()->exists())->toBeFalse();
});

test('group analytics count only the paths this group sees', function () {
    $group = Team::factory()->create();
    $otherGroup = Team::factory()->create();
    User::factory()->apprentice($group)->create();
    $general = LearningPath::factory()->published()->verified()->create();
    $foreign = LearningPath::factory()->published()->verified()->create();
    Lesson::factory()->for($general)->create();
    Lesson::factory()->for($foreign)->create();
    $foreign->teams()->attach($otherGroup);

    $report = app(GroupProgressReport::class)->handle($group);

    expect(array_map(fn ($row) => $row->path->id, $report->paths))->toBe([$general->id])
        ->and($report->members[0]->totalPaths)->toBe(1);

    $summary = app(GroupProgressReport::class)->summarize(Team::query()->whereKey([$group->id, $otherGroup->id])->get());

    expect($summary[$group->id]->pathSlotCount)->toBe(1);
});
