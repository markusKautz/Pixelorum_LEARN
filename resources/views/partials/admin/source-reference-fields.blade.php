{{--
    Mehrzeilige Quellenbeleg-Felder (Konzept e). Erwartet im umgebenden
    Livewire-Kontext ein SourceReferenceForm als Property $sourceReference;
    geteilt zwischen Block-Editor (S3.3) und Fragen-Formular (S3.4).
--}}
<flux:fieldset>
    <flux:legend>{{ __('Source references') }}</flux:legend>

    @foreach ($sourceReference->references as $index => $reference)
        <div
            wire:key="source-reference-{{ $sourceReference->rowKeys[$index] ?? $index }}"
            class="relative rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
            data-test="source-reference-row"
        >
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <flux:input
                    wire:model="sourceReference.references.{{ $index }}.source_id"
                    :label="__('Source ID')"
                    data-test="source-id-input"
                />

                <flux:input
                    wire:model="sourceReference.references.{{ $index }}.page"
                    type="number"
                    min="1"
                    :label="__('Page')"
                    data-test="source-page-input"
                />

                <flux:input
                    wire:model="sourceReference.references.{{ $index }}.slide"
                    type="number"
                    min="1"
                    :label="__('Slide')"
                    data-test="source-slide-input"
                />

                <flux:input
                    wire:model="sourceReference.references.{{ $index }}.section"
                    :label="__('Section')"
                    data-test="source-section-input"
                />
            </div>

            <div class="mt-3 flex justify-end">
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="x-mark"
                    wire:click="removeSourceReference({{ $index }})"
                    data-test="source-reference-remove"
                >
                    {{ __('Remove source reference') }}
                </flux:button>
            </div>
        </div>
    @endforeach

    <flux:error name="sourceReference.references" data-test="source-reference-error" />

    <flux:description>{{ __('State exactly one page, slide, or section.') }}</flux:description>

    <flux:button
        type="button"
        variant="subtle"
        size="sm"
        icon="plus"
        wire:click="addSourceReference"
        data-test="source-reference-add"
    >
        {{ __('Add source reference') }}
    </flux:button>
</flux:fieldset>
