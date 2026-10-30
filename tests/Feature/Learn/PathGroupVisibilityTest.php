<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Actions\Learning\BuildPracticeQuiz;
use App\Actions\Learning\ResolveContinuationPoint;
use App\Data\Analytics\PathProgressRow;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Ein freigegebener Pfad mit Lektion, übbarer Frage und Abschluss-Quiz.
 *
 * @return array{path: LearningPath, lesson: Lesson, quiz: Quiz}
 */
function releasedPath(string $title, int $position = 1): array
{
    $path = LearningPath::factory()->published()->verified()->create(['title' => $title, 'position' => $position]);
    $lesson = Lesson::factory()->for($path)->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();
    $question = Question::factory()->for($path)->create();
    QuestionOption::factory()->for($question)->create(['is_correct' => true]);

    return ['path' => $path, 'lesson' => $lesson, 'quiz' => $quiz];
}

/**
 * Die Titel der Pfade im Fortschritts-Report des Lehrlings.
 *
 * @return array<int, string>
 */
function reportedPathTitles(User $learner): array
{
    return array_map(
        fn (PathProgressRow $row): string => $row->path->title,
        app(LearnerProgressReport::class)->handle($learner)->paths,
    );
}

test('a path without groups is visible to every group', function () {
    $first = User::factory()->apprentice()->create();
    $second = User::factory()->apprentice()->create();
    ['path' => $path] = releasedPath('Allgemein');

    foreach ([$first, $second] as $learner) {
        $this->actingAs($learner)
            ->get(learnRoute('learn.paths.show', $learner, ['learningPath' => $path]))
            ->assertOk();
    }
});

test('a path assigned to a group is visible only to that group', function () {
    $group = Team::factory()->create();
    $member = User::factory()->apprentice($group)->create();
    $outsider = User::factory()->apprentice()->create();
    ['path' => $path, 'lesson' => $lesson, 'quiz' => $quiz] = releasedPath('Nur für eine Gruppe');
    $path->teams()->attach($group);

    $this->actingAs($member)
        ->get(learnRoute('learn.paths.show', $member, ['learningPath' => $path]))
        ->assertOk();

    $this->actingAs($outsider)
        ->get(learnRoute('learn.paths.show', $outsider, ['learningPath' => $path]))
        ->assertForbidden();
    $this->actingAs($outsider)
        ->get(learnRoute('learn.lessons.show', $outsider, ['learningPath' => $path, 'lesson' => $lesson]))
        ->assertForbidden();
    $this->actingAs($outsider)
        ->get(learnRoute('learn.quizzes.show', $outsider, ['quiz' => $quiz]))
        ->assertForbidden();
});

test('a personal team does not grant access', function () {
    $user = User::factory()->create();
    ['path' => $path] = releasedPath('Persönlich zugewiesen');
    $path->teams()->attach($user->personalTeam());

    $this->actingAs($user)
        ->get(learnRoute('learn.paths.show', $user, ['learningPath' => $path]))
        ->assertForbidden();
});

test('a hidden path is left out of practice, continuation and progress', function () {
    $otherGroup = Team::factory()->create();
    $learner = User::factory()->apprentice()->create();
    ['path' => $hidden] = releasedPath('Fremder Pfad', position: 1);
    ['path' => $general] = releasedPath('Allgemeiner Pfad', position: 2);
    $hidden->teams()->attach($otherGroup);

    $component = Livewire::actingAs($learner)->test('pages::learn.practice.create');
    expect($component->instance()->paths->modelKeys())->toBe([$general->id]);

    $practice = app(BuildPracticeQuiz::class)->handle($learner, [$hidden->id], 5);
    expect($practice->questions()->count())->toBe(0);

    expect(app(ResolveContinuationPoint::class)->handle($learner)?->title)->toBe('Allgemeiner Pfad')
        ->and(reportedPathTitles($learner))->toBe(['Allgemeiner Pfad']);
});

test('withdrawing and restoring an assignment keeps the progress', function () {
    $group = Team::factory()->create();
    $otherGroup = Team::factory()->create();
    $learner = User::factory()->apprentice($group)->create();
    ['path' => $path, 'lesson' => $lesson] = releasedPath('Wechselnd');

    LessonCompletion::factory()->create(['user_id' => $learner->id, 'lesson_id' => $lesson->id]);

    $path->teams()->attach($otherGroup);

    expect(reportedPathTitles($learner))->toBe([]);
    $this->assertDatabaseHas('lesson_completions', ['user_id' => $learner->id, 'lesson_id' => $lesson->id]);

    $path->teams()->attach($group);

    $rows = app(LearnerProgressReport::class)->handle($learner)->paths;
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->completedLessons)->toBe(1);
});
