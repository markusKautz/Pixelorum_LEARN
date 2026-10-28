<?php

use App\Actions\Content\CollectContentSources;
use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\ValidateContentFile;
use App\Models\LearningPath;
use Illuminate\Support\Facades\File;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

beforeEach(function () {
    $this->exportDirectory = storage_path('framework/testing/content-export-'.getmypid());
});

afterEach(function () {
    File::deleteDirectory($this->exportDirectory);
    ContentFileFixture::clean();
    ContentSourceFixture::clean();
});

test('a single path is exported into the target directory', function () {
    ContentSourceFixture::store();
    app(ImportLearningPath::class)->create(ContentFileFixture::valid());

    $this->artisan('lapp:content:export', ['slug' => 'test-content-path', '--dir' => $this->exportDirectory])
        ->assertSuccessful();

    $exported = json_decode(File::get("{$this->exportDirectory}/test-content-path.json"), true);

    expect($exported['schema_version'])->toBe(3)
        ->and(array_column($exported['sources'], 'source_id'))->toBe(['it-osi', 'scriptum']);
});

test('--all exports every live path and skips the trash', function () {
    LearningPath::factory()->create(['slug' => 'lebend']);
    LearningPath::factory()->create(['slug' => 'geloescht'])->delete();

    $this->artisan('lapp:content:export', ['--all' => true, '--dir' => $this->exportDirectory])
        ->assertSuccessful();

    expect(File::files($this->exportDirectory))->toHaveCount(1)
        ->and(File::exists("{$this->exportDirectory}/lebend.json"))->toBeTrue();
});

test('a trashed path is exported when named explicitly', function () {
    LearningPath::factory()->create(['slug' => 'geloescht'])->delete();

    $this->artisan('lapp:content:export', ['slug' => 'geloescht', '--dir' => $this->exportDirectory])
        ->assertSuccessful();

    expect(File::exists("{$this->exportDirectory}/geloescht.json"))->toBeTrue();
});

test('warnings are printed but do not fail the export', function () {
    // Ein Pfad ohne Lektionen und Abschlussquiz ist so nicht hochladbar.
    LearningPath::factory()->create(['slug' => 'leer']);

    $this->artisan('lapp:content:export', ['slug' => 'leer', '--dir' => $this->exportDirectory])
        ->expectsOutputToContain('leer.json:')
        ->assertSuccessful();
});

test('the export command needs either a slug or --all', function (array $arguments, string $output) {
    $this->artisan('lapp:content:export', $arguments)
        ->expectsOutputToContain($output)
        ->assertFailed();
})->with([
    'nichts' => [[], 'Entweder einen Slug oder --all angeben.'],
    'beides' => [['slug' => 'x', '--all' => true], 'Entweder einen Slug oder --all angeben.'],
    'unbekannter Slug' => [['slug' => 'gibt-es-nicht'], 'Lernpfad nicht gefunden: gibt-es-nicht'],
]);

test('the upgrade turns a version 1 file into a grounded current file', function () {
    ContentSourceFixture::bind();
    $file = ContentFileFixture::write(ContentFileFixture::valid(), 'upgrade');
    $before = File::get($file);

    $this->artisan('lapp:content:upgrade', ['file' => [$file]])->assertSuccessful();

    $after = File::get($file);
    $content = json_decode($after, true);

    expect($content['schema_version'])->toBe(3)
        ->and(array_keys($content))->toBe([
            'schema_version', 'slug', 'title', 'summary', 'objectives', 'position', 'difficulty',
            'estimated_minutes', 'pass_threshold', 'status', 'sources', 'lessons', 'questions', 'quiz',
        ])
        ->and(array_column($content['sources'], 'source_id'))->toBe(['it-osi', 'scriptum'])
        ->and($content['sources'][0])->not->toHaveKeys(['path', 'extract_path'])
        ->and(app(ValidateContentFile::class)->handle($file, ContentSourceFixture::registry()))->toBe($content);

    // Nur eingefügt, nichts umformatiert: Ohne die neuen Zeilen bleibt der
    // alte Text übrig.
    $removed = preg_replace('/^    "schema_version": 3,\n/m', '', $after);
    $removed = preg_replace('/^    "sources": \[\n.*?^    \],\n/ms', '', $removed);

    expect($removed)->toBe($before);

    $this->artisan('lapp:content:upgrade', ['file' => [$file]])
        ->expectsOutputToContain('Bereits aktuell')
        ->assertSuccessful();
});

test('an invalid file is left untouched by the upgrade', function () {
    ContentSourceFixture::bind();
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'] = [['source_id' => 'scriptum', 'page' => 999]];
    $file = ContentFileFixture::write($content, 'upgrade-invalid');
    $before = File::get($file);

    $this->artisan('lapp:content:upgrade', ['file' => [$file]])->assertFailed();

    expect(File::get($file))->toBe($before);
});

test('the upgrade only raises the version number of a version 2 file', function () {
    ContentSourceFixture::bind();
    $collect = new CollectContentSources;
    $content = [
        'schema_version' => 2,
        ...ContentFileFixture::valid(),
        'sources' => $collect->fromRegistry(ContentSourceFixture::registry(), ['it-osi', 'scriptum']),
    ];
    $file = ContentFileFixture::write($content, 'upgrade-v2');
    $before = File::get($file);

    $this->artisan('lapp:content:upgrade', ['file' => [$file]])->assertSuccessful();

    expect(File::get($file))->toBe(str_replace('"schema_version": 2,', '"schema_version": 3,', $before));
});
