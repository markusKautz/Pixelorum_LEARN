<?php

use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    // Die Factories belegen mit `scriptum` und `it-osi`; die Freigabe
    // verlangt bekannte Quellen (DEV-3, E10).
    ContentSource::factory()->create(['source_id' => 'scriptum', 'title' => 'Scriptum']);
    ContentSource::factory()->slides()->create(['source_id' => 'it-osi', 'title' => 'IT-OSI-Folien']);
});

test('verifying sets all three fields and the verifier relation', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    $path->refresh();

    expect($path->review_status)->toBe(ReviewStatus::Verified)
        ->and($path->verified_at)->not->toBeNull()
        ->and($path->verified_by)->toBe($this->admin->id)
        ->and($path->verifier->is($this->admin))->toBeTrue();
});

test('verifying returns to the detail view of the path', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify')
        ->assertDispatched('toast-show')
        ->assertRedirect(route('admin.learning-paths.edit', $path));
});

test('the verify button only opens a confirmation modal', function () {
    $path = LearningPath::factory()->published()->create(['title' => 'Netzwerke']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertDontSeeHtml('wire:click="verify"')
        ->assertSeeHtml('wire:submit="verify"')
        ->assertSeeHtml('data-test="verify-confirm"')
        ->assertSee('Mit der Freigabe wird er für Lehrlinge sofort sichtbar.');

    expect($path->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
});

test('a refused verification stays on the review page', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create(['source_reference' => null]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify')
        ->assertNoRedirect();
});

test('revoking nulls the verification fields', function () {
    $path = LearningPath::factory()->verified()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('revokeVerification');

    $path->refresh();

    expect($path->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($path->verified_at)->toBeNull()
        ->and($path->verified_by)->toBeNull();
});

test('the visibility scope follows verify and revoke', function () {
    $path = LearningPath::factory()->published()->create();

    expect(LearningPath::visibleToLearners()->whereKey($path->id)->exists())->toBeFalse();

    $review = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path]);

    $review->call('verify');

    expect(LearningPath::visibleToLearners()->whereKey($path->id)->exists())->toBeTrue();

    $review->call('revokeVerification');

    expect(LearningPath::visibleToLearners()->whereKey($path->id)->exists())->toBeFalse();
});

test('a published unreviewed path shows the warning callout', function () {
    $path = LearningPath::factory()->published()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertSeeHtml('data-test="published-unreviewed-warning"');
});

test('changedSinceVerification flips after a content edit', function () {
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->withSourceReference()->create();
    $block = LessonBlock::factory()->for($lesson)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    expect($path->refresh()->changedSinceVerification())->toBeFalse();

    $this->travel(5)->minutes();

    $block->update(['content' => ['body' => 'Nachträglich geändert.']]);

    expect($path->refresh()->changedSinceVerification())->toBeTrue();

    // Badge erscheint auf der Freigabe-Ansicht
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertSeeHtml('data-test="changed-since-verification-badge"');
});

test('publishing a verified path does not count as a change', function () {
    // Befund der Browserabnahme (2026-08-10): Veröffentlichen bewegte das
    // updated_at des Pfades, und changedSinceVerification() las das als
    // Inhaltsänderung. Veröffentlichen ist aber eine Ablaufänderung.
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->withSourceReference()->create();
    LessonBlock::factory()->for($lesson)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    $this->travel(5)->minutes();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('publish');

    expect($path->refresh()->status)->toBe(PathStatus::Published)
        ->and($path->changedSinceVerification())->toBeFalse()
        ->and(LearningPath::visibleToLearners()->count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertDontSeeHtml('data-test="changed-since-verification-badge"');
});

test('moving verified paths in the order does not count as a change', function () {
    // Befund der Browserabnahme (2026-10-27): Das Umsortieren verdichtet die
    // Positionen aller Pfade und bewegte dabei deren updated_at.
    $first = LearningPath::factory()->create(['position' => 1]);
    $second = LearningPath::factory()->create(['position' => 2]);

    foreach ([$first, $second] as $path) {
        Lesson::factory()->for($path)->withSourceReference()->create();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
            ->call('verify');
    }

    $this->travel(5)->minutes();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('sortPaths', $second->id, 0)
        ->assertDontSeeHtml('data-test="changed-since-verification-badge"');

    expect($second->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2)
        ->and($first->changedSinceVerification())->toBeFalse()
        ->and($second->changedSinceVerification())->toBeFalse();
});

test('unpublishing a verified path does not count as a change either', function () {
    $path = LearningPath::factory()->published()->create();
    $lesson = Lesson::factory()->for($path)->withSourceReference()->create();
    LessonBlock::factory()->for($lesson)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    $this->travel(5)->minutes();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('unpublish');

    expect($path->refresh()->status)->toBe(PathStatus::Draft)
        ->and($path->changedSinceVerification())->toBeFalse();
});

test('a question edit also counts as a change since verification', function () {
    $path = LearningPath::factory()->create();
    $question = Question::factory()->for($path, 'learningPath')->withSourceReference()->singleChoice()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    $this->travel(5)->minutes();

    $question->update(['prompt' => 'Nachträglich geänderte Frage?']);

    expect($path->refresh()->changedSinceVerification())->toBeTrue();
});

test('the badge appears in the path list after a change', function () {
    $path = LearningPath::factory()->published()->verified()->create(['verified_by' => $this->admin->id]);

    $this->travel(5)->minutes();

    Lesson::factory()->for($path)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSeeHtml('data-test="changed-since-verification-badge"');
});

test('the path list reads the change timestamps without a query per row', function () {
    // Das Badge vergleicht vier Aggregate je Zeile. Ohne Vorladen setzt jede
    // Zeile sie einzeln ab — bei drei Pfaden vier Abfragen mehr, bei fünfund-
    // zwanzig hundert (S3-N1). Gezählt wird deshalb nicht die Gesamtzahl der
    // Abfragen, sondern ob sie mit der Zeilenzahl mitwächst. Eine feste Zahl
    // stünde hier ohnehin schief: Seit dem Papierkorb (S6.4) fragt die Seite
    // die gelöschten Pfade zusätzlich ab.
    $pfadAbfragen = function (int $weiterePfade): int {
        LearningPath::factory()->published()->verified()->count($weiterePfade)->create()
            ->each(function (LearningPath $path): void {
                $lesson = Lesson::factory()->for($path)->create();
                LessonBlock::factory()->for($lesson)->create();
                Question::factory()->for($path, 'learningPath')->singleChoice()->create();
            });

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.learning-paths.index')
            ->assertSeeHtml('data-test="paths-table"');

        $treffer = collect(DB::getQueryLog())
            ->filter(fn (array $eintrag): bool => str_contains($eintrag['query'], 'from "learning_paths"'))
            ->count();

        DB::disableQueryLog();

        return $treffer;
    };

    $mitDreiPfaden = $pfadAbfragen(3);
    $mitSechsPfaden = $pfadAbfragen(3);

    expect($mitSechsPfaden)->toBe($mitDreiPfaden);
});

test('missing source references are marked and counted', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->withSourceReference()->create();
    Lesson::factory()->for($path)->create(['source_reference' => null]);
    Question::factory()->for($path, 'learningPath')->withSourceReference()->singleChoice()->create();
    Question::factory()->for($path, 'learningPath')->singleChoice()->create(['source_reference' => null]);

    $review = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path]);

    $review->assertSeeHtml('data-test="missing-reference-badge"');

    $summary = $review->instance()->summary;

    expect($summary['lessonsWithReference'])->toBe(1)
        ->and($summary['lessonsTotal'])->toBe(2)
        ->and($summary['questionsWithReference'])->toBe(1)
        ->and($summary['questionsTotal'])->toBe(2);
});

