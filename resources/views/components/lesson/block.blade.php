{{--
    Dispatcher der Render-Pipeline (Konzept d): löst einen LessonBlock in
    seine typspezifische Komponente auf. Wiederverwendet vom Lektions-Reader
    in S4.3 und der Live-Vorschau des Block-Editors.

    $glossary reicht der Reader durch (S4.3); die Editor-Vorschau lässt ihn
    weg, dann bleibt das Rendering unverändert.
--}}
@props(['block', 'glossary' => null])

<x-dynamic-component
    :component="'lesson.block.'.str_replace('_', '-', $block->type->value)"
    :block="$block"
    :glossary="$glossary"
/>
