<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/**
 * Ein gelöschter Pfad mit allem, was ein endgültiges Löschen mitnimmt: Lektion,
 * Zwischen-Check, Pool-Frage, Abschluss-Quiz, Abschluss, Versuch und Antwort.
 *
 * @return array<string, mixed>
 */
function papierkorbFixture(): array
{
    $lehrling = User::factory()->apprentice()->create();
    $pfad = LearningPath::factory()->create(['title' => 'Gelöschter Pfad', 'position' => 1]);
    $lektion = Lesson::factory()->for($pfad)->create();
    $check = Question::factory()->singleChoice()->for($pfad)->asLessonCheck($lektion)->create();
    $poolFrage = Question::factory()->singleChoice()->for($pfad)->create();
    $quiz = Quiz::factory()->pathFinal($pfad)->create();
    $quiz->questions()->attach($poolFrage->id, ['position' => 1]);

    $abschluss = LessonCompletion::factory()->create([
        'user_id' => $lehrling->id,
        'lesson_id' => $lektion->id,
    ]);
    $versuch = QuizAttempt::factory()->create([
        'user_id' => $lehrling->id,
        'quiz_id' => $quiz->id,
    ]);
    $antwort = QuestionResponse::factory()->create([
        'quiz_attempt_id' => $versuch->id,
        'question_id' => $poolFrage->id,
    ]);

    $pfad->delete();

    return [
        'lehrling' => $lehrling,
        'pfad' => $pfad,
        'lektion' => $lektion,
        'check' => $check,
        'poolFrage' => $poolFrage,
        'quiz' => $quiz,
        'abschluss' => $abschluss,
        'versuch' => $versuch,
        'antwort' => $antwort,
    ];
}

