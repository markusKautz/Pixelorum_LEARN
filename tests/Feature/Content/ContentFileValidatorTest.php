<?php

use App\Actions\Content\ValidateContentFile;
use Illuminate\Support\Facades\File;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

beforeEach(function () {
    $this->registry = ContentSourceFixture::bind();
});

afterEach(function () {
    ContentFileFixture::clean();
    ContentSourceFixture::clean();
});

test('a complete draft learning path passes validation', function () {
    $content = ContentFileFixture::valid();

    $validated = (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry);

    expect($validated)->toBe($content);
});

test('malformed json reports the file before validation', function () {
    $file = ContentFileFixture::write(ContentFileFixture::valid());
    File::put($file, '{');

    try {
        (new ValidateContentFile)->handle($file, $this->registry);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toStartWith("{$file}: \$: ");

        return;
    }

    $this->fail('Expected malformed JSON to throw a RuntimeException.');
});

test('json root identity lets an empty object reach required field validation', function () {
    $file = ContentFileFixture::write(ContentFileFixture::valid());
    File::put($file, '{}');

    try {
        (new ValidateContentFile)->handle($file, $this->registry);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain("{$file}: slug:")
            ->not->toContain('Root muss ein JSON-Objekt sein');

        return;
    }

    $this->fail('Expected an empty JSON object to fail required field validation.');
});

test('json root identity rejects lists and scalars', function (string $json) {
    $file = ContentFileFixture::write(ContentFileFixture::valid());
    File::put($file, $json);

    expect(fn () => (new ValidateContentFile)->handle($file, $this->registry))
        ->toThrow(RuntimeException::class, '$: Root muss ein JSON-Objekt sein.');
})->with([
    'list' => '[]',
    'scalar' => '42',
]);

test('an unexpected root field such as review_status is rejected', function () {
    $content = ContentFileFixture::valid();
    $content['review_status'] = 'pending';

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'review_status');
});

test('published seed content is rejected', function () {
    $content = ContentFileFixture::valid();
    $content['status'] = 'published';

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'status');
});

test('a lesson without references reports lessons.0.source_reference', function () {
    $content = ContentFileFixture::valid();
    unset($content['lessons'][0]['source_reference']);

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.source_reference');
});

test('duplicate block positions are rejected within one lesson', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['blocks'][] = $content['lessons'][0]['blocks'][0];

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.blocks.1.position');
});

test('the same block position is valid in different lessons', function () {
    $content = ContentFileFixture::valid();
    $secondLesson = $content['lessons'][0];
    $secondLesson['position'] = 2;
    $content['lessons'][] = $secondLesson;

    $validated = (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry);

    expect($validated)->toBe($content);
});

test('duplicate inline question positions are rejected within one lesson', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['questions'][] = $content['lessons'][0]['questions'][0];

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.questions.1.position');
});

test('duplicate pool question positions are rejected', function () {
    $content = ContentFileFixture::valid();
    $content['questions'][1]['position'] = 1;

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'questions.1.position');
});

test('duplicate option positions are rejected within one question', function () {
    $content = ContentFileFixture::valid();
    $content['questions'][0]['options'][1]['position'] = 1;

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'questions.0.options.1.position');
});

test('block content is checked by LessonBlockContent', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['blocks'][0]['content'] = [];

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.blocks.0.content');
});

test('an empty section break content object is valid', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['blocks'][] = [
        'position' => 2,
        'type' => 'section_break',
        'content' => new stdClass,
    ];
    $file = ContentFileFixture::write($content);

    expect(File::get($file))->toContain('"content": {}');

    $validated = (new ValidateContentFile)->handle($file, $this->registry);

    expect($validated['lessons'][0]['blocks'][1]['content'])->toBe([]);
});

