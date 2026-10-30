<?php

use App\Actions\Learning\ResolveContinuationPoint;
use App\Data\ContinuationPoint;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
});

function continuationFor(User $user): ?ContinuationPoint
{
    return app(ResolveContinuationPoint::class)->handle($user);
}

test('without visible paths there is no entry point', function () {
    LearningPath::factory()->create();

    expect(continuationFor($this->learner))->toBeNull();
});

test('without any activity the first visible path is offered', function () {
    LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Zweiter Pfad', 'position' => 2,
    ]);

    $first = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Erster Pfad', 'position' => 1,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->label)->toBe('Jetzt starten')
        ->and($point?->title)->toBe('Erster Pfad')
        ->and($point?->routeName)->toBe('learn.paths.show')
        ->and($point?->routeParameters)->toBe(['learningPath' => $first->slug]);
});

test('after a completed lesson the next open one is offered', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Grundlagen']);

    $lessons = Lesson::factory()->for($path)->count(3)->sequence(
        ['title' => 'Lektion 1', 'position' => 1],
        ['title' => 'Lektion 2', 'position' => 2],
        ['title' => 'Lektion 3', 'position' => 3],
    )->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->label)->toBe('Weiterlernen')
        ->and($point?->title)->toBe('Lektion 2')
        ->and($point?->context)->toBe('Grundlagen')
        ->and($point?->routeName)->toBe('learn.lessons.show');
});

test('with all lessons completed the entry point is the final quiz', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create(['position' => 1]);
    $quiz = Quiz::factory()->pathFinal($path)->published()->create(['title' => 'Abschlusstest']);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->label)->toBe('Abschluss-Quiz machen')
        ->and($point?->title)->toBe('Abschlusstest')
        ->and($point?->routeName)->toBe('learn.quizzes.show')
        ->and($point?->routeParameters)->toBe(['quiz' => $quiz->id]);
});

test('with all lessons completed and no visible quiz the practice round is offered', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Grundlagen']);
    $lesson = Lesson::factory()->for($path)->create(['position' => 1]);

    // Unveröffentlichtes Abschluss-Quiz ist für Teilnehmer_innen kein Ziel; der
    // Pfad gilt damit als abgeschlossen (F5).
    Quiz::factory()->pathFinal($path)->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->routeName)->toBe('learn.practice.create')
        ->and($point?->label)->toBe('Üben');
});

test('a passed final quiz moves the entry point to the next open path', function () {
    $done = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Erledigter Pfad', 'position' => 1,
    ]);
    $doneLesson = Lesson::factory()->for($done)->create(['position' => 1]);
    $doneQuiz = Quiz::factory()->pathFinal($done)->published()->create(['title' => 'Abschluss Erledigt']);

    $open = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Offener Pfad', 'position' => 2,
    ]);
    Lesson::factory()->for($open)->create(['title' => 'Offene Lektion', 'position' => 1]);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $doneLesson->id,
    ]);

    QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $doneQuiz->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->label)->toBe('Weiterlernen')
        ->and($point?->title)->toBe('Offene Lektion')
        ->and($point?->routeName)->toBe('learn.lessons.show');
});

test('when every visible path is completed the entry point is a practice round', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Einziger Pfad']);
    $lesson = Lesson::factory()->for($path)->create(['position' => 1]);
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->routeName)->toBe('learn.practice.create')
        ->and($point?->label)->toBe('Üben')
        ->and($point?->title)->toBe('Alle Lernpfade abgeschlossen');
});

test('an open attempt beats the most recent completion', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create(['position' => 1]);
    Lesson::factory()->for($path)->create(['title' => 'Offene Lektion', 'position' => 2]);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $quiz = Quiz::factory()->pathFinal($path)->published()->create(['title' => 'Abschlusstest']);

    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $point = continuationFor($this->learner);

    expect($point?->label)->toBe('Quiz fortsetzen')
        ->and($point?->title)->toBe('Abschlusstest');
});

test('a completed attempt is not an entry point', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    expect(continuationFor($this->learner)?->label)->toBe('Jetzt starten');
});

test('a revoked release drops the entry point back to the next rule', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    expect(continuationFor($this->learner)?->label)->toBe('Quiz fortsetzen');

    // Der Admin zieht die Freigabe des Pfades zurück.
    $path->update(['review_status' => ReviewStatus::Unreviewed]);

    expect(continuationFor($this->learner->fresh()))->toBeNull();
});

