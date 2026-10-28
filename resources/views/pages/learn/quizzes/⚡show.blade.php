<?php

use App\Actions\Learning\FinalizeAttempt;
use App\Actions\Learning\RecordAnswer;
use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Quiz $quiz;

    /** Nullbasierter Index der aktuellen Frage. */
    public int $index = 0;

    /**
     * Auswahl der aktuellen Frage (Option-IDs) — für alle Fragetypen ein
     * Array, Einfachauswahl bindet auf dessen ersten Platz.
     *
     * @var array<int, int|string>
     */
    public array $selection = [];

    /** Solange false, steht die Einstiegsansicht. */
    public bool $started = false;

    /**
     * Die Fragen-IDs in der Reihenfolge **dieses** Durchgangs; leer heißt
     * „Pivot-Reihenfolge des Quiz" (Sprint 5.5).
     *
     * Der Versuch entsteht erst mit der ersten Antwort (Sprint-4-Entscheidung 2),
     * die Reihenfolge muss aber schon beim Start feststehen — sonst würden sich
     * die Fragen eines gemischten Quiz nach der ersten Antwort neu sortieren.
     * Sie lebt deshalb bis dahin hier und wandert dann in den Versuch.
     *
     * Unbedenklich im Snapshot: reine Frage-IDs, die ohnehin im Markup stehen —
     * die is_correct-Flags bleiben wie gehabt draußen (Entscheidung 5).
     *
     * @var array<int, int>
     */
    public array $questionOrder = [];

    /** Der finalisierte Versuch der Ergebnisansicht, sonst null. */
    public ?int $resultAttemptId = null;

    /**
     * Das Öffnen der Seite legt **keinen** Versuch an (Entscheidung 2) — es
     * lädt höchstens einen offenen und setzt den Einstieg auf die erste
     * unbeantwortete Frage.
     */
    public function mount(Quiz $quiz): void
    {
        Gate::authorize('view', $quiz);

        $this->quiz = $quiz;

        if ($this->openAttempt !== null) {
            // Erst die Reihenfolge, dann der Index: firstUnansweredIndex()
            // zählt über die Fragen *in dieser* Reihenfolge.
            $this->questionOrder = $this->openAttempt->question_order ?? [];
            $this->index = $this->firstUnansweredIndex();
        }
    }

    /**
     * Den Versuch beginnen oder fortsetzen.
     */
    public function start(): void
    {
        Gate::authorize('view', $this->quiz);

        // Die Reihenfolge steht ab hier fest — auch bevor der Versuch existiert.
        if ($this->questionOrder === []) {
            $this->questionOrder = $this->resolveQuestionOrder();

            unset($this->questions, $this->currentQuestion);
        }

        if ($this->questions->isEmpty()) {
            return;
        }

        $this->index = $this->openAttempt !== null ? $this->firstUnansweredIndex() : 0;
        $this->started = true;

        $this->syncSelection();
    }

    /**
     * Die aktuelle Frage beantworten — serverseitig ausgewertet.
     */
    public function submitAnswer(RecordAnswer $recordAnswer): void
    {
        Gate::authorize('view', $this->quiz);

        $question = $this->currentQuestion;

        if ($question === null || $this->responses->has($question->id)) {
            return;
        }

        $selection = $question->sanitizeSelection($this->selection);

        if ($selection === null) {
            $this->addError('selection', __('Please choose an answer.'));

            return;
        }

        $this->resetErrorBag('selection');

        $recordAnswer->handle(
            Auth::user(),
            $this->quiz,
            $question,
            $selection,
            $this->openAttempt,
            $this->questionOrder,
        );

        unset($this->openAttempt, $this->responses);
    }

    /**
     * Zur nächsten Frage — hinter der letzten wird der Versuch gewertet.
     */
    public function next(FinalizeAttempt $finalizeAttempt): void
    {
        Gate::authorize('view', $this->quiz);

        if ($this->index + 1 < $this->questions->count()) {
            $this->index++;
            $this->syncSelection();

            return;
        }

        $attempt = $this->openAttempt;

        if ($attempt === null) {
            return;
        }

        $this->resultAttemptId = $finalizeAttempt->handle($attempt)->id;

        unset($this->openAttempt, $this->responses);
    }

    /**
     * Zurückblättern. Beantwortete Fragen erscheinen nur lesend — abgegebene
     * Antworten sind endgültig (Entscheidung 1).
     */
    public function previous(): void
    {
        if ($this->index > 0) {
            $this->index--;
            $this->syncSelection();
        }
    }

    /**
     * Von der Ergebnisansicht zurück auf den Einstieg. Der neue Versuch
     * entsteht wieder erst mit der ersten Antwort — Wiederholungen sind
     * unbegrenzt (Merkmal 3).
     */
    public function restart(): void
    {
        // Auch die Reihenfolge fällt: der nächste Durchgang eines gemischten
        // Quiz soll anders gemischt sein.
        $this->reset('index', 'selection', 'started', 'resultAttemptId', 'questionOrder');

        unset($this->openAttempt, $this->responses, $this->questions, $this->currentQuestion);
    }

    /**
     * Die Fragen dieses Durchgangs mit ihren Optionen, in seiner Reihenfolge.
     *
     * Ausschließlich #[Computed], nie public: Livewire serialisiert public
     * Properties in den Snapshot, und der steht im HTML — die
     * is_correct-Flags dürfen dort nicht landen (Entscheidung 5).
     *
     * Steht eine eigene Reihenfolge fest, werden die Fragen **direkt** über
     * ihre IDs geladen statt über den Quiz-Pivot: nur so ist der Versuch
     * wirklich vom veränderlichen Quiz entkoppelt (Sprint 5.5). Fehlende
     * Fragen (zwischenzeitlich gelöscht) fallen dabei einfach heraus — die
     * Wertung in FinalizeAttempt filtert genauso.
     *
     * @return Collection<int, Question>
     */
    #[Computed]
    public function questions(): Collection
    {
        if ($this->questionOrder === []) {
            return $this->quiz->questions()->with('options')->get();
        }

        $positions = array_flip($this->questionOrder);

        return Question::query()
            ->whereIn('id', $this->questionOrder)
            ->with('options')
            ->get()
            ->sortBy(fn (Question $question): int => $positions[$question->id])
            ->values();
    }

    /**
     * Die Reihenfolge, in der dieser Durchgang die Fragen zeigt.
     *
     * @return array<int, int>
     */
    protected function resolveQuestionOrder(): array
    {
        if (! $this->quiz->freezesQuestionOrder()) {
            return [];
        }

        $ids = $this->quiz->questions()->pluck('questions.id')->all();

        if ($this->quiz->shuffle_questions) {
            shuffle($ids);
        }

        return $ids;
    }

    #[Computed]
    public function currentQuestion(): ?Question
    {
        return $this->questions->get($this->index);
    }

    /**
     * Der laufende eigene Versuch. Attempt-Abfragen sind ausnahmslos auf
     * Auth::id() gescoped (Entscheidung 8) — ein fremder offener Versuch
     * wird deshalb nie übernommen.
     */
    #[Computed]
    public function openAttempt(): ?QuizAttempt
    {
        return QuizAttempt::query()
            ->where('quiz_id', $this->quiz->id)
            ->where('user_id', Auth::id())
            ->whereNull('completed_at')
            ->latest('started_at')
            ->first();
    }

    /**
     * Die Antworten des laufenden Versuchs, nach Frage-ID.
     *
     * @return BaseCollection<int, QuestionResponse>
     */
    #[Computed]
    public function responses(): BaseCollection
    {
        return $this->openAttempt?->responses()->get()->keyBy('question_id') ?? collect();
    }

    /**
     * Die Antworten des abgeschlossenen Versuchs der Ergebnisansicht.
     *
     * @return BaseCollection<int, QuestionResponse>
     */
    #[Computed]
    public function resultResponses(): BaseCollection
    {
        return $this->resultAttempt?->responses()->get()->keyBy('question_id') ?? collect();
    }

    #[Computed]
    public function resultAttempt(): ?QuizAttempt
    {
        if ($this->resultAttemptId === null) {
            return null;
        }

        return QuizAttempt::query()
            ->where('user_id', Auth::id())
            ->whereKey($this->resultAttemptId)
            ->first();
    }

    /**
     * Die falsch (oder gar nicht) beantworteten Fragen des Ergebnisses.
     *
     * @return Collection<int, Question>
     */
    #[Computed]
    public function wrongQuestions(): Collection
    {
        return $this->questions->reject(
            fn (Question $question): bool => (bool) $this->resultResponses->get($question->id)?->is_correct,
        );
    }

    /**
     * Index der ersten noch unbeantworteten Frage; sind alle beantwortet,
     * die letzte (dort finalisiert „Weiter" den Versuch).
     */
    protected function firstUnansweredIndex(): int
    {
        foreach ($this->questions as $index => $question) {
            if (! $this->responses->has($question->id)) {
                return $index;
            }
        }

        return max(0, $this->questions->count() - 1);
    }

    /**
     * Die Anzeige einer bereits beantworteten Frage zeigt die damalige
     * Auswahl; eine offene Frage startet leer.
     */
    protected function syncSelection(): void
    {
        $response = $this->currentQuestion === null
            ? null
            : $this->responses->get($this->currentQuestion->id);

        $this->selection = $response?->selected_option_ids ?? [];

        $this->resetErrorBag('selection');
    }

    public function render(): mixed
    {
        return $this->view()->title($this->quiz->title);
    }
}; ?>

