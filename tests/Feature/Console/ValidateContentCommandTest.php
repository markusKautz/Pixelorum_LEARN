<?php

use App\Actions\Content\ValidateContentFile;
use Illuminate\Support\Facades\File;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

/**
 * Ein eigenes Content-Root je Testprozess: Die Tests schreiben nie in das
 * echte Content-Verzeichnis und laufen auch dort, wo es fehlt.
 */
function testContentRoot(): string
{
    return 'storage/framework/testing/content-root-'.getmypid();
}

function testContentDatei(string $slug): string
{
    return base_path(testContentRoot()."/content/{$slug}.json");
}

beforeEach(function () {
    config(['lapp.content_root' => testContentRoot()]);
    File::ensureDirectoryExists(base_path(testContentRoot().'/content'));
});

afterEach(function () {
    File::deleteDirectory(base_path(testContentRoot()));
    ContentSourceFixture::clean();
});

test('it validates one selected slug', function () {
    $file = testContentDatei('test-command');
    File::put($file, '{}');
    ContentSourceFixture::bind();

    $validator = Mockery::mock(ValidateContentFile::class);
    $validator->shouldReceive('handle')
        ->once()
        ->with($file, Mockery::type('array'))
        ->andReturn(['slug' => 'test-command']);
    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate', ['slug' => 'test-command'])
        ->expectsOutputToContain('1 Content-Dateien wurden validiert.')
        ->assertSuccessful();
});

test('it validates every json file in sorted order', function () {
    $first = testContentDatei('test-command-a');
    $second = testContentDatei('test-command-b');
    File::put($second, '{}');
    File::put($first, '{}');
    ContentSourceFixture::bind();

    $validator = Mockery::mock(ValidateContentFile::class);

    foreach ([$first, $second] as $index => $file) {
        $validator->shouldReceive('handle')
            ->once()
            ->ordered()
            ->with($file, Mockery::type('array'))
            ->andReturn(['slug' => "validated-{$index}"]);
    }

    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate')
        ->expectsOutputToContain('2 Content-Dateien wurden validiert.')
        ->assertSuccessful();
});

test('it rejects duplicate slugs across files', function () {
    File::put(testContentDatei('test-command-a'), '{}');
    File::put(testContentDatei('test-command-b'), '{}');
    ContentSourceFixture::bind();

    $validator = Mockery::mock(ValidateContentFile::class);
    $validator->shouldReceive('handle')
        ->twice()
        ->with(Mockery::type('string'), Mockery::type('array'))
        ->andReturn(['slug' => 'duplicate']);
    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate')
        ->expectsOutputToContain('mehrfach')
        ->assertFailed();
});

test('it rejects a path traversal slug before resolving a file', function () {
    $validator = Mockery::mock(ValidateContentFile::class);
    $validator->shouldNotReceive('handle');
    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate', ['slug' => '../outside'])
        ->expectsOutputToContain('ungültig')
        ->assertFailed();
});

test('it rejects a non-string slug before resolving a file', function () {
    $validator = Mockery::mock(ValidateContentFile::class);
    $validator->shouldNotReceive('handle');
    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate', ['slug' => 123])
        ->expectsOutputToContain('ungültig')
        ->doesntExpectOutputToContain('fehlt')
        ->assertFailed();
});

test('it fails for a missing selected file and names the directory', function () {
    $validator = Mockery::mock(ValidateContentFile::class);
    $validator->shouldNotReceive('handle');
    app()->instance(ValidateContentFile::class, $validator);

    $this->artisan('lapp:content:validate', ['slug' => 'test-command-missing'])
        ->expectsOutputToContain('fehlt')
        ->expectsOutputToContain(testContentRoot().'/content')
        ->assertFailed();
});

test('it stops with a hint when the content directory is missing', function () {
    File::deleteDirectory(base_path(testContentRoot()));

    $this->artisan('lapp:content:validate')
        ->expectsOutputToContain('Kein Content-Verzeichnis gefunden')
        ->assertFailed();
});

test('it reports a missing source registry instead of crashing', function () {
    File::put(testContentDatei('test-command'), '{}');

    $this->artisan('lapp:content:validate')
        ->expectsOutputToContain('Source registry not found')
        ->assertFailed();
});

/**
 * Eine Content-Datei, wie sie ein Autor außerhalb des Projekts schreibt:
 * aktuelle Schema-Version, Quellen im eigenen `sources`-Block.
 *
 * @param  array<string, mixed>  $overrides
 */
function eigenstaendigeDatei(array $overrides = []): string
{
    $source = fn (string $id, string $kind, string $title): array => [
        'source_id' => $id,
        'kind' => $kind,
        'title' => $title,
        'publisher' => null,
        'url' => null,
        'version' => null,
        'published_at' => null,
        'accessed_at' => null,
        'content_sha256' => null,
    ];

    return ContentFileFixture::write([
        'schema_version' => ValidateContentFile::SCHEMA_VERSION_CURRENT,
        ...ContentFileFixture::valid(),
        'sources' => [$source('it-osi', 'slides', 'IT-OSI'), $source('scriptum', 'pdf', 'Scriptum')],
        ...$overrides,
    ], 'standalone');
}

test('the file mode accepts a valid file without the source archive', function () {
    $this->artisan('lapp:content:validate', ['--file' => [eigenstaendigeDatei()]])
        ->expectsOutputToContain('1 Content-Dateien wurden validiert.')
        ->assertSuccessful();

    ContentFileFixture::clean();
});

test('the file mode lists every problem at once', function () {
    $this->artisan('lapp:content:validate', ['--file' => [eigenstaendigeDatei([
        'pass_threshold' => 150,
        'objectives' => [],
    ])]])
        ->expectsOutputToContain('2 Problem(e) gefunden')
        ->expectsOutputToContain('pass_threshold')
        ->expectsOutputToContain('objectives')
        ->assertFailed();

    ContentFileFixture::clean();
});

test('the file mode rejects a reference to an undeclared source', function () {
    $lesson = ContentFileFixture::valid()['lessons'][0];
    $lesson['source_reference'] = [['source_id' => 'nirgends', 'page' => 3]];

    $this->artisan('lapp:content:validate', ['--file' => [eigenstaendigeDatei(['lessons' => [$lesson]])]])
        ->expectsOutputToContain('lessons.0.source_reference')
        ->assertFailed();

    ContentFileFixture::clean();
});

test('the file mode rejects a version 1 file with a hint', function () {
    $file = ContentFileFixture::write(ContentFileFixture::valid(), 'standalone');

    $this->artisan('lapp:content:validate', ['--file' => [$file]])
        ->expectsOutputToContain('Schema-Version 1 trägt keine Quellen')
        ->assertFailed();

    ContentFileFixture::clean();
});

test('the file mode reports a missing file and invalid json', function () {
    $broken = ContentFileFixture::write([], 'standalone');
    file_put_contents($broken, '{ kein json');

    $this->artisan('lapp:content:validate', ['--file' => ['/nirgends/pfad.json', $broken]])
        ->expectsOutputToContain('Datei nicht gefunden')
        ->expectsOutputToContain('Ungültiges JSON')
        ->assertFailed();

    ContentFileFixture::clean();
});

test('the file mode and a slug exclude each other', function () {
    $this->artisan('lapp:content:validate', ['slug' => 'test-command', '--file' => [eigenstaendigeDatei()]])
        ->expectsOutputToContain('schließen sich aus')
        ->assertFailed();

    ContentFileFixture::clean();
});
