<?php

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('a custom quiz is created with the admin as creator', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Freies Übungsquiz')
        ->set('kind', 'custom')
        ->call('createQuiz')
        ->assertHasNoErrors();

    $quiz = Quiz::firstWhere('title', 'Freies Übungsquiz');

    expect($quiz)->not->toBeNull()
        ->and($quiz->kind)->toBe(QuizKind::Custom)
        ->and($quiz->learning_path_id)->toBeNull()
        ->and($quiz->created_by)->toBe($this->admin->id);
});

test('the creator cannot be dictated by the payload', function () {
    $stranger = User::factory()->admin()->create();

    // `created_by` ist massenzuweisbar (nötig für BuildPracticeQuiz, siehe
    // Kommentar am Quiz-Model) — die Komponente bietet dafür aber keine
    // Angriffsfläche: es gibt schlicht keine solche Property, die ein Client
    // setzen könnte. Der Wert kommt ausschließlich aus Auth::id().
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Untergeschoben')
        ->set('kind', 'custom');

    expect(fn () => $component->set('created_by', $stranger->id))
        ->toThrow(PublicPropertyNotFoundException::class);

    $component->call('createQuiz')->assertHasNoErrors();

    expect(Quiz::firstWhere('title', 'Untergeschoben')->created_by)
        ->toBe($this->admin->id);
});

test('a path_final quiz is created with its path', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Abschluss-Quiz')
        ->set('kind', 'path_final')
        ->set('newQuizPathId', $path->id)
        ->call('createQuiz')
        ->assertHasNoErrors()
        ->assertRedirect();

    $quiz = Quiz::firstWhere('title', 'Abschluss-Quiz');

    expect($quiz->kind)->toBe(QuizKind::PathFinal)
        ->and($quiz->learning_path_id)->toBe($path->id);
});

test('a path_final quiz without a path fails validation', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Kaputtes Quiz')
        ->set('kind', 'path_final')
        ->call('createQuiz')
        ->assertHasErrors(['newQuizPathId']);

    expect(Quiz::firstWhere('title', 'Kaputtes Quiz'))->toBeNull();
});

test('the model guard remains the backstop for the path_final invariant', function () {
    Quiz::create([
        'title' => 'Direkt am Formular vorbei',
        'kind' => QuizKind::PathFinal,
        'learning_path_id' => null,
    ]);
})->throws(InvalidArgumentException::class);

test('a second path_final quiz for the same path fails validation', function () {
    $path = LearningPath::factory()->create();
    Quiz::factory()->pathFinal($path)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Zweites Abschluss-Quiz')
        ->set('kind', 'path_final')
        ->set('newQuizPathId', $path->id)
        ->call('createQuiz')
        ->assertHasErrors(['newQuizPathId']);
});

test('switching a quiz to path_final on an occupied path fails validation', function () {
    $path = LearningPath::factory()->create();
    Quiz::factory()->pathFinal($path)->create();
    $quiz = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('kind', 'path_final')
        ->set('quizPathId', $path->id)
        ->call('saveQuiz')
        ->assertHasErrors(['quizPathId']);

    expect($quiz->refresh()->kind)->toBe(QuizKind::Custom);
});

test('switching a final quiz to custom drops its path and hides the select', function () {
    $quiz = Quiz::factory()->pathFinal()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertSeeHtml('data-test="quiz-path-select"')
        ->set('kind', 'custom')
        ->assertSet('quizPathId', null)
        ->assertDontSeeHtml('data-test="quiz-path-select"')
        ->call('saveQuiz')
        ->assertHasNoErrors();

    expect($quiz->refresh()->kind)->toBe(QuizKind::Custom)
        ->and($quiz->learning_path_id)->toBeNull();
});

test('a manipulated payload cannot attach a path to a custom quiz', function () {
    // Das Select wird für `custom` nicht gerendert — der Wert kann nur aus
    // einem gefälschten Livewire-Payload stammen.
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('quizPathId', $path->id)
        ->call('saveQuiz')
        ->assertHasNoErrors();

    expect($quiz->refresh()->learning_path_id)->toBeNull();
});

