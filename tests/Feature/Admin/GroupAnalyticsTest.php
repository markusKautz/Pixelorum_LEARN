<?php

use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
});

test('an apprentice is forbidden on the group analytics', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $this->actingAs($apprentice)
        ->get(route('admin.groups.analytics', $this->group))
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertForbidden();
});

test('an admin can open the group analytics', function () {
    User::factory()->apprentice($this->group)->create(['name' => 'Anna Auer']);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.analytics', $this->group))
        ->assertOk()
        ->assertSee('Lehrjahr 2026')
        ->assertSee('Anna Auer');
});

test('a personal team has no analytics page', function () {
    $personal = Team::factory()->personal()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.analytics', $personal))
        ->assertNotFound();
});

test('the per path block shows average, completions and pass rate over all members', function () {
    $path = LearningPath::factory()->published()->verified()->create(['title' => 'Netzwerke']);
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $done = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    $half = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);
    User::factory()->apprentice($this->group)->create(['name' => 'Clara']);
    User::factory()->apprentice($this->group)->create(['name' => 'Dario']);

    foreach ($lessons as $lesson) {
        LessonCompletion::factory()->create(['user_id' => $done->id, 'lesson_id' => $lesson->id]);
    }

    LessonCompletion::factory()->create(['user_id' => $half->id, 'lesson_id' => $lessons[0]->id]);

    QuizAttempt::factory()->passed()->create(['user_id' => $done->id, 'quiz_id' => $quiz->id]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertOk()
        ->instance()
        ->report;

    // (100 + 50 + 0 + 0) / 4 = 37
    expect($report->paths[0]->averagePercent)->toBe(37)
        ->and($report->paths[0]->completedCount)->toBe(1)
        // Nenner ist die ganze Gruppe: 1 von 4, nicht 1 von 1 Angetretenem.
        ->and($report->paths[0]->passedCount)->toBe(1)
        ->and($report->paths[0]->passRate())->toBe(25);
});

test('the summary card shows the apprentices per reading progress band', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();

    $done = User::factory()->apprentice($this->group)->create();
    $half = User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($this->group)->create();

    foreach ($lessons as $lesson) {
        LessonCompletion::factory()->create(['user_id' => $done->id, 'lesson_id' => $lesson->id]);
    }

    LessonCompletion::factory()->create(['user_id' => $half->id, 'lesson_id' => $lessons[0]->id]);

    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertOk()
        ->assertSee('Lehrlinge nach Lesefortschritt')
        ->assertSeeInOrder(['unter 25 %', '25 bis unter 50 %', '50 bis unter 75 %', 'ab 75 %'])
        ->assertDontSeeHtml('data-test="summary-completed"')
        ->html();

    $bandCount = function (string $band) use ($html): string {
        preg_match('/data-test="distribution-'.$band.'".*?<dd[^>]*>\s*(\d+)\s*<\/dd>/s', $html, $match);

        return $match[1] ?? '';
    };

    // 0 %, 50 % und 100 %.
    expect($bandCount('below25'))->toBe('1')
        ->and($bandCount('below50'))->toBe('0')
        ->and($bandCount('below75'))->toBe('1')
        ->and($bandCount('from75'))->toBe('1');
});

test('the group list shows the average column and links to the analytics', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $learner = User::factory()->apprentice($this->group)->create();
    User::factory()->apprentice($this->group)->create();

    LessonCompletion::factory()->create(['user_id' => $learner->id, 'lesson_id' => $lesson->id]);

    $summaries = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.index')
        ->assertOk()
        ->instance()
        ->summaries;

    // 2 Mitglieder × 1 Pfad = 2 Belegungen, eine davon fertig; Ø 50 %.
    expect($summaries->get($this->group->id)->averagePercent)->toBe(50)
        ->and($summaries->get($this->group->id)->completedPathCount)->toBe(1)
        ->and($summaries->get($this->group->id)->pathSlotCount)->toBe(2);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.index'))
        ->assertOk()
        ->assertSee(route('admin.groups.analytics', $this->group), escape: false)
        // Die Einheit steht im Label (Review M1): neben der Spalte „Mitglieder: 2"
        // las sich „1 von 2 fertig" als „1 von 2 Lehrlingen".
        ->assertSee('1 von 2 Pfaden fertig')
        ->assertDontSee('1 von 2 fertig');
});

test('the inactivity flag follows the threshold set on the group', function () {
    $this->travelTo(now()->setTime(9, 0));

    $this->group->update(['inactivity_threshold_days' => 7]);

    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();

    $active = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    $stale = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);

    LessonCompletion::factory()->create([
        'user_id' => $active->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(2),
    ]);

    LessonCompletion::factory()->create([
        'user_id' => $stale->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(9),
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group->fresh()])
        ->instance()
        ->report;

    expect($report->members[0]->isInactive)->toBeFalse()
        ->and($report->members[1]->isInactive)->toBeTrue()
        ->and($report->summary->inactiveCount)->toBe(1);
});

