{{--
    In Sprint 3 als einfache Definitionsliste; die Tooltip-Verlinkung im
    Fließtext ist Reader-Scope (S4.3).
--}}
@props(['block', 'glossary' => null])

<dl class="space-y-3" data-test="lesson-block-glossary">
    @foreach ($block->content['terms'] ?? [] as $term)
        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <dt class="font-medium">{{ $term['term'] ?? '' }}</dt>
            <dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $term['definition'] ?? '' }}</dd>
        </div>
    @endforeach
</dl>