test('the edit select greys out occupied paths but keeps the quiz own path (F7)', function () {
    $occupied = LearningPath::factory()->create();
    $ownPath = LearningPath::factory()->create();
    Quiz::factory()->pathFinal($occupied)->create();
    $quiz = Quiz::factory()->pathFinal($ownPath)->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz]);

    // Fremd-belegter Pfad ist gesperrt; der eigene Pfad des Quiz nicht.
    expect($component->instance()->occupiedPathIds)
        ->toContain($occupied->id)
        ->not->toContain($ownPath->id);

    // Der belegte Pfad ist im gerenderten Select gekennzeichnet.
    $component->assertSee('hat bereits ein Abschluss-Quiz');
});

test('the paths select for new final quizzes hides occupied paths', function () {
    $occupied = LearningPath::factory()->create();
    $free = LearningPath::factory()->create();
    Quiz::factory()->pathFinal($occupied)->create();

    $component = Livewire::actingAs($this->admin)->test('pages::admin.quizzes.index');

    $ids = $component->instance()->pathsWithoutFinalQuiz->pluck('id')->all();

    expect($ids)->toContain($free->id)->not->toContain($occupied->id);
});

test('adding, removing and sorting questions keeps the quiz order intact', function () {
    $quiz = Quiz::factory()->create();
    [$first, $second, $third] = Question::factory()->count(3)->singleChoice()->create();

    $builder = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz]);

    $builder->call('addQuestion', $first->id);
    $builder->call('addQuestion', $second->id);
    $builder->call('addQuestion', $third->id);

    expect($quiz->questions()->pluck('questions.id')->all())->toBe([$first->id, $second->id, $third->id]);

    // Umsortieren: dritte Frage an den Anfang — schreibt kompakte Positionen
    $builder->call('sortQuestions', $third->id, 0);

    expect($quiz->refresh()->questions()->pluck('questions.id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and($quiz->questions()->pluck('quiz_questions.position')->all())->toBe([1, 2, 3]);

    // Entfernen darf eine Positions-Lücke lassen (Entscheidung 10) …
    $builder->call('removeQuestion', $first->id);

    expect($quiz->refresh()->questions()->pluck('questions.id')->all())->toBe([$third->id, $second->id]);

    // … und Neuzugänge landen per max+1 trotzdem am Ende
    $builder->call('addQuestion', $first->id);

    expect($quiz->refresh()->questions()->pluck('questions.id')->all())->toBe([$third->id, $second->id, $first->id]);
});

test('adding the same question twice keeps a single pivot row', function () {
    $quiz = Quiz::factory()->create();
    $question = Question::factory()->singleChoice()->create();

    $builder = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz]);

    $builder->call('addQuestion', $question->id);
    $builder->call('addQuestion', $question->id);

    expect($quiz->questions()->count())->toBe(1)
        ->and($quiz->questions()->first()->pivot->position)->toBe(1);
});

test('the pass threshold bounds are enforced', function (int $threshold) {
    $quiz = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('pass_threshold', $threshold)
        ->call('saveQuiz')
        ->assertHasErrors(['pass_threshold']);
})->with([0, 101]);

test('saving the quiz persists all fields', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('title', 'Neuer Titel')
        ->set('description', 'Neue Beschreibung')
        ->set('kind', 'path_final')
        ->set('quizPathId', $path->id)
        ->set('pass_threshold', 70)
        ->set('shuffleQuestions', true)
        ->call('saveQuiz')
        ->assertHasNoErrors();

    $quiz->refresh();

    expect($quiz->title)->toBe('Neuer Titel')
        ->and($quiz->description)->toBe('Neue Beschreibung')
        ->and($quiz->kind)->toBe(QuizKind::PathFinal)
        ->and($quiz->learning_path_id)->toBe($path->id)
        ->and($quiz->pass_threshold)->toBe(70)
        ->and($quiz->shuffle_questions)->toBeTrue();
});

