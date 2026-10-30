<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('an admin landing on the dashboard is sent to the admin dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('an apprentice keeps the learner dashboard', function () {
    $learner = User::factory()->apprentice()->create();

    $this->actingAs($learner)
        ->get(learnRoute('dashboard', $learner))
        ->assertOk()
        ->assertSee('Das sind deine Lernpfade.');
});

test('an apprentice is forbidden on the admin dashboard', function () {
    $learner = User::factory()->apprentice()->create();

    $this->actingAs($learner)
        ->get(route('admin.dashboard'))
        ->assertForbidden();

    Livewire::actingAs($learner)
        ->test('pages::admin.dashboard')
        ->assertForbidden();
});

test('the admin dashboard shows no learner grid', function () {
    $path = LearningPath::factory()->published()->verified()->create(['title' => 'Netzwerkgrundlagen']);
    Lesson::factory()->for($path)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Das sind deine Lernpfade.')
        ->assertSee('So steht deine Gruppe da.');
});

test('the cohort overview sums up all groups', function () {
    $first = Team::factory()->create(['name' => 'Lehrjahr 1']);
    $second = Team::factory()->create(['name' => 'Lehrjahr 2']);

    User::factory()->apprentice($first)->create();
    User::factory()->apprentice($first)->create();
    User::factory()->apprentice($second)->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.dashboard')
        ->assertOk();

    expect($component->instance()->summaries)->toHaveCount(2)
        ->and($component->instance()->learnerCount)->toBe(3);
});

test('the cohort overview counts apprentices active this week', function () {
    $this->travelTo(now()->setTime(9, 0));

    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    // Zwei Gruppen mit *verschiedenen* Inaktivitäts-Schwellen: das Fenster der
    // Karte ist fix (ACTIVE_WINDOW_DAYS), die Schwellen dürfen es nicht biegen.
    $group = Team::factory()->create(['inactivity_threshold_days' => 30]);
    $other = Team::factory()->create(['inactivity_threshold_days' => 3]);

    $recent = User::factory()->apprentice($group)->create();
    $stale = User::factory()->apprentice($other)->create();
    User::factory()->apprentice($other)->create(); // war noch nie aktiv

    LessonCompletion::factory()->create([
        'user_id' => $recent->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(3),
    ]);

    LessonCompletion::factory()->create([
        'user_id' => $stale->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(9),
    ]);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.dashboard');

    expect($component->instance()->activeCount)->toBe(1)
        ->and($component->instance()->learnerCount)->toBe(3)
        ->and($component->instance()->activePercent)->toBe(33);
});

test('an archived year drops out of the cohort figures', function () {
    $this->travelTo(now()->setTime(9, 0));

    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $running = Team::factory()->create(['name' => 'Lehrjahr 3']);
    $finished = Team::factory()->archived()->create(['name' => 'Lehrjahr 1']);

    User::factory()->apprentice($running)->create();
    $alumnus = User::factory()->apprentice($finished)->create();

    // Gestern noch aktiv — zählt trotzdem nicht, die Gruppe ist abgeschlossen.
    LessonCompletion::factory()->create([
        'user_id' => $alumnus->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.dashboard');

    expect($component->instance()->summaries)->toHaveCount(1)
        ->and($component->instance()->learnerCount)->toBe(1)
        ->and($component->instance()->activeCount)->toBe(0);
});

test('the admin dashboard works without any group', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.dashboard')
        ->assertOk();

    expect($component->instance()->learnerCount)->toBe(0)
        ->and($component->instance()->activeCount)->toBe(0)
        ->and($component->instance()->activePercent)->toBe(0);
});

test('the sidebar points the admin dashboard entry at the admin dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.dashboard'), escape: false);
});

test('the admin dashboard stays within its query budget', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    Lesson::factory()->for($path)->count(3)->create();

    foreach (range(1, 4) as $index) {
        $group = Team::factory()->create(['name' => "Gruppe {$index}"]);

        User::factory()->apprentice($group)->create();
        User::factory()->apprentice($group)->create();
    }

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

    expect($queries)->toBeLessThan(20);
});

test('the quick access leads to the users instead of repeating the groups', function () {
    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.dashboard')
        ->html();

    // Die Gruppen haben ihre eigene Schaltfläche über der Schnellwahl; ein
    // zweiter Weg dorthin wäre nur Rauschen.
    expect($html)->toContain(route('admin.users.index'))
        ->and(substr_count($html, route('admin.groups.index')))->toBe(1);
});
