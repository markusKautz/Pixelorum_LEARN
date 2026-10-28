@props([
    'label' => null,
    'description' => null,
    'heading' => null,
    'text' => null,
])

{{--
    Dropzone für einen Livewire-Datei-Upload (S6.5, Ersatz für Flux-Pro
    file-upload, Clean-Room-Nachbau).

    Ein verstecktes <input type="file"> trägt wire:model und accept (alle
    wire:-Attribute sowie accept, multiple und name werden dorthin
    durchgereicht, der Rest landet auf der Hülle). Die Fläche ist ein <label>:
    Klick öffnet die Dateiauswahl, die Tastatur erreicht das Feld über das
    Input. Abgelegte Dateien werden ins Input übernommen und per change-Event
    an Livewire gemeldet; den Upload selbst erledigt weiter WithFileUploads.

    Bei Hover und beim Darüberziehen wird die Fläche kräftiger: im Light Mode
    dunkler, im Dark Mode heller. Während des Uploads zeigt ein Balken den
    Fortschritt aus den livewire-upload-*-Events.
--}}
@php
    $inputAttributes = $attributes->filter(
        fn ($value, string $key): bool => str_starts_with($key, 'wire:') || in_array($key, ['accept', 'multiple', 'name'], true)
    );
    $wrapperAttributes = $attributes->filter(fn ($value, string $key): bool => ! $inputAttributes->has($key));
    $errorName = $inputAttributes->whereStartsWith('wire:model')->first();
@endphp

<div {{ $wrapperAttributes->class('space-y-2') }}>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <label
        x-data="{ dragging: false, uploading: false, progress: 0 }"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="if (! $el.contains($event.relatedTarget)) dragging = false"
        x-on:drop.prevent="
            dragging = false;
            if ($event.dataTransfer.files.length > 0) {
                $refs.input.files = $event.dataTransfer.files;
                $refs.input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        "
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-bind:data-dragging="dragging"
        class="relative flex cursor-pointer items-center gap-4 overflow-hidden rounded-xl border border-dashed border-zinc-200 bg-zinc-50 px-5 py-4 transition-colors hover:border-zinc-300 hover:bg-zinc-100 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[var(--color-accent)] data-dragging:border-zinc-300 data-dragging:bg-zinc-100 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20 dark:hover:bg-white/10 dark:data-dragging:border-white/20 dark:data-dragging:bg-white/10"
    >
        <input type="file" x-ref="input" class="sr-only" {{ $inputAttributes }} />

        <flux:icon name="cloud-arrow-up" variant="solid" class="size-6 shrink-0 text-zinc-400 dark:text-zinc-500" />

        <span class="min-w-0">
            @if ($heading)
                <span class="block text-sm font-medium text-zinc-800 dark:text-white">{{ $heading }}</span>
            @endif

            @if ($text)
                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $text }}</span>
            @endif
        </span>

        <span
            x-show="uploading"
            x-cloak
            class="absolute inset-x-0 bottom-0 h-1 bg-zinc-200 dark:bg-white/10"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            x-bind:aria-valuenow="progress"
        >
            <span class="block h-full bg-[var(--color-accent)] transition-[width]" x-bind:style="{ width: progress + '%' }"></span>
        </span>
    </label>

    @if ($errorName)
        <flux:error :name="$errorName" />
    @endif
</div>