test('the shuffle toggle starts off and reflects the stored value', function () {
    $quiz = Quiz::factory()->create();

    // Default false: bestehende Quizze behalten ihre kuratierte Reihenfolge.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertSet('shuffleQuestions', false);

    $quiz->update(['shuffle_questions' => true]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertSet('shuffleQuestions', true);
});

test('publishing and unpublishing toggles the status', function () {
    $quiz = Quiz::factory()->create();

    $builder = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz]);

    $builder->call('publish');
    expect($quiz->refresh()->status)->toBe(PathStatus::Published);

    $builder->call('unpublish');
    expect($quiz->refresh()->status)->toBe(PathStatus::Draft);
});

test('apprentices are forbidden on quiz pages and actions', function () {
    $apprentice = User::factory()->apprentice()->create();
    $quiz = Quiz::factory()->create();
    $question = Question::factory()->singleChoice()->create();

    $this->actingAs($apprentice)->get(route('admin.quizzes.index'))->assertForbidden();
    $this->actingAs($apprentice)->get(route('admin.quizzes.edit', $quiz))->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.index')
        ->set('title', 'Versuch')
        ->call('createQuiz')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.index')
        ->call('deleteQuiz', $quiz->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.index')
        ->call('publishQuiz', $quiz->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.index')
        ->call('unpublishQuiz', $quiz->id)
        ->assertForbidden();

    $builder = fn () => Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz]);

    $builder()->call('saveQuiz')->assertForbidden();
    $builder()->call('deleteQuiz')->assertForbidden();
    $builder()->call('publish')->assertForbidden();
    $builder()->call('addQuestion', $question->id)->assertForbidden();
    $builder()->call('removeQuestion', $question->id)->assertForbidden();
    $builder()->call('sortQuestions', $question->id, 0)->assertForbidden();

    expect(Quiz::find($quiz->id))->not->toBeNull()
        ->and($quiz->questions()->count())->toBe(0);
});

test('a manipulated pool type filter renders the pool unfiltered instead of crashing', function () {
    $quiz = Quiz::factory()->create();
    Question::factory()->singleChoice()->create(['prompt' => 'Sichtbar trotz Filtermanipulation?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('typeFilter', 'bogus')
        ->assertOk()
        ->assertSee('Sichtbar trotz Filtermanipulation?');
});

test('a quiz is published and withdrawn straight from the list', function () {
    $quiz = Quiz::factory()->create(['title' => 'Netzwerktest']);

    $list = Livewire::actingAs($this->admin)->test('pages::admin.quizzes.index');

    $list->call('publishQuiz', $quiz->id);

    expect($quiz->refresh()->status)->toBe(PathStatus::Published);

    // Die Zeile zeigt den neuen Stand sofort, ohne Neuladen der Seite.
    $list->assertSee('Sichtbar')->assertDontSee('Nicht sichtbar');

    $list->call('unpublishQuiz', $quiz->id);

    expect($quiz->refresh()->status)->toBe(PathStatus::Draft);

    $list->assertSee('Nicht sichtbar');
});

test('the publish dialog warns about a quiz nobody would see', function () {
    // Leer und keiner Gruppe ausgegeben: freigeben bewirkt hier nichts.
    Quiz::factory()->create(['title' => 'Leeres freies Quiz']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->assertSee('Dieses Quiz hat noch keine Fragen.')
        ->assertSee('Dieses Quiz ist keiner Gruppe ausgegeben. Sichtbar schalten allein gibt es noch nicht aus.');
});

test('the publish dialog stays quiet for a filled and handed out quiz', function () {
    $quiz = Quiz::factory()->create();
    $quiz->questions()->attach(Question::factory()->singleChoice()->create()->id, ['position' => 1]);
    $quiz->teams()->attach(Team::factory()->create()->id);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.index')
        ->assertDontSee('Dieses Quiz hat noch keine Fragen.')
        ->assertDontSee('Dieses Quiz ist keiner Gruppe ausgegeben. Sichtbar schalten allein gibt es noch nicht aus.');
});

/*
 * DEV-15: Ein Abschluss-Quiz nimmt nur Pool-Fragen seines Pfades, also genau
 * die Fragen, die `question_positions` im Content-JSON adressieren kann.
 */

test('a final quiz takes a pool question of its own path', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($path)->create();
    $question = Question::factory()->for($path, 'learningPath')->singleChoice()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->call('addQuestion', $question->id);

    expect($quiz->questions()->pluck('questions.id')->all())->toBe([$question->id]);
});

test('a final quiz rejects questions outside the pool of its path', function (string $origin) {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($path)->create();

    $question = match ($origin) {
        'foreign path' => Question::factory()->for(LearningPath::factory(), 'learningPath')->singleChoice()->create(),
        'free question' => Question::factory()->singleChoice()->create(),
        'own check question' => Question::factory()->asLessonCheck(Lesson::factory()->for($path)->create())->singleChoice()->create(),
    };

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->call('addQuestion', $question->id);

    expect($quiz->questions()->count())->toBe(0);

    // Ein freies Quiz bündelt weiterhin pfadübergreifend.
    $custom = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $custom])
        ->call('addQuestion', $question->id);

    expect($custom->questions()->pluck('questions.id')->all())->toBe([$question->id]);
})->with(['foreign path', 'free question', 'own check question']);

