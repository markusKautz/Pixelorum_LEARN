<div class="space-y-3">
    @foreach ($content['terms'] ?? [] as $index => $term)
        <div wire:key="glossary-term-{{ $index }}" class="flex items-start gap-2" data-test="glossary-term-row">
            <div class="grid flex-1 gap-2 sm:grid-cols-2">
                <flux:input
                    wire:model.live.debounce.500ms="content.terms.{{ $index }}.term"
                    :label="__('Term')"
                    data-test="glossary-term-input"
                />
                <flux:input
                    wire:model.live.debounce.500ms="content.terms.{{ $index }}.definition"
                    :label="__('Definition')"
                    data-test="glossary-definition-input"
                />
            </div>
            <flux:button variant="ghost" size="sm" icon="x-mark" class="mt-7" wire:click="removeItem({{ $index }})" data-test="glossary-term-remove" />
        </div>
    @endforeach

    <flux:error name="content.terms" />

    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addItem" data-test="glossary-term-add">
        {{ __('Add entry') }}
    </flux:button>
</div>
