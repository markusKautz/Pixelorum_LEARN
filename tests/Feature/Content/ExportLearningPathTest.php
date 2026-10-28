<?php

use App\Actions\Content\ExportLearningPath;
use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\ValidateContentFile;
use App\Enums\QuizKind;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\ContentFileFixture;

/**
 * Die Fixture importieren, wie es der Upload tut, mit bekannten Quellen.
 */
function importFixturePath(): LearningPath
{
    ContentSource::factory()->create(['source_id' => 'scriptum', 'title' => 'Scriptum']);
    ContentSource::factory()->slides()->create(['source_id' => 'it-osi', 'title' => 'IT-OSI-Folien']);

    return app(ImportLearningPath::class)->create(ContentFileFixture::valid());
}

/**
 * Sort associative keys recursively; some seed files order block keys differently.
 */
function sortKeysDeep(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(sortKeysDeep(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

/**
 * @return array{json: string, filename: string, warnings: list<string>}
 */
function exportPath(LearningPath $path): array
{
    return app(ExportLearningPath::class)->handle($path->fresh());
}

test('the example file of the learn-content skill survives the round trip through database and export', function () {
    $original = json_decode(
        File::get(base_path('.ai/skills/learn-content/references/example.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $result = exportPath(app(ImportLearningPath::class)->create($original));
    $exported = json_decode($result['json'], true, flags: JSON_THROW_ON_ERROR);

    expect($result['warnings'])->toBe([])
        ->and($exported['schema_version'])->toBe(ValidateContentFile::SCHEMA_VERSION_CURRENT)
        ->and($exported)->not->toHaveKey('media')
        ->and($exported['sources'])->toBe($original['sources'])
        ->and(sortKeysDeep(Arr::except($exported, ['schema_version', 'sources'])))
        ->toBe(sortKeysDeep(Arr::except($original, ['schema_version', 'sources'])));
});

test('the export uses two-space indentation and ends with a newline', function () {
    $json = exportPath(importFixturePath())['json'];

    expect($json)->toStartWith("{\n  \"schema_version\": 3,\n  \"slug\"")
        ->and($json)->toEndWith("}\n");
});

test('the export mirrors edits made in the admin area', function () {
    $path = importFixturePath();
    $lesson = $path->lessons()->sole();
    $lesson->blocks()->sole()->update(['content' => ['body' => 'Im Admin geändert.']]);
    $path->questions()->whereNull('lesson_id')->where('position', 2)->sole()
        ->options()->where('position', 2)->delete();

    $exported = json_decode(exportPath($path)['json'], true);

    expect($exported['lessons'][0]['blocks'][0]['content'])->toBe(['body' => 'Im Admin geändert.'])
        ->and($exported['questions'][1]['options'])->toHaveCount(1)
        ->and($exported['status'])->toBe('draft')
        ->and($exported['quiz']['status'])->toBe('draft');
});

test('an exported path can be uploaded again under a new slug', function () {
    $path = importFixturePath();
    $exported = json_decode(exportPath($path)['json'], true);
    $exported['slug'] = 'kopie';

    $copy = app(ImportLearningPath::class)->create($exported);
    $copyExport = json_decode(exportPath($copy)['json'], true);

    expect(Arr::except($copyExport, ['slug', 'position']))->toBe(Arr::except($exported, ['slug', 'position']));
});

test('a published and verified path is still exported as draft without review data', function () {
    $path = importFixturePath();
    $path->forceFill(['status' => 'published', 'review_status' => 'verified', 'verified_at' => now()])->save();

    $exported = json_decode(exportPath($path)['json'], true);

    expect($exported['status'])->toBe('draft')
        ->and($exported)->not->toHaveKeys(['review_status', 'verified_at', 'id']);
});

test('an empty block content stays a json object', function () {
    $path = importFixturePath();
    LessonBlock::factory()->for($path->lessons()->sole())->create([
        'position' => 2,
        'type' => 'section_break',
        'content' => [],
    ]);

    $json = exportPath($path)['json'];

    expect($json)->toContain('"content": {}')
        ->and(json_decode($json, false)->lessons[0]->blocks[1]->content)->toBeInstanceOf(stdClass::class);
});

test('obstacles to a re-import are reported but the file is still produced', function (Closure $breakPath, string $warning) {
    $path = importFixturePath();
    $breakPath($path);

    $result = exportPath($path);

    expect($result['json'])->toStartWith('{')
        ->and(implode("\n", $result['warnings']))->toContain($warning);
})->with([
    'ohne Abschlussquiz' => [
        fn (LearningPath $path) => $path->finalQuiz()->delete(),
        'quiz',
    ],
    'Lektion ohne Quellenangabe' => [
        fn (LearningPath $path) => $path->lessons()->update(['source_reference' => null]),
        'lessons.0.source_reference',
    ],
    'Zwischen-Check im Abschlussquiz' => [
        function (LearningPath $path): void {
            $check = $path->lessons()->sole()->questions()->sole();
            $quiz = Quiz::where('learning_path_id', $path->id)->where('kind', QuizKind::PathFinal)->sole();
            $quiz->questions()->attach($check->id, ['position' => 3]);
        },
        'ist keine Pool-Frage dieses Pfades',
    ],
    'fehlende Bilddatei' => [
        fn (LearningPath $path) => LessonBlock::factory()->for($path->lessons()->sole())->create([
            'position' => 2,
            'type' => 'media',
            'content' => ['kind' => 'image', 'url' => '/storage/lesson-media/bild.png', 'alt' => 'Bild', 'caption' => null],
        ]),
        'Die hochgeladene Bilddatei fehlt und ist nicht Teil des Exports.',
    ],
    'unbekannte Quelle' => [
        fn (LearningPath $path) => ContentSource::where('source_id', 'it-osi')->delete(),
        'Die Quelle it-osi ist in dieser Installation unbekannt',
    ],
]);

test('admins download the export with the slug as file name', function () {
    $path = importFixturePath();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.learning-paths.export', $path));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertDownload('test-content-path.json');

    expect(json_decode($response->streamedContent(), true)['slug'])->toBe('test-content-path');
});

test('apprentices and guests cannot download the export', function () {
    $path = LearningPath::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.learning-paths.export', $path))
        ->assertForbidden();

    auth()->logout();

    $this->get(route('admin.learning-paths.export', $path))
        ->assertRedirect(route('login'));
});

test('the detail view downloads directly when there is nothing to warn about', function () {
    $path = importFixturePath();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->assertSeeHtml('data-test="export-path-button"')
        ->call('export')
        ->assertRedirect(route('admin.learning-paths.export', $path));
});

test('the detail view lists the warnings before the download', function () {
    $path = importFixturePath();
    Lesson::query()->update(['source_reference' => null]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('export')
        ->assertNoRedirect()
        ->assertSet('exportWarnings', fn (array $warnings): bool => count($warnings) === 1)
        ->assertSeeHtml('data-test="export-warnings"')
        ->assertSee('Trotzdem herunterladen');
});

test('the overview downloads directly when there is nothing to warn about', function () {
    $path = importFixturePath();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.index')
        ->assertSeeHtml('data-test="path-export-button"')
        ->call('exportPath', $path->id)
        ->assertRedirect(route('admin.learning-paths.export', $path));
});

test('the overview lists the warnings before the download', function () {
    $path = importFixturePath();
    Lesson::query()->update(['source_reference' => null]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.index')
        ->call('exportPath', $path->id)
        ->assertNoRedirect()
        ->assertSet('exportWarnings', fn (array $warnings): bool => count($warnings) === 1)
        ->assertSeeHtml('data-test="export-warnings"')
        ->assertSeeHtml(route('admin.learning-paths.export', $path));
});

test('the overview lists the actions in a fixed order', function () {
    importFixturePath();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.index')
        ->assertSeeHtmlInOrder([
            'data-test="path-publish-button"',
            'data-test="path-review-button"',
            'data-test="path-edit-button"',
            'data-test="path-export-button"',
            'data-test="path-delete-button"',
        ]);
});

test('a final quiz question of another path is left out of question_positions', function () {
    $path = importFixturePath();
    $foreign = Question::factory()->singleChoice()->withSourceReference()->create();
    $path->finalQuiz->questions()->attach($foreign->id, ['position' => 3]);

    $exported = json_decode(exportPath($path)['json'], true);

    expect($exported['quiz']['question_positions'])->toBe([1, 2]);
});

test('the detail view lists every obstacle to a re-upload up front', function () {
    $path = importFixturePath();
    $path->lessons()->update(['source_reference' => null]);
    $path->finalQuiz()->delete();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->assertSeeHtml('data-test="import-problems"')
        ->assertSee('lessons.0.source_reference')
        ->assertSee('quiz:');
});

test('the detail view shows no obstacle hint for a complete path', function () {
    $path = importFixturePath();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->assertDontSeeHtml('data-test="import-problems"');
});

test('problemsIn starts with the message handleContents throws', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'] = [];
    unset($content['quiz']);
    $json = json_encode($content, JSON_THROW_ON_ERROR);
    $validate = app(ValidateContentFile::class);

    $problems = $validate->problemsIn($json, 'test.json');

    expect($problems)->toHaveCount(2)
        ->and(fn () => $validate->handleContents($json, 'test.json', null))
        ->toThrow(RuntimeException::class, $problems[0]);
});

test('problemsIn reports a broken file as a single problem', function () {
    expect(app(ValidateContentFile::class)->problemsIn('{kaputt', 'test.json'))
        ->toHaveCount(1)
        ->sequence(fn ($problem) => $problem->toContain('Ungültiges JSON'));
});
