{{--
    Klickbare Überschrift eines Eintrags. Ein echter <button>, damit Tastatur
    und Screenreader ohne eigenes Zutun funktionieren. Der Chevron zeigt
    geöffnet nach oben.
--}}
<button
    type="button"
    x-on:click="open = ! open"
    x-bind:aria-expanded="open.toString()"
    x-bind:aria-controls="$id('accordion-content')"
    {{ $attributes->class('flex w-full cursor-pointer items-center justify-between gap-4 py-4 text-start font-medium text-zinc-800 dark:text-white') }}
>
    <span class="min-w-0 flex-1">{{ $slot }}</span>

    <flux:icon
        name="chevron-down"
        variant="mini"
        class="shrink-0 text-zinc-400 transition-transform duration-200 dark:text-zinc-500"
        x-bind:class="open && 'rotate-180'"
    />
</button>
