<?php

use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\ValidateContentFile;
use App\Enums\LessonBlockType;
use App\Enums\QuestionType;
use App\Enums\SourceKind;
use App\Models\LearningPath;
use App\Rules\LessonBlockContent;

/**
 * Der Skill `learn-content` beschreibt das Content-Format für Autoren
 * außerhalb des Projekts. Diese Tests halten ihn am Code fest: Ändert sich
 * das Format, schlägt hier ein Test an, statt dass der Skill still veraltet.
 */
function skillDatei(string $relativePath): string
{
    return base_path(".ai/skills/learn-content/{$relativePath}");
}

test('the example file passes the file mode validation', function () {
    $this->artisan('lapp:content:validate', ['--file' => [skillDatei('references/example.json')]])
        ->expectsOutputToContain('1 Content-Dateien wurden validiert.')
        ->assertSuccessful();
});

test('the example file uses the current schema version', function () {
    $content = json_decode(file_get_contents(skillDatei('references/example.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($content['schema_version'])->toBe(ValidateContentFile::SCHEMA_VERSION_CURRENT);
});

test('the example file imports as a learning path', function () {
    $content = json_decode(file_get_contents(skillDatei('references/example.json')), true, flags: JSON_THROW_ON_ERROR);

    $path = app(ImportLearningPath::class)->create($content);

    expect(LearningPath::count())->toBe(1)
        ->and($path->lessons()->count())->toBe(2)
        ->and($path->finalQuiz->questions()->count())->toBe(3);
});

test('the example file shows every block type except media and every question type', function () {
    $content = json_decode(file_get_contents(skillDatei('references/example.json')), true, flags: JSON_THROW_ON_ERROR);

    $blockTypes = collect($content['lessons'])->flatMap(fn (array $lesson) => array_column($lesson['blocks'], 'type'))->unique()->sort()->values()->all();
    $questionTypes = collect($content['lessons'])
        ->flatMap(fn (array $lesson) => $lesson['questions'])
        ->merge($content['questions'])
        ->pluck('type')->unique()->sort()->values()->all();

    $expectedBlocks = collect(LessonBlockType::cases())
        ->reject(fn (LessonBlockType $type) => $type === LessonBlockType::Media)
        ->map->value->sort()->values()->all();

    expect($blockTypes)->toBe($expectedBlocks)
        ->and($questionTypes)->toBe(collect(QuestionType::cases())->map->value->sort()->values()->all());
});

test('the format reference names every allowed value of the code', function () {
    $format = file_get_contents(skillDatei('references/format.md'));

    $values = [
        ...array_map(fn (LessonBlockType $type) => $type->value, LessonBlockType::cases()),
        ...array_map(fn (QuestionType $type) => $type->value, QuestionType::cases()),
        ...LessonBlockContent::CALLOUT_VARIANTS,
        ...LessonBlockContent::CODE_LANGUAGES,
        ...LessonBlockContent::DIAGRAM_FORMATS,
        ...LessonBlockContent::MEDIA_KINDS,
    ];

    foreach ($values as $value) {
        expect($format)->toContain("`{$value}`");
    }
});

test('the source reference names every source kind of the code', function () {
    $sources = file_get_contents(skillDatei('references/sources.md'));

    foreach (SourceKind::cases() as $kind) {
        expect($sources)->toContain("`{$kind->value}`");
    }
});

test('the skill is published under the MIT license', function () {
    expect(file_get_contents(skillDatei('SKILL.md')))->toContain("\nlicense: MIT\n");
});
