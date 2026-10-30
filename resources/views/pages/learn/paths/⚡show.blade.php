<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public LearningPath $learningPath;

    /**
     * Teilnehmer_innen sehen nur freigegebene Pfade (403 sonst); Admins passieren
     * Gate::before und können den Pfad per Direktlink vorschauen — die Listen
     * laufen über den visibleTo-Scope (Entscheidung 8, Gruppen ab #9).
     */
    public function mount(LearningPath $learningPath): void
    {
        Gate::authorize('view', $learningPath);

        $this->learningPath = $learningPath;
    }

    /**
     * Lektionen in Lesereihenfolge, jede mit dem eigenen Erledigt-Status —
     * withExists hängt das als Sub-Select an dieselbe Abfrage.
     *
     * @return Collection<int, Lesson>
     */
    #[Computed]
    public function lessons(): Collection
    {
        $userId = Auth::id();

        return $this->learningPath->lessons()
            ->withExists([
                'completions as is_completed' => fn (Builder $query) => $query->where('user_id', $userId),
            ])
            ->get();
    }

    /**
     * Die nächste offene Lektion; ist alles erledigt (oder nichts begonnen),
     * die erste.
     */
    #[Computed]
    public function nextLesson(): ?Lesson
    {
        return $this->lessons->firstWhere('is_completed', false) ?? $this->lessons->first();
    }

    /**
     * Das Abschluss-Quiz, sofern es für diesen Nutzer sichtbar ist.
     */
    #[Computed]
    public function finalQuiz(): ?Quiz
    {
        $quiz = $this->learningPath->finalQuiz()->withCount('questions')->first();

        if ($quiz === null) {
            return null;
        }

        // Der Policy den bereits geladenen Pfad mitgeben, statt ihn
        // nachladen zu lassen.
        $quiz->setRelation('learningPath', $this->learningPath);

        return Auth::user()?->can('view', $quiz) ? $quiz : null;
    }

    /**
     * Der jüngste abgeschlossene eigene Versuch auf dem Abschluss-Quiz.
     * Attempt-Abfragen sind ausnahmslos auf den eigenen Nutzer gescoped
     * (Entscheidung 8) — es gibt bewusst keine Policy für Versuche.
     */
    #[Computed]
    public function lastAttempt(): ?QuizAttempt
    {
        if ($this->finalQuiz === null) {
            return null;
        }

        return QuizAttempt::query()
            ->where('quiz_id', $this->finalQuiz->id)
            ->where('user_id', Auth::id())
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first();
    }

    /**
     * Ein noch laufender eigener Versuch (Entscheidung 2), falls vorhanden.
     */
    #[Computed]
    public function openAttempt(): ?QuizAttempt
    {
        if ($this->finalQuiz === null) {
            return null;
        }

        return QuizAttempt::query()
            ->where('quiz_id', $this->finalQuiz->id)
            ->where('user_id', Auth::id())
            ->whereNull('completed_at')
            ->latest('started_at')
            ->first();
    }

    public function render(): mixed
    {
        return $this->view()->title($this->learningPath->title);
    }
}; ?>

@php
    $completed = $this->lessons->where('is_completed', true)->count();
    $total = $this->lessons->count();
    $percent = $total > 0 ? intdiv($completed * 100, $total) : 0;
    $quiz = $this->finalQuiz;
@endphp

