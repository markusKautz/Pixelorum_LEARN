<div class="space-y-4">
    <flux:select wire:model.live.debounce.500ms="content.variant" :label="__('Variant')" data-test="callout-variant-select">
        <flux:select.option value="info">{{ __('Info') }}</flux:select.option>
        <flux:select.option value="warning">{{ __('Warning') }}</flux:select.option>
        <flux:select.option value="danger">{{ __('Danger') }}</flux:select.option>
        <flux:select.option value="highlight">{{ __('Highlight') }}</flux:select.option>
        <flux:select.option value="exam">{{ __('Exam-relevant') }}</flux:select.option>
    </flux:select>

    <flux:textarea
        wire:model.live.debounce.500ms="content.body"
        :label="__('Text (Markdown)')"
        rows="4"
        data-test="callout-body-input"
    />
</div>
