{{--
    Entfernen-Knopf einer Datei-Karte; wire:click, aria-label und data-test
    kommen vom Aufrufer.
--}}
<button
    type="button"
    {{ $attributes->class('cursor-pointer rounded-md p-1.5 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-zinc-200') }}
>
    <flux:icon name="x-mark" variant="mini" />
</button>
