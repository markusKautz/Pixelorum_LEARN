@props(['block', 'glossary' => null])

@php
    $language = $block->content['language'] ?? 'text';
    $filename = $block->content['filename'] ?? null;
@endphp

<div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="lesson-block-code-snippet">
    @if ($filename)
        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2 font-mono text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
            {{ $filename }}
        </div>
    @endif

    <pre class="overflow-x-auto p-4 text-sm"><code class="language-{{ $language }}">{{ $block->content['code'] ?? '' }}</code></pre>
</div>
