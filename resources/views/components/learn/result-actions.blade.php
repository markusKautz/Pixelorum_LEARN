@props([
    'isPractice',
    'backRoute',
    'backLabel',
    'testName',
])

{{-- Die Knopf-Selektoren leiten sich aus $testName ab: der Block steht auf der
     Ergebnisseite zweimal, feste Namen träfen daher je zwei Elemente. --}}
<div class="flex flex-wrap gap-2" data-test="{{ $testName }}">
    <flux:button variant="primary" icon="arrow-path" wire:click="restart" data-test="{{ $testName }}-restart">
        {{ $isPractice ? __('Practise the same questions again') : __('Try again') }}
    </flux:button>
    <flux:button :href="$backRoute" wire:navigate data-test="{{ $testName }}-back">
        @if ($isPractice)
            {{ __('Draw new questions') }}
        @else
            {{ $backLabel }}
        @endif
    </flux:button>
</div>