<section class="w-full max-w-3xl space-y-8">
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

    <div class="space-y-3">
        <flux:heading size="xl">{{ $learningPath->title }}</flux:heading>

        @if (filled($learningPath->summary))
            <flux:subheading>{{ $learningPath->summary }}</flux:subheading>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if (filled($learningPath->difficulty))
                <flux:badge size="sm" color="zinc">{{ Str::ucfirst($learningPath->difficulty) }}</flux:badge>
            @endif

            @if ($learningPath->estimated_minutes !== null)
                <flux:badge size="sm" color="zinc" icon="clock">
                    {{ __('approx. :count min', ['count' => $learningPath->estimated_minutes]) }}
                </flux:badge>
            @endif
        </div>

        <div class="space-y-2 pt-2">
            <flux:progress :value="$percent" />
            <flux:text class="text-sm" data-test="path-progress-label">
                {{ __(':done of :total lessons', ['done' => $completed, 'total' => $total]) }}
            </flux:text>
        </div>
    </div>

    @if (filled($learningPath->objectives))
        {{-- Konzept h, Merkmale 5 und 9: sinnstiftend kommunizieren und
             Erwartungen transparent machen. --}}
        <flux:card data-test="path-objectives">
            <flux:heading size="lg">{{ __('What you will be able to do') }}</flux:heading>
            <ul class="mt-3 space-y-2">
                @foreach ($learningPath->objectives as $objective)
                    <li class="flex items-start gap-2">
                        <flux:icon name="check" variant="mini" class="mt-0.5 shrink-0 text-green-600 dark:text-green-400" />
                        <flux:text>{{ $objective }}</flux:text>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    <div class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ __('Lessons') }}</flux:heading>

            @if ($this->nextLesson !== null)
                <flux:button
                    variant="primary"
                    icon="play"
                    :href="route('learn.lessons.show', [$learningPath, $this->nextLesson])"
                    wire:navigate
                    data-test="continue-learning-button"
                >
                    {{-- Beim ersten Besuch ist noch nichts erledigt: dann wird gestartet,
                         nicht weitergelernt (Wortlaut wie auf dem Dashboard). --}}
                    {{ $completed === 0 ? __('Start') : __('Keep learning') }}
                </flux:button>
            @endif
        </div>

        @forelse ($this->lessons as $lesson)
            <div
                wire:key="lesson-{{ $lesson->id }}"
                class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
                data-test="lesson-row"
            >
                @if ($lesson->is_completed)
                    <flux:icon name="check-circle" class="size-6 shrink-0 text-green-600 dark:text-green-400" data-test="lesson-completed-icon" />
                @else
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                        {{ $loop->iteration }}
                    </span>
                @endif

                <flux:text class="flex-1 font-medium">{{ $lesson->title }}</flux:text>

                <flux:button
                    variant="ghost"
                    size="sm"
                    :href="route('learn.lessons.show', [$learningPath, $lesson])"
                    wire:navigate
                    data-test="lesson-open-button"
                >
                    {{ $lesson->is_completed ? __('Repeat') : __('Read') }}
                </flux:button>
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="lessons-empty-state">
                {{ __('This path has no lessons yet.') }}
            </flux:text>
        @endforelse
    </div>

    @if ($quiz !== null)
        @php
            $questionCount = $quiz->questions_count;
            $needed = $quiz->passingScore($questionCount);
        @endphp

        <flux:card class="space-y-4" data-test="final-quiz-card">
            <div>
                <flux:heading size="lg">{{ $quiz->title }}</flux:heading>
                <flux:subheading>
                    {{ trans_choice('{1} :count question|[2,*] :count questions', $questionCount, ['count' => $questionCount]) }}
                </flux:subheading>
            </div>

            {{-- Maßgeblich ist die Schwelle des Quiz, nicht die des Pfades
                 (Entscheidung 7) — sonst stünde hier eine andere Zahl als auf
                 der Ergebnisseite. --}}
            <flux:text data-test="pass-rule">
                {{ __('To pass you need :percent %, that is :needed of :total questions.', [
                    'percent' => $quiz->pass_threshold,
                    'needed' => $needed,
                    'total' => $questionCount,
                ]) }}
            </flux:text>

            @if ($this->lastAttempt !== null)
                <flux:callout
                    :color="$this->lastAttempt->passed ? 'green' : 'amber'"
                    :icon="$this->lastAttempt->passed ? 'check-circle' : 'arrow-path'"
                    data-test="last-attempt"
                >
                    <flux:callout.text>
                        {{ __('Your last attempt: :score of :max correct (:percent %), :verdict', [
                            'score' => $this->lastAttempt->score,
                            'max' => $this->lastAttempt->max_score,
                            'percent' => $this->lastAttempt->percent,
                            'verdict' => $this->lastAttempt->passed ? __('passed') : __('not passed'),
                        ]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:button
                variant="primary"
                icon="clipboard-document-check"
                :href="route('learn.quizzes.show', $quiz)"
                wire:navigate
                data-test="start-quiz-button"
            >
                @if ($this->openAttempt !== null)
                    {{ __('Resume attempt') }}
                @elseif ($this->lastAttempt !== null)
                    {{ __('Try again') }}
                @else
                    {{ __('Start quiz') }}
                @endif
            </flux:button>

            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('You may repeat the quiz as often as you like.') }}
            </flux:text>
        </flux:card>
    @else
        <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="no-final-quiz-hint">
            {{ __('There is no final quiz for this path yet.') }}
        </flux:text>
    @endif
</section>
