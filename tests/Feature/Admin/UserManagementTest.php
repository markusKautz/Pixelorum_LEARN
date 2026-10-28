<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Ausbildnerin']);
});

test('the list is fully in German', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);

    // Namen ohne die gesuchten Wörter: sonst bestünde die Behauptung schon
    // durch „Anna Ausbildnerin" und bewiese nichts über das Rollen-Badge.
    User::factory()->apprentice($group)->create(['name' => 'Max Mustermann']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->assertOk()
        ->assertSee('Lehrling')
        ->assertDontSee('Apprentice')
        // Die Paginierungszeile kommt aus Flux' eigenem View und läuft über
        // vier einzelne `__()`-Aufrufe.
        ->assertSee('Zeige 1 bis 2 von 2 Ergebnissen')
        ->assertDontSee('Showing');
});

test('the list shows apprentices and trainers with their group', function () {
    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create(['name' => 'Lena Lehrling']);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->assertOk()
        ->assertSee('Lena Lehrling')
        ->assertSee('Anna Ausbildnerin')
        ->assertSee('Lehrjahr 2026');

    expect($component->instance()->users->pluck('id')->all())
        ->toContain($learner->id, $this->admin->id);
});

test('a personal team never shows up as a group', function () {
    // Der Admin hat nur sein persönliches Team. Der Name wird hier aus der
    // Fixture gelesen statt abgeschrieben: stünde er als Literal im Test und
    // änderte die Factory ihn, ginge die Zusicherung ins Leere, ohne zu brechen.
    $personalName = $this->admin->personalTeam()?->name;

    expect($personalName)->toBe("Anna Ausbildnerin's Team");

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->assertSee('Anna Ausbildnerin')
        ->assertDontSee($personalName)
        ->assertSee('data-test="user-without-group"', escape: false);
});

test('the group filter narrows the list to one group', function () {
    $first = Team::factory()->create(['name' => 'Lehrjahr 1']);
    $second = Team::factory()->create(['name' => 'Lehrjahr 2']);

    $inFirst = User::factory()->apprentice($first)->create();
    $inSecond = User::factory()->apprentice($second)->create();

    $ids = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->set('groupFilter', $first->id)
        ->instance()->users->pluck('id')->all();

    expect($ids)->toContain($inFirst->id)
        ->and($ids)->not->toContain($inSecond->id)
        // Auch der Admin fällt heraus — er gehört der Gruppe nicht an.
        ->and($ids)->not->toContain($this->admin->id);
});

test('the without-a-group filter finds a learner whose group is gone', function () {
    $group = Team::factory()->create();
    $orphan = User::factory()->apprentice($group)->create(['name' => 'Ohne Gruppe']);
    $stillGrouped = User::factory()->apprentice($group)->create();

    // Genau der Zustand, den eine Gruppenlöschung ohne Mitlöschen hinterlässt.
    $group->members()->detach($orphan);

    $ids = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->set('groupFilter', User::NO_GROUP)
        ->instance()->users->pluck('id')->all();

    expect($ids)->toContain($orphan->id)
        ->and($ids)->not->toContain($stillGrouped->id);
});

test('the search matches name and email', function () {
    $group = Team::factory()->create();
    $byName = User::factory()->apprentice($group)->create(['name' => 'Rudolf Rohrleger']);
    $byMail = User::factory()->apprentice($group)->create([
        'name' => 'Berta Bauer',
        'email' => 'rohrpost@example.test',
    ]);
    $neither = User::factory()->apprentice($group)->create(['name' => 'Franz Fräser']);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.users.index');

    $ids = $component->set('search', 'rohr')->instance()->users->pluck('id')->all();

    // Kleinschreibung findet „Rudolf Rohrleger" (Name) und die Adresse — das ist
    // der `whereLike`-Fall, der auf PostgreSQL sonst nichts fände.
    expect($ids)->toContain($byName->id, $byMail->id)
        ->and($ids)->not->toContain($neither->id);
});

test('last activity is read for the listed users', function () {
    $this->travelTo(now()->setTime(9, 0));

    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $group = Team::factory()->create();
    $active = User::factory()->apprentice($group)->create();
    $never = User::factory()->apprentice($group)->create();

    LessonCompletion::factory()->create([
        'user_id' => $active->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(2),
    ]);

    $activity = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.index')
        ->instance()->lastActivity;

    expect($activity)->toHaveKey($active->id)
        ->and($activity[$active->id]->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($activity)->not->toHaveKey($never->id);
});

test('the analytics link appears only for an apprentice with a group', function () {
    $group = Team::factory()->create();
    $learner = User::factory()->apprentice($group)->create();
    $orphan = User::factory()->apprentice($group)->create();
    $group->members()->detach($orphan);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.users.index');

    $component->assertSee(
        route('admin.groups.learner', ['team' => $group, 'member' => $learner]),
        escape: false,
    );

    // Weder für den Ausbildner noch für den Lehrling ohne Gruppe gibt es ein
    // Ziel — die Route braucht `{team}/{member}`.
    expect($component->instance()->analyticsGroup($this->admin))->toBeNull()
        ->and($component->instance()->analyticsGroup($orphan))->toBeNull()
        ->and($component->instance()->analyticsGroup($learner)?->id)->toBe($group->id);
});

test('apprentices are forbidden on the user list', function () {
    $learner = User::factory()->apprentice()->create();

    $this->actingAs($learner)->get(route('admin.users.index'))->assertForbidden();

    Livewire::actingAs($learner)
        ->test('pages::admin.users.index')
        ->assertForbidden();
});

test('the user list stays within its query budget', function () {
    $group = Team::factory()->create();
    User::factory()->apprentice($group)->count(12)->create();

    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk();

    // Ohne Eager Loading und die gebündelte Aktivitäts-Abfrage wären es ~3 je Zeile.
    expect($queries)->toBeLessThan(15);
});