test('a deleted path shows up in the trash and not in the main list', function () {
    $trashed = LearningPath::factory()->create(['title' => 'Weggeräumt']);
    $trashed->delete();
    $living = LearningPath::factory()->create(['title' => 'Im Betrieb']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSee('Weggeräumt')
        ->assertSee('Im Betrieb')
        ->assertSet('trashedPaths', fn ($paths) => $paths->pluck('id')->all() === [$trashed->id])
        ->assertSet('paths', fn ($paths) => $paths->pluck('id')->all() === [$living->id]);
});

test('the trash section stays away while nothing is deleted', function () {
    LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertDontSeeHtml('data-test="paths-trash"');
});

test('restoring puts the path back at the end of the order', function () {
    $trashed = LearningPath::factory()->create(['position' => 2]);
    $trashed->delete();
    LearningPath::factory()->create(['position' => 1]);
    LearningPath::factory()->create(['position' => 2]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('restorePath', $trashed->id);

    $trashed->refresh();

    expect($trashed->trashed())->toBeFalse()
        ->and($trashed->position)->toBe(3);
});

test('restoring keeps lessons, questions and the progress of the apprentices', function () {
    ['pfad' => $pfad, 'lektion' => $lektion, 'poolFrage' => $poolFrage, 'abschluss' => $abschluss] = papierkorbFixture();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('restorePath', $pfad->id);

    expect($pfad->fresh()->trashed())->toBeFalse()
        ->and(Lesson::find($lektion->id))->not->toBeNull()
        ->and(Question::find($poolFrage->id))->not->toBeNull()
        ->and(LessonCompletion::find($abschluss->id))->not->toBeNull();
});

test('the trash actions only ever load deleted paths', function (string $action) {
    $living = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call($action, $living->id)
        ->assertNotFound();

    expect($living->fresh())->not->toBeNull()
        ->and($living->fresh()->trashed())->toBeFalse();
})->with(['restorePath', 'forceDeletePath']);

test('the trash actions reject an unknown id', function (string $action) {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call($action, 9999)
        ->assertNotFound();
})->with(['restorePath', 'forceDeletePath']);

test('deleting permanently removes the path with its content and learner data', function () {
    [
        'pfad' => $pfad,
        'lektion' => $lektion,
        'check' => $check,
        'poolFrage' => $poolFrage,
        'quiz' => $quiz,
        'abschluss' => $abschluss,
        'versuch' => $versuch,
        'antwort' => $antwort,
    ] = papierkorbFixture();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('forceDeletePath', $pfad->id);

    expect(LearningPath::withTrashed()->find($pfad->id))->toBeNull()
        ->and(Lesson::find($lektion->id))->toBeNull()
        ->and(Question::find($check->id))->toBeNull()
        ->and(Question::find($poolFrage->id))->toBeNull()
        ->and(QuestionOption::where('question_id', $poolFrage->id)->count())->toBe(0)
        ->and(Quiz::find($quiz->id))->toBeNull()
        ->and(DB::table('quiz_questions')->where('quiz_id', $quiz->id)->count())->toBe(0)
        ->and(LessonCompletion::find($abschluss->id))->toBeNull()
        ->and(QuizAttempt::find($versuch->id))->toBeNull()
        ->and(QuestionResponse::find($antwort->id))->toBeNull();
});

test('a custom quiz survives the permanent deletion but loses the questions of that path', function () {
    ['pfad' => $pfad, 'poolFrage' => $poolFrage] = papierkorbFixture();

    $freiesQuiz = Quiz::factory()->create();
    $eigeneFrage = Question::factory()->singleChoice()->create();
    $freiesQuiz->questions()->attach([
        $poolFrage->id => ['position' => 1],
        $eigeneFrage->id => ['position' => 2],
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('forceDeletePath', $pfad->id);

    expect(Quiz::find($freiesQuiz->id))->not->toBeNull()
        ->and($freiesQuiz->fresh()->questions->pluck('id')->all())->toBe([$eigeneFrage->id]);
});

/*
 * Die Besitzsemantik aus E3, andere Richtung: Der Pfad besitzt seine Fragen,
 * ein Quiz verweist nur auf sie. Ohne diesen Test hätte die Cascade-Migration
 * niemanden, der sie gegen eine spätere „vereinheitlichende" Änderung schützt.
 */
test('deleting a quiz does not delete its questions', function () {
    $pfad = LearningPath::factory()->create();
    $frage = Question::factory()->singleChoice()->for($pfad)->create();
    $quiz = Quiz::factory()->pathFinal($pfad)->create();
    $quiz->questions()->attach($frage->id, ['position' => 1]);

    $quiz->delete();

    expect(Question::find($frage->id))->not->toBeNull()
        ->and(DB::table('quiz_questions')->where('quiz_id', $quiz->id)->count())->toBe(0);
});

test('the confirmation dialog names the learner data it takes along', function () {
    ['pfad' => $pfad] = papierkorbFixture();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSeeHtml('data-test="force-delete-impact"')
        ->assertSee('1 erledigte Lektionen, 1 Quiz-Versuche und 1 Antworten von Teilnehmer_innen werden mitgelöscht.');
});

test('the confirmation dialog stays harmless without any learner data', function () {
    $pfad = LearningPath::factory()->create();
    Lesson::factory()->for($pfad)->create();
    $pfad->delete();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSee('Mit diesem Pfad hat noch niemand gearbeitet.');
});

test('the confirmation dialog warns about other quizzes losing questions', function () {
    ['pfad' => $pfad, 'poolFrage' => $poolFrage] = papierkorbFixture();

    Quiz::factory()->create()->questions()->attach($poolFrage->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSee('1 weiteres Quiz verliert die Fragen, die es aus diesem Pfad übernommen hat.');
});

test('apprentices reach neither the page nor the trash actions', function () {
    $apprentice = User::factory()->apprentice()->create();
    $trashed = LearningPath::factory()->create();
    $trashed->delete();

    $this->actingAs($apprentice)->get('/admin/learning-paths')->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('restorePath', $trashed->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('forceDeletePath', $trashed->id)
        ->assertForbidden();

    expect(LearningPath::withTrashed()->find($trashed->id))->not->toBeNull();
});
