{{--
    `min-w-0` ist nicht kosmetisch. `flux:main` liegt in `[grid-area:main]`,
    und ein Grid-Item hat `min-width: auto`: Es schrumpft nicht unter die
    min-content-Breite seines Inhalts. Eine breite Lektionstabelle zog dadurch
    die ganze Seite in die Breite, statt im eigenen `.lesson-table-scroll` zu
    scrollen. Der Wrapper bekam die volle Tabellenbreite zugeteilt und hatte
    dadurch nichts mehr zu scrollen. Gefunden in Gate 8 zu OSI 4 (2026-08-25).
--}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="min-w-0">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
