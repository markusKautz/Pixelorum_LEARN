<?php

use App\Rules\LessonBlockContent;

/**
 * highlight.js liegt seit DEV-6 in resources/js/highlight.js und wird erst
 * nachgeladen, wenn eine Seite Code-Blöcke enthält. Die Sprachen dort müssen
 * zu den erlaubten Sprachen der Code-Blöcke passen, sonst bleibt ein
 * gespeicherter Block ungefärbt, ohne dass ein anderer Test es bemerkt.
 */
test('every allowed code language is registered in the highlight module', function () {
    $module = file_get_contents(resource_path('js/highlight.js'));

    preg_match_all("/registerLanguage\\('([a-z]+)'/", $module, $languages);
    preg_match_all("/registerAliases\\(\\['([a-z]+)'\\]/", $module, $aliases);

    $registered = [...$languages[1], ...$aliases[1]];

    expect(array_diff(LessonBlockContent::CODE_LANGUAGES, $registered))->toBe([]);
});

test('highlight.js is only loaded lazily and not bundled into every page', function () {
    $renderer = file_get_contents(resource_path('js/lesson-blocks.js'));

    expect($renderer)->not->toContain("from 'highlight.js")
        ->and($renderer)->toContain("import('./highlight')");
});
