<?php

use App\Actions\Learning\BuildPracticeQuiz;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Der Lehrling stellt sich selbst ein Übungsquiz zusammen (Sprint 5.5).
 *
 * Wie „Mein Fortschritt" (Sprint-5-Entscheidung 7) ohne {user}-Parameter: die
 * Seite baut ausschließlich für Auth::user(), es gibt also nichts zu
 * autorisieren. Die Erdung sitzt in BuildPracticeQuiz, das nur aus
 * freigegebenen Pfaden zieht.
 */
new class extends Component {
    /**
     * Die angehakten Pfade. Livewire liefert Checkbox-Werte als Strings.
     *
     * @var array<int, int|string>
     */
    public array $selectedPathIds = [];

    /** Wie viele Fragen gezogen werden sollen — eine Quelle für beide Einstiege. */
    public int $questionCount = BuildPracticeQuiz::DEFAULT_QUESTION_COUNT;

    /**
     * Vorbelegung über `?paths=1,2` — so kann „Mein Fortschritt" direkt auf
     * die Pfade der eigenen Schwachstellen verlinken. Unbekannte oder nicht
     * freigegebene IDs fallen still heraus.
     *
     * Admins kommen hier nicht an: `EnsureTeamMembership` verzweigt sie im
     * ganzen Lehrling-Bereich auf ihr eigenes Dashboard (Entscheidung 8). Ein
     * Übungsbehälter unter dem Namen eines Admins entsteht damit nie.
     */
    public function mount(): void
    {
        $requested = collect(explode(',', (string) request()->query('paths', '')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter()
            ->all();

        $this->selectedPathIds = array_values(
            array_intersect($this->paths->modelKeys(), $requested),
        );
    }

    /**
     * Die freien Quizze, die einer Gruppe dieses Lehrlings ausgegeben wurden
     * (Sprint 5.6).
     *
     * Sie stehen hier und nicht mehr auf dem Dashboard: das Dashboard zeigt
     * Lernpfade, diese Seite alles zum Üben — vom Ausbildner ausgegeben oder
     * selbst zusammengestellt.
     *
     * @return Collection<int, Quiz>
     */
    #[Computed]
    public function assignedQuizzes(): Collection
    {
        return Quiz::query()
            ->assignedTo(Auth::user())
            ->withCount('questions')
            ->orderBy('title')
            ->get();
    }

    /**
     * Bester abgeschlossener eigener Versuch je ausgegebenem Quiz — eine
     * Abfrage statt einer je Karte.
     *
     * „Bester" wird **zeilenweise** bestimmt (`ROW_NUMBER()` über die Partition
     * `quiz_id`, der Subquery gibt nur Rang 1 heraus), damit `score` und
     * `max_score` aus demselben Versuch stammen — dieselbe Korrektur wie in
     * GroupProgressReport::bestAttemptStats(). Unabhängige `MAX(score)` /
     * `MAX(max_score)` hätten nach einem zwischenzeitlich vergrößerten Quiz aus
     * 8/10 und 7/11 ein 8/11 gemacht statt der tatsächlich besten 8/10.
     *
     * @return BaseCollection<int, QuizAttempt>
     */
    #[Computed]
    public function assignedQuizResults(): BaseCollection
    {
        $quizIds = $this->assignedQuizzes->modelKeys();

        if ($quizIds === []) {
            return collect();
        }

        $ranked = QuizAttempt::query()
            ->select('quiz_id')
            ->selectRaw('score as best_score')
            ->selectRaw('max_score as best_max_score')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY quiz_id ORDER BY score * 1.0 / NULLIF(max_score, 0) DESC, score DESC, completed_at DESC) AS rn')
            ->whereIn('quiz_id', $quizIds)
            ->where('user_id', Auth::id())
            ->whereNotNull('completed_at');

        return QuizAttempt::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->get()
            ->keyBy('quiz_id');
    }

    /**
     * Wie viel dieser Lehrling schon geübt hat.
     *
     * Bewusst eine **Kennzahl statt einer Liste**: jede Runde zieht eine andere
     * Zufallsauswahl, „6 von 8" und „7 von 10" sagen also nichts übereinander —
     * und alle Zeilen hießen „Übungsquiz". Sichtbare Lernzeit motiviert
     * (Merkmal 2), eine Tabelle unvergleichbarer Zeilen nicht.
     *
     * Der Filter auf `user_id` genügt: ein Lehrling kommt nur an seinen eigenen
     * Übungsbehälter (QuizPolicy), fremde Practice-Versuche kann er gar nicht
     * haben.
     *
     * @return array{rounds: int, lastAt: ?Carbon}
     */
    #[Computed]
    public function practiceStats(): array
    {
        $row = QuizAttempt::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('completed_at')
            ->whereHas('quiz', fn (Builder $query) => $query->where('kind', QuizKind::Practice))
            ->selectRaw('COUNT(*) as rounds')
            ->selectRaw('MAX(completed_at) as last_at')
            ->first();

        $lastAt = $row?->getAttribute('last_at');

        return [
            'rounds' => (int) ($row?->getAttribute('rounds') ?? 0),
            'lastAt' => is_string($lastAt) ? Carbon::parse($lastAt) : null,
        ];
    }

    /**
     * Die zur Auswahl stehenden Pfade — nur freigegebene (Konzept e).
     *
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        return LearningPath::query()
            ->visibleToLearners()
            ->orderBy('position')
            ->get();
    }

    /**
     * Wie viele Fragen je Pfad geübt werden können.
     *
     * Aus derselben Action wie die Ziehung, damit die angezeigte Zahl exakt
     * die ist, aus der auch gezogen wird.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function availableCounts(): array
    {
        return app(BuildPracticeQuiz::class)->availableCounts($this->paths->modelKeys());
    }

    /** Wie viele Fragen die aktuelle Auswahl hergibt. */
    #[Computed]
    public function availableForSelection(): int
    {
        $counts = $this->availableCounts;

        return collect($this->normalisedPathIds())
            ->sum(fn (int $pathId): int => $counts[$pathId] ?? 0);
    }

    /**
     * Steht der Umschalter gerade auf „abwählen"?
     *
     * Wahr, sobald jeder ziehbare Pfad angehakt ist — dann ist der nächste
     * sinnvolle Druck das Abräumen, nicht ein weiteres Auswählen.
     */
    #[Computed]
    public function allPathsSelected(): bool
    {
        $selectable = $this->selectablePathIds();

        return $selectable !== []
            && array_diff($selectable, $this->normalisedPathIds()) === [];
    }

    /**
     * Ein Umschalter statt zweier Knöpfe: der erste Druck hakt alle Pfade an,
     * aus denen tatsächlich Fragen gezogen werden können, der zweite räumt die
     * Auswahl wieder ab.
     */
    public function toggleAllPaths(): void
    {
        $this->selectedPathIds = $this->allPathsSelected ? [] : $this->selectablePathIds();

        // Der berechnete Wert oben hängt an der gerade geänderten Auswahl; ohne
        // das Leeren zeigte der Knopf in derselben Request noch das alte Label.
        unset($this->allPathsSelected);

        $this->resetValidation('selectedPathIds');
    }

    /**
     * Die Pfade, aus denen überhaupt gezogen werden kann.
     *
     * Abgeleitet aus `paths` und nicht aus den Schlüsseln von
     * `availableCounts`: die Zähler kommen aus einem `GROUP BY` und stehen
     * damit in der Reihenfolge der Datenbank. So folgt die Auswahl der
     * Reihenfolge, die der Lehrling auf dem Bildschirm sieht.
     *
     * @return array<int, int>
     */
    protected function selectablePathIds(): array
    {
        $counts = $this->availableCounts;

        return $this->paths
            ->filter(fn (LearningPath $path): bool => ($counts[$path->id] ?? 0) > 0)
            ->values()
            ->modelKeys();
    }

    /**
     * Auswahl ziehen und direkt in die Quiz-Durchführung springen.
     */
    public function startPractice(BuildPracticeQuiz $buildPracticeQuiz): void
    {
        $this->validate([
            'selectedPathIds' => ['required', 'array', 'min:1'],
            'selectedPathIds.*' => ['integer'],
            'questionCount' => ['required', 'integer', 'min:1', 'max:'.BuildPracticeQuiz::MAX_QUESTION_COUNT],
        ]);

        if ($this->availableForSelection === 0) {
            $this->addError('selectedPathIds', __('These paths have no questions to practise yet.'));

            return;
        }

        $quiz = $buildPracticeQuiz->handle(
            Auth::user(),
            $this->normalisedPathIds(),
            $this->questionCount,
        );

        $this->redirectRoute('learn.quizzes.show', ['quiz' => $quiz], navigate: true);
    }

    /**
     * Die Auswahl als Integer-Liste.
     *
     * @return array<int, int>
     */
    protected function normalisedPathIds(): array
    {
        return array_values(array_map(intval(...), $this->selectedPathIds));
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Practise'));
    }
}; ?>

<section class="w-full space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('dashboard')"
            wire:navigate
            data-test="back-to-dashboard-button"
        >
            {{ __('Dashboard') }}
        </flux:button>
    </div>

    <div>
        <flux:heading size="xl">{{ __('Practise') }}</flux:heading>
        <flux:subheading>{{ __('What your trainer handed out, and anything you want to drill yourself.') }}</flux:subheading>
    </div>

    {{-- Zuerst das, was der Ausbildner ausgegeben hat: es ist verbindlicher
         als die freiwillige Eigenübung und steht deshalb oben. --}}
    @if ($this->assignedQuizzes->isNotEmpty())
        <div class="space-y-4" data-test="assigned-quizzes-section">
            <div>
                <flux:heading size="lg">{{ __('From your trainer') }}</flux:heading>
                <flux:subheading>{{ __('Quizzes handed out to your group. They belong to no single path.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->assignedQuizzes as $assignedQuiz)
                    @php
                        $result = $this->assignedQuizResults->get($assignedQuiz->id);
                    @endphp

                    <flux:card
                        wire:key="assigned-quiz-{{ $assignedQuiz->id }}"
                        class="flex flex-col gap-4"
                        data-test="assigned-quiz-card"
                    >
                        <div class="space-y-2">
                            <flux:heading size="lg">{{ $assignedQuiz->title }}</flux:heading>

                            @if (filled($assignedQuiz->description))
                                <flux:text class="line-clamp-3">{{ $assignedQuiz->description }}</flux:text>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" color="zinc" icon="question-mark-circle">
                                {{ trans_choice('{1} :count question|[2,*] :count questions', $assignedQuiz->questions_count, ['count' => $assignedQuiz->questions_count]) }}
                            </flux:badge>

                            @if ($result !== null)
                                {{-- Amber statt rot, wie überall: ein Versuch ist eine
                                     Zwischenstation (Merkmal 3). --}}
                                <flux:badge
                                    size="sm"
                                    :color="$result->best_score === $result->best_max_score ? 'green' : 'amber'"
                                    data-test="assigned-quiz-result"
                                >
                                    {{ __('Best: :score of :max', [
                                        'score' => $result->best_score,
                                        'max' => $result->best_max_score,
                                    ]) }}
                                </flux:badge>
                            @endif
                        </div>

                        <flux:button
                            variant="primary"
                            icon="play"
                            :href="route('learn.quizzes.show', $assignedQuiz)"
                            wire:navigate
                            class="mt-auto w-full"
                            data-test="assigned-quiz-button"
                        >
                            {{ $result !== null ? __('Try again') : __('Start quiz') }}
                        </flux:button>
                    </flux:card>
                @endforeach
            </div>
        </div>

        <flux:separator />
    @endif

    <div>
        <flux:heading size="lg">{{ __('Put together a practice quiz') }}</flux:heading>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <flux:badge size="sm" color="zinc">{{ __('Practice quiz') }}</flux:badge>
            <flux:badge size="sm" color="zinc" icon="arrow-path">{{ __('Drawn at random') }}</flux:badge>
        </div>

        {{-- Geleistete Übung sichtbar machen (Merkmal 2) — als Kennzahl, nicht
             als Liste: die Runden sind untereinander nicht vergleichbar. --}}
        @if ($this->practiceStats['rounds'] > 0)
            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400" data-test="practice-history">
                {{ trans_choice(
                    '{1} :count practice round so far|[2,*] :count practice rounds so far',
                    $this->practiceStats['rounds'],
                    ['count' => $this->practiceStats['rounds']],
                ) }}@if ($this->practiceStats['lastAt'] !== null), {{ __('last on :date', ['date' => $this->practiceStats['lastAt']->format('d.m.Y')]) }}@endif
            </flux:text>
        @endif
    </div>

    @if ($this->paths->isEmpty())
        <flux:callout color="zinc" icon="information-circle" data-test="practice-empty-state">
            <flux:callout.text>{{ __('No learning paths have been released yet.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="startPractice">
            {{-- Zweispaltig wie der Quiz-Builder im Adminbereich: links die
                 Auswahl, rechts die Einstellungen samt Abschluss-Knopf. --}}
            <div class="grid gap-8 lg:grid-cols-2">
                {{-- Links: die Themen --}}
                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <flux:heading size="lg">{{ __('Topics') }}</flux:heading>
                            <flux:subheading>
                                {{ __('Pick the topics you want to drill. The questions are drawn at random, a new set every time.') }}
                            </flux:subheading>
                        </div>

                        @if (array_sum($this->availableCounts) > 0)
                            <flux:button
                                type="button"
                                variant="subtle"
                                size="sm"
                                :icon="$this->allPathsSelected ? 'x-mark' : 'check'"
                                wire:click="toggleAllPaths"
                                wire:loading.attr="disabled"
                                wire:target="toggleAllPaths"
                                class="shrink-0"
                                data-test="select-all-practice-paths-button"
                            >
                                {{ $this->allPathsSelected ? __('Deselect all') : __('Select all') }}
                            </flux:button>
                        @endif
                    </div>

                    <flux:checkbox.group wire:model.live="selectedPathIds" data-test="practice-paths-group">
                        <div class="space-y-2">
                            @foreach ($this->paths as $path)
                                @php
                                    $available = $this->availableCounts[$path->id] ?? 0;
                                    $isChosen = in_array($path->id, array_map('intval', $this->selectedPathIds), true);
                                @endphp

                                <div
                                    wire:key="practice-path-{{ $path->id }}"
                                    class="flex items-center gap-3 rounded-lg border p-3 {{ $available === 0 ? 'border-zinc-200 opacity-50 dark:border-zinc-700' : ($isChosen ? 'border-[var(--color-accent)]/50 bg-[var(--color-accent)]/5' : 'border-zinc-200 dark:border-zinc-700') }}"
                                    data-test="practice-path-row"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-medium">{{ $path->title }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            <flux:badge size="sm" color="zinc">
                                                {{ trans_choice('{0} no questions yet|{1} :count question|[2,*] :count questions', $available, ['count' => $available]) }}
                                            </flux:badge>
                                        </div>
                                    </div>

                                    <flux:checkbox
                                        :value="$path->id"
                                        :disabled="$available === 0"
                                        data-test="practice-path-checkbox"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </flux:checkbox.group>

                    <flux:error name="selectedPathIds" />
                </div>

                {{-- Rechts: die Einstellungen --}}
                <div class="space-y-6">
                    <div class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:input
                            wire:model="questionCount"
                            type="number"
                            min="1"
                            :max="\App\Actions\Learning\BuildPracticeQuiz::MAX_QUESTION_COUNT"
                            :label="__('Number of questions')"
                            :description="__('Fewer questions than that available? Then you simply get all of them.')"
                            data-test="practice-count-input"
                        />

                        <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                            <flux:text class="text-sm">{{ __('Available in your selection') }}</flux:text>
                            <flux:badge
                                size="sm"
                                :color="$this->availableForSelection > 0 ? 'zinc' : 'amber'"
                                data-test="practice-available-hint"
                            >
                                {{ trans_choice('{0} no questions yet|{1} :count question|[2,*] :count questions', $this->availableForSelection, ['count' => $this->availableForSelection]) }}
                            </flux:badge>
                        </div>

                        <flux:button
                            variant="primary"
                            icon="play"
                            type="submit"
                            class="w-full"
                            data-test="start-practice-button"
                        >
                            {{ __('Start practice quiz') }}
                        </flux:button>
                    </div>

                    <flux:callout color="zinc" icon="light-bulb">
                        <flux:callout.text>
                            {{ __('No pass mark here: you practise, you see the answer, you move on.') }}
                        </flux:callout.text>
                    </flux:callout>
                </div>
            </div>
        </form>
    @endif
</section>
