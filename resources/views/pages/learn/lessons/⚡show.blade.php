<?php

use App\Actions\Learning\EvaluateAnswer;
use App\Actions\Learning\LinkGlossaryTerms;
use App\Enums\LessonBlockType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\Quiz;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public LearningPath $learningPath;

    public Lesson $lesson;

    /**
     * Der offene Abschnitt steht in der Adresse, damit Browser-Zurück und
     * geteilte Links funktionieren (Entscheidung 9). Manipulierte Werte
     * werden auf den gültigen Bereich geklemmt, nie abgewiesen.
     */
    #[Url(as: 'step', except: 1)]
    public int $step = 1;

    /**
     * Auswahl je Zwischen-Check (Frage-ID → Option-IDs). Zwischen-Checks
     * bleiben flüchtig (Entscheidung 10): sie leben nur im Komponenten-
     * Zustand. Eine `question_responses`-Zeile bräuchte zwingend einen
     * `quiz_attempt_id`, und ein Attempt für Lernhilfen würde die Auswertung
     * verfälschen — Konzept f: Zwischen-Checks zählen nicht in die Wertung.
     *
     * @var array<int, array<int, int|string>>
     */
    public array $checkSelection = [];

    /**
     * Ergebnis je Zwischen-Check (Frage-ID → richtig?), ebenfalls flüchtig.
     *
     * @var array<int, bool>
     */
    public array $checkResult = [];

    /**
     * Lektionen erben die Sichtbarkeit ihres Pfades — eine eigene
     * LessonPolicy würde nur delegieren (Entscheidung 8). Die Route bindet
     * scoped, eine Lektion eines fremden Pfades ergibt also schon 404.
     */
    public function mount(LearningPath $learningPath, Lesson $lesson): void
    {
        Gate::authorize('view', $learningPath);

        $this->learningPath = $learningPath;
        $this->lesson = $lesson;
        $this->step = $this->clampStep($this->step);
    }

    public function updatedStep(): void
    {
        $this->step = $this->clampStep($this->step);
    }

    public function previousStep(): void
    {
        $this->step = $this->clampStep($this->step - 1);
    }

    public function nextStep(): void
    {
        $this->step = $this->clampStep($this->step + 1);
    }

    /**
     * Einen Zwischen-Check auswerten — ohne Attempt, ohne Response.
     *
     * Dieselbe Scoring-Wahrheit wie das Quiz (Entscheidung 4); der
     * Unterschied ist ausschließlich die fehlende Persistenz.
     */
    public function submitCheck(EvaluateAnswer $evaluateAnswer, int $questionId): void
    {
        Gate::authorize('view', $this->learningPath);

        $question = $this->checkQuestions->firstWhere('id', $questionId);

        abort_if($question === null, 404);

        $selection = $question->sanitizeSelection($this->checkSelection[$questionId] ?? []);

        if ($selection === null) {
            $this->addError("checkSelection.{$questionId}", __('Please choose an answer.'));

            return;
        }

        $this->resetErrorBag("checkSelection.{$questionId}");

        $this->checkResult[$questionId] = $evaluateAnswer->handle($question, $selection);
    }

    /**
     * Einen Zwischen-Check zurücksetzen — Lernhilfen dürfen beliebig oft
     * wiederholt werden (Merkmale 3 und 8), es hängt keine Wertung daran.
     */
    public function retryCheck(int $questionId): void
    {
        unset($this->checkResult[$questionId], $this->checkSelection[$questionId]);

        $this->resetErrorBag("checkSelection.{$questionId}");
    }

    /**
     * Die Zwischen-Check-Fragen der Lektion.
     *
     * Ausschließlich als #[Computed], nie als public Property: Livewire
     * serialisiert public Properties in den Snapshot, und der steht im HTML —
     * die is_correct-Flags der Optionen dürfen dort nicht landen
     * (Entscheidung 5).
     *
     * @return Collection<int, Question>
     */
    #[Computed]
    public function checkQuestions(): Collection
    {
        return $this->lesson->questions()->with('options')->get();
    }

    /**
     * Die Lektion als erledigt vermerken und weiterleiten.
     *
     * updateOrCreate ist idempotent — mehrfaches Klicken erzeugt keine
     * zweite Zeile; der Unique-Index ist die letzte Verteidigung.
     */
    public function completeLesson(): void
    {
        Gate::authorize('view', $this->learningPath);

        LessonCompletion::updateOrCreate(
            ['user_id' => Auth::id(), 'lesson_id' => $this->lesson->id],
            ['completed_at' => now()],
        );

        Flux::toast(variant: 'success', text: __('Lesson completed.'));

        if ($this->nextLesson !== null) {
            $this->redirectRoute(
                'learn.lessons.show',
                ['learningPath' => $this->learningPath, 'lesson' => $this->nextLesson],
                navigate: true,
            );

            return;
        }

        if ($this->finalQuiz !== null) {
            $this->redirectRoute('learn.quizzes.show', ['quiz' => $this->finalQuiz], navigate: true);

            return;
        }

        $this->redirectRoute('learn.paths.show', ['learningPath' => $this->learningPath], navigate: true);
    }

    /**
     * Die kompletten Blöcke der Lektion, an jedem Abschnitts-Trenner zu
     * Schritten gruppiert (Entscheidung 9).
     *
     * Unvollständige Blöcke werden verworfen (Review-Vormerkung N3): „Block
     * hinzufügen" legt shape-konforme, aber leere Nutzlasten an; der Editor
     * markiert sie als „Unvollständig", der Reader zeigt sie nicht. Der
     * Trenner selbst wird nicht gerendert, sondern als Grenze konsumiert —
     * sein Titel wird die Abschnitts-Unterüberschrift. Eine Lektion ohne
     * Trenner ergibt einen Schritt (abwärtskompatibel).
     *
     * @return array<int, array{title: string|null, blocks: array<int, LessonBlock>}>
     */
    #[Computed]
    public function steps(): array
    {
        $steps = [];
        $current = ['title' => null, 'blocks' => []];

        foreach ($this->lesson->blocks()->get() as $block) {
            if (! $block->isComplete()) {
                continue;
            }

            if ($block->type === LessonBlockType::SectionBreak) {
                if ($current['blocks'] !== []) {
                    $steps[] = $current;
                }

                $current = ['title' => $block->content['title'] ?? null, 'blocks' => []];

                continue;
            }

            $current['blocks'][] = $block;
        }

        if ($current['blocks'] !== []) {
            $steps[] = $current;
        }

        // Leere Lektion: ein Schritt, damit Stepper und Abschluss-Button
        // eine Bezugsgröße haben (die Ansicht zeigt dann den Hinweis).
        return $steps === [] ? [['title' => null, 'blocks' => []]] : $steps;
    }

    /**
     * Die Glossar-Begriffe der Lektion, gesammelt aus ihren kompletten
     * Glossar-Blöcken (Entscheidung 11).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function glossaryTerms(): array
    {
        $terms = [];

        foreach ($this->steps as $step) {
            foreach ($step['blocks'] as $block) {
                if ($block->type !== LessonBlockType::Glossary) {
                    continue;
                }

                foreach ($block->content['terms'] ?? [] as $entry) {
                    if (filled($entry['term'] ?? null) && filled($entry['definition'] ?? null)) {
                        $terms[$entry['term']] = $entry['definition'];
                    }
                }
            }
        }

        return $terms;
    }

    /**
     * Der Linker für diesen Render-Durchgang.
     *
     * #[Computed] memoisiert je Request — es gibt also genau eine Instanz je
     * gerendertem Schritt, und damit genau eine Verlinkung je Begriff und
     * Schritt. Der Glossar-Block selbst bleibt zusätzlich als
     * Definitionsliste stehen (Nachschlagen).
     */
    #[Computed]
    public function glossary(): LinkGlossaryTerms
    {
        return app(LinkGlossaryTerms::class)->forTerms($this->glossaryTerms);
    }

    /**
     * @return array{title: string|null, blocks: array<int, LessonBlock>}
     */
    #[Computed]
    public function currentStep(): array
    {
        return $this->steps[$this->clampStep($this->step) - 1];
    }

    public function isLastStep(): bool
    {
        return $this->clampStep($this->step) === count($this->steps);
    }

    /**
     * Alle Lektionen des Pfades in Lesereihenfolge — eine Abfrage, aus der
     * sich Vorgänger und Nachfolger ohne Positions-Arithmetik ergeben (und
     * ohne Sonderfall bei gleichen position-Werten).
     *
     * @return Collection<int, Lesson>
     */
    #[Computed]
    public function siblings(): Collection
    {
        return $this->learningPath->lessons()->get();
    }

    #[Computed]
    public function previousLesson(): ?Lesson
    {
        return $this->neighbour(-1);
    }

    #[Computed]
    public function nextLesson(): ?Lesson
    {
        return $this->neighbour(1);
    }

    /**
     * Das Abschluss-Quiz, sofern es für diesen Nutzer sichtbar ist.
     */
    #[Computed]
    public function finalQuiz(): ?Quiz
    {
        $quiz = $this->learningPath->finalQuiz;

        if ($quiz === null) {
            return null;
        }

        $quiz->setRelation('learningPath', $this->learningPath);

        return Auth::user()?->can('view', $quiz) ? $quiz : null;
    }

    /**
     * Get the sibling lesson at the given offset from the current one.
     */
    protected function neighbour(int $offset): ?Lesson
    {
        $index = $this->siblings->search(fn (Lesson $lesson): bool => $lesson->id === $this->lesson->id);

        return $index === false ? null : $this->siblings->get($index + $offset);
    }

    /**
     * Keep the step within the available range instead of rejecting it.
     */
    protected function clampStep(int $step): int
    {
        return max(1, min($step, count($this->steps)));
    }

    public function render(): mixed
    {
        return $this->view()->title($this->lesson->title);
    }
}; ?>

