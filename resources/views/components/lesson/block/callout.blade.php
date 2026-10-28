@props(['block', 'glossary' => null])

@php
    $variant = $block->content['variant'] ?? 'info';

    // Farbsemantik aus Konzept g: warning ist nicht-bestrafend (amber, nie
    // rot), danger bleibt echten Gefahren vorbehalten, exam markiert
    // Prüfungsrelevantes im Brand-Ton.
    [$color, $icon] = match ($variant) {
        'warning' => ['amber', 'exclamation-triangle'],
        'danger' => ['red', 'exclamation-circle'],
        'highlight' => ['pink', 'sparkles'],
        'exam' => ['indigo', 'academic-cap'],
        default => ['blue', 'information-circle'],
    };
@endphp

<flux:callout :color="$color" :icon="$icon" data-test="lesson-block-callout" data-variant="{{ $variant }}">
    <flux:callout.text>
        <x-lesson.markdown :text="$block->content['body'] ?? ''" :glossary="$glossary" />
    </flux:callout.text>
</flux:callout>
