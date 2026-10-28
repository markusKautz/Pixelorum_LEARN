<?php

use App\Actions\Content\LocateLessonImages;
use App\Enums\LessonBlockType;
use App\Models\LessonBlock;
use App\Rules\LessonBlockContent;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public LessonBlock $block;

    /** @var array<string, mixed> */
    public array $content = [];

    /**
     * Umschalter Upload/URL für Bild-Felder (Entscheidung 4); reine UI-Wahl,
     * gespeichert wird immer nur die resultierende URL im Content-Shape.
     */
    public string $imageSource = 'url';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $imageUpload = null;

    public function mount(LessonBlock $block): void
    {
        $this->block = $block;
        $this->content = $block->content;

        // Ein bereits hochgeladenes Bild (site-relative /storage-URL) beim
        // Bearbeiten wieder im Upload-Modus öffnen — sonst stünde die interne
        // URL im URL-Feld, obwohl das Bild gar nicht extern ist.
        $field = $this->imageUrlField();

        if ($field !== null && $this->isLocalUpload((string) ($this->content[$field] ?? ''))) {
            $this->imageSource = 'upload';
        }
    }

    public function save(): void
    {
        Gate::authorize('update', $this->block->lesson->learningPath);

        $this->storeUploadedImage();

        $this->normalizeContent();

        $this->validate($this->rules());

        $this->block->update(['content' => $this->content]);

        $this->dispatch('block-saved');
        $this->dispatch('lesson-blocks-updated');

        Flux::toast(variant: 'success', text: __('Block saved.'));
    }

    /**
     * Re-Render der Client-Renderer (Mermaid/Highlighting) nach jedem
     * Vorschau-Update anstoßen — das Event landet in lesson-blocks.js.
     */
    public function updatedContent(): void
    {
        $this->dispatch('lesson-blocks-updated');
    }

    /**
     * Eine noch nicht gespeicherte Auswahl wieder verwerfen (Papierkorb am
     * x-ui.file-item). Die temporäre Datei wird dabei mit gelöscht.
     */
    public function removeImageUpload(): void
    {
        $this->imageUpload?->delete();

        $this->reset('imageUpload');
    }

    /**
     * Ungespeicherte Kopie des Blocks mit dem aktuellen Formularstand —
     * gerendert über dieselbe Pipeline wie später der S4.3-Reader.
     */
    #[Computed]
    public function previewBlock(): LessonBlock
    {
        $preview = $this->block->replicate();

        $preview->content = $this->content;

        return $preview;
    }

    /**
     * Legt ein hochgeladenes Bild auf der public-Disk ab (setzt
     * `php artisan storage:link` voraus) und schreibt die site-relative
     * /storage-URL in das unveränderte Content-Shape. Bewusst relativ und
     * ohne Host: hostunabhängig und als lokaler Upload erkennbar. Ersetzte
     * oder verwaiste Dateien werden nicht aufgeräumt — MVP-Grenze
     * (Entscheidung 4). Der Upload-Modus bleibt aktiv; das Bild ist kein
     * externer Link und soll nicht als URL angezeigt werden.
     */
    protected function storeUploadedImage(): void
    {
        $field = $this->imageUrlField();

        if ($field === null || $this->imageUpload === null) {
            return;
        }

        $this->validate([
            'imageUpload' => ['image', 'max:4096'],
        ]);

        $path = $this->imageUpload->store('lesson-media', 'public');

        $this->content[$field] = $this->publicPath($path);

        $this->reset('imageUpload');

        // Gerade lokal abgelegt — im Upload-Modus bleiben, nicht als URL zeigen.
        $this->imageSource = 'upload';
    }

    /**
     * Site-relative /storage-URL für einen Pfad auf der public-Disk — der
     * Pfadteil der konfigurierten Disk-URL, also ohne Schema und Host.
     */
    protected function publicPath(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * Ob eine gespeicherte Bild-URL auf einen eigenen Upload zeigt (site-relativer
     * /storage-Pfad) statt auf eine externe Adresse. Deckt auch Alt-Bestände mit
     * absoluter Host-URL ab, da nur der Pfadteil geprüft wird.
     */
    protected function isLocalUpload(string $url): bool
    {
        return app(LocateLessonImages::class)->isLocalUpload($url);
    }

    /**
     * Get the content field that holds the image URL, if the block currently
     * shows an image (Diagram im image-Format bzw. Media mit kind image).
     */
    protected function imageUrlField(): ?string
    {
        return app(LocateLessonImages::class)->imageField($this->block->type, $this->content);
    }

    public function addItem(): void
    {
        if ($this->block->type === LessonBlockType::Accordion) {
            $this->content['items'][] = ['heading' => '', 'body' => ''];
        }

        if ($this->block->type === LessonBlockType::Glossary) {
            $this->content['terms'][] = ['term' => '', 'definition' => ''];
        }
    }

    public function removeItem(int $index): void
    {
        $key = $this->listKey();

        if ($key === null || ! array_key_exists($index, $this->content[$key] ?? [])) {
            return;
        }

        unset($this->content[$key][$index]);

        $this->content[$key] = array_values($this->content[$key]);
    }

    public function moveItem(int $index, int $direction): void
    {
        $key = $this->listKey();
        $target = $index + $direction;

        if ($key === null
            || ! array_key_exists($index, $this->content[$key] ?? [])
            || ! array_key_exists($target, $this->content[$key] ?? [])) {
            return;
        }

        [$this->content[$key][$index], $this->content[$key][$target]]
            = [$this->content[$key][$target], $this->content[$key][$index]];
    }

    /**
     * Get the name of the item list for list-based block types.
     */
    protected function listKey(): ?string
    {
        return match ($this->block->type) {
            LessonBlockType::Accordion => 'items',
            LessonBlockType::Glossary => 'terms',
            default => null,
        };
    }

    /**
     * Feldweise Regeln für gute Fehlermeldungen; die LessonBlockContent-Rule
     * bleibt die eine Wahrheit über den Shape (inkl. unbekannter Felder).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = ['content' => ['required', 'array', new LessonBlockContent($this->block->type)]];

        return $rules + match ($this->block->type) {
            LessonBlockType::Markdown => [
                'content.body' => ['required', 'string'],
            ],
            LessonBlockType::Accordion => [
                'content.items' => ['required', 'array', 'min:1'],
                'content.items.*.heading' => ['required', 'string', 'max:255'],
                'content.items.*.body' => ['required', 'string'],
            ],
            LessonBlockType::Callout => [
                'content.variant' => ['required', Rule::in(LessonBlockContent::CALLOUT_VARIANTS)],
                'content.body' => ['required', 'string'],
            ],
            LessonBlockType::CodeSnippet => [
                'content.language' => ['required', Rule::in(LessonBlockContent::CODE_LANGUAGES)],
                'content.code' => ['required', 'string'],
                'content.filename' => ['nullable', 'string', 'max:255'],
            ],
            LessonBlockType::Diagram => [
                'content.format' => ['required', Rule::in(LessonBlockContent::DIAGRAM_FORMATS)],
                'content.source' => ['required', 'string'],
                'content.caption' => ['nullable', 'string', 'max:500'],
            ],
            LessonBlockType::Media => [
                'content.kind' => ['required', Rule::in(LessonBlockContent::MEDIA_KINDS)],
                'content.url' => ['required', 'string', 'max:2048'],
                'content.caption' => ['nullable', 'string', 'max:500'],
                'content.alt' => ['nullable', 'string', 'max:500'],
            ],
            LessonBlockType::Glossary => [
                'content.terms' => ['required', 'array', 'min:1'],
                'content.terms.*.term' => ['required', 'string', 'max:255'],
                'content.terms.*.definition' => ['required', 'string'],
            ],
            LessonBlockType::SectionBreak => [
                'content.title' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'content.body' => __('Text'),
            'content.items' => __('Entries'),
            'content.items.*.heading' => __('Heading'),
            'content.items.*.body' => __('Text'),
            'content.variant' => __('Variant'),
            'content.language' => __('Language'),
            'content.code' => __('Code'),
            'content.filename' => __('Filename'),
            'content.format' => __('Format'),
            'content.source' => __('Source'),
            'content.caption' => __('Caption'),
            'content.kind' => __('Kind'),
            'content.url' => __('URL'),
            'content.alt' => __('Alternative text'),
            'content.terms' => __('Entries'),
            'content.terms.*.term' => __('Term'),
            'content.terms.*.definition' => __('Definition'),
            'content.title' => __('Section title'),
            'imageUpload' => __('Image file'),
        ];
    }

    /**
     * Trim strings, null out empty optional fields, and re-index item lists.
     */
    protected function normalizeContent(): void
    {
        $trim = fn (mixed $value): mixed => is_string($value) ? trim($value) : $value;

        $content = array_map($trim, $this->content);

        foreach (['filename', 'caption', 'alt', 'title'] as $optional) {
            if (array_key_exists($optional, $content) && $content[$optional] === '') {
                $content[$optional] = null;
            }
        }

        foreach (['items', 'terms'] as $listKey) {
            if (array_key_exists($listKey, $content) && is_array($content[$listKey])) {
                $content[$listKey] = array_values(array_map(
                    fn (mixed $item): mixed => is_array($item) ? array_map($trim, $item) : $item,
                    $content[$listKey],
                ));
            }
        }

        $this->content = $content;
    }
}; ?>

