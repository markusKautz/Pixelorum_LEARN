<?php

use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\PathProgress;
use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

it('erlaubt nur einen Abschluss je Lektion und Lehrling', function () {
    $completion = LessonCompletion::factory()->create();

    expect(fn () => LessonCompletion::factory()->create([
        'user_id' => $completion->user_id,
        'lesson_id' => $completion->lesson_id,
    ]))->toThrow(QueryException::class);
});

it('erlaubt nur eine Fortschritts-Zeile je Pfad und Lehrling', function () {
    $progress = PathProgress::factory()->create();

    expect(fn () => PathProgress::factory()->create([
        'user_id' => $progress->user_id,
        'learning_path_id' => $progress->learning_path_id,
    ]))->toThrow(QueryException::class);
});

it('erlaubt nur eine Antwort je Frage und Versuch', function () {
    $response = QuestionResponse::factory()->create();

    expect(fn () => QuestionResponse::factory()->create([
        'quiz_attempt_id' => $response->quiz_attempt_id,
        'question_id' => $response->question_id,
    ]))->toThrow(QueryException::class);
});

it('führt keine timestamps auf Completions und Antworten', function () {
    expect(LessonCompletion::factory()->create()->timestamps)->toBeFalse()
        ->and(QuestionResponse::factory()->create()->timestamps)->toBeFalse();
});

it('castet die Felder eines Lektions-Abschlusses', function () {
    $completion = LessonCompletion::factory()->create()->fresh();

    expect($completion->completed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($completion->lesson)->toBeInstanceOf(Lesson::class)
        ->and($completion->user)->toBeInstanceOf(User::class);
});

it('castet die Felder eines Quiz-Versuchs', function () {
    $attempt = QuizAttempt::factory()->passed()->create()->fresh();

    expect($attempt->passed)->toBeTrue()
        ->and($attempt->started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->completed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->quiz)->toBeInstanceOf(Quiz::class);
});

it('hält score, max_score und passed je State konsistent', function () {
    $bestanden = QuizAttempt::factory()->passed()->create();
    $durchgefallen = QuizAttempt::factory()->failed()->create();
    $laufend = QuizAttempt::factory()->inProgress()->create();

    expect($bestanden->passed)->toBeTrue()
        ->and($bestanden->score)->toBeGreaterThanOrEqual((int) ceil($bestanden->max_score * 0.8))
        ->and($durchgefallen->passed)->toBeFalse()
        ->and($durchgefallen->score)->toBeLessThan($durchgefallen->max_score)
        ->and($laufend->completed_at)->toBeNull()
        ->and($laufend->passed)->toBeFalse();
});

it('verbindet Antworten mit ihrem Versuch', function () {
    $attempt = QuizAttempt::factory()->create();
    QuestionResponse::factory()->count(2)->for($attempt, 'quizAttempt')->create();

    expect($attempt->responses)->toHaveCount(2)
        ->and($attempt->responses->first()->quizAttempt->id)->toBe($attempt->id);
});

it('castet die gewählten Optionen einer Antwort als Liste von IDs', function () {
    $question = Question::factory()->singleChoice()->create();

    $response = QuestionResponse::factory()->correct()->create([
        'question_id' => $question->id,
    ])->fresh();

    expect($response->is_correct)->toBeTrue()
        ->and($response->selected_option_ids)->toBe(
            $question->options->where('is_correct', true)->pluck('id')->all()
        );
});

it('weist den correct()-State ohne Antwortoptionen ab', function () {
    // Review N1: eine Default-Frage hat keine Optionen — der Guard verhindert
    // ein stilles []+true-Fixture.
    expect(fn () => QuestionResponse::factory()->correct()->create())
        ->toThrow(RuntimeException::class);
});

it('castet Status und Prozent des Pfad-Fortschritts', function () {
    $nichtBegonnen = PathProgress::factory()->create()->fresh();
    $inArbeit = PathProgress::factory()->inProgress()->create()->fresh();
    $abgeschlossen = PathProgress::factory()->completed()->create()->fresh();

    expect($nichtBegonnen->status)->toBe(ProgressStatus::NotStarted)
        ->and($nichtBegonnen->percent)->toBe(0)
        ->and($nichtBegonnen->last_activity_at)->toBeNull()
        ->and($inArbeit->status)->toBe(ProgressStatus::InProgress)
        ->and($inArbeit->percent)->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($abgeschlossen->status)->toBe(ProgressStatus::Completed)
        ->and($abgeschlossen->percent)->toBe(100);
});

it('nutzt die Tabelle path_progress ohne Pluralisierungs-Unfall', function () {
    expect((new PathProgress)->getTable())->toBe('path_progress');
});

it('verbindet den Lehrling mit seinem Fortschritt', function () {
    $user = User::factory()->create();
    $path = LearningPath::factory()->create();

    LessonCompletion::factory()->for($user)->create();
    QuizAttempt::factory()->for($user)->create();
    PathProgress::factory()->for($user)->for($path)->create();

    expect($user->lessonCompletions)->toHaveCount(1)
        ->and($user->quizAttempts)->toHaveCount(1)
        ->and($user->pathProgress)->toHaveCount(1)
        ->and($user->pathProgress->first()->learningPath->id)->toBe($path->id);
});
