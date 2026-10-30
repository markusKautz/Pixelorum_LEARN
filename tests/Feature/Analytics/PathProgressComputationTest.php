<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Data\Analytics\PathProgressRow;
use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;

/*
 * Modell A (Sprint-5-Entscheidung 3) ist der kritische Bereich dieses Sprints
 * (Konzept i): jede Auswertung — Gruppe, Drilldown, Selbstsicht — rechnet
 * damit. Getestet wird durch LearnerProgressReport hindurch, damit die
 * Abfragen mit im Netz hängen und nicht nur die reine Arithmetik.
 */

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
});

/**
 * Build a visible path with the given number of lessons, complete the first
 * $completed of them for the learner and attach the requested quiz situation.
 */
function pathWithProgress(User $learner, int $lessons, int $completed, string $quiz): LearningPath
{
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => 1]);

    $created = Lesson::factory()->for($path)->count($lessons)->sequence(
        ...array_map(fn (int $index): array => ['position' => $index], range(1, max($lessons, 1))),
    )->create();

    foreach ($created->take($completed) as $lesson) {
        LessonCompletion::factory()->create([
            'user_id' => $learner->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    if ($quiz === 'none') {
        return $path;
    }

    $finalQuiz = Quiz::factory()->pathFinal($path)->published()->create();

    match ($quiz) {
        'passed' => QuizAttempt::factory()->passed()->create([
            'user_id' => $learner->id,
            'quiz_id' => $finalQuiz->id,
        ]),
        'failed' => QuizAttempt::factory()->failed()->create([
            'user_id' => $learner->id,
            'quiz_id' => $finalQuiz->id,
        ]),
        default => null,
    };

    return $path;
}

/**
 * Read the computed row of the given path out of the learner's report.
 */
function progressRow(User $learner, LearningPath $path): PathProgressRow
{
    $row = collect(app(LearnerProgressReport::class)->handle($learner)->paths)
        ->first(fn (PathProgressRow $row): bool => $row->path->id === $path->id);

    expect($row)->not->toBeNull();

    return $row;
}

dataset('aggregation matrix', [
    // Beschreibung => [Lektionen, davon erledigt, Quiz-Lage, Prozent, Status]
    'nichts gelesen, kein Quiz' => [3, 0, 'none', 0, ProgressStatus::NotStarted],
    'nichts gelesen, Quiz unangetastet' => [3, 0, 'open', 0, ProgressStatus::NotStarted],
    'nichts gelesen, Quiz nicht bestanden' => [3, 0, 'failed', 0, ProgressStatus::InProgress],
    'teilweise gelesen, kein Quiz' => [3, 2, 'none', 66, ProgressStatus::InProgress],
    'teilweise gelesen, Quiz bestanden' => [3, 2, 'passed', 66, ProgressStatus::InProgress],
    'alles gelesen, kein Quiz' => [3, 3, 'none', 100, ProgressStatus::Completed],
    'alles gelesen, Quiz unangetastet' => [3, 3, 'open', 100, ProgressStatus::InProgress],
    'alles gelesen, Quiz nicht bestanden' => [3, 3, 'failed', 100, ProgressStatus::InProgress],
    'alles gelesen, Quiz bestanden' => [3, 3, 'passed', 100, ProgressStatus::Completed],
]);

test('percent and status follow model A', function (
    int $lessons,
    int $completed,
    string $quiz,
    int $expectedPercent,
    ProgressStatus $expectedStatus,
) {
    $path = pathWithProgress($this->learner, $lessons, $completed, $quiz);

    $row = progressRow($this->learner, $path);

    expect($row->percent)->toBe($expectedPercent)
        ->and($row->status)->toBe($expectedStatus);
})->with('aggregation matrix');

test('a path without lessons stays at zero percent and not started', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();

    $row = progressRow($this->learner, $path);

    expect($row->percent)->toBe(0)
        ->and($row->status)->toBe(ProgressStatus::NotStarted)
        ->and($row->hasQuiz)->toBeFalse();
});

test('a path without lessons counts as completed once its quiz is passed', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $row = progressRow($this->learner, $path);

    expect($row->percent)->toBe(0)
        ->and($row->status)->toBe(ProgressStatus::Completed);
});

test('an unpublished final quiz never blocks completion', function () {
    // Ein Entwurfs-Quiz kann die Teilnehmer_in nicht ablegen — es darf ihr den
    // Pfad also nicht offen halten (gleiche Lesart wie das Sprint-4-Badge).
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();
    Quiz::factory()->pathFinal($path)->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $row = progressRow($this->learner, $path);

    expect($row->hasQuiz)->toBeFalse()
        ->and($row->status)->toBe(ProgressStatus::Completed);
});

test('the percentage is floored rather than rounded', function () {
    // 2 von 3 sind 66 %, nicht 67 % — sonst stünde „67 %" neben einer noch
    // offenen Lektion (gleiche Ganzzahl-Arithmetik wie Quiz::isPassing).
    $path = pathWithProgress($this->learner, 3, 2, 'none');

    expect(progressRow($this->learner, $path)->percent)->toBe(66);
});

test('the last activity is the later of lesson completion and quiz attempt', function () {
    // Eingefroren, weil der Vergleich unten now() erneut aufruft; ein
    // Sekundenwechsel dazwischen liess den Test sporadisch scheitern.
    $this->freezeSecond();

    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
        'completed_at' => now()->subDays(5),
    ]);

    $attempt = QuizAttempt::factory()->failed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $attempt->forceFill(['updated_at' => now()->subDay()])->save();

    expect(progressRow($this->learner, $path)->lastActivityAt->toDateTimeString())
        ->toBe(now()->subDay()->toDateTimeString());
});

test('a running attempt still counts as activity', function () {
    // Wer gerade ein Quiz schreibt, ist nicht inaktiv (Entscheidung 5:
    // max(quiz_attempts.updated_at), nicht completed_at).
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $row = progressRow($this->learner, $path);

    expect($row->lastActivityAt)->not->toBeNull()
        // Ein laufender Versuch zählt aber nicht als Versuch für den Status.
        ->and($row->status)->toBe(ProgressStatus::NotStarted);
});

test('activity of another apprentice does not change the own row', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $other = User::factory()->apprentice()->create();

    foreach ($lessons as $lesson) {
        LessonCompletion::factory()->create([
            'user_id' => $other->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    QuizAttempt::factory()->passed()->create([
        'user_id' => $other->id,
        'quiz_id' => $quiz->id,
    ]);

    $row = progressRow($this->learner, $path);

    expect($row->percent)->toBe(0)
        ->and($row->quizPassed)->toBeFalse()
        ->and($row->status)->toBe(ProgressStatus::NotStarted)
        ->and($row->lastActivityAt)->toBeNull();
});

test('only published and verified paths appear in the report', function () {
    $visible = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    LearningPath::factory()->published()->create();
    LearningPath::factory()->verified()->create();
    LearningPath::factory()->create();

    $report = app(LearnerProgressReport::class)->handle($this->learner);

    expect($report->paths)->toHaveCount(1)
        ->and($report->paths[0]->path->id)->toBe($visible->id);
});

test('the report averages the reading progress over all visible paths', function () {
    pathWithProgress($this->learner, 4, 4, 'passed');
    pathWithProgress($this->learner, 4, 1, 'none');

    $report = app(LearnerProgressReport::class)->handle($this->learner);

    // (100 + 25) / 2 = 62 (abgerundet)
    expect($report->averagePercent())->toBe(62)
        ->and($report->completedPaths())->toBe(1);
});
