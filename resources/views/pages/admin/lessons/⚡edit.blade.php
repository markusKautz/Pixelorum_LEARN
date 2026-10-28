<?php

use App\Actions\Content\ReorderToPosition;
use App\Enums\LessonBlockType;
use App\Livewire\Forms\SourceReferenceForm;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public LearningPath $learningPath;

    public Lesson $lesson;

    public string $title = '';

    public SourceReferenceForm $sourceReference;

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function mount(LearningPath $learningPath, Lesson $lesson): void
    {
        $this->learningPath = $learningPath;
        $this->lesson = $lesson;
        $this->title = $lesson->title;

        $this->sourceReference->fillFromReferences($lesson->source_reference);
    }

    public function saveDetails(): void
    {
        Gate::authorize('update', $this->learningPath);

        $this->sourceReference->normalize();

        // Argumentloses validate(): Komponenten-Rules und die Rules des
        // Form-Objekts laufen kumulativ, alle Fehler erscheinen zusammen.
        $validated = $this->validate();

        $this->lesson->update([
            'title' => $validated['title'],
            'source_reference' => $this->sourceReference->toReferences(),
        ]);

        Flux::toast(variant: 'success', text: __('Lesson updated.'));
    }

    public function addSourceReference(): void
    {
        Gate::authorize('update', $this->learningPath);

        $this->sourceReference->addReference();
    }

    public function removeSourceReference(int $index): void
    {
        Gate::authorize('update', $this->learningPath);

        $this->sourceReference->removeReference($index);
    }

    public function addBlock(string $type): void
    {
        $this->createBlock($type);
    }

    /**
     * Einen neuen Block anlegen und direkt an Index $position einsortieren.
     * Insert = anlegen (am Ende) + ReorderToPosition — derselbe eine Pfad wie
     * Drag&Drop, daher keine parallele Positions-Arithmetik.
     */
    public function addBlockAt(ReorderToPosition $reorderToPosition, string $type, int $position): void
    {
        $block = $this->createBlock($type);

        $reorderToPosition->handle($this->lesson->blocks(), $block, $position);

        unset($this->blocks);
    }

    /**
     * Shared: leeren, shape-konformen Block am Ende anlegen.
     */
    protected function createBlock(string $type): LessonBlock
    {
        Gate::authorize('update', $this->learningPath);

        $blockType = LessonBlockType::tryFrom($type);

        abort_if($blockType === null, 422);

        $block = $this->lesson->blocks()->create([
            'type' => $blockType,
            'content' => $this->emptyContent($blockType),
            'position' => ((int) $this->lesson->blocks()->max('position')) + 1,
        ]);

        unset($this->blocks);

        Flux::toast(variant: 'success', text: __('Block added. Fill it in and save it.'));

        return $block;
    }

    public function sortBlocks(ReorderToPosition $reorderToPosition, int $blockId, int $position): void
    {
        Gate::authorize('update', $this->learningPath);

        $block = $this->lesson->blocks()->findOrFail($blockId);

        $reorderToPosition->handle($this->lesson->blocks(), $block, $position);

        unset($this->blocks);
    }

    public function deleteBlock(int $blockId): void
    {
        Gate::authorize('update', $this->learningPath);

        $block = $this->lesson->blocks()->findOrFail($blockId);

        $block->delete();

        $this->modal("delete-block-{$blockId}")->close();

        unset($this->blocks);

        Flux::toast(variant: 'success', text: __('Block deleted.'));
    }

    #[On('block-saved')]
    public function refreshBlocks(): void
    {
        unset($this->blocks);
    }

    /**
     * @return Collection<int, LessonBlock>
     */
    #[Computed]
    public function blocks(): Collection
    {
        return $this->lesson->blocks()->get();
    }

    /**
     * Shape-konformer, aber leerer Startinhalt je Typ — die Pflichtfelder
     * erzwingt erst das Speichern im Block-Formular (LessonBlockContent).
     *
     * @return array<string, mixed>
     */
    protected function emptyContent(LessonBlockType $type): array
    {
        return match ($type) {
            LessonBlockType::Markdown => ['body' => ''],
            LessonBlockType::Accordion => ['items' => [['heading' => '', 'body' => '']]],
            LessonBlockType::Callout => ['variant' => 'info', 'body' => ''],
            LessonBlockType::CodeSnippet => ['language' => 'php', 'code' => '', 'filename' => null],
            LessonBlockType::Diagram => ['format' => 'mermaid', 'source' => '', 'caption' => null],
            LessonBlockType::Media => ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null],
            LessonBlockType::Glossary => ['terms' => [['term' => '', 'definition' => '']]],
            // Leerer Titel ist gültig: ein frischer Abschnitt ist sofort komplett
            // (nie „Unvollständig") und markiert nur eine Grenze.
            LessonBlockType::SectionBreak => ['title' => null],
        };
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Edit :name', ['name' => $this->lesson->title]));
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.learning-paths.edit', $learningPath)"
            wire:navigate
            data-test="back-to-path-button"
        >
            {{ $learningPath->title }}
        </flux:button>
    </div>

    <div>
        <flux:heading size="xl">{{ $lesson->title }}</flux:heading>
        <flux:subheading>{{ __('Edit the lesson details and its content blocks.') }}</flux:subheading>
    </div>

    <form wire:submit="saveDetails" class="space-y-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input wire:model="title" :label="__('Title')" data-test="lesson-title-input" />

        @include('partials.admin.source-reference-fields')

        <flux:button variant="primary" type="submit" data-test="lesson-details-save">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Content blocks') }}</flux:heading>
            <flux:subheading>{{ __('The blocks of this lesson in reading order.') }}</flux:subheading>
        </div>

        @if ($this->blocks->isNotEmpty())
            {{-- Einfügen ganz oben (Index 0). Der count im wire:key sorgt dafür,
                 dass das Popover nach dem Einfügen frisch (geschlossen) rendert. --}}
            <div class="flex justify-center">
                <x-ui.popover position="bottom" panel-class="w-96" wire:key="insert-top-{{ $this->blocks->count() }}">
                    <x-slot:trigger>
                        <flux:button size="xs" variant="ghost" icon="plus" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" data-test="insert-block-top">
                            {{ __('Insert block') }}
                        </flux:button>
                    </x-slot:trigger>

                    @include('partials.admin.block-type-picker', ['insertIndex' => 0])
                </x-ui.popover>
            </div>
        @endif

        <div class="space-y-10" wire:sort="sortBlocks">
            @forelse ($this->blocks as $block)
                <div
                    wire:key="block-{{ $block->id }}"
                    wire:sort:item="{{ $block->id }}"
                    class="group/block relative rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
                    data-test="block-card"
                >
                    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <div wire:sort:handle class="cursor-grab">
                                <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                            </div>
                            <flux:badge size="sm" color="zinc">{{ $block->type->label() }}</flux:badge>
                            @unless ($block->isComplete())
                                <flux:badge size="sm" color="amber" data-test="block-incomplete-badge">
                                    {{ __('Incomplete') }}
                                </flux:badge>
                            @endunless
                        </div>

                        <flux:modal.trigger :name="'delete-block-'.$block->id">
                            <flux:tooltip :content="__('Delete block')">
                                <flux:button variant="ghost" size="sm" icon="trash" data-test="block-delete-button" />
                            </flux:tooltip>
                        </flux:modal.trigger>
                    </div>

                    <div class="p-4">
                        <livewire:admin.lesson-block-form :block="$block" :key="'block-form-'.$block->id" />
                    </div>

                    @unless ($loop->last)
                        {{-- Einfügen ZWISCHEN den Blöcken: an die Unterkante der Karte
                             gehängt (top-full), also außerhalb des Rahmens im
                             Zwischenraum. Bleibt DOM-Kind der Karte → für wire:sort
                             unsichtbar und via :hover-Propagation einblendbar; die
                             Zone überbrückt Karte und Button (pointer-events aktiv),
                             damit es beim Hinuntergehen keinen toten Bereich gibt.
                             Beim Erscheinen gleitet der Button per padding-top nach
                             unten in die Mitte des Zwischenraums. --}}
                        <div class="absolute inset-x-0 top-full z-10 flex justify-center opacity-0 transition-all duration-200 group-hover/block:pt-3 group-hover/block:opacity-100 focus-within:pt-3 focus-within:opacity-100">
                            <x-ui.popover position="bottom" panel-class="w-96" wire:key="insert-after-{{ $block->id }}-{{ $this->blocks->count() }}">
                                <x-slot:trigger>
                                    <flux:button size="xs" variant="ghost" icon="plus" class="bg-white text-zinc-400 shadow-sm hover:text-zinc-700 dark:bg-zinc-900 dark:hover:text-zinc-200" :data-test="'insert-block-after-'.$block->id">
                                        {{ __('Insert block') }}
                                    </flux:button>
                                </x-slot:trigger>

                                @include('partials.admin.block-type-picker', ['insertIndex' => $loop->index + 1])
                            </x-ui.popover>
                        </div>
                    @endunless
                </div>
            @empty
                <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('No content blocks yet.') }}
                </flux:text>
            @endforelse
        </div>

        {{-- Anhängen am Ende + Leerzustand + Touch-Fallback. Optisch identisch
             zum „Einfügen"-Button ganz oben. --}}
        <div class="flex justify-center">
            <x-ui.popover position="top" panel-class="w-96" wire:key="add-end-{{ $this->blocks->count() }}">
                <x-slot:trigger>
                    <flux:button size="xs" variant="ghost" icon="plus" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" data-test="add-block-button">
                        {{ __('Add block') }}
                    </flux:button>
                </x-slot:trigger>

                @include('partials.admin.block-type-picker', ['insertIndex' => null])
            </x-ui.popover>
        </div>
    </div>

    @foreach ($this->blocks as $block)
        {{-- wire:key ist hier zwingend: ohne ihn morpht Livewire die Modals dieser
             Schleife positionsweise, wenn sich die Blockliste ändert (Einfügen,
             Löschen, Sortieren) — das erzeugt doppelte Dialog-Knoten gleichen Namens
             oder verwaiste Trigger. --}}
        <flux:modal wire:key="delete-block-modal-{{ $block->id }}" :name="'delete-block-'.$block->id" focusable class="max-w-lg">
            <form wire:submit="deleteBlock({{ $block->id }})" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete block') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete this :type block? This cannot be undone.', ['type' => $block->type->label()]) }}
                    </flux:subheading>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-block-confirm">
                        {{ __('Delete block') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach
</section>
