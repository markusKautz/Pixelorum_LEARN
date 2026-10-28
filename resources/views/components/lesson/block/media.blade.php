@props(['block', 'glossary' => null])

@php
    $kind = $block->content['kind'] ?? 'image';
    $url = $block->content['url'] ?? '';
    $caption = $block->content['caption'] ?? null;
    $alt = $block->content['alt'] ?? null;
    $isDirectVideo = (bool) preg_match('/\.(mp4|webm|ogg)(\?.*)?$/i', $url);

    // Template-Guard zusätzlich zur Speicher-Validierung (LessonBlockContent):
    // nur http(s) bzw. für Bilder site-relative Pfade — kein javascript:/data:.
    $isHttpUrl = (bool) preg_match('#^https?://#i', $url);
    $isSafeImageUrl = $isHttpUrl || (str_starts_with($url, '/') && ! str_starts_with($url, '//'));
@endphp

<figure class="space-y-2" data-test="lesson-block-media">
    @if ($kind === 'image' && $isSafeImageUrl)
        <img src="{{ $url }}" alt="{{ $alt ?? $caption ?? '' }}" class="max-w-full rounded-lg" />
    @elseif ($kind === 'video' && $isHttpUrl && $isDirectVideo)
        <video controls preload="metadata" class="w-full rounded-lg" src="{{ $url }}"></video>
    @elseif ($kind === 'video' && $isHttpUrl)
        {{-- Externe Video-Plattformen werden nicht eingebettet (MVP-Grenze) --}}
        <flux:button :href="$url" target="_blank" rel="noopener" icon="play" variant="outline" data-test="media-video-link">
            {{ __('Open video') }}
        </flux:button>
    @else
        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="media-invalid-url">
            {{ __('Invalid media URL.') }}
        </flux:text>
    @endif

    @if ($caption)
        <figcaption class="text-sm text-zinc-500 dark:text-zinc-400">{{ $caption }}</figcaption>
    @endif
</figure>
