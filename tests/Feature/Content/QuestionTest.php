<?php

use App\Enums\QuestionType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\UniqueConstraintViolationException;

it('legt eine Single-Choice-Frage mit genau einer richtigen von vier Optionen an', function () {
    $question = Question::factory()->singleChoice()->create();

    expect($question->type)->toBe(QuestionType::Single)
        ->and($question->options)->toHaveCount(4)
        ->and($question->options->where('is_correct', true))->toHaveCount(1);
});

it('legt eine Multiple-Choice-Frage mit zwei bis drei richtigen von fünf Optionen an', function () {
    $question = Question::factory()->multipleChoice()->create();

    expect($question->type)->toBe(QuestionType::Multiple)
        ->and($question->options)->toHaveCount(5)
        ->and($question->options->where('is_correct', true)->count())->toBeGreaterThanOrEqual(2)
        ->and($question->options->where('is_correct', true)->count())->toBeLessThanOrEqual(3);
});

it('legt eine Wahr/Falsch-Frage mit genau einer richtigen von zwei Optionen an', function () {
    $question = Question::factory()->boolean()->create();

    expect($question->type)->toBe(QuestionType::Boolean)
        ->and($question->options->pluck('label')->all())->toBe(['Wahr', 'Falsch'])
        ->and($question->options->where('is_correct', true))->toHaveCount(1);
});

it('verlangt immer eine Begründung', function () {
    $question = Question::factory()->create();

    expect($question->explanation)->toBeString()->not->toBe('');
});

it('sortiert Optionen nach position', function () {
    $question = Question::factory()->create();

    QuestionOption::factory()->for($question)->create(['label' => 'Zweitens', 'position' => 2]);
    QuestionOption::factory()->for($question)->create(['label' => 'Erstens', 'position' => 1]);

    expect($question->options()->pluck('label')->all())->toBe(['Erstens', 'Zweitens']);
});

it('rotiert die sichtbare Antwortreihenfolge pro Frage stabil', function () {
    $question = Question::factory()->create(['id' => 5]);

    foreach (['Erstens', 'Zweitens', 'Drittens', 'Viertens'] as $index => $label) {
        QuestionOption::factory()->for($question)->create([
            'label' => $label,
            'position' => $index + 1,
        ]);
    }

    expect($question->optionsForDisplay()->pluck('label')->all())
        ->toBe(['Zweitens', 'Drittens', 'Viertens', 'Erstens'])
        ->and($question->optionsForDisplay()->pluck('label')->all())
        ->toBe(['Zweitens', 'Drittens', 'Viertens', 'Erstens']);
});

it('hängt einen Zwischen-Check an Lektion und Pfad', function () {
    $lesson = Lesson::factory()->create();

    $question = Question::factory()->asLessonCheck($lesson)->singleChoice()->create();

    expect($question->lesson_id)->toBe($lesson->id)
        ->and($question->learning_path_id)->toBe($lesson->learning_path_id)
        ->and($question->lesson)->toBeInstanceOf(Lesson::class);
});

it('behält die Frage als Pool-Frage, wenn ihre Lektion gelöscht wird', function () {
    $lesson = Lesson::factory()->create();
    $question = Question::factory()->asLessonCheck($lesson)->create();

    $lesson->delete();

    expect($question->fresh()->lesson_id)->toBeNull()
        ->and($question->fresh()->learning_path_id)->toBe($lesson->learning_path_id);
});

it('löscht die Frage mit ihrem Pfad, wenn der Pfad endgültig gelöscht wird', function () {
    // Besitzsemantik seit S6.4 (E3): Der Pfad besitzt seine Fragen, Lektion und
    // Quiz verweisen nur auf sie. Bis zum Papierkorb überlebte die Frage ihren
    // Pfad als herkunftslose Pool-Frage — das war richtig, solange niemand
    // einen Pfad endgültig löschen konnte, und ließe jetzt bei jedem
    // endgültigen Löschen ein Feld verwaister Fragen zurück.
    $path = LearningPath::factory()->create();
    $question = Question::factory()->create(['learning_path_id' => $path->id]);

    $path->forceDelete();

    expect($question->fresh())->toBeNull();
});

it('castet Typ und Quellenangabe', function () {
    $question = Question::factory()->multipleChoice()->withSourceReference()->create()->fresh();

    expect($question->type)->toBe(QuestionType::Multiple)
        ->and($question->source_reference)->toBeList()
        ->and($question->source_reference[0]['source_id'])->toBe('it-osi')
        ->and($question->source_reference[0]['slide'])->toBeInt();
});

it('löscht Optionen beim Löschen der Frage mit', function () {
    $question = Question::factory()->singleChoice()->create();

    $question->delete();

    expect(QuestionOption::where('question_id', $question->id)->count())->toBe(0);
});

it('verbietet zwei Zwischen-Checks derselben Lektion auf derselben Position', function () {
    $lesson = Lesson::factory()->create();

    Question::factory()->asLessonCheck($lesson)->create(['position' => 1]);

    expect(fn () => Question::factory()->asLessonCheck($lesson)->create(['position' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('verbietet zwei Pool-Fragen desselben Pfades auf derselben Position', function () {
    $path = LearningPath::factory()->create();

    Question::factory()->for($path, 'learningPath')->create(['position' => 1]);

    expect(fn () => Question::factory()->for($path, 'learningPath')->create(['position' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lässt Zwischen-Check und Pool-Frage desselben Pfades dieselbe Position teilen', function () {
    // Die beiden Import-Schlüssel sind getrennte Namensräume: der eine zählt je
    // Lektion, der andere je Pfad. Deshalb zwei partielle Indizes mit
    // komplementären Teilmengen und nicht ein gemeinsamer.
    $lesson = Lesson::factory()->create();

    $check = Question::factory()->asLessonCheck($lesson)->create(['position' => 1]);
    $pool = Question::factory()
        ->for($lesson->learningPath, 'learningPath')
        ->create(['position' => 1]);

    expect($check->position)->toBe(1)
        ->and($pool->position)->toBe(1)
        ->and($pool->lesson_id)->toBeNull();
});

it('lässt herkunftslose Fragen auf derselben Position zu', function () {
    // Waisen (Pfad hart gelöscht) tragen NULL im Schlüssel, und NULL gilt im
    // Unique-Index als von allem verschieden. Der Index schützt sie also nicht —
    // er muss es auch nicht, denn der Import spricht sie nie an.
    Question::factory()->create(['position' => 1]);
    Question::factory()->create(['position' => 1]);

    expect(Question::whereNull('learning_path_id')->count())->toBe(2);
});

it('gibt dem Verlierer eines Import-Rennens die bestehende Pool-Frage', function () {
    $path = LearningPath::factory()->create();
    $vorhanden = Question::factory()->for($path, 'learningPath')->create(['position' => 1]);

    // `createOrFirst` überspringt das führende SELECT und läuft damit wirklich
    // in die Verletzung — das ist das Fenster, das `updateOrCreate` im
    // ContentSeeder bei zwei parallelen Importläufen hat.
    $verlierer = Question::createOrFirst(
        ['learning_path_id' => $path->id, 'lesson_id' => null, 'position' => 1],
        [
            'type' => QuestionType::Single,
            'prompt' => 'Zweiter Importlauf?',
            'explanation' => 'Derselbe Schlüssel, dieselbe Zeile.',
        ],
    );

    expect($verlierer->id)->toBe($vorhanden->id)
        ->and(Question::where('learning_path_id', $path->id)->count())->toBe(1);
});
