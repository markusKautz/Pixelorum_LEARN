<?php

use App\Actions\Learning\LinkGlossaryTerms;
use App\Enums\LessonBlockType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\User;
use Dom\HTMLDocument;
use Livewire\Livewire;

/**
 * @param  array<string, string>  $terms
 */
function link_terms(string $html, array $terms): string
{
    return app(LinkGlossaryTerms::class)->forTerms($terms)->handle($html);
}

test('a term in running text is linked', function () {
    $linked = link_terms('<p>Der Compiler übersetzt den Code.</p>', [
        'Compiler' => 'Übersetzt Quellcode in Maschinencode.',
    ]);

    expect($linked)->toContain('data-test="glossary-term"')
        ->toContain('Compiler')
        ->toContain('Übersetzt Quellcode in Maschinencode.');
});

test('the linked term keeps the paragraph in one piece', function () {
    // Regression: flux:tooltip rendert seinen Inhalt als <div>. Ein <div> ist
    // in einem <p> nicht erlaubt, der Browser schloss den Absatz deshalb
    // vorzeitig und brach den Satz mitten im Text um. Nur Inline-Elemente.
    $linked = link_terms('<p>Davor ein Compiler danach.</p>', [
        'Compiler' => 'Übersetzt Quellcode in Maschinencode.',
    ]);

    $document = HTMLDocument::createFromString(
        '<div>'.$linked.'</div>',
        LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED,
    );

    expect($linked)->not->toContain('<div')
        ->and($document->getElementsByTagName('p')->count())->toBe(1)
        ->and(trim($document->getElementsByTagName('p')->item(0)->textContent))
        ->toStartWith('Davor ein Compiler')
        ->toEndWith('danach.');
});

test('only the first occurrence of a term is linked', function () {
    $linked = link_terms('<p>Ein Compiler ist ein Compiler.</p>', [
        'Compiler' => 'Definition.',
    ]);

    expect(substr_count($linked, 'data-test="glossary-term"'))->toBe(1);
});

test('occurrences in code, links and headings are left alone', function (string $html) {
    $linked = link_terms($html, ['Compiler' => 'Definition.']);

    expect($linked)->not->toContain('data-test="glossary-term"');
})->with([
    'inline code' => '<p><code>Compiler</code></p>',
    'code block' => '<pre><code>Compiler</code></pre>',
    'link' => '<p><a href="https://example.test">Compiler</a></p>',
    'heading' => '<h2>Compiler</h2>',
]);

test('a term in a table is skipped and linked later in running text', function () {
    $linked = link_terms(
        '<table><tbody><tr><td>UDP</td></tr></tbody></table><p>UDP sendet Datagramme.</p>',
        ['UDP' => 'User Datagram Protocol.'],
    );
    $document = HTMLDocument::createFromString(
        '<div>'.$linked.'</div>',
        LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED,
    );

    expect($document->getElementsByTagName('table')->item(0)->getElementsByClassName('lesson-glossary')->count())->toBe(0)
        ->and($document->getElementsByClassName('lesson-glossary')->count())->toBe(1)
        ->and($document->getElementsByTagName('p')->item(0)->getElementsByClassName('lesson-glossary')->count())->toBe(1);
});

test('a term never splits a longer word', function () {
    $linked = link_terms('<p>Eine Klassenvariable gehört zur Klasse.</p>', [
        'Klasse' => 'Bauplan für Objekte.',
    ]);

    // „Klassenvariable" bleibt unangetastet, „Klasse" am Satzende wird verlinkt.
    expect($linked)->toContain('Klassenvariable gehört zur <')
        ->and(substr_count($linked, 'data-test="glossary-term"'))->toBe(1);
});

test('longer terms are linked before shorter ones', function () {
    $linked = link_terms('<p>Die Klassenvariable ist besonders.</p>', [
        'Klasse' => 'Bauplan für Objekte.',
        'Klassenvariable' => 'Eine Variable, die zur Klasse gehört.',
    ]);

    expect($linked)->toContain('Eine Variable, die zur Klasse gehört.')
        ->and(substr_count($linked, 'data-test="glossary-term"'))->toBe(1);
});

test('several terms in one text node are all linked', function () {
    $linked = link_terms('<p>Der Compiler meldet einen Laufzeitfehler.</p>', [
        'Compiler' => 'Definition A.',
        'Laufzeitfehler' => 'Definition B.',
    ]);

    expect(substr_count($linked, 'data-test="glossary-term"'))->toBe(2);
});

test('matching is case insensitive but keeps the original spelling', function () {
    $linked = link_terms('<p>Der compiler arbeitet.</p>', ['Compiler' => 'Definition.']);

    expect($linked)->toContain('>compiler<');
});

test('html without a matching term is returned unchanged', function () {
    $html = '<p>Nichts zu sehen.</p>';

    expect(link_terms($html, ['Compiler' => 'Definition.']))->toBe($html);
});

test('the linker leaves the html alone without terms', function () {
    $html = '<p>Der Compiler arbeitet.</p>';

    expect(link_terms($html, []))->toBe($html);
});

test('the reader links glossary terms in the lesson text', function () {
    $learner = User::factory()->apprentice()->create();
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();

    LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::Markdown,
        'content' => ['body' => 'Der Compiler übersetzt den Quelltext.'],
        'position' => 1,
    ]);

    LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::Glossary,
        'content' => ['terms' => [['term' => 'Compiler', 'definition' => 'Übersetzt Quellcode.']]],
        'position' => 2,
    ]);

    Livewire::actingAs($learner)
        ->test('pages::learn.lessons.show', ['learningPath' => $path, 'lesson' => $lesson])
        ->assertSeeHtml('data-test="glossary-term"')
        // Der Glossar-Block bleibt zusätzlich als Definitionsliste stehen.
        ->assertSeeHtml('data-test="lesson-block-glossary"');
});

test('a term is linked only once per step, across blocks', function () {
    $learner = User::factory()->apprentice()->create();
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();

    foreach ([1, 2] as $position) {
        LessonBlock::factory()->for($lesson)->create([
            'type' => LessonBlockType::Markdown,
            'content' => ['body' => 'Der Compiler arbeitet in Block '.$position.'.'],
            'position' => $position,
        ]);
    }

    LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::Glossary,
        'content' => ['terms' => [['term' => 'Compiler', 'definition' => 'Übersetzt Quellcode.']]],
        'position' => 3,
    ]);

    $rendered = Livewire::actingAs($learner)
        ->test('pages::learn.lessons.show', ['learningPath' => $path, 'lesson' => $lesson])
        ->html();

    expect(substr_count($rendered, 'data-test="glossary-term"'))->toBe(1);
});

test('the editor preview renders unchanged without a glossary', function () {
    $admin = User::factory()->admin()->create();
    $path = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($path)->create();

    LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::Markdown,
        'content' => ['body' => 'Der Compiler arbeitet.'],
        'position' => 1,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.lessons.edit', [$path, $lesson]))
        ->assertOk()
        ->assertSee('Der Compiler arbeitet.')
        ->assertDontSee('data-test="glossary-term"', escape: false);
});
