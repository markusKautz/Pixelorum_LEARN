<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Enums\PathStatus;
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

test('an admin assigns a path to all running groups at once', function () {
    $admin = User::factory()->admin()->create();
    $running = Team::factory()->count(2)->create();
    $archived = Team::factory()->archived()->create();
    $path = LearningPath::factory()->create();
    $path->teams()->attach($running->first());

    Livewire::actingAs($admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('assignAllGroups')
        ->assertDispatched('toast-show');

    expect($path->teams()->pluck('teams.id')->sort()->values()->all())->toBe($running->modelKeys())
        ->and($path->teams()->whereKey($archived->id)->exists())->toBeFalse();
});

test('the path list shows how many groups see a path, or that none does', function () {
    $admin = User::factory()->admin()->create();
    $assigned = LearningPath::factory()->create();
    LearningPath::factory()->create();
    $assigned->teams()->attach(Team::factory()->create());

    $html = Livewire::actingAs($admin)->test('pages::admin.learning-paths.index')->html();

    expect(substr_count($html, 'data-test="path-groups-badge"'))->toBe(1)
        ->and(substr_count($html, 'data-test="path-no-group-badge"'))->toBe(1);
});

test('a visible path without groups shows a hint', function (PathStatus $status, bool $withGroup, bool $hint) {
    $admin = User::factory()->admin()->create();
    $path = LearningPath::factory()->create(['status' => $status]);

    if ($withGroup) {
        $path->teams()->attach(Team::factory()->create());
    }

    $component = Livewire::actingAs($admin)->test('pages::admin.learning-paths.edit', ['learningPath' => $path]);

    $hint
        ? $component->assertSeeHtml('data-test="path-no-group-hint"')
        : $component->assertDontSeeHtml('data-test="path-no-group-hint"');
})->with([
    'visible without group' => [PathStatus::Published, false, true],
    'visible with group' => [PathStatus::Published, true, false],
    'not visible without group' => [PathStatus::Draft, false, false],
]);

test('the group page lists the paths this group sees', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    LearningPath::factory()->published()->verified()->create(['title' => 'Ohne Gruppe']);
    $assigned = LearningPath::factory()->published()->verified()->create(['title' => 'Für diese Gruppe']);
    $foreign = LearningPath::factory()->published()->verified()->create(['title' => 'Für eine andere Gruppe']);
    $assigned->teams()->attach($group);
    $foreign->teams()->attach(Team::factory()->create());

    Livewire::actingAs($admin)
        ->test('pages::admin.groups.show', ['team' => $group])
        ->assertSee('Für diese Gruppe')
        ->assertDontSee('Ohne Gruppe')
        ->assertDontSee('Für eine andere Gruppe');
});

test('group analytics count only the paths this group sees', function () {
    $group = Team::factory()->create();
    $otherGroup = Team::factory()->create();
    User::factory()->apprentice($group)->create();
    $own = LearningPath::factory()->published()->verified()->create();
    $foreign = LearningPath::factory()->published()->verified()->create();
    $unassigned = LearningPath::factory()->published()->verified()->create();
    foreach ([$own, $foreign, $unassigned] as $path) {
        Lesson::factory()->for($path)->create();
    }
    $own->teams()->attach($group);
    $foreign->teams()->attach($otherGroup);

    $report = app(GroupProgressReport::class)->handle($group);

    expect(array_map(fn ($row) => $row->path->id, $report->paths))->toBe([$own->id])
        ->and($report->members[0]->totalPaths)->toBe(1);

    $summary = app(GroupProgressReport::class)->summarize(Team::query()->whereKey([$group->id, $otherGroup->id])->get());

    expect($summary[$group->id]->pathSlotCount)->toBe(1);
});
