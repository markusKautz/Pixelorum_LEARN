@props(['block', 'glossary' => null])

@php
    $format = $block->content['format'] ?? 'mermaid';
    $caption = $block->content['caption'] ?? null;
    $source = $block->content['source'] ?? '';

    // Template-Guard zusätzlich zur Speicher-Validierung (LessonBlockContent):
    // nur http(s) oder site-relative Pfade — kein javascript:/data:.
    $isSafeImageUrl = preg_match('#^https?://#i', $source)
        || (str_starts_with($source, '/') && ! str_starts_with($source, '//'));
@endphp

<figure class="space-y-2" data-test="lesson-block-diagram">
    @if ($format === 'mermaid')
        {{-- Gerendert clientseitig von resources/js/lesson-blocks.js --}}
        <pre class="mermaid" data-error-label="{{ __('The diagram could not be rendered. Please check the Mermaid source.') }}">{{ $source }}</pre>
    @elseif ($isSafeImageUrl)
        <img src="{{ $source }}" alt="{{ $caption ?? '' }}" class="max-w-full rounded-lg" />
    @else
        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="diagram-invalid-url">
            {{ __('Invalid image URL.') }}
        </flux:text>
    @endif

    @if ($caption)
        <figcaption class="text-sm text-zinc-500 dark:text-zinc-400">{{ $caption }}</figcaption>
    @endif
</figure>