<form wire:submit="save" class="space-y-4" data-test="block-form-{{ $block->type->value }}">
    @switch($block->type)
        @case(App\Enums\LessonBlockType::Markdown)
            @include('partials.admin.block-forms.markdown')
            @break

        @case(App\Enums\LessonBlockType::Accordion)
            @include('partials.admin.block-forms.accordion')
            @break

        @case(App\Enums\LessonBlockType::Callout)
            @include('partials.admin.block-forms.callout')
            @break

        @case(App\Enums\LessonBlockType::CodeSnippet)
            @include('partials.admin.block-forms.code-snippet')
            @break

        @case(App\Enums\LessonBlockType::Diagram)
            @include('partials.admin.block-forms.diagram')
            @break

        @case(App\Enums\LessonBlockType::Media)
            @include('partials.admin.block-forms.media')
            @break

        @case(App\Enums\LessonBlockType::Glossary)
            @include('partials.admin.block-forms.glossary')
            @break

        @case(App\Enums\LessonBlockType::SectionBreak)
            @include('partials.admin.block-forms.section-break')
            @break
    @endswitch

    <div class="flex justify-end">
        <flux:button variant="primary" size="sm" type="submit" data-test="block-save-button">
            {{ __('Save block') }}
        </flux:button>
    </div>

    <div class="rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-600" data-test="block-preview">
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
            {{ __('Preview') }}
        </div>
        <x-lesson.block :block="$this->previewBlock" />
    </div>
</form>
