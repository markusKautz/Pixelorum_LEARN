<div class="space-y-4">
    <flux:select wire:model.live="content.kind" :label="__('Kind')" data-test="media-kind-select">
        <flux:select.option value="image">{{ __('Image') }}</flux:select.option>
        <flux:select.option value="video">{{ __('Video') }}</flux:select.option>
    </flux:select>

    @if (($content['kind'] ?? 'image') === 'image')
        @include('partials.admin.block-forms.image-source', ['urlModel' => 'content.url', 'currentUrl' => $content['url'] ?? ''])
    @else
        {{-- Videos bleiben URL-basiert — kein Video-Hosting (Entscheidung 4) --}}
        <flux:input
            wire:model.live.debounce.500ms="content.url"
            :label="__('Video URL')"
            data-test="media-url-input"
        />
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input
            wire:model.live.debounce.500ms="content.caption"
            :label="__('Caption')"
            :placeholder="__('optional')"
            data-test="media-caption-input"
        />

        @if (($content['kind'] ?? 'image') === 'image')
            <flux:input
                wire:model.live.debounce.500ms="content.alt"
                :label="__('Alternative text')"
                :description="__('Recommended for images.')"
                data-test="media-alt-input"
            />
        @endif
    </div>
</div>