@php
    $total = $this->questions->count();
    $isPractice = $quiz->kind === App\Enums\QuizKind::Practice;

    // Aus einem Übungsquiz führt der Weg zurück zum Builder — dort zieht der
    // Lehrling die nächste Auswahl.
    $backRoute = match (true) {
        $isPractice => route('learn.practice.create'),
        $quiz->learningPath !== null => route('learn.paths.show', $quiz->learningPath),
        default => route('dashboard'),
    };
    $backLabel = match (true) {
        $isPractice => __('Practise'),
        $quiz->learningPath !== null => $quiz->learningPath->title,
        default => __('Dashboard'),
    };
@endphp

<section class="w-full max-w-2xl space-y-8">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="$backRoute" wire:navigate data-test="back-button">
            {{ $backLabel }}
        </flux:button>
    </div>

    @if ($this->resultAttempt !== null)
        @php
            $attempt = $this->resultAttempt;
            $percent = $attempt->percent;
        @endphp

        <div class="space-y-6" data-test="quiz-result">
            <flux:heading size="xl">{{ $quiz->title }}</flux:heading>

            {{-- Farbsemantik Konzept g: nicht bestanden ist amber, nie rot.
                 Ein Übungsquiz kennt gar kein Urteil (Entscheidung E2) — es
                 zeigt nur den Stand, damit Üben nicht wie Prüfen wirkt. --}}
            <flux:callout
                :color="! $quiz->reportsVerdict() ? 'blue' : ($attempt->passed ? 'green' : 'amber')"
                :icon="! $quiz->reportsVerdict() ? 'academic-cap' : ($attempt->passed ? 'check-circle' : 'arrow-path')"
                data-test="result-verdict"
            >
                @if ($quiz->reportsVerdict())
                    <flux:callout.heading>
                        {{ $attempt->passed ? __('Passed!') : __('Not passed yet.') }}
                    </flux:callout.heading>
                @else
                    <flux:callout.heading>{{ __('Practice round done.') }}</flux:callout.heading>
                @endif

                <flux:callout.text>
                    {{ __(':score of :max correct (:percent %).', [
                        'score' => $attempt->score,
                        'max' => $attempt->max_score,
                        'percent' => $percent,
                    ]) }}
                    @if ($quiz->reportsVerdict())
                        {{ __('To pass you need :percent %.', ['percent' => $quiz->pass_threshold]) }}
                    @endif
                </flux:callout.text>
            </flux:callout>

            @if ($this->wrongQuestions->isNotEmpty())
                <x-learn.result-actions
                    :is-practice="$isPractice"
                    :back-route="$backRoute"
                    :back-label="$backLabel"
                    test-name="result-actions-top"
                />
            @endif

            @if ($this->wrongQuestions->isNotEmpty())
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('What is worth reviewing') }}</flux:heading>

                    @foreach ($this->wrongQuestions as $question)
                        <flux:card wire:key="wrong-{{ $question->id }}">
                            <x-learn.question
                                :question="$question"
                                :selected="$this->resultResponses->get($question->id)?->selected_option_ids ?? []"
                                :result="false"
                                :disabled="true"
                                :reveal-correct="true"
                            />
                        </flux:card>
                    @endforeach
                </div>
            @endif

            <x-learn.result-actions
                :is-practice="$isPractice"
                :back-route="$backRoute"
                :back-label="$backLabel"
                test-name="result-actions-bottom"
            />
        </div>
    @elseif (! $started)
        <div class="space-y-6" data-test="quiz-intro">
            <div>
                <flux:heading size="xl">{{ $quiz->title }}</flux:heading>
                @if (filled($quiz->description))
                    <flux:subheading>{{ $quiz->description }}</flux:subheading>
                @endif
            </div>

            @if ($total === 0)
                {{-- Kantenfall: kein Start, kein Versuch, keine Division durch null. --}}
                <flux:callout color="zinc" icon="information-circle" data-test="quiz-empty-state">
                    <flux:callout.text>{{ __('This quiz has no questions yet.') }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:card class="space-y-3">
                    <flux:text>
                        {{ trans_choice('{1} :count question|[2,*] :count questions', $total, ['count' => $total]) }}
                    </flux:text>

                    @if ($quiz->reportsVerdict())
                        <flux:text data-test="pass-rule">
                            {{ __('To pass you need :percent %, that is :needed of :total questions.', [
                                'percent' => $quiz->pass_threshold,
                                'needed' => $quiz->passingScore($total),
                                'total' => $total,
                            ]) }}
                        </flux:text>
                    @else
                        {{-- Entscheidung E2: keine Schwelle beim Üben. --}}
                        <flux:text data-test="practice-rule">
                            {{ __('No pass mark here: you practise, you see the answer, you move on.') }}
                        </flux:text>
                    @endif

                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('You may repeat the quiz as often as you like.') }}
                    </flux:text>

                    @if ($this->openAttempt !== null)
                        <flux:callout color="blue" icon="play" data-test="resume-hint">
                            <flux:callout.text>
                                {{ __('You have answered :done of :total questions.', [
                                    'done' => $this->responses->count(),
                                    'total' => $total,
                                ]) }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                </flux:card>

                <flux:button variant="primary" icon="play" wire:click="start" data-test="start-quiz-button">
                    {{ $this->openAttempt !== null ? __('Resume attempt') : __('Start quiz') }}
                </flux:button>
            @endif
        </div>
    @else
        @php
            $question = $this->currentQuestion;
            $response = $question === null ? null : $this->responses->get($question->id);
        @endphp

        <div class="space-y-6" data-test="quiz-question">
            <div class="space-y-2">
                <flux:progress :value="intdiv(($this->index + 1) * 100, max($total, 1))" />
            </div>

            @if ($question !== null)
                <flux:card wire:key="question-{{ $question->id }}" class="space-y-4">
                    <x-learn.question
                        :question="$question"
                        model="selection"
                        :result="$response?->is_correct"
                        :disabled="$response !== null"
                        :number="$this->index + 1"
                        :total="$total"
                    >
                        <flux:error name="selection" />
                    </x-learn.question>
                </flux:card>
            @endif

            <div class="flex items-center justify-between gap-4">
                <div>
                    @if ($this->index > 0)
                        <flux:button size="sm" icon="arrow-left" wire:click="previous" data-test="previous-question-button">
                            {{ __('Back') }}
                        </flux:button>
                    @endif
                </div>

                <div>
                    @if ($response === null)
                        <flux:button variant="primary" wire:click="submitAnswer" data-test="submit-answer-button">
                            {{ __('Submit answer') }}
                        </flux:button>
                    @else
                        <flux:button variant="primary" icon-trailing="arrow-right" wire:click="next" data-test="next-question-button">
                            {{ $this->index + 1 < $total ? __('Next') : __('Show result') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>
