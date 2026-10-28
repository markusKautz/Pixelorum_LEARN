<div class="space-y-4">
    <flux:select wire:model.live="content.format" :label="__('Format')" data-test="diagram-format-select">
        <flux:select.option value="mermaid">Mermaid</flux:select.option>
        <flux:select.option value="image">{{ __('Image') }}</flux:select.option>
    </flux:select>

    @if (($content['format'] ?? 'mermaid') === 'mermaid')
        <flux:textarea
            wire:model.live.debounce.500ms="content.source"
            :label="__('Mermaid source')"
            rows="8"
            class="font-mono"
            data-test="diagram-source-input"
        />
    @else
        @include('partials.admin.block-forms.image-source', ['urlModel' => 'content.source', 'currentUrl' => $content['source'] ?? ''])
    @endif

    <flux:input
        wire:model.live.debounce.500ms="content.caption"
        :label="__('Caption')"
        :placeholder="__('optional')"
        data-test="diagram-caption-input"
    />
</div>
