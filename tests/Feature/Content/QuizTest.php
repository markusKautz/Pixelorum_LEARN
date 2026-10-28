<?php

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\QueryException;

it('sortiert Quiz-Fragen nach der Pivot-Position', function () {
    $quiz = Quiz::factory()->create();
    $ersteFrage = Question::factory()->create(['prompt' => 'Erstens?']);
    $zweiteFrage = Question::factory()->create(['prompt' => 'Zweitens?']);

    $quiz->questions()->attach([
        $zweiteFrage->id => ['position' => 2],
        $ersteFrage->id => ['position' => 1],
    ]);

    expect($quiz->questions()->pluck('prompt')->all())->toBe(['Erstens?', 'Zweitens?'])
        ->and($quiz->questions()->first()->pivot->position)->toBe(1);
});

it('verhindert, dass dieselbe Frage zweimal im Quiz landet', function () {
    $quiz = Quiz::factory()->create();
    $question = Question::factory()->create();

    $quiz->questions()->attach($question, ['position' => 1]);

    expect(fn () => $quiz->questions()->attach($question, ['position' => 2]))
        ->toThrow(QueryException::class);
});

it('erlaubt dieselbe Frage in mehreren Quizzen', function () {
    $question = Question::factory()->create();
    $erstesQuiz = Quiz::factory()->create();
    $zweitesQuiz = Quiz::factory()->create();

    $erstesQuiz->questions()->attach($question, ['position' => 1]);
    $zweitesQuiz->questions()->attach($question, ['position' => 1]);

    expect($question->quizzes)->toHaveCount(2);
});

it('weist ein Abschluss-Quiz ohne Lernpfad ab', function () {
    expect(fn () => Quiz::factory()->create([
        'kind' => QuizKind::PathFinal,
        'learning_path_id' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('weist auch das nachträgliche Entfernen des Pfades von einem Abschluss-Quiz ab', function () {
    $quiz = Quiz::factory()->pathFinal()->create();

    expect(fn () => $quiz->update(['learning_path_id' => null]))
        ->toThrow(InvalidArgumentException::class);
});

it('weist ein freies Quiz mit Lernpfad ab', function () {
    // Gegenrichtung derselben Invariante: der Pfad ist das Merkmal des
    // Abschluss-Quiz. Hinge er an einem freien Quiz, legte QuizPolicy::view()
    // das Grounding-Gate des Pfads über ein Quiz, das nicht dazugehört.
    $path = LearningPath::factory()->create();

    expect(fn () => Quiz::factory()->create([
        'kind' => QuizKind::Custom,
        'learning_path_id' => $path->id,
    ]))->toThrow(InvalidArgumentException::class);
});

it('erlaubt ein Abschluss-Quiz mit Lernpfad', function () {
    $path = LearningPath::factory()->create();

    $quiz = Quiz::factory()->pathFinal($path)->create();

    expect($quiz->kind)->toBe(QuizKind::PathFinal)
        ->and($quiz->learningPath->id)->toBe($path->id)
        ->and($path->quizzes)->toHaveCount(1);
});

it('erlaubt ein freies Quiz ohne Lernpfad', function () {
    $quiz = Quiz::factory()->create();

    expect($quiz->kind)->toBe(QuizKind::Custom)
        ->and($quiz->learning_path_id)->toBeNull();
});

it('löscht das Abschluss-Quiz mit seinem Pfad', function () {
    $path = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($path)->create();

    $path->forceDelete();

    expect(Quiz::find($quiz->id))->toBeNull();
});

it('castet Art und Status', function () {
    $quiz = Quiz::factory()->published()->create()->fresh();

    expect($quiz->kind)->toBe(QuizKind::Custom)
        ->and($quiz->status)->toBe(PathStatus::Published)
        ->and($quiz->creator->is_admin)->toBeTrue();
});
