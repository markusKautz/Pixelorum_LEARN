@props([
    'heading',
    'image' => null,
    'size' => null,
])

{{--
    Karte für eine gewählte Datei (S6.5, Ersatz für Flux-Pro file-item,
    Clean-Room-Nachbau): Vorschaubild oder Dateisymbol, Name, Größe und rechts
    die Aktionen (Slot `actions`, z.B. <x-ui.file-item.remove>).
--}}
<div {{ $attributes->class('flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-2.5 shadow-xs dark:border-white/10 dark:bg-white/5') }}>
    @if ($image)
        <img src="{{ $image }}" alt="" class="h-10 w-14 shrink-0 rounded-md object-cover" />
    @else
        <span class="flex h-10 w-14 shrink-0 items-center justify-center rounded-md bg-zinc-100 dark:bg-white/10">
            <flux:icon name="document" variant="mini" class="text-zinc-400" />
        </span>
    @endif

    <div class="min-w-0 flex-1">
        <div class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $heading }}</div>

        @if ($size !== null)
            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Number::fileSize($size) }}</div>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center">
            {{ $actions }}
        </div>
    @endisset
</div>
