{{--
    Bild per URL oder Upload (Entscheidung 4). Erwartet:
    - $urlModel: wire:model-Pfad des URL-Feldes im Content-Shape
    - $currentUrl: aktuell gespeicherte URL (Anzeige im Upload-Modus)

    Die beiden Zweige tragen bewusst ein wire:key: ohne das patcht der
    Livewire-Morph beim Umschalten die Attribute des einen Wrapper-<div>
    auf das andere, statt den Knoten auszutauschen — das Upload-Element
    bekam dadurch die Kinder des URL-Feldes und verlor seine Refs
    (Browser-Test S3, F1: „Choose File" ohne Wirkung, Upload-Button
    blieb im URL-Modus stehen).
--}}
<div class="space-y-3">
    <flux:radio.group wire:model.live="imageSource" variant="segmented" :label="__('Image source')" data-test="image-source-toggle">
        <flux:radio value="url" :label="__('URL')" />
        <flux:radio value="upload" :label="__('Upload')" />
    </flux:radio.group>

    @if ($imageSource === 'upload')
        <div class="space-y-3" wire:key="image-source-upload">
            <x-ui.file-upload
                wire:model="imageUpload"
                accept="image/*"
                :label="__('Image file')"
                :description="__('The image is stored when you save the block.')"
                :heading="__('Drop an image here or click to browse')"
                :text="__('JPG, PNG, GIF or WEBP up to 4 MB')"
                data-test="image-upload-input"
            />

            @if ($imageUpload)
                {{--
                    isPreviewable() als Guard: temporaryUrl() wirft für nicht
                    darstellbare Dateien. Eine .txt landet hier trotz accept
                    (Drag & Drop umgeht den Dateidialog-Filter) und soll erst
                    beim Speichern an der image-Regel scheitern, nicht schon
                    beim Rendern.
                --}}
                <x-ui.file-item
                    :heading="$imageUpload->getClientOriginalName()"
                    :image="$imageUpload->isPreviewable() ? $imageUpload->temporaryUrl() : null"
                    :size="$imageUpload->getSize()"
                    data-test="image-upload-item"
                >
                    <x-slot name="actions">
                        <x-ui.file-item.remove
                            wire:click="removeImageUpload"
                            :aria-label="__('Remove file')"
                            data-test="image-upload-remove"
                        />
                    </x-slot>
                </x-ui.file-item>
            @endif

            @if (! $imageUpload && $currentUrl !== '')
                {{--
                    Bereits hochgeladenes Bild: nur der Dateiname, nicht die
                    interne /storage-URL. Das Bild selbst zeigt die Vorschau
                    unten. Str::afterLast schneidet den Pfad auf den Namen.
                --}}
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="image-upload-current">
                    {{ __('Current image: :name', ['name' => \Illuminate\Support\Str::afterLast($currentUrl, '/')]) }}
                </flux:text>
            @endif
        </div>
    @else
        <div wire:key="image-source-url">
            <flux:input
                wire:model.live.debounce.500ms="{{ $urlModel }}"
                :label="__('Image URL')"
                data-test="image-url-input"
            />
        </div>
    @endif
</div>
