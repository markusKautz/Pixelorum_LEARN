<?php

use App\Enums\QuestionType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\ContentSourceFixture;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    ContentSourceFixture::bind();
});

afterEach(function () {
    ContentSourceFixture::clean();
});

test('a single-choice question is created with options and pool position', function () {
    $path = LearningPath::factory()->create();
    Question::factory()->for($path, 'learningPath')->create(['position' => 3]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Was ist eine Variable?')
        ->set('explanation', 'Ein benannter Platzhalter für einen Wert.')
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 7)
        ->set('learningPathId', $path->id)
        ->set('options', [
            ['id' => null, 'label' => 'Ein Platzhalter', 'is_correct' => true],
            ['id' => null, 'label' => 'Eine Schleife', 'is_correct' => false],
            ['id' => null, 'label' => 'Eine Funktion', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.questions.index'));

    $question = Question::firstWhere('prompt', 'Was ist eine Variable?');

    expect($question)->not->toBeNull()
        ->and($question->type)->toBe(QuestionType::Single)
        ->and($question->learning_path_id)->toBe($path->id)
        ->and($question->lesson_id)->toBeNull()
        ->and($question->position)->toBe(4)
        ->and($question->source_reference)->toBe([['source_id' => 'scriptum', 'page' => 7]])
        ->and($question->options()->count())->toBe(3)
        ->and($question->options()->where('is_correct', true)->count())->toBe(1)
        ->and($question->options()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);
});

test('a boolean question is created with fixed wahr/falsch options', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'boolean')
        ->set('prompt', 'PHP ist eine Programmiersprache?')
        ->set('explanation', 'Ja, PHP ist eine Skriptsprache.')
        ->set('options', [
            ['id' => null, 'label' => 'Wahr', 'is_correct' => true],
            ['id' => null, 'label' => 'Falsch', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $question = Question::firstWhere('prompt', 'PHP ist eine Programmiersprache?');

    expect($question->options()->orderBy('position')->pluck('label')->all())->toBe(['Wahr', 'Falsch']);
});

test('a multiple-choice question allows several correct options', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'multiple')
        ->set('prompt', 'Welche sind Schleifen?')
        ->set('explanation', 'for und while sind Schleifen.')
        ->set('options', [
            ['id' => null, 'label' => 'for', 'is_correct' => true],
            ['id' => null, 'label' => 'while', 'is_correct' => true],
            ['id' => null, 'label' => 'if', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Question::firstWhere('prompt', 'Welche sind Schleifen?')->options()->where('is_correct', true)->count())->toBe(2);
});

test('a check question gets a per-lesson position', function () {
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->create();
    Question::factory()->asLessonCheck($lesson)->create(['position' => 2]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Zwischen-Check-Frage?')
        ->set('explanation', 'Darum.')
        ->set('learningPathId', $path->id)
        ->set('lessonId', $lesson->id)
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $question = Question::firstWhere('prompt', 'Zwischen-Check-Frage?');

    expect($question->lesson_id)->toBe($lesson->id)
        ->and($question->position)->toBe(3);
});

test('type invariants are enforced', function (string $type, array $options) {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', $type)
        ->set('prompt', 'Kaputte Frage?')
        ->set('explanation', 'Begründung.')
        ->set('options', $options)
        ->call('save')
        ->assertHasErrors(['options']);

    expect(Question::firstWhere('prompt', 'Kaputte Frage?'))->toBeNull();
})->with([
    'single mit zwei korrekten' => ['single', [
        ['id' => null, 'label' => 'A', 'is_correct' => true],
        ['id' => null, 'label' => 'B', 'is_correct' => true],
    ]],
    'single ohne korrekte' => ['single', [
        ['id' => null, 'label' => 'A', 'is_correct' => false],
        ['id' => null, 'label' => 'B', 'is_correct' => false],
    ]],
    'single mit nur einer option' => ['single', [
        ['id' => null, 'label' => 'A', 'is_correct' => true],
    ]],
    'multiple ohne korrekte' => ['multiple', [
        ['id' => null, 'label' => 'A', 'is_correct' => false],
        ['id' => null, 'label' => 'B', 'is_correct' => false],
    ]],
    'boolean mit drei optionen' => ['boolean', [
        ['id' => null, 'label' => 'Wahr', 'is_correct' => true],
        ['id' => null, 'label' => 'Falsch', 'is_correct' => false],
        ['id' => null, 'label' => 'Vielleicht', 'is_correct' => false],
    ]],
    'boolean mit zwei korrekten' => ['boolean', [
        ['id' => null, 'label' => 'Wahr', 'is_correct' => true],
        ['id' => null, 'label' => 'Falsch', 'is_correct' => true],
    ]],
]);

test('a lesson of a foreign path is rejected', function () {
    $path = LearningPath::factory()->create();
    $foreignLesson = Lesson::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Frage?')
        ->set('explanation', 'Begründung.')
        ->set('learningPathId', $path->id)
        ->set('lessonId', $foreignLesson->id)
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasErrors(['lessonId']);
});

test('a missing explanation is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Frage ohne Begründung?')
        ->set('explanation', '')
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasErrors(['explanation' => 'required']);
});

test('editing keeps existing option ids and removes deleted ones', function () {
    $question = Question::factory()->singleChoice()->create();
    $options = $question->options()->orderBy('position')->get();

    $kept = $options->first();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->set('options', [
            ['id' => $kept->id, 'label' => 'Umbenannt', 'is_correct' => true],
            ['id' => null, 'label' => 'Ganz neu', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $question->refresh();

    expect($question->options()->count())->toBe(2)
        ->and($question->options()->whereKey($kept->id)->exists())->toBeTrue()
        ->and($question->options()->whereKey($kept->id)->first()->label)->toBe('Umbenannt')
        ->and($question->options()->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

test('switching the type resets correct markings', function () {
    $question = Question::factory()->singleChoice()->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->set('type', 'multiple');

    $component->assertSet('typeChangeNotice', true);

    foreach ($component->get('options') as $option) {
        expect($option['is_correct'])->toBeFalse();
    }
});

test('switching to boolean replaces the options with wahr/falsch', function () {
    $question = Question::factory()->singleChoice()->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->set('type', 'boolean');

    $options = $component->get('options');

    expect(array_column($options, 'label'))->toBe(['Wahr', 'Falsch'])
        ->and(array_column($options, 'is_correct'))->toBe([false, false]);
});

test('the filter scopes narrow down the pool', function () {
    $path = LearningPath::factory()->create();
    $other = LearningPath::factory()->create();

    $inPath = Question::factory()->for($path, 'learningPath')->boolean()->create(['prompt' => 'Gehört zum Pfad?']);
    Question::factory()->for($other, 'learningPath')->singleChoice()->create(['prompt' => 'Anderer Pfad?']);

    expect(Question::forPath($path->id)->pluck('id')->all())->toBe([$inPath->id])
        ->and(Question::ofType(QuestionType::Boolean)->pluck('id')->all())->toBe([$inPath->id])
        ->and(Question::search('gehört')->pluck('id')->all())->toBe([$inPath->id])
        ->and(Question::forPath(null)->count())->toBe(2)
        ->and(Question::search('100%_treffer')->count())->toBe(0);
});

test('a manipulated type value fails validation instead of crashing', function () {
    // Review-Nachtrag Sprint 3: QuestionType::from() vor der Validierung hätte
    // mit einer ValueError-500 geantwortet — tryFrom + Enum-Rule melden den
    // Fehler sauber am Feld.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'bogus')
        ->set('prompt', 'Manipulierte Frage?')
        ->set('explanation', 'Erklärung')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(Question::query()->where('prompt', 'Manipulierte Frage?')->exists())->toBeFalse();
});

test('the lesson filter narrows the pool to one lesson or to the lesson-less rest', function () {
    // Ein Pfad mit 10 Lektionen à 2–3 Zwischen-Checks ist ohne diesen Filter
    // unübersichtlich — er ersetzt den Typ-Filter im Fragen-Pool.
    $path = LearningPath::factory()->create();
    $first = Lesson::factory()->for($path)->create(['position' => 1]);
    $second = Lesson::factory()->for($path)->create(['position' => 2]);

    $firstCheck = Question::factory()->asLessonCheck($first)->create();
    Question::factory()->asLessonCheck($second)->create();
    $poolOnly = Question::factory()->for($path, 'learningPath')->singleChoice()->create();

    expect(Question::forLesson($first->id)->pluck('id')->all())->toBe([$firstCheck->id])
        ->and(Question::forLesson(Question::NO_LESSON)->pluck('id')->all())->toBe([$poolOnly->id])
        ->and(Question::forLesson(null)->count())->toBe(3)
        ->and(Question::forLesson('bogus')->count())->toBe(0);
});

test('the lesson filter stays locked until a path is chosen and resets when it changes', function () {
    $path = LearningPath::factory()->create();
    $otherPath = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->create();

    Question::factory()->asLessonCheck($lesson)->create(['prompt' => 'Check der ersten Lektion?']);
    Question::factory()->for($path, 'learningPath')->singleChoice()->create(['prompt' => 'Freie Pool-Frage?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->assertSee('Erst Lernpfad wählen')
        ->set('pathFilter', $path->id)
        ->assertSee('Check der ersten Lektion?')
        ->assertSee('Freie Pool-Frage?')
        ->set('lessonFilter', $lesson->id)
        ->assertSee('Check der ersten Lektion?')
        ->assertDontSee('Freie Pool-Frage?')
        ->set('lessonFilter', Question::NO_LESSON)
        ->assertSee('Freie Pool-Frage?')
        ->assertDontSee('Check der ersten Lektion?')
        // Lektionen hängen am Pfad: nach dem Wechsel wäre die Auswahl sonst
        // eine Lektion des alten Pfads und die Tabelle dauerhaft leer
        ->set('pathFilter', $otherPath->id)
        ->assertSet('lessonFilter', null);
});

test('the pool can be sorted by how often a question is used in quizzes', function () {
    // Absichtlich in dieser Reihenfolge angelegt: die Standardsortierung
    // (neueste zuerst) stellt $unused nach vorn, die Quiz-Sortierung $used.
    $used = Question::factory()->singleChoice()->create(['prompt' => 'In zwei Quizzen?']);
    $unused = Question::factory()->singleChoice()->create(['prompt' => 'In keinem Quiz?']);

    Quiz::factory()->count(2)->create()
        ->each(fn (Quiz $quiz) => $quiz->questions()->attach($used->id, ['position' => 1]));

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->assertSeeHtmlInOrder([$unused->prompt, $used->prompt])
        ->call('sort', 'quizzes_count')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder([$used->prompt, $unused->prompt])
        ->call('sort', 'quizzes_count')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeHtmlInOrder([$unused->prompt, $used->prompt]);
});

test('a manipulated sort column never reaches the query', function () {
    // orderBy() bindet Spaltennamen nicht als Parameter — ohne die Whitelist
    // in sort() ginge der Wert roh in die Query.
    Question::factory()->singleChoice()->create(['prompt' => 'Trotz Sortier-Manipulation sichtbar?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->call('sort', 'prompt) --')
        ->assertOk()
        ->assertSet('sortBy', 'id')
        ->assertSet('sortDirection', 'desc')
        ->assertSee('Trotz Sortier-Manipulation sichtbar?');
});

test('a manipulated lesson filter renders an empty pool instead of crashing', function () {
    // Über die UI nicht erreichbar (Feld ist ohne Pfad deaktiviert), wohl aber
    // per manipuliertem Livewire-Payload. Das Gegenstück für den Typ-Filter
    // liegt beim Quiz-Builder, der ihn weiterhin führt (QuizBuilderTest).
    Question::factory()->singleChoice()->create(['prompt' => 'Nur ohne Filtermanipulation sichtbar?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->set('lessonFilter', 'bogus')
        ->assertOk()
        ->assertDontSee('Nur ohne Filtermanipulation sichtbar?');
});

test('a long prompt is clipped in the pool table instead of running into the next column', function () {
    // Flux-Tabellen setzen whitespace-nowrap: ohne truncate-Wrapper zeichnet die
    // Fragestellung über die Typ-Spalte (Browser-Test S3). Str::limit() half nicht,
    // weil eine Zeichen-Grenze nichts über die Spaltenbreite in Pixeln aussagt.
    $prompt = str_repeat('Eine sehr lange Fragestellung ', 8).'?';

    Question::factory()->singleChoice()->create(['prompt' => $prompt]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->assertSeeHtml('<span class="block max-w-md truncate" title="'.e($prompt).'">');
});

test('deleting a question removes its quiz pivot entries', function () {
    $question = Question::factory()->singleChoice()->create();
    $quiz = Quiz::factory()->create();
    $quiz->questions()->attach($question->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.index')
        ->call('deleteQuestion', $question->id);

    expect(Question::find($question->id))->toBeNull()
        ->and($quiz->questions()->count())->toBe(0);
});

test('the form deletes the question, removes it from its quizzes and returns to the list', function () {
    $question = Question::factory()->singleChoice()->create();
    $quiz = Quiz::factory()->create();
    $quiz->questions()->attach($question->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->assertSeeHtml('data-test="question-delete-button"')
        ->assertSee('Sie steckt in 1 Quiz')
        ->call('deleteQuestion')
        ->assertRedirect(route('admin.questions.index'));

    expect(Question::find($question->id))->toBeNull()
        ->and($quiz->questions()->count())->toBe(0);
});

test('a new question offers saving at the top but no delete button', function () {
    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->assertDontSeeHtml('data-test="question-delete-button"')
        ->assertSeeHtml('data-test="question-save-button"')
        ->html();

    expect($html)->toContain('<form id="question-form" wire:submit="save"')
        ->and($html)->toMatch('/<button[^>]*form="question-form"[^>]*data-test="question-save-icon-button"/');
});

test('persisting a complete two-reference list works', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Zwei Quellen?')
        ->set('explanation', 'Beide sind richtig.')
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 12)
        ->set('sourceReference.references.1.source_id', 'it-osi')
        ->set('sourceReference.references.1.slide', 4)
        ->set('learningPathId', $path->id)
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $question = Question::firstWhere('prompt', 'Zwei Quellen?');

    expect($question->source_reference)->toBe([
        ['source_id' => 'scriptum', 'page' => 12],
        ['source_id' => 'it-osi', 'slide' => 4],
    ]);
});

test('add and remove reference rows', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form');

    $component->call('addSourceReference');
    expect(count($component->get('sourceReference.references')))->toBe(2);

    $component->call('removeSourceReference', 0);
    expect(count($component->get('sourceReference.references')))->toBe(1);
});

test('invalid page and slide together gives sourceReference.references error', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Mehrere Locators?')
        ->set('explanation', 'Falsch.')
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 3)
        ->set('sourceReference.references.0.slide', 4)
        ->set('learningPathId', $path->id)
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasErrors(['sourceReference.references']);

    expect(Question::firstWhere('prompt', 'Mehrere Locators?'))->toBeNull();
});

test('malformed scalar row gives sourceReference.references.0 error', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('sourceReference.references.0', 'not-an-array')
        ->call('save')
        ->assertHasErrors(['sourceReference.references.0']);
});

test('clearing all references stores null', function () {
    $question = Question::factory()->singleChoice()->withSourceReference()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->set('sourceReference.references.0.source_id', '')
        ->set('sourceReference.references.0.slide', null)
        ->call('save')
        ->assertHasNoErrors();

    expect($question->refresh()->source_reference)->toBeNull();
});

test('apprentices are forbidden on question pages and actions', function () {
    $apprentice = User::factory()->apprentice()->create();
    $question = Question::factory()->singleChoice()->create();

    $this->actingAs($apprentice)->get(route('admin.questions.index'))->assertForbidden();
    $this->actingAs($apprentice)->get(route('admin.questions.create'))->assertForbidden();
    $this->actingAs($apprentice)->get(route('admin.questions.edit', $question))->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->call('addSourceReference')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->call('removeSourceReference', 0)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.questions.index')
        ->call('deleteQuestion', $question->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->call('deleteQuestion')
        ->assertForbidden();

    expect(Question::find($question->id))->not->toBeNull();
});

test('questions can be saved without references', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form')
        ->set('type', 'single')
        ->set('prompt', 'Ohne Quelle?')
        ->set('explanation', 'Kein Beleg nötig.')
        ->set('learningPathId', $path->id)
        ->set('options', [
            ['id' => null, 'label' => 'A', 'is_correct' => true],
            ['id' => null, 'label' => 'B', 'is_correct' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $question = Question::firstWhere('prompt', 'Ohne Quelle?');

    expect($question->source_reference)->toBeNull();
});

test('stable rowKeys survive editing source_id', function () {
    $question = Question::factory()->withSourceReference()->create();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question]);

    $initialKeys = $component->get('sourceReference.rowKeys');
    $initialKey = $initialKeys[0];

    $component->set('sourceReference.references.0.source_id', 'it-osi');

    $updatedKeys = $component->get('sourceReference.rowKeys');

    expect($updatedKeys[0])->toBe($initialKey);
});

test('a question in a final quiz cannot leave the pool of that path', function (string $change) {
    $path = LearningPath::factory()->create();
    $question = Question::factory()->for($path, 'learningPath')->singleChoice()->create();
    Quiz::factory()->pathFinal($path)->create()->questions()->attach($question->id, ['position' => 1]);

    $form = Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question]);

    if ($change === 'other path') {
        $form->set('learningPathId', LearningPath::factory()->create()->id);
    } else {
        $form->set('lessonId', Lesson::factory()->for($path)->create()->id);
    }

    $form->call('save')->assertHasErrors([$change === 'other path' ? 'learningPathId' : 'lessonId']);

    expect($question->refresh()->learning_path_id)->toBe($path->id)
        ->and($question->lesson_id)->toBeNull();
})->with(['other path', 'check question']);

test('a question in a custom quiz may change its path', function () {
    $path = LearningPath::factory()->create();
    $target = LearningPath::factory()->create();
    $question = Question::factory()->for($path, 'learningPath')->singleChoice()->create();
    Quiz::factory()->create()->questions()->attach($question->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.questions.form', ['question' => $question])
        ->set('learningPathId', $target->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($question->refresh()->learning_path_id)->toBe($target->id);
});
