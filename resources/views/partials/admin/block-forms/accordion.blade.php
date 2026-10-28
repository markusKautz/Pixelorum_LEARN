<div class="space-y-3">
    @foreach ($content['items'] ?? [] as $index => $item)
        <div wire:key="accordion-item-{{ $index }}" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="accordion-item-row">
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <flux:input
                        wire:model.live.debounce.500ms="content.items.{{ $index }}.heading"
                        :label="__('Heading')"
                        data-test="accordion-heading-input"
                    />
                </div>
                <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="moveItem({{ $index }}, -1)" :disabled="$index === 0" />
                <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="moveItem({{ $index }}, 1)" :disabled="$index === count($content['items']) - 1" />
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeItem({{ $index }})" data-test="accordion-item-remove" />
            </div>

            <flux:textarea
                wire:model.live.debounce.500ms="content.items.{{ $index }}.body"
                :label="__('Text (Markdown)')"
                rows="3"
                data-test="accordion-body-input"
            />
        </div>
    @endforeach

    <flux:error name="content.items" />

    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addItem" data-test="accordion-item-add">
        {{ __('Add entry') }}
    </flux:button>
</div>