test('review displays every source reference of lessons and questions', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create(['source_reference' => [
        ['source_id' => 'scriptum', 'page' => 12],
        ['source_id' => 'it-osi', 'slide' => 4],
    ]]);
    Question::factory()->for($path, 'learningPath')->singleChoice()->create([
        'source_reference' => [['source_id' => 'scriptum', 'page' => 13]],
    ]);

    $review = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertSeeInOrder([
            'Scriptum, Seite 12',
            'IT-OSI-Folien, Folie 4',
            'Scriptum, Seite 13',
        ]);

    expect(substr_count($review->html(), 'data-test="source-reference-badge"'))->toBe(3);
});

test('review links web sources and shows the source id on hover', function () {
    ContentSource::factory()->web()->create([
        'source_id' => 'infschule-dualsystem',
        'title' => 'inf-schule: Dualsystem',
        'url' => 'https://inf-schule.de/dualsystem',
    ]);
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create(['source_reference' => [
        ['source_id' => 'infschule-dualsystem', 'section' => 'Stellenwertsystem'],
    ]]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertSee('inf-schule: Dualsystem, Abschnitt: Stellenwertsystem')
        ->assertSeeHtml('href="https://inf-schule.de/dualsystem" target="_blank" rel="noopener" title="infschule-dualsystem"');
});

test('an unknown source is flagged and blocks the verification', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create(['source_reference' => [
        ['source_id' => 'nirgends-registriert', 'page' => 4],
    ]]);

    $review = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->assertSeeHtml('data-unknown-source')
        ->assertSeeHtml('data-test="unknown-sources-warning"')
        ->assertSee('nirgends-registriert, Seite 4');

    expect($review->instance()->summary['unknownSourceIds'])->toBe(['nirgends-registriert']);

    $review->call('verify')->assertNoRedirect();

    expect($path->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
});

test('review keeps lesson checks out of the pool after an action request', function () {
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->withSourceReference()->create();
    $lessonCheck = Question::factory()->asLessonCheck($lesson)->withSourceReference()->singleChoice()->create([
        'prompt' => 'UNIQUE LESSON CHECK PROMPT',
    ]);
    $poolQuestion = Question::factory()->for($path, 'learningPath')->withSourceReference()->singleChoice()->create([
        'prompt' => 'UNIQUE POOL QUESTION PROMPT',
    ]);

    $review = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify');

    $summary = $review->instance()->summary;
    $html = $review->html();
    $poolHtml = explode(__('Pool questions'), $html, 2)[1];

    expect($summary['questionsTotal'])->toBe(2)
        ->and(substr_count($html, e($lessonCheck->prompt)))->toBe(1)
        ->and(substr_count($html, e($poolQuestion->prompt)))->toBe(1)
        ->and($poolHtml)->not->toContain(e($lessonCheck->prompt))
        ->and($poolHtml)->toContain(e($poolQuestion->prompt));
});

test('verification is refused when a lesson check has no source reference', function () {
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->withSourceReference()->create();
    Question::factory()->asLessonCheck($lesson)->singleChoice()->create(['source_reference' => null]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify')
        ->assertDispatched('toast-show');

    expect($path->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
});

test('verification is refused while a lesson or question has no source reference', function (string $missingReference) {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->withSourceReference()->create([
        'source_reference' => $missingReference === 'lesson' ? null : [
            ['source_id' => 'scriptum', 'page' => 12],
        ],
    ]);
    Question::factory()->for($path, 'learningPath')->withSourceReference()->singleChoice()->create([
        'source_reference' => $missingReference === 'question' ? null : [
            ['source_id' => 'it-osi', 'slide' => 4],
        ],
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify')
        ->assertDispatched('toast-show');

    expect($path->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
})->with([
    'missing lesson reference' => 'lesson',
    'missing question reference' => 'question',
]);

test('apprentices are forbidden on the review page and its actions', function () {
    $apprentice = User::factory()->apprentice()->create();
    $path = LearningPath::factory()->create();

    $this->actingAs($apprentice)
        ->get(route('admin.learning-paths.review', $path))
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('verify')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.review', ['learningPath' => $path])
        ->call('revokeVerification')
        ->assertForbidden();

    expect($path->refresh()->review_status)->toBe(ReviewStatus::Unreviewed);
});
