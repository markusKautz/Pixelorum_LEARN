<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Enums\TeamRole;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 1']);
});

/**
 * Complete the first $count lessons of the path for the given apprentice.
 */
function completeLessons(User $learner, LearningPath $path, int $count): void
{
    foreach ($path->lessons()->take($count)->get() as $lesson) {
        LessonCompletion::factory()->create([
            'user_id' => $learner->id,
            'lesson_id' => $lesson->id,
        ]);
    }
}

test('the per path block averages the reading progress over all members', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    Lesson::factory()->for($path)->count(4)->sequence(
        ['position' => 1], ['position' => 2], ['position' => 3], ['position' => 4],
    )->create();

    $eager = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    $slow = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);
    User::factory()->apprentice($this->group)->create(['name' => 'Clara']);

    completeLessons($eager, $path, 4);
    completeLessons($slow, $path, 2);

    $report = app(GroupProgressReport::class)->handle($this->group);

    // (100 + 50 + 0) / 3 = 50
    expect($report->paths)->toHaveCount(1)
        ->and($report->paths[0]->averagePercent)->toBe(50)
        ->and($report->paths[0]->completedCount)->toBe(1)
        ->and($report->paths[0]->memberCount)->toBe(3);
});

test('the pass rate uses all members as the denominator', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $passer = User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($this->group)->create();

    QuizAttempt::factory()->passed()->create([
        'user_id' => $passer->id,
        'quiz_id' => $quiz->id,
    ]);

    $report = app(GroupProgressReport::class)->handle($this->group);

    // 1 von 4 Mitgliedern — nicht „1 von 1 Angetretenen".
    expect($report->paths[0]->passedCount)->toBe(1)
        ->and($report->paths[0]->passRate())->toBe(25);
});

test('members appear alphabetically with their own averages', function () {
    $first = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => 1]);
    $second = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => 2]);
    Lesson::factory()->for($first)->count(2)->sequence(['position' => 1], ['position' => 2])->create();
    Lesson::factory()->for($second)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

    $bruno = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);
    $anna = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);

    completeLessons($anna, $first, 2);
    completeLessons($bruno, $second, 1);

    $report = app(GroupProgressReport::class)->handle($this->group);

    expect($report->members)->toHaveCount(2)
        ->and($report->members[0]->member->id)->toBe($anna->id)
        // Anna: 100 % + 0 % über zwei Pfade
        ->and($report->members[0]->averagePercent)->toBe(50)
        ->and($report->members[0]->completedPaths)->toBe(1)
        ->and($report->members[1]->member->id)->toBe($bruno->id)
        // Bruno: 0 % + 50 %
        ->and($report->members[1]->averagePercent)->toBe(25)
        ->and($report->members[1]->completedPaths)->toBe(0);
});

test('only published and verified paths enter the group aggregates', function () {
    LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    LearningPath::factory()->published()->create();
    LearningPath::factory()->verified()->create();

    User::factory()->apprentice($this->group)->create();

    expect(app(GroupProgressReport::class)->handle($this->group)->paths)->toHaveCount(1);
});

test('a member is marked inactive according to the group threshold', function () {
    $this->group->update(['inactivity_threshold_days' => 7]);

    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $recent = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    $stale = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);
    User::factory()->apprentice($this->group)->create(['name' => 'Clara']);

    LessonCompletion::factory()->create([
        'user_id' => $recent->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(3),
    ]);

    LessonCompletion::factory()->create([
        'user_id' => $stale->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(10),
    ]);

    $report = app(GroupProgressReport::class)->handle($this->group);

    expect($report->members[0]->isInactive)->toBeFalse()
        ->and($report->members[1]->isInactive)->toBeTrue()
        // Nie aktiv gilt ebenfalls als inaktiv.
        ->and($report->members[2]->lastActivityAt)->toBeNull()
        ->and($report->members[2]->isInactive)->toBeTrue()
        ->and($report->summary->inactiveCount)->toBe(2);
});

test('a raised threshold keeps the same member active', function () {
    $this->group->update(['inactivity_threshold_days' => 30]);

    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $learner = User::factory()->apprentice($this->group)->create();

    LessonCompletion::factory()->create([
        'user_id' => $learner->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(20),
    ]);

    expect(app(GroupProgressReport::class)->handle($this->group)->members[0]->isInactive)->toBeFalse();
});

test('the summary counts completed path slots out of all path slots', function () {
    $first = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => 1]);
    $second = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => 2]);
    Lesson::factory()->for($first)->create();
    Lesson::factory()->for($second)->create();

    $anna = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);

    completeLessons($anna, $first, 1);

    $summary = app(GroupProgressReport::class)->handle($this->group)->summary;

    // 2 Mitglieder × 2 Pfade = 4 Belegungen, davon eine abgeschlossen.
    expect($summary->memberCount)->toBe(2)
        ->and($summary->pathSlotCount)->toBe(4)
        ->and($summary->completedPathCount)->toBe(1)
        ->and($summary->averagePercent)->toBe(25);
});

