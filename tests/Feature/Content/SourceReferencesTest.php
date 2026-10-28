<?php

use App\Actions\Content\LoadSourceRegistry;
use App\Rules\SourceReferences;
use Illuminate\Support\Facades\Validator;
use Mockery\MockInterface;
use Tests\Support\ContentSourceFixture;

afterEach(function () {
    ContentSourceFixture::clean();
});

test('it accepts multiple local references with exactly one locator each', function () {
    $validator = Validator::make(['references' => [
        ['source_id' => 'scriptum', 'page' => 12],
        ['source_id' => 'it-osi', 'slide' => 4],
    ]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->passes())->toBeTrue();
});

test('it rejects an unknown source id', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => 'unknown', 'page' => 1,
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('it rejects more than one locator on a reference', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => 'scriptum', 'page' => 1, 'slide' => 1,
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('it rejects a locator above the extract page count', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => 'it-osi', 'slide' => 16,
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('it rejects a locator that does not match the source kind', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => 'it-osi', 'page' => 4,
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('only the admin form variant accepts a completely blank row', function (bool $allowBlankRows, bool $passes) {
    $validator = Validator::make(['references' => [[
        'source_id' => '', 'page' => null, 'slide' => null, 'section' => '',
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry(), $allowBlankRows)]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'strict content' => [false, false],
    'optional admin form' => [true, true],
]);

test('it reports a missing versioned extract', function () {
    $registry = ContentSourceFixture::registry();
    $registry['scriptum']['extract_path'] = storage_path('framework/testing/content-source-fixture/missing.txt');
    $validator = Validator::make(['references' => [[
        'source_id' => 'scriptum', 'page' => 1,
    ]]], ['references' => [new SourceReferences($registry)]]);

    expect($validator->fails())->toBeTrue();
});

test('it accepts only headings present in the approved source card', function (string $section, bool $passes) {
    $validator = Validator::make(['references' => [[
        'source_id' => 'official-source', 'section' => $section,
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'approved heading' => ['Approved section', true],
    'missing heading' => ['Missing section', false],
]);

test('it rejects case-sensitive heading mismatch', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => 'official-source', 'section' => 'approved section',
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('it rejects unexpected keys even if blank', function () {
    $validator = Validator::make(['references' => [[
        'source_id' => '', 'page' => null, 'unexpected' => '',
    ]]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
});

test('it rejects non-list, scalar entry, and malformed locator values without type error', function (mixed $value) {
    $validator = Validator::make(['references' => $value], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->fails())->toBeTrue();
})->with([
    'non-list string' => 'not-a-list',
    'non-list int' => 123,
    'scalar entry' => ['not-an-array'],
    'malformed source_id int' => [['source_id' => 123, 'page' => 1]],
    'malformed page array' => [['source_id' => 'scriptum', 'page' => []]],
    'malformed slide object' => [['source_id' => 'it-osi', 'slide' => new stdClass]],
]);

test('it rejects a missing web card', function () {
    $registry = ContentSourceFixture::registry();
    $registry['official-source']['snapshot_path'] = storage_path('framework/testing/content-source-fixture/missing-card.md');
    $validator = Validator::make(['references' => [[
        'source_id' => 'official-source', 'section' => 'Approved section',
    ]]], ['references' => [new SourceReferences($registry)]]);

    expect($validator->fails())->toBeTrue();
});

test('it accepts integer-like string locators from livewire', function () {
    $validator = Validator::make(['references' => [
        ['source_id' => 'scriptum', 'page' => '12'],
    ]], ['references' => [new SourceReferences(ContentSourceFixture::registry())]]);

    expect($validator->passes())->toBeTrue();
});

test('invalid locator value and above-max locator produce different messages', function () {
    $originalLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $registry = ContentSourceFixture::registry();

        $validatorInvalid = Validator::make(['references' => [
            ['source_id' => 'scriptum', 'page' => 'not-a-number'],
        ]], ['references' => [new SourceReferences($registry)]]);

        $validatorAboveMax = Validator::make(['references' => [
            ['source_id' => 'scriptum', 'page' => 999],
        ]], ['references' => [new SourceReferences($registry)]]);

        expect($validatorInvalid->fails())->toBeTrue()
            ->and($validatorAboveMax->fails())->toBeTrue()
            ->and($validatorInvalid->errors()->first('references'))->toBe('Source reference 1 needs a positive integer locator.')
            ->and($validatorAboveMax->errors()->first('references'))->toBe('Source reference 1 exceeds the source page count.');
    } finally {
        app()->setLocale($originalLocale);
    }
});

test('bind and clean remove the mock from the container', function () {
    ContentSourceFixture::bind();

    $instance = app()->make(LoadSourceRegistry::class);
    expect($instance)->toBeInstanceOf(MockInterface::class);

    ContentSourceFixture::clean();

    $instanceAfter = app()->make(LoadSourceRegistry::class);
    expect($instanceAfter)->toBeInstanceOf(LoadSourceRegistry::class)
        ->not->toBeInstanceOf(MockInterface::class);
});
