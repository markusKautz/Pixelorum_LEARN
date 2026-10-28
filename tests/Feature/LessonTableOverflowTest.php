<?php

use Illuminate\Support\Facades\Blade;

/**
 * `flux:main` sitzt in `[grid-area:main]`. Ein Grid-Item hat `min-width: auto`
 * und schrumpft damit nicht unter die min-content-Breite seines Inhalts. Ohne
 * `min-w-0` zog eine breite Lektionstabelle die ganze Seite in die Breite,
 * statt im eigenen Wrapper zu scrollen: Der Wrapper bekam die volle
 * Tabellenbreite zugeteilt und hatte dadurch nichts mehr zu scrollen.
 *
 * Gemessen am 2026-08-25 bei 420 CSS-Pixeln Viewport: ohne die Klasse
 * `document.scrollWidth` 566 gegen `clientWidth` 485, mit ihr beides 485 und
 * der Wrapper scrollt intern. Dieser Wächter fängt den Rückfall, weil eine
 * CSS-Regression sonst erst wieder jemandem im Browser auffällt.
 */
test('the main region may shrink below its content width', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('<flux:main class="min-w-0">');
});

test('wide lesson tables render inside a horizontally scrollable wrapper', function () {
    $html = Blade::render(
        '<x-lesson.markdown :text="$text" />',
        ['text' => "| A | B | C |\n|---|---|---|\n| 1 | 2 | 3 |\n"],
    );

    expect($html)
        ->toContain('<div class="lesson-table-scroll"><table>')
        ->toContain('</table></div>');
});
