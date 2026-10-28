<div class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model.live.debounce.500ms="content.language" :label="__('Language')" data-test="code-language-select">
            @foreach (App\Rules\LessonBlockContent::CODE_LANGUAGES as $language)
                <flux:select.option value="{{ $language }}">{{ $language }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.debounce.500ms="content.filename"
            :label="__('Filename')"
            :placeholder="__('optional')"
            data-test="code-filename-input"
        />
    </div>

    <flux:textarea
        wire:model.live.debounce.500ms="content.code"
        :label="__('Code')"
        rows="8"
        class="font-mono"
        data-test="code-input"
    />
</div>
