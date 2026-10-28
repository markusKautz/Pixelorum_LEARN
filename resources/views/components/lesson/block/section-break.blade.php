{{--
    Abschnitts-Trenner. In der Editor-Vorschau eine sichtbare Trennlinie mit
    optionalem Titel, damit der Autor die Segmentgrenze erkennt. Im Reader (S4.3)
    wird der Trenner nicht inline gezeigt, sondern als Schrittgrenze konsumiert;
    der Titel wird dort zur Abschnitts-Unterüberschrift (Fortschritt bleibt
    lektionsweise).
--}}
@props(['block', 'glossary' => null])

@php($title = $block->content['title'] ?? null)

<div class="py-2" data-test="lesson-block-section-break">
    @if (filled($title))
        <flux:separator :text="$title" />
    @else
        <flux:separator />
    @endif
</div>