/**
 * Die beiden folgenden Tests decken dasselbe Durchfallen für **Regel 2** ab.
 * Der Test darüber heißt zwar ähnlich, prüft aber Regel 1 (offener Versuch);
 * für die Erledigungs-Regel gab es bis hierher keinen Test, obwohl sie ihre
 * eigene Sichtbarkeitsprüfung mitbringt.
 *
 * Beide halten die genaue Sprungweite fest: unsichtbar geworden heißt „weiter
 * zur nächstälteren **Erledigung**", nicht „weiter zur nächsten **Regel**".
 * Beide Pfade sind deshalb angefangen, und der Einstieg muss im älteren landen
 * statt beim Kaltstart „Jetzt starten".
 */
function zweiAngefangenePfade(User $learner): array
{
    $aelter = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Älterer Pfad', 'position' => 1,
    ]);

    $aeltereLektionen = Lesson::factory()->for($aelter)->count(2)->sequence(
        ['title' => 'Alt 1', 'position' => 1],
        ['title' => 'Alt 2', 'position' => 2],
    )->create();

    $juenger = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create([
        'title' => 'Jüngerer Pfad', 'position' => 2,
    ]);

    $juengereLektionen = Lesson::factory()->for($juenger)->count(2)->sequence(
        ['title' => 'Jung 1', 'position' => 1],
        ['title' => 'Jung 2', 'position' => 2],
    )->create();

    LessonCompletion::factory()->create([
        'user_id' => $learner->id,
        'lesson_id' => $aeltereLektionen[0]->id,
        'completed_at' => now()->subDay(),
    ]);

    LessonCompletion::factory()->create([
        'user_id' => $learner->id,
        'lesson_id' => $juengereLektionen[0]->id,
        'completed_at' => now(),
    ]);

    return [$aelter, $juenger];
}

test('a revoked release drops the most recent completion out of the cascade', function () {
    [, $juenger] = zweiAngefangenePfade($this->learner);

    // Solange die Freigabe steht, gewinnt die jüngste Erledigung.
    expect(continuationFor($this->learner)?->title)->toBe('Jung 2');

    $juenger->update(['review_status' => ReviewStatus::Unreviewed]);

    expect(continuationFor($this->learner)?->title)->toBe('Alt 2');
});

test('a deleted path drops the most recent completion out of the cascade', function () {
    // Soft-Delete: Lektionen und Erledigungen überleben das (cascadeOnDelete
    // greift nur beim echten Löschen), die Relation liefert danach null.
    [, $juenger] = zweiAngefangenePfade($this->learner);

    expect(continuationFor($this->learner)?->title)->toBe('Jung 2');

    $juenger->delete();

    expect(continuationFor($this->learner)?->title)->toBe('Alt 2');
});

test('the activity of another apprentice does not change the own entry point', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();

    Lesson::factory()->for($path)->count(2)->sequence(
        ['title' => 'Lektion 1', 'position' => 1],
        ['title' => 'Lektion 2', 'position' => 2],
    )->create();

    $other = User::factory()->apprentice()->create();

    LessonCompletion::factory()->create([
        'user_id' => $other->id,
        'lesson_id' => $path->lessons()->first()->id,
    ]);

    // Der eigene Einstieg steht weiterhin am Anfang.
    expect(continuationFor($this->learner)?->label)->toBe('Jetzt starten');
});

test('the completion of another apprentice does not skip an own open lesson', function () {
    // Der Test darüber prüft die Abgrenzung für Regel 3 (Einstieg ohne eigene
    // Aktivität). Dieser prüft sie **innerhalb** eines Pfades: die Suche nach
    // der nächsten offenen Lektion fragt eine gemeinsame Tabelle ab und muss
    // sich dort selbst auf die Teilnehmer_in eingrenzen.
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Grundlagen']);

    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['title' => 'Lektion 1', 'position' => 1],
        ['title' => 'Lektion 2', 'position' => 2],
    )->create();

    $other = User::factory()->apprentice()->create();

    // Der andere ist schon durch — auch mit Lektion 2.
    foreach ($lessons as $lesson) {
        LessonCompletion::factory()->create([
            'user_id' => $other->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    // Man selbst hat gerade erst Lektion 1 fertig.
    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    expect(continuationFor($this->learner)?->title)->toBe('Lektion 2');
});

test('the dashboard shows the entry point as a card', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Grundlagen']);

    Lesson::factory()->for($path)->count(2)->sequence(
        ['title' => 'Lektion 1', 'position' => 1],
        ['title' => 'Lektion 2', 'position' => 2],
    )->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $path->lessons()->first()->id,
    ]);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSeeHtml('data-test="continuation-card"')
        ->assertSee('Weiterlernen')
        ->assertSee('Lektion 2');
});

test('the dashboard shows no entry point card without visible paths', function () {
    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertDontSeeHtml('data-test="continuation-card"')
        ->assertSee('Es sind noch keine Lernpfade freigegeben.');
});