test('switching to path_final with a foreign question fails and leaves the quiz unchanged', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->create();
    $quiz->questions()->attach(Question::factory()->singleChoice()->create()->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('kind', 'path_final')
        ->set('quizPathId', $path->id)
        ->call('saveQuiz')
        ->assertHasErrors(['quizPathId']);

    expect($quiz->refresh()->kind)->toBe(QuizKind::Custom)
        ->and($quiz->learning_path_id)->toBeNull();
});

test('moving a final quiz to another path fails while it holds questions of the old one', function () {
    [$oldPath, $newPath] = LearningPath::factory()->count(2)->create();
    $quiz = Quiz::factory()->pathFinal($oldPath)->create();
    $quiz->questions()->attach(
        Question::factory()->for($oldPath, 'learningPath')->singleChoice()->create()->id,
        ['position' => 1],
    );

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('quizPathId', $newPath->id)
        ->call('saveQuiz')
        ->assertHasErrors(['quizPathId']);

    expect($quiz->refresh()->learning_path_id)->toBe($oldPath->id);
});

test('the pool of a final quiz only lists pool questions of its path', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($path)->create();
    $own = Question::factory()->for($path, 'learningPath')->singleChoice()->create();
    $check = Question::factory()->asLessonCheck(Lesson::factory()->for($path)->create())->singleChoice()->create();
    $foreign = Question::factory()->for(LearningPath::factory(), 'learningPath')->singleChoice()->create();

    $builder = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        // Ein manipulierter Filter löst die Sperre nicht.
        ->set('pathFilter', $foreign->learning_path_id);

    expect($builder->get('pathFilter'))->toBe($foreign->learning_path_id)
        ->and($builder->instance()->poolQuestions->pluck('id')->all())->toBe([$own->id]);

    $builder->assertDontSee($check->prompt)->assertDontSee($foreign->prompt);
});

test('legacy questions outside the pool are flagged and can be removed', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($path)->create();
    $foreign = Question::factory()->for(LearningPath::factory(), 'learningPath')->singleChoice()->create();
    $quiz->questions()->attach($foreign->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertSeeHtml('data-test="quiz-outside-pool-hint"')
        ->assertSeeHtml('data-test="quiz-question-outside-pool"')
        ->call('removeQuestion', $foreign->id)
        ->assertDontSeeHtml('data-test="quiz-outside-pool-hint"');

    expect($quiz->questions()->count())->toBe(0);
});

test('the editor deletes the quiz and returns to the list', function () {
    $quiz = Quiz::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertSeeHtml('data-test="quiz-delete-button"')
        ->call('deleteQuiz')
        ->assertRedirect(route('admin.quizzes.index'));

    expect(Quiz::find($quiz->id))->toBeNull();
});
