<?php

use App\Models\LessonBlock;

test('a markdown block renders its body through the pipeline', function () {
    $block = LessonBlock::factory()->create([
        'content' => ['body' => 'Ein **wichtiger** Hinweis.'],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('lesson-markdown', escape: false)
        ->assertSee('<strong>wichtiger</strong>', escape: false);
});

test('an accordion block renders one item per entry', function () {
    $block = LessonBlock::factory()->accordion()->create();

    $view = $this->blade('<x-lesson.block :block="$block" />', ['block' => $block]);

    foreach ($block->content['items'] as $item) {
        $view->assertSee($item['heading']);
    }
});

test('a callout block maps its variant to color and icon', function () {
    $block = LessonBlock::factory()->callout()->create();

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('data-variant="exam"', escape: false)
        ->assertSee('Prüfungsrelevant');
});

test('a code snippet block exposes the language class for highlighting', function () {
    $block = LessonBlock::factory()->codeSnippet()->create();

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('language-php', escape: false)
        ->assertSee('hallo.php');
});

test('a mermaid diagram renders its source into a mermaid container', function () {
    $block = LessonBlock::factory()->diagram()->create();

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('class="mermaid"', escape: false)
        ->assertSee('graph TD;')
        ->assertSee('Ablauf einer Anfrage');
});

test('lesson block enhancements rerun after livewire finishes morphing', function () {
    $javascript = file_get_contents(resource_path('js/lesson-blocks.js'));

    expect($javascript)->toContain("Livewire.hook('morphed'");
});

test('an image diagram renders an img tag instead of a mermaid container', function () {
    $block = LessonBlock::factory()->diagram()->create([
        'content' => ['format' => 'image', 'source' => '/storage/lesson-media/schema.png', 'caption' => 'Schema'],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('src="/storage/lesson-media/schema.png"', escape: false)
        ->assertDontSee('class="mermaid"', escape: false);
});

test('an image media block renders an img with alt text', function () {
    $block = LessonBlock::factory()->media()->create();

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('src="https://example.test/bilder/schleifen.png"', escape: false)
        ->assertSee('Schematische Darstellung einer Schleife');
});

test('a direct video url renders a video element', function () {
    $block = LessonBlock::factory()->media()->create([
        'content' => ['kind' => 'video', 'url' => 'https://example.test/kurs.mp4', 'caption' => null, 'alt' => null],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('<video', escape: false)
        ->assertSee('https://example.test/kurs.mp4');
});

test('an external video url renders a link instead of an embed', function () {
    $block = LessonBlock::factory()->media()->create([
        'content' => ['kind' => 'video', 'url' => 'https://videos.example.test/watch?v=abc', 'caption' => null, 'alt' => null],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('<video', escape: false)
        ->assertSee('data-test="media-video-link"', escape: false);
});

test('a glossary block renders a definition list', function () {
    $block = LessonBlock::factory()->glossary()->create();

    $view = $this->blade('<x-lesson.block :block="$block" />', ['block' => $block]);

    $view->assertSee('<dl', escape: false);

    foreach ($block->content['terms'] as $term) {
        $view->assertSee($term['term'])->assertSee($term['definition']);
    }
});

test('a javascript video url renders neither link nor embed', function () {
    $block = LessonBlock::factory()->media()->create([
        'content' => ['kind' => 'video', 'url' => 'javascript:alert(1)', 'caption' => null, 'alt' => null],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('javascript:', escape: false)
        ->assertSee('data-test="media-invalid-url"', escape: false);
});

test('a data url image renders a fallback instead of an img tag', function () {
    $block = LessonBlock::factory()->media()->create([
        'content' => ['kind' => 'image', 'url' => 'data:text/html;base64,PHNjcmlwdD4=', 'caption' => null, 'alt' => null],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('<img', escape: false)
        ->assertSee('data-test="media-invalid-url"', escape: false);
});

test('a non-http diagram image renders a fallback instead of an img tag', function () {
    $block = LessonBlock::factory()->diagram()->create([
        'content' => ['format' => 'image', 'source' => 'javascript:alert(1)', 'caption' => null],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('<img', escape: false)
        ->assertSee('data-test="diagram-invalid-url"', escape: false);
});

test('a markdown table renders as a table inside a scrollable wrapper', function () {
    $block = LessonBlock::factory()->create([
        'content' => ['body' => "| Symbol | Wert |\n|---|---|\n| Ki | 1024 |"],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('<div class="lesson-table-scroll"><table>', escape: false)
        ->assertSee('<th>Symbol</th>', escape: false)
        ->assertSee('<td>1024</td>', escape: false)
        ->assertSee('</table></div>', escape: false);
});

test('the lesson stylesheet styles markdown tables', function () {
    // Regression aus Gate 5: Der Konverter lieferte korrektes <table>-Markup,
    // aber app.css hatte keine Tabellenregel und Tailwinds Preflight entfernt
    // die Browser-Rahmen — die Tabellen rendern dann als Textwüste.
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.lesson-markdown table')
        ->toContain('.lesson-markdown :where(th, td)')
        ->toContain('.lesson-markdown .lesson-table-scroll')
        ->toContain('overflow-x: auto');
});

test('glossary definitions stay inside the mobile viewport', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('@media (max-width: 40rem)')
        ->toMatch('/@media \(max-width: 40rem\)[\s\S]*?\.lesson-glossary-definition\s*\{[\s\S]*?position:\s*fixed;[\s\S]*?right:\s*1rem;[\s\S]*?left:\s*1rem;/');
});

test('markdown escapes script tags instead of running them', function () {
    $block = LessonBlock::factory()->create([
        'content' => ['body' => "Harmlos.\n\n<script>alert('xss')</script>"],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('<script>', escape: false)
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertSee('Harmlos.');
});

test('markdown keeps generic type names visible', function () {
    // commonmark ab 2.10.4 liest `<T>` als HTML-Tag; mit `strip` verschwand
    // es aus dem Text.
    $block = LessonBlock::factory()->create([
        'content' => ['body' => 'Eine **List<T>** wächst, ein **HashSet<T>** verhindert Duplikate.'],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertSee('<strong>List&lt;T&gt;</strong>', escape: false)
        ->assertSee('<strong>HashSet&lt;T&gt;</strong>', escape: false);
});

test('markdown neutralizes javascript links', function () {
    $block = LessonBlock::factory()->create([
        'content' => ['body' => '[Klick mich](javascript:alert(1))'],
    ]);

    $this->blade('<x-lesson.block :block="$block" />', ['block' => $block])
        ->assertDontSee('href="javascript:', escape: false)
        ->assertSee('Klick mich');
});
