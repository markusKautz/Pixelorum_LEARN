<?php

use App\Actions\Content\ReorderPivotToPosition;
use App\Enums\PathStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Watch-Point aus dem Sprint-2-Review: alle Schreibpfade laufen über
 * normales save()/update()/attach() — kein saveQuietly(), kein
 * withoutEvents(); der saving-Guard am Quiz bleibt die letzte Verteidigung
 * der path_final-Invariante.
 */
new class extends Component {
    use WithPagination;

    public Quiz $quiz;

    public string $title = '';

    public string $description = '';

    public string $kind = 'custom';

    /** @var int|string|null */
    public $quizPathId = null;

    /** @var int|string */
    public $pass_threshold = 80;

    /** Fragen je Versuch mischen (Sprint 5.5) — erschwert Positions-Auswendiglernen. */
    public bool $shuffleQuestions = false;

    /** @var int|string|null */
    public $pathFilter = null;

    public string $typeFilter = '';

    public string $search = '';

    /**
     * Nur ein Abschluss-Quiz trägt einen Pfad (Konzept: `learning_path_id`
     * gesetzt ⇔ `path_final`). Beim Wechsel auf „frei“ die Auswahl fallen
     * lassen, damit kein unsichtbarer Wert im Formular stehen bleibt.
     */
    public function updatedKind(string $value): void
    {
        if ($value !== QuizKind::PathFinal->value) {
            $this->quizPathId = null;
        }
    }

    public function mount(Quiz $quiz): void
    {
        $this->quiz = $quiz;
        $this->title = $quiz->title;
        $this->description = $quiz->description ?? '';
        $this->kind = $quiz->kind->value;
        $this->quizPathId = $quiz->learning_path_id;
        $this->pass_threshold = $quiz->pass_threshold;
        $this->shuffleQuestions = $quiz->shuffle_questions;

        $this->lockPoolToFinalQuizPath();
    }

    /**
     * Ein Abschluss-Quiz nimmt nur Pool-Fragen seines Pfades (DEV-15, E4):
     * der Pool-Filter steht dann fest auf diesem Pfad. Maßgeblich ist der
     * gespeicherte Stand, nicht das Formular, denn addQuestion() arbeitet
     * auf dem gespeicherten Quiz.
     */
    protected function lockPoolToFinalQuizPath(): void
    {
        if ($this->isPoolLocked()) {
            $this->pathFilter = $this->quiz->learning_path_id;
            $this->resetPage('pool');
        }
    }

    public function isPoolLocked(): bool
    {
        return $this->quiz->kind === QuizKind::PathFinal && $this->quiz->learning_path_id !== null;
    }

    public function saveQuiz(): void
    {
        Gate::authorize('update', $this->quiz);

        $this->quizPathId = $this->quizPathId === '' ? null : $this->quizPathId;

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Backstop gegen manipulierte Payloads: ein Quiz lässt sich nicht
            // per Formular in einen Übungsbehälter umwidmen (Sprint 5.5).
            'kind' => ['required', Rule::enum(QuizKind::class)->only(QuizKind::editorialCases())],
            'quizPathId' => [
                'nullable',
                'integer',
                Rule::exists('learning_paths', 'id'),
                Rule::requiredIf($this->kind === QuizKind::PathFinal->value),
            ],
            'pass_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'shuffleQuestions' => ['boolean'],
        ]);

        $pathId = filled($validated['quizPathId']) ? (int) $validated['quizPathId'] : null;

        // Entscheidung 7: höchstens ein path_final-Quiz je Pfad — eigenes
        // Quiz beim Bearbeiten ausgenommen.
        if ($validated['kind'] === QuizKind::PathFinal->value
            && Quiz::where('learning_path_id', $pathId)
                ->where('kind', QuizKind::PathFinal)
                ->whereKeyNot($this->quiz->id)
                ->exists()) {
            $this->addError('quizPathId', __('This path already has a final quiz.'));

            return;
        }

        // DEV-15: erst die Fragen bereinigen, dann umwidmen. Stilles Abhängen
        // würde ein Quiz verändern, an dem womöglich schon Versuche hängen.
        if ($validated['kind'] === QuizKind::PathFinal->value) {
            $outside = $this->quiz->questionsOutsidePoolOf((int) $pathId)->count();

            if ($outside > 0) {
                $this->addError('quizPathId', trans_choice(
                    '{1} The quiz contains :count question that is not a pool question of this path. Remove it first.|[2,*] The quiz contains :count questions that are not pool questions of this path. Remove them first.',
                    $outside,
                    ['count' => $outside],
                ));

                return;
            }
        }

        $this->quiz->update([
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'kind' => QuizKind::from($validated['kind']),
            // Backstop gegen manipulierte Payloads: ein freies Quiz trägt nie
            // einen Pfad, sonst legte QuizPolicy::view() das Grounding-Gate
            // des Pfads über ein Quiz, das gar nicht dazugehört.
            'learning_path_id' => $validated['kind'] === QuizKind::PathFinal->value ? $pathId : null,
            'pass_threshold' => (int) $validated['pass_threshold'],
            'shuffle_questions' => (bool) $validated['shuffleQuestions'],
        ]);

        /*
         * Wird ein freies Quiz zum Abschluss-Quiz umgewidmet, verliert es seine
         * Gruppen-Ausgabe: seine Zielgruppe kommt ab dann vom Pfad, und ein
         * Abschluss-Quiz ist ohnehin für alle sichtbar. Ohne das Aufräumen
         * blieben Zeilen in `quiz_team` liegen, die bei einem späteren
         * Zurückschalten unbemerkt wieder gälten.
         */
        if ($this->quiz->kind !== QuizKind::Custom) {
            $this->quiz->teams()->detach();

            unset($this->assignedTeamIds);
        }

        unset($this->questionIdsOutsidePool);

        $this->lockPoolToFinalQuizPath();

        Flux::toast(variant: 'success', text: __('Quiz saved.'));
    }

    /**
     * Ein freies Quiz einer Gruppe ausgeben oder die Ausgabe zurücknehmen
     * (Sprint 5.6).
     *
     * Sofort wirksam statt über den Speichern-Knopf: die Zuweisung ist keine
     * Eigenschaft des Quiz-Formulars, sondern eine eigene Handlung — und ein
     * Validierungsfehler im Titel soll sie nicht mit verwerfen (dasselbe
     * Argument wie bei der Inaktivitäts-Schwelle in S5.2).
     */
    public function toggleGroup(int $teamId): void
    {
        Gate::authorize('update', $this->quiz);

        // Nur freie Quizze werden ausgegeben: das Abschluss-Quiz erbt seine
        // Zielgruppe vom Pfad, das Übungsquiz gehört einem Lehrling.
        abort_unless($this->quiz->kind === QuizKind::Custom, 403);

        $team = Team::query()
            ->where('is_personal', false)
            ->findOrFail($teamId);

        $this->quiz->teams()->toggle($team->id);

        unset($this->assignedTeamIds);

        Flux::toast(variant: 'success', text: __('Assignment updated.'));
    }

    /**
     * Die Gruppen, denen dieses Quiz ausgegeben ist.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function assignedTeamIds(): array
    {
        return $this->quiz->teams()->pluck('teams.id')->all();
    }

    /**
     * Die Lehrlingsgruppen, an die ausgegeben werden kann.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function assignableTeams(): Collection
    {
        return Team::query()
            ->where('is_personal', false)
            ->withCount('apprentices')
            ->orderBy('name')
            ->get();
    }

    public function publish(): void
    {
        Gate::authorize('update', $this->quiz);

        $this->quiz->update(['status' => PathStatus::Published]);

        Flux::toast(variant: 'success', text: __('Quiz published.'));
    }

    public function unpublish(): void
    {
        Gate::authorize('update', $this->quiz);

        $this->quiz->update(['status' => PathStatus::Draft]);

        Flux::toast(variant: 'success', text: __('Quiz unpublished.'));
    }

    /**
     * Versuche, die beim Löschen mitgehen; das Lösch-Modal nennt sie.
     */
    #[Computed]
    public function attemptsCount(): int
    {
        return $this->quiz->attempts()->count();
    }

    public function deleteQuiz(): void
    {
        Gate::authorize('delete', $this->quiz);

        $this->quiz->delete();

        $this->modal('delete-quiz')->close();

        Flux::toast(variant: 'success', text: __('Quiz deleted.'));

        $this->redirectRoute('admin.quizzes.index', navigate: true);
    }

    public function addQuestion(int $questionId): void
    {
        Gate::authorize('update', $this->quiz);

        $question = Question::findOrFail($questionId);

        // Backstop zum gesperrten Pool (DEV-15, E4): ein manipulierter
        // Payload bringt keine fremde Frage ins Abschluss-Quiz.
        if ($this->quiz->kind === QuizKind::PathFinal && ! $question->isPoolQuestionOf((int) $this->quiz->learning_path_id)) {
            Flux::toast(variant: 'danger', text: __('A final quiz only takes pool questions of its own path.'));

            return;
        }

        // Existenz-Check fängt Doppel-Klicks ab, ohne die Position der
        // bestehenden Zuordnung anzufassen; der Pivot-Unique aus S2.4 ist
        // die letzte Verteidigung.
        if ($this->quiz->questions()->whereKey($question->id)->exists()) {
            return;
        }

        $position = ((int) $this->quiz->questions()->max('quiz_questions.position')) + 1;

        $this->quiz->questions()->attach($question->id, ['position' => $position]);

        unset($this->quizQuestions);
    }

    public function removeQuestion(int $questionId): void
    {
        Gate::authorize('update', $this->quiz);

        // Entfernen darf eine Positions-Lücke lassen (Entscheidung 10):
        // orderByPivot trägt die Reihenfolge, addQuestion setzt max+1.
        $this->quiz->questions()->detach($questionId);

        unset($this->quizQuestions, $this->questionIdsOutsidePool);
    }

    public function sortQuestions(ReorderPivotToPosition $reorderPivotToPosition, int $questionId, int $position): void
    {
        Gate::authorize('update', $this->quiz);

        $reorderPivotToPosition->handle($this->quiz->questions(), $questionId, $position);

        unset($this->quizQuestions);
    }

    /**
     * @return Collection<int, Question>
     */
    #[Computed]
    public function quizQuestions(): Collection
    {
        return $this->quiz->questions()->get();
    }

    /**
     * Fragen dieses Abschluss-Quiz, die gegen die Pool-Regel verstoßen
     * (Altbestand vor DEV-15, E5); bei jedem anderen Quiz leer.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function questionIdsOutsidePool(): array
    {
        return $this->quiz->questionsOutsidePathPool()->pluck('questions.id')->all();
    }

    /**
     * @return LengthAwarePaginator<int, Question>
     */
    #[Computed]
    public function poolQuestions(): LengthAwarePaginator
    {
        // Gesperrt: der Pfad kommt vom Quiz, nicht aus dem manipulierbaren
        // Filter, und Zwischen-Checks bleiben draußen.
        $isLocked = $this->isPoolLocked();

        return Question::query()
            ->with(['learningPath', 'lesson'])
            ->withCount('options')
            ->forPath($isLocked ? $this->quiz->learning_path_id : (filled($this->pathFilter) ? (int) $this->pathFilter : null))
            ->when($isLocked, fn ($query) => $query->whereNull('lesson_id'))
            ->ofType(QuestionType::tryFrom($this->typeFilter))
            ->search($this->search)
            ->latest('id')
            ->paginate(10, pageName: 'pool');
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function assignedQuestionIds(): array
    {
        return $this->quizQuestions->pluck('id')->all();
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
     * Pfade, die bereits ein Abschluss-Quiz haben — das eigene Quiz ausgenommen.
     * Sie werden im Select ausgegraut; saveQuiz bleibt der Backstop (F7).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function occupiedPathIds(): array
    {
        return Quiz::query()
            ->where('kind', QuizKind::PathFinal)
            ->whereKeyNot($this->quiz->id)
            ->whereNotNull('learning_path_id')
            ->pluck('learning_path_id')
            ->all();
    }

    public function updatedPathFilter(): void
    {
        $this->resetPage('pool');
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage('pool');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('pool');
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Edit :name', ['name' => $this->quiz->title]));
    }
}; ?>

<section class="w-full space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.quizzes.index')"
            wire:navigate
            data-test="back-to-quizzes-button"
        >
            {{ __('Quizzes') }}
        </flux:button>
    </div>

    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $quiz->title }}</flux:heading>
            <div class="mt-2 flex items-center gap-2">
                <flux:badge size="sm" :color="$quiz->kind === QuizKind::PathFinal ? 'indigo' : 'zinc'">
                    {{ $quiz->kind->label() }}
                </flux:badge>
                <flux:badge size="sm" :color="$quiz->status === PathStatus::Published ? 'green' : 'zinc'" data-test="quiz-status-badge">
                    {{ $quiz->status->label() }}
                </flux:badge>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if ($quiz->status === PathStatus::Published)
                <flux:button variant="outline" icon="eye-slash" wire:click="unpublish" data-test="quiz-unpublish-button">
                    {{ __('Unpublish') }}
                </flux:button>
            @else
                <flux:button variant="primary" icon="eye" wire:click="publish" data-test="quiz-publish-button">
                    {{ __('Publish') }}
                </flux:button>
            @endif

            <flux:modal.trigger name="delete-quiz">
                <flux:tooltip :content="__('Delete quiz')">
                    <flux:button variant="ghost" icon="trash" data-test="quiz-delete-button" />
                </flux:tooltip>
            </flux:modal.trigger>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Links: Fragen-Pool --}}
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Question pool') }}</flux:heading>
                <flux:subheading>
                    @if ($this->isPoolLocked())
                        {{ __('A final quiz only takes pool questions of its own path. Check questions of the lessons stay out.') }}
                    @else
                        {{ __('Search the pool and add questions to the quiz.') }}
                    @endif
                </flux:subheading>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model.live="pathFilter" :label="__('Learning path')" :disabled="$this->isPoolLocked()" data-test="pool-filter-path">
                    <flux:select.option value="">{{ __('All paths') }}</flux:select.option>
                    @foreach ($this->paths as $path)
                        <flux:select.option value="{{ $path->id }}">{{ $path->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="typeFilter" :label="__('Type')" data-test="pool-filter-type">
                    <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                    @foreach (QuestionType::cases() as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    data-test="pool-filter-search"
                />
            </div>

            <div class="space-y-2">
                @forelse ($this->poolQuestions as $question)
                    @php $assigned = in_array($question->id, $this->assignedQuestionIds, true); @endphp
                    <div
                        wire:key="pool-question-{{ $question->id }}"
                        class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 {{ $assigned ? 'opacity-50' : '' }}"
                        data-test="pool-question-row"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ $question->prompt }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <flux:badge size="sm" color="zinc">{{ $question->type->label() }}</flux:badge>
                                @if ($question->learningPath)
                                    <flux:badge size="sm" color="zinc">{{ $question->learningPath->title }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <flux:tooltip :content="$assigned ? __('Already in the quiz') : __('Add to quiz')">
                            <div>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="plus"
                                    wire:click="addQuestion({{ $question->id }})"
                                    :disabled="$assigned"
                                    data-test="pool-question-add"
                                />
                            </div>
                        </flux:tooltip>
                    </div>
                @empty
                    <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No questions found.') }}
                    </flux:text>
                @endforelse
            </div>

            <flux:pagination :paginator="$this->poolQuestions" />
        </div>

        {{-- Rechts: das Quiz --}}
        <div class="space-y-6">
            <form wire:submit="saveQuiz" class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:input wire:model="title" :label="__('Title')" required data-test="quiz-title-input" />

                <flux:textarea wire:model="description" :label="__('Description')" rows="2" data-test="quiz-description-input" />

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:select wire:model.live="kind" :label="__('Kind')" data-test="quiz-kind-select">
                        @foreach (QuizKind::editorialCases() as $quizKind)
                            <flux:select.option value="{{ $quizKind->value }}">{{ $quizKind->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($kind === QuizKind::PathFinal->value)
                        <flux:select wire:model="quizPathId" :label="__('Learning path')" data-test="quiz-path-select">
                            <flux:select.option value="">{{ __('No path') }}</flux:select.option>
                            @foreach ($this->paths as $path)
                                @php($isOccupied = in_array($path->id, $this->occupiedPathIds, true))
                                <flux:select.option value="{{ $path->id }}" :disabled="$isOccupied">
                                    {{ $path->title }}@if ($isOccupied) ({{ __('already has a final quiz') }})@endif
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:input
                        wire:model="pass_threshold"
                        type="number"
                        min="1"
                        max="100"
                        :label="__('Pass threshold (%)')"
                        required
                        data-test="quiz-threshold-input"
                    />
                </div>

                <flux:switch
                    wire:model="shuffleQuestions"
                    :label="__('Shuffle questions')"
                    :description="__('Each attempt shows the questions in a different order. That makes memorising answers by position harder. The order stays fixed within a running attempt.')"
                    data-test="quiz-shuffle-switch"
                />

                <flux:button variant="primary" icon="save" type="submit" data-test="quiz-save-button">{{ __('Save') }}</flux:button>
            </form>

            {{-- Ausgabe an Gruppen (Sprint 5.6) — nur freie Quizze: das
                 Abschluss-Quiz erbt seine Zielgruppe vom Pfad. --}}
            @if ($kind === QuizKind::Custom->value)
                <div class="space-y-3" data-test="quiz-groups-section">
                    <div>
                        <flux:heading size="lg">{{ __('Hand out to groups') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Only apprentices in a group you pick here can see this quiz. Publishing alone does not hand it out.') }}
                        </flux:subheading>
                    </div>

                    @if ($quiz->kind !== QuizKind::Custom)
                        {{-- Der Formular-State zeigt den Typ-Wechsel sofort, aber
                             die Gruppenzuweisung darf erst nach dem Speichern
                             erfolgen — sonst schlägt der Guard in toggleGroup()
                             mit 403 zu. --}}
                        <flux:callout color="amber" icon="exclamation-triangle" data-test="quiz-groups-unsaved-hint">
                            <flux:callout.text>
                                {{ __('Save the type change first. Then you can hand this quiz out to groups.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @else
                        @forelse ($this->assignableTeams as $team)
                            @php($isAssigned = in_array($team->id, $this->assignedTeamIds, true))

                            <div
                                wire:key="assign-team-{{ $team->id }}"
                                class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                                data-test="quiz-group-row"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">{{ $team->name }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        <flux:badge size="sm" color="zinc">
                                            {{ trans_choice('{1} :count apprentice|[2,*] :count apprentices', $team->apprentices_count, ['count' => $team->apprentices_count]) }}
                                        </flux:badge>
                                        @if ($isAssigned)
                                            <flux:badge size="sm" color="green" icon="check">{{ __('Handed out') }}</flux:badge>
                                        @endif
                                    </div>
                                </div>

                                <flux:button
                                    :variant="$isAssigned ? 'ghost' : 'filled'"
                                    size="sm"
                                    :icon="$isAssigned ? 'x-mark' : 'paper-airplane'"
                                    wire:click="toggleGroup({{ $team->id }})"
                                    data-test="quiz-group-toggle"
                                >
                                    {{ $isAssigned ? __('Withdraw') : __('Hand out') }}
                                </flux:button>
                            </div>
                        @empty
                            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="quiz-groups-empty-state">
                                {{ __('No groups yet.') }}
                            </flux:text>
                        @endforelse

                        @if ($quiz->status !== PathStatus::Published && $this->assignedTeamIds !== [])
                            {{-- Beide Stufen sind nötig: zugewiesen, aber Entwurf =
                                 immer noch unsichtbar. Ohne den Hinweis sucht der
                                 Ausbildner den Fehler bei der Zuweisung. --}}
                            <flux:callout color="amber" icon="exclamation-triangle" data-test="quiz-unpublished-hint">
                                <flux:callout.text>
                                    {{ __('This quiz is still a draft. Apprentices will only see it once it is published.') }}
                                </flux:callout.text>
                            </flux:callout>
                        @endif
                    @endif
                </div>
            @endif

            <div class="space-y-3">
                <div>
                    <flux:heading size="lg">
                        {{ __('Quiz questions (:count)', ['count' => $this->quizQuestions->count()]) }}
                    </flux:heading>
                    <flux:subheading>{{ __('Drag to change the order apprentices will see.') }}</flux:subheading>
                </div>

                @if ($this->questionIdsOutsidePool !== [])
                    {{-- Altbestand vor DEV-15: solche Fragen fehlen im Export
                         und blockieren einen Pfadwechsel des Quiz. --}}
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="quiz-outside-pool-hint">
                        <flux:callout.text>
                            {{ __('Some questions are not pool questions of this path. A final quiz should only contain those, the others are missing from the export. Remove the marked questions.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="space-y-2" wire:sort="sortQuestions">
                    @forelse ($this->quizQuestions as $question)
                        <div
                            wire:key="quiz-question-{{ $question->id }}"
                            wire:sort:item="{{ $question->id }}"
                            class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
                            data-test="quiz-question-row"
                        >
                            <div wire:sort:handle class="cursor-grab">
                                <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">{{ $question->prompt }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    <flux:badge size="sm" color="zinc">{{ $question->type->label() }}</flux:badge>
                                    @if (in_array($question->id, $this->questionIdsOutsidePool, true))
                                        <flux:badge size="sm" color="amber" data-test="quiz-question-outside-pool">{{ __('Not in the pool of this path') }}</flux:badge>
                                    @endif
                                </div>
                            </div>

                            <flux:tooltip :content="__('Remove from quiz')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="x-mark"
                                    wire:click="removeQuestion({{ $question->id }})"
                                    data-test="quiz-question-remove"
                                />
                            </flux:tooltip>
                        </div>
                    @empty
                        <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No questions in this quiz yet. Add some from the pool on the left.') }}
                        </flux:text>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <flux:modal name="delete-quiz" focusable class="max-w-lg">
        <form wire:submit="deleteQuiz" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete quiz') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete ":title"?', ['title' => $quiz->title]) }}
                    @if ($this->attemptsCount > 0)
                        {{ trans_choice('{1} :count attempt by apprentices will be deleted as well.|[2,*] :count attempts by apprentices will be deleted as well.', $this->attemptsCount, ['count' => $this->attemptsCount]) }}
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
</section>
