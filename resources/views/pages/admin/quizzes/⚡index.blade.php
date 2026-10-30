<?php

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Quiz;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $title = '';

    public string $kind = 'custom';

    /** @var int|string|null */
    public $newQuizPathId = null;

    public function createQuiz(): void
    {
        Gate::authorize('create', Quiz::class);

        $this->newQuizPathId = $this->newQuizPathId === '' ? null : $this->newQuizPathId;

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            // Backstop gegen manipulierte Payloads: „practice" ist kein Wert,
            // den ein Admin setzen darf (Sprint 5.5).
            'kind' => ['required', Rule::enum(QuizKind::class)->only(QuizKind::editorialCases())],
            'newQuizPathId' => [
                'nullable',
                'integer',
                Rule::exists('learning_paths', 'id'),
                Rule::requiredIf($this->kind === QuizKind::PathFinal->value),
            ],
        ]);

        $pathId = filled($validated['newQuizPathId']) ? (int) $validated['newQuizPathId'] : null;

        // Entscheidung 7: höchstens ein Abschluss-Quiz je Pfad — als
        // Form-Validierung; der saving-Guard am Model bleibt Backstop.
        if ($validated['kind'] === QuizKind::PathFinal->value
            && Quiz::where('learning_path_id', $pathId)->where('kind', QuizKind::PathFinal)->exists()) {
            $this->addError('newQuizPathId', __('This path already has a final quiz.'));

            return;
        }

        $quiz = Quiz::create([
            'title' => $validated['title'],
            'kind' => QuizKind::from($validated['kind']),
            'learning_path_id' => $validated['kind'] === QuizKind::PathFinal->value ? $pathId : null,
            'created_by' => Auth::id(),
        ]);

        $this->modal('create-quiz')->close();

        Flux::toast(variant: 'success', text: __('Quiz created.'));

        $this->redirectRoute('admin.quizzes.edit', ['quiz' => $quiz->id], navigate: true);
    }

    /**
     * Veröffentlichen direkt aus der Liste, wie bei den Lernpfaden. Der Dialog
     * bleibt, weil beides Folgen für die Teilnehmer_innen hat: freigeben stellt
     * ihnen das Quiz hin, zurückziehen nimmt es ihnen wieder weg.
     */
    public function publishQuiz(int $quizId): void
    {
        $quiz = Quiz::findOrFail($quizId);

        Gate::authorize('update', $quiz);

        $quiz->update(['status' => PathStatus::Published]);

        $this->modal("publish-quiz-{$quizId}")->close();

        unset($this->quizzes);

        Flux::toast(variant: 'success', text: __('Quiz published.'));
    }

    public function unpublishQuiz(int $quizId): void
    {
        $quiz = Quiz::findOrFail($quizId);

        Gate::authorize('update', $quiz);

        $quiz->update(['status' => PathStatus::Draft]);

        $this->modal("unpublish-quiz-{$quizId}")->close();

        unset($this->quizzes);

        Flux::toast(variant: 'success', text: __('Quiz unpublished.'));
    }

    public function deleteQuiz(int $quizId): void
    {
        $quiz = Quiz::findOrFail($quizId);

        Gate::authorize('delete', $quiz);

        $quiz->delete();

        $this->modal("delete-quiz-{$quizId}")->close();

        unset($this->quizzes);

        Flux::toast(variant: 'success', text: __('Quiz deleted.'));
    }

    /**
     * @return Collection<int, Quiz>
     */
    #[Computed]
    public function quizzes(): Collection
    {
        return Quiz::query()
            // Ohne den Filter flutete je Teilnehmer_in eine Übungsquiz-Zeile die
            // Redaktionsliste (Sprint 5.5).
            ->editorial()
            ->with(['learningPath', 'teams'])
            ->withCount(['questions', 'attempts'])
            ->latest('id')
            ->get();
    }

    /**
     * Pfade ohne bestehendes Abschluss-Quiz (Entscheidung 7) — nur diese
     * stehen im Anlegen-Modal für path_final zur Auswahl.
     *
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function pathsWithoutFinalQuiz(): Collection
    {
        return LearningPath::query()
            ->whereDoesntHave('quizzes', fn ($query) => $query->where('kind', QuizKind::PathFinal))
            ->orderBy('position')
            ->get();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Quizzes'));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Quizzes') }}</flux:heading>
            <flux:subheading>{{ __('Final quizzes per path and freely bundled quizzes from the pool.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="create-quiz">
            <flux:button variant="primary" icon="plus" data-test="new-quiz-button">
                {{ __('New quiz') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table data-test="quizzes-table">
        <flux:table.columns>
            <flux:table.column>{{ __('Title') }}</flux:table.column>
            <flux:table.column>{{ __('Kind') }}</flux:table.column>
            <flux:table.column>{{ __('Learning path') }}</flux:table.column>
            <flux:table.column>{{ __('Handed out to') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Questions') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->quizzes as $quiz)
                <flux:table.row :key="$quiz->id" data-test="quiz-row">
                    <flux:table.cell variant="strong">
                        <span class="block max-w-xs truncate" title="{{ $quiz->title }}">{{ $quiz->title }}</span>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$quiz->kind === QuizKind::PathFinal ? 'indigo' : 'zinc'">
                            {{ $quiz->kind->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <span class="block max-w-3xs truncate" @if ($quiz->learningPath !== null) title="{{ $quiz->learningPath->title }}" @endif>{{ $quiz->learningPath?->title ?? __('No path') }}</span>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{-- Zeigt auf einen Blick, was schon ausgegeben ist:
                             ein veröffentlichtes freies Quiz ohne Gruppe sieht
                             niemand (Sprint 5.6). Zwei Gruppennamen bleiben
                             sichtbar, der Rest klappt in einen Tooltip. --}}
                        @if ($quiz->kind !== QuizKind::Custom)
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not applicable') }}</span>
                        @elseif ($quiz->teams->isEmpty())
                            <flux:badge size="sm" color="amber" data-test="quiz-unassigned-badge">
                                {{ __('Not handed out') }}
                            </flux:badge>
                        @else
                            <div class="flex max-w-xs flex-wrap items-center gap-1" data-test="quiz-teams-badges">
                                @foreach ($quiz->teams->take(2) as $team)
                                    <flux:badge size="sm" color="zinc">{{ $team->name }}</flux:badge>
                                @endforeach

                                @if ($quiz->teams->count() > 2)
                                    <flux:tooltip :content="$quiz->teams->slice(2)->pluck('name')->implode(', ')">
                                        <flux:badge size="sm" color="zinc" data-test="quiz-teams-more-badge">
                                            {{ __('+:count more', ['count' => $quiz->teams->count() - 2]) }}
                                        </flux:badge>
                                    </flux:tooltip>
                                @endif
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $quiz->questions_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$quiz->status === PathStatus::Published ? 'green' : 'zinc'">
                            {{ $quiz->status->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-1">
                            {{-- Veröffentlichen ohne Umweg über den Quiz-Editor,
                                 wie in der Lernpfad-Liste. --}}
                            @if ($quiz->status === PathStatus::Published)
                                <flux:modal.trigger :name="'unpublish-quiz-'.$quiz->id">
                                    <flux:tooltip :content="__('Unpublish')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="eye-slash"
                                            data-test="quiz-unpublish-button"
                                        />
                                    </flux:tooltip>
                                </flux:modal.trigger>
                            @else
                                <flux:modal.trigger :name="'publish-quiz-'.$quiz->id">
                                    <flux:tooltip :content="__('Publish')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="eye"
                                            data-test="quiz-publish-button"
                                        />
                                    </flux:tooltip>
                                </flux:modal.trigger>
                            @endif

                            <flux:tooltip :content="__('Edit')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    :href="route('admin.quizzes.edit', $quiz)"
                                    wire:navigate
                                    data-test="quiz-edit-button"
                                />
                            </flux:tooltip>

                            <flux:modal.trigger :name="'delete-quiz-'.$quiz->id">
                                <flux:tooltip :content="__('Delete')">
                                    <flux:button variant="ghost" size="sm" icon="trash" data-test="quiz-delete-button" />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No quizzes yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @foreach ($this->quizzes as $quiz)
        @if ($quiz->status === PathStatus::Published)
            <flux:modal wire:key="unpublish-quiz-modal-{{ $quiz->id }}" :name="'unpublish-quiz-'.$quiz->id" focusable class="max-w-lg">
                <form wire:submit="unpublishQuiz({{ $quiz->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Unpublish quiz') }}</flux:heading>
                        <flux:subheading>
                            {{ __('The quiz disappears from the apprentice view until it is published again. Attempts already made are kept.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit" data-test="quiz-unpublish-confirm">
                            {{ __('Unpublish') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @else
            <flux:modal wire:key="publish-quiz-modal-{{ $quiz->id }}" :name="'publish-quiz-'.$quiz->id" focusable class="max-w-lg">
                <form wire:submit="publishQuiz({{ $quiz->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Publish quiz') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Do you want to publish ":title"?', ['title' => $quiz->title]) }}
                        </flux:subheading>
                    </div>

                    {{-- Die zwei Fälle, in denen Freigeben allein nichts bewirkt:
                         ein leeres Quiz und ein freies Quiz, das keiner Gruppe
                         ausgegeben ist (Sprint 5.6). --}}
                    @if ($quiz->questions_count === 0)
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="quiz-publish-empty-warning">
                            <flux:callout.text>
                                {{ __('This quiz has no questions yet.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($quiz->kind === QuizKind::Custom && $quiz->teams->isEmpty())
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="quiz-publish-unassigned-warning">
                            <flux:callout.text>
                                {{ __('This quiz is not handed out to any group. Publishing alone does not hand it out.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit" data-test="quiz-publish-confirm">
                            {{ __('Publish') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        <flux:modal wire:key="delete-quiz-modal-{{ $quiz->id }}" :name="'delete-quiz-'.$quiz->id" focusable class="max-w-lg">
            <form wire:submit="deleteQuiz({{ $quiz->id }})" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete quiz') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete ":title"?', ['title' => $quiz->title]) }}
                        @if ($quiz->attempts_count > 0)
                            {{ trans_choice('{1} :count attempt by apprentices will be deleted as well.|[2,*] :count attempts by apprentices will be deleted as well.', $quiz->attempts_count, ['count' => $quiz->attempts_count]) }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-quiz-confirm">
                        {{ __('Delete quiz') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach

    <flux:modal name="create-quiz" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createQuiz" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a new quiz') }}</flux:heading>
                <flux:subheading>{{ __('Questions are added afterwards in the quiz builder.') }}</flux:subheading>
            </div>

            <flux:input wire:model="title" :label="__('Title')" autofocus data-test="create-quiz-title" />

            <flux:select wire:model.live="kind" :label="__('Kind')" data-test="create-quiz-kind">
                {{-- Übungsquizze entstehen nur durch Teilnehmer_innen (Sprint 5.5) —
                     der Admin legt keine an. --}}
                @foreach (QuizKind::editorialCases() as $quizKind)
                    <flux:select.option value="{{ $quizKind->value }}">{{ $quizKind->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($kind === QuizKind::PathFinal->value)
                <flux:select wire:model="newQuizPathId" :label="__('Learning path')" data-test="create-quiz-path">
                    <flux:select.option value="">{{ __('Choose a path …') }}</flux:select.option>
                    @foreach ($this->pathsWithoutFinalQuiz as $path)
                        <flux:select.option value="{{ $path->id }}">{{ $path->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-quiz-submit">
                    {{ __('Create quiz') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