test('the inactivity threshold can be saved on the group page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $this->group])
        ->assertSet('inactivityThresholdDays', 14)
        ->set('inactivityThresholdDays', 21)
        ->call('saveThreshold')
        ->assertHasNoErrors();

    expect($this->group->refresh()->inactivity_threshold_days)->toBe(21);
});

test('the inactivity threshold rejects values outside one to 365 days', function (int $value) {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $this->group])
        ->set('inactivityThresholdDays', $value)
        ->call('saveThreshold')
        ->assertHasErrors(['inactivityThresholdDays']);

    expect($this->group->refresh()->inactivity_threshold_days)->toBe(14);
})->with([0, -1, 366, 400]);

test('the inactivity threshold error names the field in German', function () {
    /*
     * Ohne Eintrag in der attributes-Map humanisiert Laravel den Property-Namen
     * selbst und schreibt „Inactivity threshold days" in eine sonst deutsche
     * Meldung (Review N1). Der Schlüssel dort ist der camelCase-Name der
     * Livewire-Property, nicht die snake_case-Spalte dahinter.
     *
     * Bewusst über `update()` statt `set()->call()`: ein geleertes Zahlenfeld
     * lässt Livewire die typisierte int-Property zurücksetzen (uninitialisiert),
     * und über zwei getrennte Test-Requests käme beim Aufruf wieder der
     * Klassen-Default an. `wire:submit` schickt Wert und Aufruf zusammen — das
     * bildet nur ein einzelner Request ab.
     */
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.show', ['team' => $this->group])
        ->update(
            calls: [['method' => 'saveThreshold', 'params' => [], 'path' => '']],
            updates: ['inactivityThresholdDays' => ''],
        );

    $component->assertHasErrors(['inactivityThresholdDays']);

    expect($component->errors()->first('inactivityThresholdDays'))
        ->toBe('Inaktivitäts-Schwelle (Tage) muss ausgefüllt werden.');

    expect($this->group->refresh()->inactivity_threshold_days)->toBe(14);
});

test('an apprentice cannot save the inactivity threshold', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.groups.show', ['team' => $this->group])
        ->set('inactivityThresholdDays', 99)
        ->call('saveThreshold')
        ->assertForbidden();

    expect($this->group->refresh()->inactivity_threshold_days)->toBe(14);
});

test('the group analytics stay within their query budget', function () {
    foreach (range(1, 4) as $index) {
        $path = LearningPath::factory()->published()->verified()->create(['position' => $index]);
        Lesson::factory()->for($path)->count(3)->create();
        Quiz::factory()->pathFinal($path)->published()->create();
    }

    foreach (range(1, 6) as $index) {
        User::factory()->apprentice($this->group)->create(['name' => "Lehrling {$index}"]);
    }

    $this->actingAs($this->admin)->get(route('admin.groups.analytics', $this->group))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->admin)->get(route('admin.groups.analytics', $this->group))->assertOk();

    expect($queries)->toBeLessThan(20);
});

test('the group list stays within its query budget regardless of the group count', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    Lesson::factory()->for($path)->count(3)->create();

    foreach (range(1, 4) as $index) {
        $group = Team::factory()->create(['name' => "Gruppe {$index}"]);
        User::factory()->apprentice($group)->create();
        User::factory()->apprentice($group)->create();
    }

    $this->actingAs($this->admin)->get(route('admin.groups.index'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->admin)->get(route('admin.groups.index'))->assertOk();

    expect($queries)->toBeLessThan(20);
});

test('everything but the apprentice table is wrapped in the accordion', function () {
    $path = LearningPath::factory()->published()->verified()->create(['title' => 'Netzwerke']);
    Lesson::factory()->for($path)->create();
    User::factory()->apprentice($this->group)->create(['name' => 'Anna']);

    // Ohne ausgegebenes Quiz fehlt der zweite Accordion-Eintrag ganz.
    Quiz::factory()->published()->create(['kind' => QuizKind::Custom])
        ->teams()->attach($this->group);

    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertOk()
        ->html();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $insideAccordion = fn (string $test): bool => (bool) $xpath->query(
        '//*[@data-test="group-accordion"]//*[@data-test="'.$test.'"]'
    )->length;

    // Beides Aggregierte steckt im Accordion, die Lehrlings-Tabelle bleibt
    // offen darunter stehen (Sprint-5.6/U4, erweitert beim Retest 2026-07-29).
    // Gegenstück in der Lehrling-Auswertung: dort bleibt die Pfad-Tabelle offen.
    expect($insideAccordion('paths-table'))->toBeTrue()
        ->and($insideAccordion('assigned-quizzes-table'))->toBeTrue()
        ->and($insideAccordion('members-table'))->toBeFalse()
        ->and((bool) $xpath->query('//*[@data-test="members-table"]')->length)->toBeTrue();
});
