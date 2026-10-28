{{--
    Typauswahl fürs Einfügen eines Blocks (Sprint 3.1). Erwartet:
    - $insertIndex: 0-basierter Ziel-Index (Einfügen an der Stelle) ODER
      null = am Ende anhängen.
    Wird aus mehreren Einfügepunkten eingebunden; der Ziel-Index ist je Punkt
    fest verdrahtet, daher kein geteilter Server-Zustand.
--}}
<div class="grid grid-cols-2 gap-2" data-test="block-type-picker">
    @foreach (App\Enums\LessonBlockType::cases() as $type)
        @php($click = $insertIndex === null
            ? "addBlock('{$type->value}')"
            : "addBlockAt('{$type->value}', {$insertIndex})")

        <button
            type="button"
            wire:click="{{ $click }}"
            class="flex items-center gap-2 rounded-lg border border-zinc-200 p-2.5 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
            data-test="pick-block-{{ $type->value }}"
        >
            <flux:icon :name="$type->icon()" variant="mini" class="shrink-0 text-zinc-500 dark:text-zinc-400" />
            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $type->label() }}</span>
        </button>
    @endforeach
</div>
