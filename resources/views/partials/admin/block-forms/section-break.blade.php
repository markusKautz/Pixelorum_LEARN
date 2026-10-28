{{--
    Abschnitts-Trenner (Konzept d, Chunking-Richtung B): markiert im Block-Strom
    eine Segmentgrenze. Kein Pflichtinhalt — der optionale Titel wird im Reader
    (S4.3) zur Unterüberschrift des Abschnitts. Im Editor rendert die Vorschau
    eine Trennlinie, damit der Autor die Grenze sieht.
--}}
<flux:input
    wire:model.live.debounce.500ms="content.title"
    :label="__('Section title')"
    :placeholder="__('optional')"
    :description="__('Splits the lesson into a new section from here on; the title becomes its subheading in the reader.')"
    data-test="section-break-title-input"
/>