test('raw container identity rejects a section break content list', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['blocks'][0]['type'] = 'section_break';
    $content['lessons'][0]['blocks'][0]['content'] = [];
    $file = ContentFileFixture::write($content);

    expect(File::get($file))->toContain('"content": []');
    expect(fn () => (new ValidateContentFile)->handle($file, $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.blocks.0.content');
});

test('raw container identity rejects an object where inline questions must be a list', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['questions'] = new stdClass;
    $file = ContentFileFixture::write($content);

    expect(File::get($file))->toContain('"questions": {}');
    expect(fn () => (new ValidateContentFile)->handle($file, $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.questions');
});

test('raw container identity accepts an empty inline question list', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['questions'] = [];
    $file = ContentFileFixture::write($content);

    expect(File::get($file))->toContain('"questions": []');

    $validated = (new ValidateContentFile)->handle($file, $this->registry);

    expect($validated['lessons'][0]['questions'])->toBe([]);
});

test('numeric-key json objects cannot impersonate lists', function (Closure $mutate, string $path) {
    $content = ContentFileFixture::valid();
    $mutate($content);
    $file = ContentFileFixture::write($content);
    $raw = json_decode(File::get($file), false, 512, JSON_THROW_ON_ERROR);

    expect(data_get($raw, $path))->toBeInstanceOf(stdClass::class);
    expect(fn () => (new ValidateContentFile)->handle($file, $this->registry))
        ->toThrow(RuntimeException::class, $path);
})->with([
    'objectives' => [fn (array &$content) => $content['objectives'] = (object) $content['objectives'], 'objectives'],
    'lessons' => [fn (array &$content) => $content['lessons'] = (object) $content['lessons'], 'lessons'],
    'lesson source references' => [fn (array &$content) => $content['lessons'][0]['source_reference'] = (object) $content['lessons'][0]['source_reference'], 'lessons.0.source_reference'],
    'lesson blocks' => [fn (array &$content) => $content['lessons'][0]['blocks'] = (object) $content['lessons'][0]['blocks'], 'lessons.0.blocks'],
    'inline questions' => [fn (array &$content) => $content['lessons'][0]['questions'] = (object) $content['lessons'][0]['questions'], 'lessons.0.questions'],
    'inline source references' => [fn (array &$content) => $content['lessons'][0]['questions'][0]['source_reference'] = (object) $content['lessons'][0]['questions'][0]['source_reference'], 'lessons.0.questions.0.source_reference'],
    'inline options' => [fn (array &$content) => $content['lessons'][0]['questions'][0]['options'] = (object) $content['lessons'][0]['questions'][0]['options'], 'lessons.0.questions.0.options'],
    'pool questions' => [fn (array &$content) => $content['questions'] = (object) $content['questions'], 'questions'],
    'pool source references' => [fn (array &$content) => $content['questions'][0]['source_reference'] = (object) $content['questions'][0]['source_reference'], 'questions.0.source_reference'],
    'pool options' => [fn (array &$content) => $content['questions'][0]['options'] = (object) $content['questions'][0]['options'], 'questions.0.options'],
    'quiz question positions' => [fn (array &$content) => $content['quiz']['question_positions'] = (object) $content['quiz']['question_positions'], 'quiz.question_positions'],
]);

test('nested block content lists preserve raw container identity', function (
    string $type,
    string $field,
    array $item,
    bool $objectShaped,
) {
    $content = ContentFileFixture::valid();
    $items = [$item];
    $content['lessons'][0]['blocks'][0]['type'] = $type;
    $content['lessons'][0]['blocks'][0]['content'] = [
        $field => $objectShaped ? (object) $items : $items,
    ];
    $file = ContentFileFixture::write($content);
    $path = "lessons.0.blocks.0.content.{$field}";
    $raw = json_decode(File::get($file), false, 512, JSON_THROW_ON_ERROR);

    if ($objectShaped) {
        expect(data_get($raw, $path))->toBeInstanceOf(stdClass::class);
        expect(fn () => (new ValidateContentFile)->handle($file, $this->registry))
            ->toThrow(RuntimeException::class, $path);

        return;
    }

    expect(data_get($raw, $path))->toBeArray();

    $validated = (new ValidateContentFile)->handle($file, $this->registry);

    expect($validated['lessons'][0]['blocks'][0]['content'][$field])->toBe($items);
})->with([
    'accordion object' => ['accordion', 'items', ['heading' => 'Titel', 'body' => 'Inhalt'], true],
    'accordion list' => ['accordion', 'items', ['heading' => 'Titel', 'body' => 'Inhalt'], false],
    'glossary object' => ['glossary', 'terms', ['term' => 'Begriff', 'definition' => 'Definition'], true],
    'glossary list' => ['glossary', 'terms', ['term' => 'Begriff', 'definition' => 'Definition'], false],
]);

test('missing block content remains invalid', function () {
    $content = ContentFileFixture::valid();
    unset($content['lessons'][0]['blocks'][0]['content']);

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.blocks.0.content');
});

test('question options are checked by QuestionOptionsMatchType', function () {
    $content = ContentFileFixture::valid();
    $content['questions'][0]['options'][1]['is_correct'] = true;

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'questions.0.options');
});

test('is_correct must be a json boolean', function () {
    $content = ContentFileFixture::valid();
    $content['questions'][0]['options'][0]['is_correct'] = 'true';

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'questions.0.options.0.is_correct');
});

