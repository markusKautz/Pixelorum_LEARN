<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
    $this->path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Grundlagen der Programmierung',
        'objectives' => ['Variablen erklären können', 'Schleifen anwenden können'],
    ]);
});

/**
 * Drei Lektionen in fester Reihenfolge — Grundlage mehrerer Tests.
 *
 * @return Collection<int, Lesson>
 */
function lessonsOf(LearningPath $path)
{
    return Lesson::factory()->for($path)->count(3)->sequence(
        ['title' => 'Erste Lektion', 'position' => 1],
        ['title' => 'Zweite Lektion', 'position' => 2],
        ['title' => 'Dritte Lektion', 'position' => 3],
    )->create();
}

test('an apprentice sees a released path', function () {
    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee('Grundlagen der Programmierung')
        ->assertSee('Das kannst du danach')
        ->assertSee('Variablen erklären können');
});

test('an apprentice is forbidden on unreleased paths', function (array $states) {
    $factory = LearningPath::factory();

    foreach ($states as $state) {
        $factory = $factory->{$state}();
    }

    $path = $factory->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $path]))
        ->assertForbidden();
})->with([
    'draft and unreviewed' => [[]],
    'published but unreviewed' => [['published']],
    'verified but draft' => [['verified']],
]);

test('an admin may preview a draft path', function () {
    $admin = User::factory()->admin()->create();
    $draft = LearningPath::factory()->create(['title' => 'Noch im Entwurf']);

    $this->actingAs($admin)
        ->get(learnRoute('learn.paths.show', $admin, ['learningPath' => $draft]))
        ->assertOk()
        ->assertSee('Noch im Entwurf');
});

test('the lesson list is ordered by position and shows the completion state', function () {
    $lessons = lessonsOf($this->path);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    $response = $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSeeInOrder(['Erste Lektion', 'Zweite Lektion', 'Dritte Lektion'])
        ->assertSee('1 von 3 Lektionen');

    // Erledigte Lektion bietet „Wiederholen", offene „Lesen".
    expect($response->getContent())->toContain('Wiederholen')->toContain('Lesen');
});

test('keep learning points at the first open lesson', function () {
    $lessons = lessonsOf($this->path);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee(route('learn.lessons.show', [$this->path, $lessons[1]], absolute: false), escape: false)
        ->assertSee('Weiterlernen');
});

test('an untouched path invites to start, not to keep learning', function () {
    $lessons = lessonsOf($this->path);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee(route('learn.lessons.show', [$this->path, $lessons[0]], absolute: false), escape: false)
        ->assertSee('Starten')
        ->assertDontSee('Weiterlernen');
});

test('a path without lessons shows an empty state', function () {
    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee('Dieser Pfad hat noch keine Lektionen.');
});

test('the quiz card is missing while the quiz is unpublished', function () {
    Quiz::factory()->pathFinal($this->path)->create(['title' => 'Abschlusstest']);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertDontSee('Abschlusstest')
        ->assertSee('Für diesen Pfad gibt es noch kein Abschluss-Quiz.');
});

test('the displayed threshold is the one of the quiz, not the one of the path', function () {
    $this->path->update(['pass_threshold' => 50]);

    $quiz = Quiz::factory()->pathFinal($this->path)->published()->create([
        'title' => 'Abschlusstest',
        'pass_threshold' => 80,
    ]);

    $quiz->questions()->attach(
        Question::factory()->singleChoice()->count(8)->create()->pluck('id')->all(),
    );

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee('Zum Bestehen brauchst du 80 %, also 7 von 8 Fragen.')
        ->assertDontSee('50 %');
});

test('the quiz card names the last own attempt and offers a repeat', function () {
    $quiz = Quiz::factory()->pathFinal($this->path)->published()->create();

    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'score' => 6,
        'max_score' => 8,
        'passed' => false,
    ]);

    // Fremder Versuch darf die eigene Anzeige nicht beeinflussen.
    QuizAttempt::factory()->passed()->create([
        'user_id' => User::factory()->apprentice()->create()->id,
        'quiz_id' => $quiz->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee('Dein letzter Versuch: 6 von 8 richtig (75 %), nicht bestanden')
        ->assertSee('Nochmal versuchen');
});

test('an open attempt turns the button into resume', function () {
    $quiz = Quiz::factory()->pathFinal($this->path)->published()->create();

    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.paths.show', $this->learner, ['learningPath' => $this->path]))
        ->assertOk()
        ->assertSee('Versuch fortsetzen');
});