@php
    $stepCount = count($this->steps);
    $step = $this->currentStep;
@endphp

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('learn.paths.show', $learningPath)"
            wire:navigate
            data-test="back-to-path-button"
        >
            {{ $learningPath->title }}
        </flux:button>
    </div>

    <div>
        <flux:heading size="xl">{{ $lesson->title }}</flux:heading>
    </div>

    @if ($stepCount > 1)
        <div class="space-y-2" data-test="lesson-stepper">
            <flux:progress :value="intdiv($this->step * 100, $stepCount)" />
            <flux:text class="text-sm">
                {{ __('Step :current of :total', ['current' => $this->step, 'total' => $stepCount]) }}
            </flux:text>
        </div>
    @endif

    <div class="space-y-8" wire:key="step-{{ $this->step }}">
        @if (filled($step['title']))
            <flux:heading size="lg" data-test="section-title">{{ $step['title'] }}</flux:heading>
        @endif

        @forelse ($step['blocks'] as $block)
            <div wire:key="block-{{ $block->id }}">
                <x-lesson.block :block="$block" :glossary="$this->glossary" />
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="lesson-empty-state">
                {{ __('This lesson has no content yet.') }}
            </flux:text>
        @endforelse
    </div>

    @if ($this->isLastStep() && $this->checkQuestions->isNotEmpty())
        {{-- Zwischen-Checks stehen gesammelt am Ende des letzten Schritts
             (Entscheidung 10). Sie je Abschnitt zu verankern bräuchte eine
             Anker-Information im Datenmodell und ist bewusst nicht MVP. --}}
        <div class="space-y-4" data-test="inline-checks">
            <div>
                <flux:heading size="lg">{{ __('Quick check') }}</flux:heading>
                <flux:subheading>
                    {{ __('Just for you: these questions do not count towards any quiz.') }}
                </flux:subheading>
            </div>

            @foreach ($this->checkQuestions as $question)
                @php($answered = array_key_exists($question->id, $checkResult))

                <flux:card wire:key="check-{{ $question->id }}" class="space-y-4">
                    <x-learn.question
                        :question="$question"
                        :model="'checkSelection.'.$question->id"
                        :result="$checkResult[$question->id] ?? null"
                        :disabled="$answered"
                    >
                        <flux:error name="checkSelection.{{ $question->id }}" />

                        <div>
                            @if ($answered)
                                <flux:button
                                    size="sm"
                                    icon="arrow-path"
                                    wire:click="retryCheck({{ $question->id }})"
                                    :data-test="'retry-check-'.$question->id"
                                >
                                    {{ __('Try again') }}
                                </flux:button>
                            @else
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    wire:click="submitCheck({{ $question->id }})"
                                    :data-test="'submit-check-'.$question->id"
                                >
                                    {{ __('Check answer') }}
                                </flux:button>
                            @endif
                        </div>
                    </x-learn.question>
                </flux:card>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <div>
            @if ($this->step > 1)
                <flux:button icon="arrow-left" wire:click="previousStep" data-test="previous-step-button">
                    {{ __('Back') }}
                </flux:button>
            @endif
        </div>

        <div>
            @if (! $this->isLastStep())
                <flux:button variant="primary" icon-trailing="arrow-right" wire:click="nextStep" data-test="next-step-button">
                    {{ __('Next') }}
                </flux:button>
            @else
                <flux:button variant="primary" icon="check" wire:click="completeLesson" data-test="complete-lesson-button">
                    {{ __('Complete lesson') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            @if ($this->previousLesson !== null)
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="chevron-left"
                    :href="route('learn.lessons.show', [$learningPath, $this->previousLesson])"
                    wire:navigate
                    data-test="previous-lesson-link"
                >
                    {{ $this->previousLesson->title }}
                </flux:button>
            @endif
        </div>

        <div>
            @if ($this->nextLesson !== null)
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon-trailing="chevron-right"
                    :href="route('learn.lessons.show', [$learningPath, $this->nextLesson])"
                    wire:navigate
                    data-test="next-lesson-link"
                >
                    {{ $this->nextLesson->title }}
                </flux:button>
            @endif
        </div>
    </div>
</section>

@script
<script>
    // Mermaid und Syntax-Highlighting laufen nur über frisch eingeblendete
    // Knoten — nach jedem Schrittwechsel muss der Renderer also erneut über
    // den DOM. Auf genau dieses Fenster-Event hört resources/js/lesson-blocks.js
    // bereits (Muster aus dem Block-Editor).
    $wire.$watch('step', () => {
        requestAnimationFrame(() => window.dispatchEvent(new CustomEvent('lesson-blocks-updated')));
    });
</script>
@endscript