test('integer fields must be json integers instead of numeric strings', function (Closure $mutate, string $path) {
    $content = ContentFileFixture::valid();
    $mutate($content);

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, $path);
})->with([
    'root position' => [fn (array &$content) => $content['position'] = '1', 'position'],
    'estimated minutes' => [fn (array &$content) => $content['estimated_minutes'] = '30', 'estimated_minutes'],
    'root pass threshold' => [fn (array &$content) => $content['pass_threshold'] = '80', 'pass_threshold'],
    'lesson position' => [fn (array &$content) => $content['lessons'][0]['position'] = '1', 'lessons.0.position'],
    'lesson source page' => [fn (array &$content) => $content['lessons'][0]['source_reference'][0]['page'] = '12', 'lessons.0.source_reference.0.page'],
    'block position' => [fn (array &$content) => $content['lessons'][0]['blocks'][0]['position'] = '1', 'lessons.0.blocks.0.position'],
    'inline question position' => [fn (array &$content) => $content['lessons'][0]['questions'][0]['position'] = '1', 'lessons.0.questions.0.position'],
    'inline question source page' => [fn (array &$content) => $content['lessons'][0]['questions'][0]['source_reference'][0]['page'] = '12', 'lessons.0.questions.0.source_reference.0.page'],
    'inline option position' => [fn (array &$content) => $content['lessons'][0]['questions'][0]['options'][0]['position'] = '1', 'lessons.0.questions.0.options.0.position'],
    'pool question position' => [fn (array &$content) => $content['questions'][0]['position'] = '1', 'questions.0.position'],
    'pool question source slide' => [fn (array &$content) => $content['questions'][0]['source_reference'][0]['slide'] = '4', 'questions.0.source_reference.0.slide'],
    'pool question source page' => [fn (array &$content) => $content['questions'][1]['source_reference'][0]['page'] = '12', 'questions.1.source_reference.0.page'],
    'pool option position' => [fn (array &$content) => $content['questions'][0]['options'][0]['position'] = '1', 'questions.0.options.0.position'],
    'quiz pass threshold' => [fn (array &$content) => $content['quiz']['pass_threshold'] = '80', 'quiz.pass_threshold'],
    'quiz question position' => [fn (array &$content) => $content['quiz']['question_positions'][0] = '1', 'quiz.question_positions.0'],
]);

test('an unknown final quiz question position is rejected', function () {
    $content = ContentFileFixture::valid();
    $content['quiz']['question_positions'][1] = 999;

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'quiz.question_positions.1');
});

test('source reference errors report an unknown source id at its concrete field path', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'][0]['source_id'] = 'unknown';

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.source_reference.0.source_id');
});

test('source reference errors report an over-limit locator at its concrete field path', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'][0]['page'] = 175;

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.source_reference.0.page');
});

test('source reference errors report an unknown web heading at its concrete field path', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'] = [[
        'source_id' => 'official-source',
        'section' => 'Missing section',
    ]];

    expect(fn () => (new ValidateContentFile)->handle(ContentFileFixture::write($content), $this->registry))
        ->toThrow(RuntimeException::class, 'lessons.0.source_reference.0.section');
});

/*
 * Zweistufige Prüfung (S6.4, Entscheidung E2): Ohne Registry läuft die
 * Strukturprüfung, die Erdung bleibt Autorenwerkzeug. Der Admin-Upload nutzt
 * genau diesen Modus, weil die Produktion das Quellenarchiv nicht kennt.
 */

test('an unknown source id passes the structural check without a registry', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'][0]['source_id'] = 'nirgends-registriert';

    $validated = (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'upload.json',
        null,
    );

    expect($validated)->toBe($content);
});

test('the same unknown source id still fails with a registry', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'][0]['source_id'] = 'nirgends-registriert';

    expect(fn () => (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'upload.json',
        $this->registry,
    ))->toThrow(RuntimeException::class, 'lessons.0.source_reference.0.source_id');
});

test('a source reference without a locator fails without a registry', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'] = [['source_id' => 'scriptum']];

    expect(fn () => (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'upload.json',
        null,
    ))->toThrow(RuntimeException::class, 'lessons.0.source_reference.0');
});

test('a source reference with two locators fails without a registry', function () {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['source_reference'] = [[
        'source_id' => 'scriptum',
        'page' => 12,
        'section' => 'Approved section',
    ]];

    expect(fn () => (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'upload.json',
        null,
    ))->toThrow(RuntimeException::class, 'lessons.0.source_reference.0');
});

test('a structural error fails in both modes', function (?string $mode) {
    $content = ContentFileFixture::valid();
    $content['lessons'][0]['blocks'][0]['type'] = 'kein-blocktyp';

    expect(fn () => (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'upload.json',
        $mode === null ? null : $this->registry,
    ))->toThrow(RuntimeException::class, 'lessons.0.blocks.0.type');
})->with([
    'ohne Registry' => [null],
    'mit Registry' => ['registry'],
]);

test('the label replaces the file path in the error message', function () {
    $content = ContentFileFixture::valid();
    unset($content['title']);

    expect(fn () => (new ValidateContentFile)->handleContents(
        json_encode($content, JSON_THROW_ON_ERROR),
        'netzwerkgrundlagen.json',
        null,
    ))->toThrow(RuntimeException::class, 'netzwerkgrundlagen.json: title');
});
