{{--
    Geteilte Frage-Darstellung von Zwischen-Check (S4.3) und Quiz (S4.4)
    — seit Entscheidung 1 (Sofort-Feedback je Frage) sind das dieselbe
    Interaktion, sie unterscheiden sich nur in der Persistenz.

    Bewusst **kein** Livewire-Kind je Frage (Entscheidung 4): der Zustand
    bleibt beim Eltern-Component. Das hält den Snapshot klein und die
    Correct-Flags aus dem Client — hier werden vor der Auswertung
    ausschließlich `id` und `label` einer Option gerendert (Entscheidung 5).

    $model ist der wire:model-Pfad im Eltern-Component; die Auswahl ist für
    alle Fragetypen ein Array von Option-IDs, Einfachauswahl bindet deshalb
    auf dessen ersten Platz.
--}}
@props([
    'question',
    'model' => null,
    'selected' => [],
    'result' => null,
    'disabled' => false,
    'revealCorrect' => false,
    'number' => null,
    'total' => null,
])

@php
    $isMultiple = $question->type === App\Enums\QuestionType::Multiple;

    // Ohne $model hängt die Darstellung an keinem Livewire-Zustand (die
    // Ergebnisliste zeigt vergangene Antworten) — dann markiert $selected.
    $selectedIds = array_map(intval(...), $selected);

    // Einfachauswahl bindet auf Index 0 desselben Array-Zustands, damit beide
    // Fragetypen serverseitig gleich behandelt werden.
    $binding = new Illuminate\View\ComponentAttributeBag(
        $model === null ? [] : ['wire:model' => $isMultiple ? $model : $model.'.0'],
    );
@endphp

<div class="space-y-4" data-test="learn-question" data-question-id="{{ $question->id }}">
    <div>
        @if ($number !== null && $total !== null)
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Question :number of :total', ['number' => $number, 'total' => $total]) }}
            </flux:text>
        @endif

        <flux:heading size="lg" class="mt-1">{{ $question->prompt }}</flux:heading>

        @if ($isMultiple)
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Several answers are correct.') }}
            </flux:text>
        @endif
    </div>

    @if ($isMultiple)
        <flux:checkbox.group variant="cards" class="flex-col" :attributes="$binding">
            @foreach ($question->optionsForDisplay() as $option)
                <flux:checkbox
                    :value="(string) $option->id"
                    :label="$option->label"
                    :disabled="$disabled"
                    :checked="$model === null && in_array($option->id, $selectedIds, true)"
                    :wire:key="'option-'.$option->id"
                />
            @endforeach
        </flux:checkbox.group>
    @else
        <flux:radio.group variant="cards" class="flex-col" :attributes="$binding">
            @foreach ($question->optionsForDisplay() as $option)
                <flux:radio
                    :value="(string) $option->id"
                    :label="$option->label"
                    :disabled="$disabled"
                    :checked="$model === null && in_array($option->id, $selectedIds, true)"
                    :wire:key="'option-'.$option->id"
                />
            @endforeach
        </flux:radio.group>
    @endif

    @if ($revealCorrect)
        {{-- Nur **nach** der Auswertung: die richtige Lösung im Klartext. --}}
        <flux:text class="text-sm" data-test="question-correct-answer">
            {{ __('Correct answer: :answer', [
                'answer' => $question->options->where('is_correct', true)->pluck('label')->join(', '),
            ]) }}
        </flux:text>
    @endif

    @if ($result !== null)
        {{-- Farbsemantik Konzept g: richtig grün, falsch amber — nie rot.
             Ein Fehlversuch ist eine Lerngelegenheit, keine Bestrafung
             (Meyer, Merkmal 3). --}}
        <flux:callout
            :color="$result ? 'green' : 'amber'"
            :icon="$result ? 'check-circle' : 'light-bulb'"
            data-test="question-feedback"
            data-correct="{{ $result ? 'true' : 'false' }}"
        >
            <flux:callout.heading>
                {{ $result ? __('Correct!') : __('Not quite.') }}
            </flux:callout.heading>

            @if (filled($question->explanation))
                <flux:callout.text>
                    <x-lesson.markdown :text="$question->explanation" data-test="question-explanation" />
                </flux:callout.text>
            @endif
        </flux:callout>
    @endif

    {{ $slot }}
</div>
