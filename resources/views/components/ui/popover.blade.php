@props([
    'position' => 'bottom',
    'panelClass' => '',
])

{{--
    Schwebendes Panel an einem Auslöser (S6.5, Ersatz für Flux-Pro-Popover
    samt Dropdown-Hülle, Clean-Room-Nachbau).

    - Öffnet per Klick auf den Auslöser (Slot `trigger`), dezent animiert.
    - Schließt bei Klick daneben und mit Escape; Escape gibt den Fokus an den
      Auslöser zurück, beim Öffnen springt er ins Panel.
    - x-anchor (in Livewires Alpine enthalten) richtet das Panel mittig am
      Auslöser aus und weicht bei Platzmangel auf die Gegenseite aus.

    $position: 'bottom' oder 'top'. Zusätzliche Attribute (z.B. wire:key)
    landen auf der Hülle, $panelClass auf dem Panel.
--}}
<div
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="if (open) { $event.stopPropagation(); open = false; $refs.trigger.querySelector('button')?.focus() }"
    {{ $attributes->class('relative') }}
>
    <div
        x-ref="trigger"
        x-on:click="open = ! open; if (open) $nextTick(() => $refs.panel.querySelector('button, [href], input, select, textarea')?.focus())"
        x-effect="$el.querySelector('button')?.setAttribute('aria-expanded', open.toString())"
    >
        {{ $trigger }}
    </div>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-anchor.{{ $position === 'top' ? 'top' : 'bottom' }}.offset.8="$refs.trigger"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="scale-95 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        x-transition:leave="transition duration-100 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        role="dialog"
        @class([
            'z-50 rounded-2xl border border-zinc-200 bg-white p-4 shadow-lg dark:border-white/10 dark:bg-zinc-700',
            $panelClass,
        ])
    >
        {{ $slot }}
    </div>
</div>
