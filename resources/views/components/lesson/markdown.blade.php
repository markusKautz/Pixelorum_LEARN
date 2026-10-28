{{--
    Die eine Markdown-Render-Stelle (Entscheidung 5): XSS-Härtung zentral —
    rohes HTML wird als Text maskiert, unsichere Links (javascript: u.ä.)
    entschärft. Alle markdown-tragenden Block-Komponenten müssen hierüber
    rendern.

    Maskieren statt Entfernen (seit 2026-10-27): commonmark ab 2.10.4 liest
    C#-Generics wie `List<T>` als HTML-Tag. Mit `strip` verschwand `<T>`
    stillschweigend aus dem Lektionstext, mit `escape` bleibt er sichtbar.

    $glossary ist der optionale, zustandsbehaftete Linker des Lektions-Readers
    (S4.3): er hängt an die erste Fundstelle je Begriff einen Tooltip. Er
    arbeitet auf dem **bereits gesäuberten** HTML, damit die Härtung oben
    nicht dupliziert wird. Ohne ihn (Editor-Vorschau) bleibt alles wie gehabt.
--}}
@props(['text', 'glossary' => null])

@php
    $html = Illuminate\Support\Str::markdown($text ?? '', [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]);

    if ($glossary?->hasTerms()) {
        $html = $glossary->handle($html);
    }

    // GFM-Tabellen bekommen einen scrollbaren Wrapper, damit breite Tabellen
    // auf schmalen Bildschirmen nicht die ganze Seite in die Breite ziehen.
    // Das Markup stammt ausschließlich aus dem Konverter — rohes HTML ist
    // oben bereits maskiert —, deshalb genügt der einfache Austausch.
    $html = str_replace(
        ['<table>', '</table>'],
        ['<div class="lesson-table-scroll"><table>', '</table></div>'],
        $html,
    );
@endphp

<div {{ $attributes->merge(['class' => 'lesson-markdown']) }}>{!! $html !!}</div>
