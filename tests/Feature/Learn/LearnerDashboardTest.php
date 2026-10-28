<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
});

test('only published and verified paths appear on the dashboard', function () {
    $visible = LearningPath::factory()->published()->verified()->create(['title' => 'Sichtbarer Pfad']);
    LearningPath::factory()->published()->create(['title' => 'Ungeprüfter Pfad']);
    LearningPath::factory()->verified()->create(['title' => 'Entwurf trotz Freigabe']);
    LearningPath::factory()->create(['title' => 'Reiner Entwurf']);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee($visible->title)
        ->assertDontSee('Ungeprüfter Pfad')
        ->assertDontSee('Entwurf trotz Freigabe')
        ->assertDontSee('Reiner Entwurf');
});

test('the progress count only counts the apprentices own completions', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lessons = Lesson::factory()->for($path)->count(3)->sequence(
        ['position' => 1], ['position' => 2], ['position' => 3],
    )->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    // Fremde Completion derselben Lektion darf den eigenen Stand nicht heben.
    LessonCompletion::factory()->create([
        'user_id' => User::factory()->apprentice()->create()->id,
        'lesson_id' => $lessons[1]->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('1 von 3 Lektionen');
});

test('the final quiz badge turns to passed after a passed attempt', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Abschluss-Quiz offen')
        ->assertDontSee('Abschluss-Quiz bestanden');

    QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Abschluss-Quiz bestanden');
});

test('a passed attempt of another apprentice does not mark the quiz passed', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->passed()->create([
        'user_id' => User::factory()->apprentice()->create()->id,
        'quiz_id' => $quiz->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Abschluss-Quiz offen');
});

test('an unpublished final quiz is shown as no final quiz', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    Quiz::factory()->pathFinal($path)->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Kein Abschluss-Quiz');
});

test('the dashboard shows an empty state without visible paths', function () {
    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Es sind noch keine Lernpfade freigegeben.');
});

test('a completed path without a quiz shows review again and a completed badge', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create(['position' => 1]);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $content = $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Nochmal ansehen')
        ->assertSee('Abgeschlossen')
        ->assertSee('data-test="path-status-badge"', escape: false)
        ->getContent();

    /*
     * Der grüne Rahmen ist bewusst weg (Retest 2026-07-29, F5): flux:card setzt
     * border-zinc-200, dieselbe Spezifität, und im erzeugten Stylesheet steht
     * zinc nach green — im Browser war nie ein grüner Rahmen zu sehen. Die
     * frühere Assertion prüfte nur, dass die Klasse im HTML steht, und war
     * darum grün für ein unsichtbares Merkmal. Das Badge trägt das Signal.
     */
    expect($content)->not->toContain('border-green-500');
});

test('a path with all lessons read but an open quiz still shows resume', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    Quiz::factory()->pathFinal($path)->published()->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);
    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[1]->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Fortsetzen')
        ->assertDontSee('Nochmal ansehen')
        ->assertDontSee('Abgeschlossen');
});

test('a path with all lessons read but a failed quiz still shows resume', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);
    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[1]->id,
    ]);

    QuizAttempt::factory()->failed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Fortsetzen')
        ->assertDontSee('Nochmal ansehen')
        ->assertDontSee('Abgeschlossen');
});

test('the dashboard stays within its query budget regardless of the path count', function () {
    // N+1-Regressionsschutz: der Fortschritt je Pfad läuft über einen
    // gefilterten Sub-Count, die Quiz-Ergebnisse über eine aggregierte
    // Abfrage — die Zahl der Abfragen darf also nicht mit den Pfaden wachsen.
    foreach (range(1, 3) as $index) {
        $path = LearningPath::factory()->published()->verified()->create(['position' => $index]);
        Lesson::factory()->for($path)->count(5)->create();
        Quiz::factory()->pathFinal($path)->published()->create();
    }

    $this->actingAs($this->learner)->get(learnRoute('dashboard', $this->learner))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->learner)->get(learnRoute('dashboard', $this->learner))->assertOk();

    expect($queries)->toBeLessThan(15);
});
