<flux:textarea
    wire:model.live.debounce.500ms="content.body"
    :label="__('Text (Markdown)')"
    rows="8"
    data-test="block-body-input"
/>
