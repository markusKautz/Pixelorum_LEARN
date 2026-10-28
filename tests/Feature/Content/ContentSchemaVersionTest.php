<?php

use App\Actions\Content\CollectContentSources;
use App\Actions\Content\ValidateContentFile;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

afterEach(function () {
    ContentSourceFixture::clean();
});

/**
 * Die Validator-Fixture als Schema-Version 2 mit passendem Quellenblock.
 *
 * @return array<string, mixed>
 */
function contentVersionTwo(): array
{
    $collect = new CollectContentSources;
    $registry = ContentSourceFixture::registry();

    return [
        'schema_version' => 2,
        ...ContentFileFixture::valid(),
        'sources' => $collect->fromRegistry($registry, ['it-osi', 'scriptum']),
    ];
}

/**
 * @param  array<string, mixed>  $content
 * @param  array<string, array<string, mixed>>|null  $registry
 * @param  list<string>|null  $knownSourceIds
 * @return array<string, mixed>
 */
function validateContent(array $content, ?array $registry = null, ?array $knownSourceIds = null): array
{
    return app(ValidateContentFile::class)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'pfad.json',
        $registry,
        $knownSourceIds,
    );
}

test('a file without schema_version is read as version 1', function () {
    $content = ContentFileFixture::valid();

    expect(validateContent($content))->toBe($content)
        ->and(app(ValidateContentFile::class)->schemaVersionOf($content))->toBe(1);
});

test('an explicit version 1 is accepted', function () {
    expect(validateContent(['schema_version' => 1, ...ContentFileFixture::valid()]))
        ->toHaveKey('schema_version', 1);
});

test('a version newer than the installation is rejected before any structure rule', function () {
    // Ohne `lessons` scheiterte die Datei sonst an einer Strukturregel, die
    // den eigentlichen Grund verdeckt.
    validateContent(['schema_version' => 4, 'slug' => 'zukunft']);
})->throws(RuntimeException::class, 'pfad.json: schema_version: Schema-Version 4 ist neuer als diese Installation unterstützt (bis 3).');

test('a malformed schema version is rejected', function (mixed $version) {
    validateContent(['schema_version' => $version, ...ContentFileFixture::valid()]);
})->with([
    'string' => '1',
    'float' => 1.5,
    'zero' => 0,
    'bool' => true,
])->throws(RuntimeException::class, 'schema_version: Die Schema-Version muss eine ganze Zahl ab 1 sein.');

test('version 1 does not allow a sources block', function () {
    validateContent([...ContentFileFixture::valid(), 'sources' => []]);
})->throws(RuntimeException::class, 'sources: Unerwartetes Feld.');

test('a version 2 file with a matching sources block is valid without and with registry', function () {
    $content = contentVersionTwo();

    expect(validateContent($content))->toBe($content)
        ->and(validateContent($content, ContentSourceFixture::registry()))->toBe($content);
});

test('version 2 requires the sources block', function () {
    $content = contentVersionTwo();
    unset($content['sources']);

    validateContent($content);
})->throws(RuntimeException::class, 'pfad.json: sources:');

test('version 2 rejects broken sources blocks', function (Closure $mutate, string $path) {
    $content = contentVersionTwo();
    $mutate($content);

    expect(fn () => validateContent($content))->toThrow(RuntimeException::class, "pfad.json: {$path}");
})->with([
    'doppelte source_id' => [function (array &$content): void {
        $content['sources'][] = $content['sources'][0];
    }, 'sources.'],
    'unbekannter Typ' => [function (array &$content): void {
        $content['sources'][0]['kind'] = 'buch';
    }, 'sources.0.kind'],
    'unerwartetes Feld' => [function (array &$content): void {
        $content['sources'][0]['extract_path'] = 'doc/ressource/extract/x.txt';
    }, 'sources.0'],
    'Webquelle ohne URL' => [function (array &$content): void {
        $content['sources'][0]['kind'] = 'web';
        $content['sources'][0]['url'] = null;
    }, 'sources.0.url'],
    'Quellenangabe ohne Eintrag im Block' => [function (array &$content): void {
        $content['sources'] = array_values(array_filter(
            $content['sources'],
            fn (array $source): bool => $source['source_id'] !== 'it-osi',
        ));
    }, 'questions.0.source_reference.0.source_id'],
    'Locator passt nicht zum Typ im Block' => [function (array &$content): void {
        $content['questions'][0]['source_reference'] = [['source_id' => 'it-osi', 'page' => 4]];
    }, 'questions.0.source_reference.0.page'],
    'ungenutzter Eintrag' => [function (array &$content): void {
        $content['sources'][] = [...$content['sources'][0], 'source_id' => 'nie-verwendet'];
    }, 'sources.2.source_id'],
]);

test('a sources entry that differs from the registry is only rejected with registry', function () {
    $content = contentVersionTwo();
    $content['sources'][1]['title'] = 'Anderer Titel';

    expect(validateContent($content))->toBe($content)
        ->and(fn () => validateContent($content, ContentSourceFixture::registry()))
        ->toThrow(RuntimeException::class, 'sources.1.title: Die Quelle scriptum weicht im Feld title von der Quellen-Registry ab.');
});

test('version 1 without registry checks the source ids only when known ids are given', function () {
    $content = ContentFileFixture::valid();

    expect(validateContent($content))->toBe($content)
        ->and(validateContent($content, knownSourceIds: ['scriptum', 'it-osi']))->toBe($content)
        ->and(fn () => validateContent($content, knownSourceIds: ['scriptum']))
        ->toThrow(RuntimeException::class, 'questions.0.source_reference.0.source_id: Quellenangabe 1 verweist auf eine Quelle, die diese Installation nicht kennt.');
});

test('known source ids do not apply to version 2', function () {
    $content = contentVersionTwo();

    expect(validateContent($content, knownSourceIds: []))->toBe($content);
});