test('the summary sorts every member into exactly one reading progress band', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    Lesson::factory()->for($path)->count(4)->sequence(
        ['position' => 1], ['position' => 2], ['position' => 3], ['position' => 4],
    )->create();

    // 0, 25, 50, 75 und 100 Prozent: jede Bereichsgrenze einmal getroffen.
    foreach (range(0, 4) as $completed) {
        $learner = User::factory()->apprentice($this->group)->create();
        completeLessons($learner, $path, $completed);
    }

    $summary = app(GroupProgressReport::class)->handle($this->group)->summary;

    expect($summary->progressBands)->toBe([
        'below25' => 1,
        'below50' => 1,
        'below75' => 1,
        'from75' => 2,
    ])->and(array_sum($summary->progressBands))->toBe($summary->memberCount);
});

test('a group without members has empty progress bands', function () {
    expect(app(GroupProgressReport::class)->handle($this->group)->summary->progressBands)
        ->toBe(['below25' => 0, 'below50' => 0, 'below75' => 0, 'from75' => 0]);
});

test('summarize reports every group separately', function () {
    $other = Team::factory()->create(['name' => 'Lehrjahr 2']);

    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    Lesson::factory()->for($path)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

    $mine = User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($other)->create();

    completeLessons($mine, $path, 2);

    $summaries = app(GroupProgressReport::class)->summarize(
        Team::query()->where('is_personal', false)->get()
    );

    expect($summaries->get($this->group->id)->averagePercent)->toBe(100)
        ->and($summaries->get($this->group->id)->completedPathCount)->toBe(1)
        ->and($summaries->get($other->id)->averagePercent)->toBe(0)
        ->and($summaries->get($other->id)->completedPathCount)->toBe(0);
});

test('summarize returns an empty collection without groups', function () {
    expect(app(GroupProgressReport::class)->summarize(new Collection))->toBeEmpty();
});

test('the group report stays within its query budget regardless of size', function () {
    // N+1-Regressionsschutz: Mitglieder × Pfade werden über wenige
    // GROUP-BY-Abfragen geholt und in PHP verrechnet — die Abfragezahl darf
    // mit keiner der beiden Dimensionen wachsen.
    foreach (range(1, 4) as $index) {
        $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => $index]);
        Lesson::factory()->for($path)->count(3)->create();
        Quiz::factory()->pathFinal($path)->published()->create();
    }

    foreach (range(1, 5) as $index) {
        User::factory()->apprentice($this->group)->create(['name' => "Teilnehmer_in {$index}"]);
    }

    app(GroupProgressReport::class)->handle($this->group->fresh());

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    app(GroupProgressReport::class)->handle($this->group->fresh());

    expect($queries)->toBeLessThan(10);
});

test('an admin sitting in the group is no apprentice', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    Lesson::factory()->for($path)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

    $learner = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    completeLessons($learner, $path, 2);

    // Zwei erreichbare Wege, wie ein Admin in einer Gruppe landet: das
    // allgemeine „Team erstellen" hängt ihn als Owner an, eine angenommene
    // Gruppen-Einladung als member. Beide dürfen die Quote nicht verwässern.
    $this->group->members()->attach(
        User::factory()->admin()->create(['name' => 'Ausbildner']),
        ['role' => TeamRole::Owner->value],
    );
    $this->group->members()->attach(
        User::factory()->admin()->create(['name' => 'Co-Trainerin']),
        ['role' => TeamRole::Member->value],
    );

    $report = app(GroupProgressReport::class)->handle($this->group->fresh());

    // Ohne den Filter stünden hier drei Zeilen und ein Ø von 33 % statt 100 %.
    expect($report->members)->toHaveCount(1)
        ->and($report->members[0]->member->name)->toBe('Anna')
        ->and($report->summary->memberCount)->toBe(1)
        ->and($report->summary->averagePercent)->toBe(100);
});

test('the group summary ignores admins as well', function () {
    // Eigener Test, weil summarize() nicht über Team::apprentices() geht,
    // sondern über eine rohe Sammelabfrage — die Mengen müssen übereinstimmen.
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    Lesson::factory()->for($path)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

    $learner = User::factory()->apprentice($this->group)->create();
    completeLessons($learner, $path, 2);

    $this->group->members()->attach(
        User::factory()->admin()->create(),
        ['role' => TeamRole::Owner->value],
    );

    $summary = app(GroupProgressReport::class)
        ->summarize(Collection::make([$this->group->fresh()]))
        ->get($this->group->id);

    expect($summary->memberCount)->toBe(1)
        ->and($summary->averagePercent)->toBe(100);
});

test('the invitation duplicate check still sees every member', function () {
    // Gegenprobe zur Naht: members() darf nicht mitgewandert sein. Sonst
    // könnte ein Admin, der schon in der Gruppe sitzt, erneut eingeladen
    // werden.
    $admin = User::factory()->admin()->create();
    $this->group->members()->attach($admin, ['role' => TeamRole::Owner->value]);

    expect($this->group->members()->count())->toBe(1)
        ->and($this->group->apprentices()->count())->toBe(0);
});
