<?php

use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Livewire\Forms\SourceReferenceForm;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Rules\QuestionOptionsMatchType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?Question $question = null;

    public string $type = 'single';

    public string $prompt = '';

    public string $explanation = '';

    public SourceReferenceForm $sourceReference;

    /** @var int|string|null */
    public $learningPathId = null;

    /** @var int|string|null */
    public $lessonId = null;

    /** @var array<int, array{id: int|null, label: string, is_correct: bool}> */
    public array $options = [];

    /** Hinweis nach Typwechsel: Korrekt-Markierungen wurden zurückgesetzt. */
    public bool $typeChangeNotice = false;

    public function mount(?Question $question = null): void
    {
        $this->question = $question?->exists ? $question : null;

        $this->sourceReference->fillFromReferences($question?->source_reference);

        if ($this->question === null) {
            $this->options = [
                ['id' => null, 'label' => '', 'is_correct' => false],
                ['id' => null, 'label' => '', 'is_correct' => false],
            ];

            return;
        }

        $this->type = $this->question->type->value;
        $this->prompt = $this->question->prompt;
        $this->explanation = $this->question->explanation;
        $this->learningPathId = $this->question->learning_path_id;
        $this->lessonId = $this->question->lesson_id;

        $this->options = $this->question->options
            ->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'is_correct' => $option->is_correct,
            ])
            ->all();
    }

    /**
     * Typwechsel (Plan-Entscheidung): Boolean erzwingt fixe Wahr/Falsch-
     * Optionen; ansonsten werden nur die Korrekt-Markierungen zurückgesetzt.
     */
    public function updatedType(string $value): void
    {
        $hadContent = collect($this->options)
            ->contains(fn (array $option) => $option['id'] !== null || $option['label'] !== '' || $option['is_correct']);

        if ($value === QuestionType::Boolean->value) {
            $this->options = [
                ['id' => null, 'label' => 'Wahr', 'is_correct' => false],
                ['id' => null, 'label' => 'Falsch', 'is_correct' => false],
            ];
        } else {
            foreach ($this->options as $index => $option) {
                $this->options[$index]['is_correct'] = false;
            }

            while (count($this->options) < 2) {
                $this->options[] = ['id' => null, 'label' => '', 'is_correct' => false];
            }
        }

        $this->typeChangeNotice = $hadContent;
    }

    public function updatedLearningPathId(): void
    {
        $this->lessonId = null;
    }

    public function addOption(): void
    {
        $this->options[] = ['id' => null, 'label' => '', 'is_correct' => false];
    }

    public function removeOption(int $index): void
    {
        if ($this->isBoolean() || ! array_key_exists($index, $this->options)) {
            return;
        }

        unset($this->options[$index]);

        $this->options = array_values($this->options);
    }

    public function sortOptions(int $index, int $position): void
    {
        if ($this->isBoolean() || ! array_key_exists($index, $this->options)) {
            return;
        }

        $moved = array_splice($this->options, $index, 1);

        array_splice($this->options, $position, 0, $moved);
    }

    public function addSourceReference(): void
    {
        Gate::authorize('admin');

        $this->sourceReference->addReference();
    }

    public function removeSourceReference(int $index): void
    {
        Gate::authorize('admin');

        $this->sourceReference->removeReference($index);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        // tryFrom: ein manipulierter type-Wert bekommt den Validierungsfehler
        // der Enum-Rule, statt als ValueError mit 500 zu crashen; die
        // Options-Invariante entfällt dann (der Typ-Fehler wird eh gemeldet).
        $type = QuestionType::tryFrom($this->type);

        return [
            'type' => ['required', Rule::enum(QuestionType::class)],
            'prompt' => ['required', 'string', 'max:2000'],
            // Fachliches Pflichtfeld (Konzept f): wird bei falscher Antwort gezeigt
            'explanation' => ['required', 'string', 'max:2000'],
            'learningPathId' => ['nullable', 'integer', Rule::exists('learning_paths', 'id')],
            'lessonId' => [
                'nullable',
                'integer',
                // Die Lektion muss zum gewählten Pfad gehören
                Rule::exists('lessons', 'id')->where('learning_path_id', $this->learningPathId),
            ],
            'options' => ['required', 'array', ...($type !== null ? [new QuestionOptionsMatchType($type)] : [])],
            'options.*.label' => ['required', 'string', 'max:500'],
            'options.*.is_correct' => ['boolean'],
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');

        $this->normalizeInput();

        // Argumentloses validate(): Komponenten-Rules und die Rules des
        // Form-Objekts laufen kumulativ, alle Fehler erscheinen zusammen.
        $validated = $this->validate();

        // Erst nach der Validierung: die Enum-Rule garantiert den Wert.
        $type = QuestionType::from($validated['type']);

        if (filled($validated['lessonId']) && blank($validated['learningPathId'])) {
            $this->addError('lessonId', __('A check question needs a learning path.'));

            return;
        }

        $references = $this->sourceReference->toReferences();

        $pathId = filled($validated['learningPathId']) ? (int) $validated['learningPathId'] : null;
        $lessonId = filled($validated['lessonId']) ? (int) $validated['lessonId'] : null;

        $blockingQuiz = $this->finalQuizLeftBy($pathId, $lessonId);

        if ($blockingQuiz !== null) {
            $this->addError($lessonId !== null ? 'lessonId' : 'learningPathId', __('This question is part of the final quiz of :path. Remove it there first.', [
                'path' => $blockingQuiz->learningPath?->title ?? '',
            ]));

            return;
        }

        DB::transaction(function () use ($validated, $type, $references, $pathId, $lessonId) {
            $question = $this->question ?? new Question;

            $scopeChanged = $question->exists
                && ($question->learning_path_id !== $pathId || $question->lesson_id !== $lessonId);

            $question->fill([
                'type' => $type,
                'prompt' => $validated['prompt'],
                'explanation' => $validated['explanation'],
                'source_reference' => $references,
                'learning_path_id' => $pathId,
                'lesson_id' => $lessonId,
            ]);

            // Entscheidung 10: max+1 im jeweiligen Scope — Zwischen-Check je
            // Lektion, Pool-Frage je Pfad; bestehende Positionen bleiben.
            if (! $question->exists || $scopeChanged) {
                $question->position = $this->nextPosition($pathId, $lessonId);
            }

            $question->save();

            $this->persistOptions($question);

            $this->question = $question;
        });

        Flux::toast(variant: 'success', text: __('Question saved.'));

        $this->redirectRoute('admin.questions.index', navigate: true);
    }

    /**
     * Optionen per Update-by-id erhalten (Responses aus Demo-Daten behalten
     * ihre Referenzen), nur entfernte löschen, neue anlegen.
     */
    protected function persistOptions(Question $question): void
    {
        $submittedIds = collect($this->options)->pluck('id')->filter()->all();

        $question->options()->whereNotIn('id', $submittedIds)->delete();

        foreach (array_values($this->options) as $index => $option) {
            $attributes = [
                'label' => $option['label'],
                'is_correct' => (bool) $option['is_correct'],
                'position' => $index + 1,
            ];

            if ($option['id'] !== null) {
                $question->options()->whereKey($option['id'])->first()?->update($attributes);
            } else {
                $question->options()->create($attributes);
            }
        }
    }

    /**
     * The final quiz whose pool this question would leave with the new path and lesson.
     *
     * Ein Abschluss-Quiz nimmt nur Pool-Fragen seines Pfades (DEV-15, E6).
     * Wechselt eine Frage darin den Pfad oder wird sie zum Zwischen-Check,
     * bliebe sie sonst als Verstoß im Quiz stehen.
     */
    protected function finalQuizLeftBy(?int $pathId, ?int $lessonId): ?Quiz
    {
        if ($this->question === null || ! $this->question->exists) {
            return null;
        }

        return $this->question->quizzes()
            ->where('kind', QuizKind::PathFinal)
            ->with('learningPath')
            ->get()
            ->first(fn (Quiz $quiz): bool => $quiz->learning_path_id !== $pathId || $lessonId !== null);
    }

    protected function nextPosition(?int $pathId, ?int $lessonId): int
    {
        $query = $lessonId !== null
            ? Question::query()->where('lesson_id', $lessonId)
            : Question::query()->where('learning_path_id', $pathId)->whereNull('lesson_id');

        return ((int) $query->max('position')) + 1;
    }

    protected function normalizeInput(): void
    {
        $this->prompt = trim($this->prompt);
        $this->explanation = trim($this->explanation);
        $this->sourceReference->normalize();
        $this->learningPathId = $this->learningPathId === '' ? null : $this->learningPathId;
        $this->lessonId = $this->lessonId === '' ? null : $this->lessonId;

        $this->options = collect($this->options)
            ->map(fn (array $option) => [
                'id' => $option['id'] ?? null,
                'label' => trim($option['label'] ?? ''),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
            ])
            ->reject(fn (array $option) => $option['id'] === null && $option['label'] === '' && ! $option['is_correct'])
            ->values()
            ->all();
    }

    protected function isBoolean(): bool
    {
        return $this->type === QuestionType::Boolean->value;
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
     * @return Collection<int, Lesson>
     */
    #[Computed]
    public function lessons(): Collection
    {
        if (blank($this->learningPathId)) {
            return new Collection;
        }

        return Lesson::query()
            ->where('learning_path_id', (int) $this->learningPathId)
            ->orderBy('position')
            ->get();
    }

    /**
     * Quizze, aus denen die Frage beim Löschen verschwindet; das Lösch-Modal nennt sie.
     */
    #[Computed]
    public function quizzesCount(): int
    {
        return $this->question?->quizzes()->count() ?? 0;
    }

    public function deleteQuestion(): void
    {
        Gate::authorize('admin');

        abort_if($this->question === null, 404);

        // Pivot-Cascade entfernt die Frage aus allen Quizzen
        $this->question->delete();

        $this->modal('delete-question')->close();

        Flux::toast(variant: 'success', text: __('Question deleted.'));

        $this->redirectRoute('admin.questions.index', navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title(
            $this->question === null ? __('New question') : __('Edit question')
        );
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.questions.index')"
            wire:navigate
            data-test="back-to-questions-button"
        >
            {{ __('Questions') }}
        </flux:button>
    </div>

    <div class="flex items-start justify-between gap-4">
        <flux:heading size="xl">
            {{ $question === null ? __('New question') : __('Edit question') }}
        </flux:heading>

        <div class="flex items-center gap-2">
            {{-- Außerhalb des Formulars, deshalb über das form-Attribut verbunden. --}}
            <flux:tooltip :content="__('Save')">
                <flux:button variant="ghost" icon="save" type="submit" form="question-form" data-test="question-save-icon-button" />
            </flux:tooltip>

            @if ($question !== null)
                <flux:modal.trigger name="delete-question">
                    <flux:tooltip :content="__('Delete question')">
                        <flux:button variant="ghost" icon="trash" data-test="question-delete-button" />
                    </flux:tooltip>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    <form id="question-form" wire:submit="save" class="space-y-6">
        <flux:select wire:model.live="type" :label="__('Type')" data-test="question-type-select">
            @foreach (App\Enums\QuestionType::cases() as $questionType)
                <flux:select.option value="{{ $questionType->value }}">{{ $questionType->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($typeChangeNotice)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="type-change-notice">
                <flux:callout.text>
                    {{ __('The type was changed and the correct markings have been reset. Please check the options.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:textarea wire:model="prompt" :label="__('Prompt')" rows="3" data-test="question-prompt-input" />

        <flux:textarea
            wire:model="explanation"
            :label="__('Explanation')"
            :description="__('Shown to apprentices after a wrong answer. Required.')"
            rows="3"
            data-test="question-explanation-input"
        />

        @include('partials.admin.source-reference-fields')

        <flux:fieldset>
            <flux:legend>{{ __('Assignment') }}</flux:legend>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="learningPathId" :label="__('Learning path')" data-test="question-path-select">
                    <flux:select.option value="">{{ __('No path') }}</flux:select.option>
                    @foreach ($this->paths as $path)
                        <flux:select.option value="{{ $path->id }}">{{ $path->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="lessonId"
                    :label="__('Lesson (makes it a check question)')"
                    :disabled="blank($learningPathId)"
                    data-test="question-lesson-select"
                >
                    <flux:select.option value="">{{ __('No lesson (pool question)') }}</flux:select.option>
                    @foreach ($this->lessons as $lesson)
                        <flux:select.option value="{{ $lesson->id }}">{{ $lesson->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Options') }}</flux:legend>

            <div class="space-y-2" wire:sort="sortOptions">
                @foreach ($options as $index => $option)
                    <div
                        wire:key="option-{{ $index }}"
                        wire:sort:item="{{ $index }}"
                        class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                        data-test="option-row"
                    >
                        @unless ($this->isBoolean())
                            <div wire:sort:handle class="cursor-grab">
                                <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                            </div>
                        @endunless

                        <div class="flex-1">
                            <flux:input
                                wire:model="options.{{ $index }}.label"
                                :placeholder="__('Option label')"
                                :disabled="$this->isBoolean()"
                                data-test="option-label-input"
                            />
                        </div>

                        <flux:switch
                            wire:model="options.{{ $index }}.is_correct"
                            :label="__('Correct')"
                            data-test="option-correct-toggle"
                        />

                        @unless ($this->isBoolean())
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeOption({{ $index }})"
                                data-test="option-remove-button"
                            />
                        @endunless
                    </div>
                @endforeach
            </div>

            <flux:error name="options" />

            @unless ($this->isBoolean())
                <flux:button variant="subtle" size="sm" icon="plus" wire:click="addOption" data-test="option-add-button">
                    {{ __('Add option') }}
                </flux:button>
            @endunless
        </flux:fieldset>

        <flux:button variant="primary" icon="save" type="submit" data-test="question-save-button">{{ __('Save') }}</flux:button>
    </form>

    @if ($question !== null)
        <flux:modal name="delete-question" focusable class="max-w-lg">
            <form wire:submit="deleteQuestion" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete question') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete this question?') }}
                        @if ($this->quizzesCount > 0)
                            {{ trans_choice('{1} It is part of :count quiz and will be removed from it.|[2,*] It is part of :count quizzes and will be removed from them.', $this->quizzesCount, ['count' => $this->quizzesCount]) }}
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
    @endif
</section>
