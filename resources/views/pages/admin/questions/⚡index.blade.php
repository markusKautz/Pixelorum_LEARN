<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /** @var int|string|null */
    public $pathFilter = null;

    /** @var int|string|null */
    public $lessonFilter = null;

    public string $search = '';

    public string $sortBy = 'id';

    public string $sortDirection = 'desc';

    /**
     * Whitelist für orderBy(): Spaltennamen werden nicht als Parameter
     * gebunden, ein manipulierter Wert landete sonst roh in der Query.
     *
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['id', 'quizzes_count'];

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            // Beim ersten Klick interessiert „die meistgenutzten Fragen zuerst“
            $this->sortDirection = 'desc';
        }

        $this->resetPage();
    }

    public function updatedPathFilter(): void
    {
        // Lektionen hängen an genau einem Pfad — eine Auswahl aus dem alten
        // Pfad würde nach dem Wechsel eine leere Tabelle liefern
        $this->lessonFilter = null;

        $this->resetPage();
    }

    public function updatedLessonFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function deleteQuestion(int $questionId): void
    {
        Gate::authorize('admin');

        $question = Question::findOrFail($questionId);

        // Pivot-Cascade entfernt die Frage aus allen Quizzen
        $question->delete();

        $this->modal("delete-question-{$questionId}")->close();

        unset($this->questions);

        Flux::toast(variant: 'success', text: __('Question deleted.'));
    }

    /**
     * @return LengthAwarePaginator<int, Question>
     */
    #[Computed]
    public function questions(): LengthAwarePaginator
    {
        return Question::query()
            ->with(['learningPath', 'lesson'])
            // Nur redaktionelle Quizze: „steckt in N Quizzen" soll dem
            // Ausbildner die Wiederverwendung zeigen, nicht mitzählen, wie oft
            // die Frage in einer Übungssitzung gezogen wurde (Sprint 5.5).
            ->withCount(['quizzes' => fn (Builder $query) => $query->editorial()])
            ->forPath(filled($this->pathFilter) ? (int) $this->pathFilter : null)
            ->forLesson($this->lessonFilter)
            ->search($this->search)
            ->orderBy($this->sortBy, $this->sortDirection)
            // Ohne eindeutigen Zweitschlüssel würden Fragen mit gleicher
            // Quiz-Anzahl über die Seitengrenzen springen oder doppelt erscheinen
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        return LearningPath::query()->orderBy('position')->get();
    }

    /**
     * Die Lektionen des gewählten Pfads in Lesereihenfolge. Ohne Pfad bleibt
     * die Liste leer und das Feld abgeschaltet — eine Flachliste über alle
     * Pfade (~25 × 10 Lektionen) wäre unbedienbar.
     *
     * @return Collection<int, Lesson>
     */
    #[Computed]
    public function lessons(): Collection
    {
        if (blank($this->pathFilter)) {
            return new Collection;
        }

        return Lesson::query()
            ->where('learning_path_id', (int) $this->pathFilter)
            ->withCount('questions')
            ->orderBy('position')
            ->get();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Questions'));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Questions') }}</flux:heading>
            <flux:subheading>{{ __('The reusable question pool for checks and quizzes.') }}</flux:subheading>
        </div>

        <flux:button
            variant="primary"
            icon="plus"
            :href="route('admin.questions.create')"
            wire:navigate
            data-test="new-question-button"
        >
            {{ __('New question') }}
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:select wire:model.live="pathFilter" :label="__('Learning path')" data-test="filter-path-select">
            <flux:select.option value="">{{ __('All paths') }}</flux:select.option>
            @foreach ($this->paths as $path)
                <flux:select.option value="{{ $path->id }}">{{ $path->title }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model.live="lessonFilter"
            :label="__('Lesson')"
            :disabled="blank($this->pathFilter)"
            data-test="filter-lesson-select"
        >
            @if (blank($this->pathFilter))
                <flux:select.option value="">{{ __('Select a learning path first') }}</flux:select.option>
            @else
                <flux:select.option value="">{{ __('All lessons') }}</flux:select.option>
                <flux:select.option value="{{ Question::NO_LESSON }}">{{ __('Without lesson (pool only)') }}</flux:select.option>
                @foreach ($this->lessons as $lesson)
                    <flux:select.option value="{{ $lesson->id }}">
                        {{ $loop->iteration }}. {{ $lesson->title }} ({{ $lesson->questions_count }})
                    </flux:select.option>
                @endforeach
            @endif
        </flux:select>

        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            :placeholder="__('Search in the prompt …')"
            icon="magnifying-glass"
            data-test="filter-search-input"
        />
    </div>

    <flux:table :paginate="$this->questions" data-test="questions-table">
        <flux:table.columns>
            <flux:table.column>{{ __('Prompt') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Learning path') }}</flux:table.column>
            <flux:table.column
                align="end"
                sortable
                :sorted="$sortBy === 'quizzes_count'"
                :direction="$sortDirection"
                wire:click="sort('quizzes_count')"
                data-test="sort-quizzes-column"
            >{{ __('Quizzes') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->questions as $question)
                <flux:table.row :key="$question->id" data-test="question-row">
                    <flux:table.cell variant="strong">
                        {{-- Flux-Tabellen sind whitespace-nowrap: ohne truncate läuft die Fragestellung in die Nachbarspalte --}}
                        <span class="block max-w-md truncate" title="{{ $question->prompt }}">{{ $question->prompt }}</span>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap items-center gap-1">
                            <flux:badge size="sm" color="zinc">{{ $question->type->label() }}</flux:badge>
                            @if ($question->lesson_id !== null)
                                <flux:badge size="sm" color="indigo" class="max-w-3xs" data-test="lesson-check-badge">
                                    <span class="truncate">{{ __('Check: :lesson', ['lesson' => $question->lesson?->title]) }}</span>
                                </flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <span class="block max-w-3xs truncate" @if ($question->learningPath !== null) title="{{ $question->learningPath->title }}" @endif>{{ $question->learningPath?->title ?? __('No path') }}</span>
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $question->quizzes_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-1">
                            <flux:tooltip :content="__('Edit')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    :href="route('admin.questions.edit', $question)"
                                    wire:navigate
                                    data-test="question-edit-button"
                                />
                            </flux:tooltip>

                            <flux:modal.trigger :name="'delete-question-'.$question->id">
                                <flux:tooltip :content="__('Delete')">
                                    <flux:button variant="ghost" size="sm" icon="trash" data-test="question-delete-button" />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No questions found.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @foreach ($this->questions as $question)
        <flux:modal wire:key="delete-question-modal-{{ $question->id }}" :name="'delete-question-'.$question->id" focusable class="max-w-lg">
            <form wire:submit="deleteQuestion({{ $question->id }})" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete question') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete this question?') }}
                        @if ($question->quizzes_count > 0)
                            {{ trans_choice('{1} It is part of :count quiz and will be removed from it.|[2,*] It is part of :count quizzes and will be removed from them.', $question->quizzes_count, ['count' => $question->quizzes_count]) }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-question-confirm">
                        {{ __('Delete question') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach
</section>
