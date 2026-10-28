<?php

use App\Enums\LessonBlockType;
use App\Models\Lesson;
use App\Models\LessonBlock;

it('sortiert Blöcke nach position', function () {
    $lesson = Lesson::factory()->create();

    LessonBlock::factory()->for($lesson)->callout()->create(['position' => 3]);
    LessonBlock::factory()->for($lesson)->create(['position' => 1]);
    LessonBlock::factory()->for($lesson)->glossary()->create(['position' => 2]);

    expect($lesson->blocks()->pluck('type')->all())->toBe([
        LessonBlockType::Markdown,
        LessonBlockType::Glossary,
        LessonBlockType::Callout,
    ]);
});

it('castet den Block-Typ und die Content-Nutzlast', function () {
    $block = LessonBlock::factory()->create();

    $fresh = $block->fresh();

    expect($fresh->type)->toBe(LessonBlockType::Markdown)
        ->and($fresh->content)->toHaveKey('body')
        ->and($fresh->content['body'])->toBeString()
        ->and($fresh->lesson)->toBeInstanceOf(Lesson::class);
});

it('liefert je Block-Typ shape-konformen Content', function (?string $state, LessonBlockType $type, array $keys) {
    $factory = LessonBlock::factory();

    // Markdown ist der Default-Typ und hat keinen eigenen State.
    $block = ($state === null ? $factory : $factory->{$state}())->create()->fresh();

    expect($block->type)->toBe($type)
        ->and(array_keys($block->content))->toBe($keys);
})->with([
    'markdown' => [null, LessonBlockType::Markdown, ['body']],
    'accordion' => ['accordion', LessonBlockType::Accordion, ['items']],
    'callout' => ['callout', LessonBlockType::Callout, ['variant', 'body']],
    'code_snippet' => ['codeSnippet', LessonBlockType::CodeSnippet, ['language', 'code', 'filename']],
    'diagram' => ['diagram', LessonBlockType::Diagram, ['format', 'source', 'caption']],
    'media' => ['media', LessonBlockType::Media, ['kind', 'url', 'caption', 'alt']],
    'glossary' => ['glossary', LessonBlockType::Glossary, ['terms']],
]);

it('behält verschachtelte Content-Strukturen beim Roundtrip', function () {
    $block = LessonBlock::factory()->accordion()->create()->fresh();

    expect($block->content['items'])->toHaveCount(2)
        ->and($block->content['items'][0])->toHaveKeys(['heading', 'body']);
});

it('löscht Blöcke beim Löschen der Lektion mit', function () {
    $lesson = Lesson::factory()->create();
    LessonBlock::factory()->for($lesson)->create();

    $lesson->delete();

    expect(LessonBlock::where('lesson_id', $lesson->id)->count())->toBe(0);
});

it('castet die Quellenangabe einer Lektion', function () {
    $lesson = Lesson::factory()->withSourceReference()->create()->fresh();

    expect($lesson->source_reference)->toBeList()
        ->and($lesson->source_reference[0])->toHaveKey('source_id')
        ->and($lesson->source_reference[0]['source_id'])->toBe('scriptum')
        ->and($lesson->source_reference[0]['page'])->toBeInt();
});
