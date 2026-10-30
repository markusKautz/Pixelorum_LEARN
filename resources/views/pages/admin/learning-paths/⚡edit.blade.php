<?php

use App\Actions\Content\ExportLearningPath;
use App\Actions\Content\ReorderToPosition;
use App\Actions\Content\SetPathStatus;
use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public LearningPath $learningPath;

    public string $newLessonTitle = '';

    public ?int $editingLessonId = null;

    public string $editingLessonTitle = '';

    public string $title = '';

    public string $slug = '';

    public string $summary = '';

    /** @var array<int, string> */
    public array $objectives = [];

    public string $difficulty = '';

    /** @var int|string|null */
    public $estimated_minutes = null;

    /** @var int|string */
    public $pass_threshold = 80;

    /**
     * Was einen erneuten Upload der exportierten Datei verhindern würde.
     *
     * @var list<string>
     */
    public array $exportWarnings = [];

    public function mount(LearningPath $learningPath): void
    {
        $this->learningPath = $learningPath;
        $this->title = $learningPath->title;
        $this->slug = $learningPath->slug;
        $this->summary = $learningPath->summary ?? '';
        $this->objectives = $learningPath->objectives;
        $this->difficulty = $learningPath->difficulty ?? '';
        $this->estimated_minutes = $learningPath->estimated_minutes;
        $this->pass_threshold = $learningPath->pass_threshold;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->learningPath);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // withTrashed-Unique: auch Slugs soft-gelöschter Pfade bleiben belegt
                Rule::unique('learning_paths', 'slug')->ignore($this->learningPath->id),
            ],
            'summary' => ['nullable', 'string', 'max:2000'],
            'objectives' => ['array'],
            'objectives.*' => ['nullable', 'string', 'max:500'],
            'difficulty' => ['nullable', 'string', Rule::in($this->difficultyOptions)],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'pass_threshold' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $objectives = collect($validated['objectives'])
            ->map(fn (?string $objective) => trim($objective ?? ''))
            ->filter(fn (string $objective) => $objective !== '')
            ->values()
            ->all();

        $this->learningPath->update([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'summary' => $validated['summary'] === '' ? null : $validated['summary'],
            'objectives' => $objectives,
            'difficulty' => $validated['difficulty'] === '' ? null : $validated['difficulty'],
            'estimated_minutes' => $validated['estimated_minutes'] === null || $validated['estimated_minutes'] === ''
                ? null
                : (int) $validated['estimated_minutes'],
            'pass_threshold' => (int) $validated['pass_threshold'],
        ]);

        $this->objectives = $objectives;

        Flux::toast(variant: 'success', text: __('Learning path updated.'));

        if ($this->learningPath->wasChanged('slug')) {
            $this->redirectRoute('admin.learning-paths.edit', ['learningPath' => $this->learningPath->slug], navigate: true);
        }
    }

    public function addObjective(): void
    {
        $this->objectives[] = '';
    }

    public function removeObjective(int $index): void
    {
        unset($this->objectives[$index]);

        $this->objectives = array_values($this->objectives);
    }

    public function sortObjectives(int $index, int $position): void
    {
        if (! array_key_exists($index, $this->objectives)) {
            return;
        }

        $moved = array_splice($this->objectives, $index, 1);

        array_splice($this->objectives, $position, 0, $moved);
    }

    public function publish(SetPathStatus $setPathStatus): void
    {
        Gate::authorize('publish', $this->learningPath);

        $setPathStatus->handle($this->learningPath, PathStatus::Published);

        $this->modal('publish-path')->close();

        Flux::toast(variant: 'success', text: __('Learning path published.'));
    }

    public function unpublish(SetPathStatus $setPathStatus): void
    {
        Gate::authorize('publish', $this->learningPath);

        $setPathStatus->handle($this->learningPath, PathStatus::Draft);

        $this->modal('unpublish-path')->close();

        Flux::toast(variant: 'success', text: __('Learning path unpublished.'));
    }

    public function deletePath(): void
    {
        Gate::authorize('delete', $this->learningPath);

        $this->learningPath->delete();

        $this->modal('delete-path')->close();

        Flux::toast(variant: 'success', text: __('Learning path deleted.'));

        $this->redirectRoute('admin.learning-paths.index', navigate: true);
    }

    public function createLesson(): void
    {
        Gate::authorize('update', $this->learningPath);

        $validated = $this->validate([
            'newLessonTitle' => ['required', 'string', 'max:255'],
        ]);

        $this->learningPath->lessons()->create([
            'title' => $validated['newLessonTitle'],
            'position' => ((int) $this->learningPath->lessons()->max('position')) + 1,
        ]);

        $this->reset('newLessonTitle');

        unset($this->lessons);

        Flux::toast(variant: 'success', text: __('Lesson created.'));
    }

    public function startRenamingLesson(int $lessonId): void
    {
        $lesson = $this->learningPath->lessons()->findOrFail($lessonId);

        $this->editingLessonId = $lesson->id;
        $this->editingLessonTitle = $lesson->title;
    }

    public function renameLesson(): void
    {
        Gate::authorize('update', $this->learningPath);

        $lesson = $this->learningPath->lessons()->findOrFail($this->editingLessonId);

        $validated = $this->validate([
            'editingLessonTitle' => ['required', 'string', 'max:255'],
        ]);

        $lesson->update(['title' => $validated['editingLessonTitle']]);

        $this->reset('editingLessonId', 'editingLessonTitle');

        unset($this->lessons);

        Flux::toast(variant: 'success', text: __('Lesson renamed.'));
    }

    public function cancelRenamingLesson(): void
    {
        $this->reset('editingLessonId', 'editingLessonTitle');
    }

    public function sortLessons(ReorderToPosition $reorderToPosition, int $lessonId, int $position): void
    {
        Gate::authorize('update', $this->learningPath);

        $lesson = $this->learningPath->lessons()->findOrFail($lessonId);

        $reorderToPosition->handle($this->learningPath->lessons(), $lesson, $position);

        unset($this->lessons);
    }

    /**
     * Löscht eine Lektion und setzt ihre Zwischen-Checks in den Fragen-Pool
     * um (Konzept d): `lesson_id` nullen und kollisionsfrei ans Pool-Ende
     * setzen — das bloße `SET NULL` der DB ließe die lektionsbezogene
     * Position im Pool kollidieren und machte den S6.1-Match-Schlüssel
     * (`learning_path_id` + `position` bei `lesson_id = null`) mehrdeutig.
     */
    public function deleteLesson(int $lessonId): void
    {
        Gate::authorize('update', $this->learningPath);

        $lesson = $this->learningPath->lessons()->findOrFail($lessonId);

        DB::transaction(function () use ($lesson) {
            $poolPosition = (int) Question::query()
                ->where('learning_path_id', $this->learningPath->id)
                ->whereNull('lesson_id')
                ->max('position');

            $lesson->questions()
                ->orderBy('position')
                ->get()
                ->each(function (Question $question) use (&$poolPosition) {
                    $question->update([
                        'lesson_id' => null,
                        'position' => ++$poolPosition,
                    ]);
                });

            $lesson->delete();
        });

        $this->modal("delete-lesson-{$lessonId}")->close();

        unset($this->lessons);

        Flux::toast(variant: 'success', text: __('Lesson deleted.'));
    }

    /**
     * Den Export anstoßen: ohne Warnungen direkt herunterladen (DEV-1, E3).
     *
     * Mit Warnungen öffnet sich erst ein Modal mit der Liste. Die Datei kommt
     * dort trotzdem, weil sie zuerst eine Sicherung ist; der Admin soll nur
     * wissen, dass sie so nicht wieder hochladbar wäre.
     */
    public function export(): void
    {
        Gate::authorize('view', $this->learningPath);

        unset($this->importProblems);

        $this->exportWarnings = $this->importProblems;

        if ($this->exportWarnings === []) {
            $this->redirectRoute('admin.learning-paths.export', ['learningPath' => $this->learningPath->slug]);

            return;
        }

        $this->modal('export-path')->show();
    }

    /**
     * Was ein erneutes Hochladen des exportierten Pfades verhindern würde (DEV-16).
     *
     * Dieselbe Liste wie die Export-Warnungen, aber dauerhaft sichtbar: Der
     * Admin sieht eine fehlende Quellenangabe beim Bearbeiten, nicht erst
     * beim Download. Ein Export je Request, für einen Pfad unkritisch.
     *
     * Eine frische Instanz, weil der Export Lektionen, Blöcke und Fragen
     * eager lädt: an der Property hingen die Relationen sonst im Snapshot und
     * würden bei jedem Request neu geladen.
     *
     * @return list<string>
     */
    #[Computed]
    public function importProblems(): array
    {
        $path = LearningPath::withTrashed()->findOrFail($this->learningPath->id);

        return app(ExportLearningPath::class)->handle($path)['warnings'];
    }

    /**
     * @return Collection<int, Lesson>
     */
    /**
     * Den Pfad auf eine Gruppe einschränken oder die Einschränkung
     * zurücknehmen (#9).
     *
     * Sofort wirksam wie „An Gruppen ausgeben“ im Quiz-Editor: die Zuweisung
     * ist keine Eigenschaft des Formulars, ein Validierungsfehler im Titel
     * soll sie nicht mit verwerfen.
     */
    public function toggleGroup(int $teamId): void
    {
        Gate::authorize('update', $this->learningPath);

        $team = Team::query()
            ->where('is_personal', false)
            ->findOrFail($teamId);

        $this->learningPath->teams()->toggle($team->id);

        unset($this->assignedTeamIds);

        Flux::toast(variant: 'success', text: __('Assignment updated.'));
    }

    /**
     * Die Gruppen, auf die dieser Pfad eingeschränkt ist.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function assignedTeamIds(): array
    {
        return $this->learningPath->teams()->pluck('teams.id')->all();
    }

    /**
     * Die Lehrlingsgruppen, auf die eingeschränkt werden kann.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function assignableTeams(): Collection
    {
        return Team::query()
            ->groups()
            ->withCount('apprentices')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function lessons(): Collection
    {
        return $this->learningPath->lessons()->withCount('questions')->get();
    }

    /**
     * Festes Werteset (Sprint-2-Entscheidung 4: Spalte bleibt freier String).
     * Es folgt bewusst dem Vokabular der Seed-Daten und der Factory, weil die
     * Seeds die Content-Quelle sind; ein abweichender Altwert bleibt wählbar,
     * damit der Formular-Roundtrip ihn nicht stillschweigend verwirft.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function difficultyOptions(): array
    {
        $options = ['einsteiger', 'fortgeschritten', 'experte'];

        $current = $this->learningPath->difficulty;

        if ($current !== null && ! in_array($current, $options, true)) {
            array_unshift($options, $current);
        }

        return $options;
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Edit :name', ['name' => $this->learningPath->title]));
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.learning-paths.index')"
            wire:navigate
            data-test="back-to-paths-button"
        >
            {{ __('Learning paths') }}
        </flux:button>
    </div>

    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $learningPath->title }}</flux:heading>
            <div class="mt-2 flex items-center gap-2">
                <flux:badge size="sm" :color="$learningPath->status === PathStatus::Published ? 'green' : 'zinc'" data-test="path-status-badge">
                    {{ $learningPath->status->label() }}
                </flux:badge>
                <flux:badge
                    size="sm"
                    :color="$learningPath->review_status === ReviewStatus::Verified ? 'green' : ($learningPath->status === PathStatus::Published ? 'amber' : 'zinc')"
                >
                    {{ $learningPath->review_status->label() }}
                </flux:badge>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="outline"
                icon="shield-check"
                :href="route('admin.learning-paths.review', $learningPath)"
                wire:navigate
                data-test="path-review-link"
            >
                {{ __('Verification') }}
            </flux:button>

            @if ($learningPath->status === PathStatus::Published)
                <flux:modal.trigger name="unpublish-path">
                    <flux:button variant="outline" icon="eye-slash" data-test="unpublish-button">{{ __('Unpublish') }}</flux:button>
                </flux:modal.trigger>
            @else
                <flux:modal.trigger name="publish-path">
                    <flux:button variant="primary" icon="eye" data-test="publish-button">{{ __('Publish') }}</flux:button>
                </flux:modal.trigger>
            @endif

            {{-- Außerhalb des Formulars, deshalb über das form-Attribut verbunden. --}}
            <flux:tooltip :content="__('Save')">
                <flux:button variant="ghost" icon="save" type="submit" form="path-form" data-test="path-save-button" />
            </flux:tooltip>

            <flux:tooltip :content="__('Export')">
                <flux:button variant="ghost" icon="arrow-down-tray" wire:click="export" data-test="export-path-button" />
            </flux:tooltip>

            <flux:modal.trigger name="delete-path">
                <flux:tooltip :content="__('Delete learning path')">
                    <flux:button variant="ghost" icon="trash" data-test="delete-path-button" />
                </flux:tooltip>
            </flux:modal.trigger>
        </div>
    </div>

    @if ($this->importProblems !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="import-problems">
            <flux:callout.heading>{{ __('This learning path cannot be uploaded again as it is') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($this->importProblems as $problem)
                        <li wire:key="import-problem-{{ $loop->index }}" class="break-words">{{ $problem }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <form id="path-form" wire:submit="save" class="space-y-6">
        <flux:input wire:model="title" :label="__('Title')" required data-test="path-title-input" />

        <flux:input
            wire:model="slug"
            :label="__('Slug')"
            :description="__('Part of the shared link. Changing it breaks existing links.')"
            required
            data-test="path-slug-input"
        />

        <flux:textarea wire:model="summary" :label="__('Summary')" rows="3" data-test="path-summary-input" />

        <flux:field>
            <flux:label>{{ __('Objectives') }}</flux:label>

            <div class="space-y-2" wire:sort="sortObjectives">
                @foreach ($objectives as $index => $objective)
                    <div
                        class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
                        wire:key="objective-{{ $index }}"
                        wire:sort:item="{{ $index }}"
                        data-test="objective-row"
                    >
                        <div wire:sort:handle class="cursor-grab">
                            <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                        </div>
                        <div class="flex-1">
                            {{-- class:input trifft das Input-Element selbst (class ginge an den Wrapper);
                                 randlos + transparent, damit es in die Karte übergeht statt einen zweiten
                                 Rahmen zu ziehen. ! nötig, weil Tailwind v4 nach CSS-Reihenfolge sortiert. --}}
                            <flux:input
                                wire:model="objectives.{{ $index }}"
                                class:input="border-transparent! bg-transparent! shadow-none! dark:bg-transparent!"
                                data-test="objective-input"
                            />
                        </div>
                        <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeObjective({{ $index }})" data-test="objective-remove" />
                    </div>
                @endforeach
            </div>

            <flux:error name="objectives.*" />

            <flux:button variant="subtle" size="sm" icon="plus" wire:click="addObjective" data-test="objective-add">
                {{ __('Add objective') }}
            </flux:button>
        </flux:field>

        <div class="grid gap-6 sm:grid-cols-3">
            <flux:select wire:model="difficulty" :label="__('Difficulty')" data-test="path-difficulty-select">
                <flux:select.option value="">{{ __('Not specified') }}</flux:select.option>
                @foreach ($this->difficultyOptions as $option)
                    <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="estimated_minutes" type="number" min="1" :label="__('Estimated minutes')" data-test="path-minutes-input" />

            <flux:input wire:model="pass_threshold" type="number" min="1" max="100" :label="__('Pass threshold (%)')" required data-test="path-threshold-input" />
        </div>
    </form>

    <flux:separator />

    {{-- Gruppen (#9): ohne Auswahl sieht jede Gruppe den Pfad (E8). --}}
    <div class="space-y-3" data-test="path-groups-section">
        <div>
            <flux:heading size="lg">{{ __('Groups') }}</flux:heading>
            <flux:subheading>
                {{ $this->assignedTeamIds === []
                    ? __('Every group sees this path. Pick groups to limit it to them.')
                    : __('Only the groups picked here see this path. Progress of other groups is kept and returns when you assign them again.') }}
            </flux:subheading>
        </div>

        @forelse ($this->assignableTeams as $team)
            @php($isAssigned = in_array($team->id, $this->assignedTeamIds, true))

            <div
                wire:key="path-team-{{ $team->id }}"
                class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                data-test="path-group-row"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ $team->name }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                        <flux:badge size="sm" color="zinc">
                            {{ trans_choice('{1} :count apprentice|[2,*] :count apprentices', $team->apprentices_count, ['count' => $team->apprentices_count]) }}
                        </flux:badge>
                        @if ($team->isArchived())
                            <flux:badge size="sm" color="zinc">{{ __('archived') }}</flux:badge>
                        @endif
                        @if ($isAssigned)
                            <flux:badge size="sm" color="green" icon="check">{{ __('Assigned') }}</flux:badge>
                        @endif
                    </div>
                </div>

                <flux:button
                    :variant="$isAssigned ? 'ghost' : 'filled'"
                    size="sm"
                    :icon="$isAssigned ? 'x-mark' : 'plus'"
                    wire:click="toggleGroup({{ $team->id }})"
                    data-test="path-group-toggle"
                >
                    {{ $isAssigned ? __('Remove') : __('Assign') }}
                </flux:button>
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                {{ __('No groups yet.') }}
            </flux:text>
        @endforelse
    </div>

    <flux:separator />

    <div class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Lessons') }}</flux:heading>
            <flux:subheading>{{ __('The lessons of this path in reading order.') }}</flux:subheading>
        </div>

        <div class="space-y-2" wire:sort="sortLessons">
            @forelse ($this->lessons as $lesson)
                <div
                    wire:key="lesson-{{ $lesson->id }}"
                    wire:sort:item="{{ $lesson->id }}"
                    class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
                    data-test="lesson-row"
                >
                    <div wire:sort:handle class="cursor-grab">
                        <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                    </div>

                    @if ($editingLessonId === $lesson->id)
                        <form wire:submit="renameLesson" class="flex flex-1 items-center gap-2">
                            <div class="flex-1">
                                <flux:input wire:model="editingLessonTitle" size="sm" autofocus data-test="lesson-rename-input" />
                            </div>
                            <flux:button variant="primary" size="sm" type="submit" data-test="lesson-rename-save">{{ __('Save') }}</flux:button>
                            <flux:button variant="ghost" size="sm" wire:click="cancelRenamingLesson">{{ __('Cancel') }}</flux:button>
                        </form>
                    @else
                        <div class="flex flex-1 items-center gap-2">
                            <span class="font-medium">{{ $lesson->title }}</span>
                            @if ($lesson->questions_count > 0)
                                <flux:badge size="sm" color="zinc">
                                    {{ trans_choice('{1} :count check question|[2,*] :count check questions', $lesson->questions_count, ['count' => $lesson->questions_count]) }}
                                </flux:badge>
                            @endif
                        </div>

                        <div class="flex items-center gap-1">
                            <flux:tooltip :content="__('Rename lesson')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    wire:click="startRenamingLesson({{ $lesson->id }})"
                                    data-test="lesson-rename-button"
                                />
                            </flux:tooltip>

                            <flux:tooltip :content="__('Edit blocks')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="rectangle-stack"
                                    :href="route('admin.lessons.edit', ['learningPath' => $learningPath, 'lesson' => $lesson])"
                                    wire:navigate
                                    data-test="lesson-blocks-button"
                                />
                            </flux:tooltip>

                            <flux:modal.trigger :name="'delete-lesson-'.$lesson->id">
                                <flux:tooltip :content="__('Delete lesson')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        data-test="lesson-delete-button"
                                    />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        </div>
                    @endif
                </div>
            @empty
                <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('No lessons yet.') }}
                </flux:text>
            @endforelse
        </div>

        <form wire:submit="createLesson" class="flex items-start gap-2">
            <div class="flex-1">
                <flux:input wire:model="newLessonTitle" :placeholder="__('Title of the new lesson')" data-test="new-lesson-title" />
            </div>
            <flux:button variant="primary" type="submit" icon="plus" data-test="create-lesson-button">
                {{ __('Add lesson') }}
            </flux:button>
        </form>
    </div>

    @foreach ($this->lessons as $lesson)
        <flux:modal wire:key="delete-lesson-modal-{{ $lesson->id }}" :name="'delete-lesson-'.$lesson->id" focusable class="max-w-lg">
            <form wire:submit="deleteLesson({{ $lesson->id }})" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete lesson') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete the lesson ":title"? Its content blocks are deleted permanently.', ['title' => $lesson->title]) }}
                        @if ($lesson->questions_count > 0)
                            {{ trans_choice('{1} Its :count check question moves to the question pool.|[2,*] Its :count check questions move to the question pool.', $lesson->questions_count, ['count' => $lesson->questions_count]) }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-lesson-confirm">
                        {{ __('Delete lesson') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach

    <flux:modal name="publish-path" focusable class="max-w-lg">
        <form wire:submit="publish" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Publish learning path') }}</flux:heading>
                <flux:subheading>{{ __('Do you want to publish ":title"?', ['title' => $learningPath->title]) }}</flux:subheading>
            </div>

            @if ($learningPath->review_status !== ReviewStatus::Verified)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="publish-unverified-warning">
                    <flux:callout.text>
                        {{ __('This path has not been verified against its sources yet. It stays hidden from apprentices until it is verified.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="publish-confirm">{{ __('Publish') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="unpublish-path" focusable class="max-w-lg">
        <form wire:submit="unpublish" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Unpublish learning path') }}</flux:heading>
                <flux:subheading>
                    {{ __('The path disappears from the apprentice view until it is published again.') }}
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="unpublish-confirm">{{ __('Unpublish') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="export-path" focusable class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Export') }}</flux:heading>
                <flux:subheading>
                    {{ __('The file is a complete backup of the content, but it cannot be uploaded again as it is:') }}
                </flux:subheading>
            </div>

            <ul class="list-disc space-y-1 ps-5 text-sm text-zinc-700 dark:text-zinc-300" data-test="export-warnings">
                @foreach ($exportWarnings as $warning)
                    <li wire:key="export-warning-{{ $loop->index }}" class="break-words">{{ $warning }}</li>
                @endforeach
            </ul>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    icon="arrow-down-tray"
                    :href="route('admin.learning-paths.export', $learningPath)"
                    data-test="export-download-link"
                >
                    {{ __('Download anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-path" focusable class="max-w-lg">
        <form wire:submit="deletePath" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete learning path') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete ":title"? The path disappears from the apprentice view; its lessons and quizzes remain attached to it.', ['title' => $learningPath->title]) }}
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="delete-path-confirm">
                    {{ __('Delete learning path') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
