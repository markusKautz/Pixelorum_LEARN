<?php

use App\Actions\Content\CollectContentSources;
use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public LearningPath $learningPath;

    public function mount(LearningPath $learningPath): void
    {
        $this->learningPath = $learningPath->load('verifier');
    }

    public function verify(): void
    {
        Gate::authorize('verify', $this->learningPath);

        $summary = $this->summary;

        if ($summary['lessonsWithReference'] !== $summary['lessonsTotal']
            || $summary['questionsWithReference'] !== $summary['questionsTotal']) {
            $this->modal('grant-verification')->close();

            Flux::toast(variant: 'danger', text: __('Every lesson and question needs a source reference before verification.'));

            return;
        }

        // DEV-3, E10: Eine Quelle, die die Installation nicht kennt, ist für
        // den Admin nicht prüfbar, auch wenn ein Beleg eingetragen ist.
        if ($summary['unknownSourceIds'] !== []) {
            $this->modal('grant-verification')->close();

            Flux::toast(variant: 'danger', text: __('Every source must be known to this installation before verification: :ids', [
                'ids' => implode(', ', $summary['unknownSourceIds']),
            ]));

            return;
        }

        // Explizite Zuweisung: die Felder sind bewusst nicht-fillable
        // (Sprint-2-Härtung) — kein fill()/update() mit diesen Keys.
        $this->learningPath->review_status = ReviewStatus::Verified;
        $this->learningPath->verified_at = now();
        $this->learningPath->verified_by = Auth::id();

        // Ohne Zeitstempel, aus demselben Grund wie beim Veröffentlichen: Die
        // Freigabe selbst ist keine Inhaltsänderung. Fällt der Speichervorgang
        // über eine Sekundengrenze, läge updated_at sonst hinter verified_at
        // und der Pfad wäre in derselben Sekunde „seit Freigabe geändert".
        LearningPath::withoutTimestamps(fn () => $this->learningPath->save());

        Flux::toast(variant: 'success', text: __('Path verified.'));

        // Nach der Freigabe zurück zur Detailansicht des Pfades (DEV-9): Die
        // Freigabe ist der letzte Schritt der Prüfung, weitergearbeitet wird dort.
        $this->redirectRoute('admin.learning-paths.edit', ['learningPath' => $this->learningPath->slug], navigate: true);
    }

    public function revokeVerification(): void
    {
        Gate::authorize('verify', $this->learningPath);

        $this->learningPath->review_status = ReviewStatus::Unreviewed;
        $this->learningPath->verified_at = null;
        $this->learningPath->verified_by = null;

        LearningPath::withoutTimestamps(fn () => $this->learningPath->save());

        $this->modal('revoke-verification')->close();

        Flux::toast(variant: 'success', text: __('Verification revoked.'));
    }

    /**
     * @return Collection<int, Lesson>
     */
    #[Computed]
    public function lessons(): Collection
    {
        return $this->learningPath->lessons()->with('questions')->get();
    }

    /**
     * @return Collection<int, Question>
     */
    #[Computed]
    public function poolQuestions(): Collection
    {
        return $this->learningPath->questions()->whereNull('lesson_id')->get();
    }

    /**
     * The sources referenced on this page, keyed by source ID.
     *
     * @return SupportCollection<string, ContentSource>
     */
    #[Computed]
    public function sources(): SupportCollection
    {
        return ContentSource::whereIn('source_id', $this->usedSourceIds())->get()->keyBy('source_id');
    }

    /**
     * @return list<string>
     */
    protected function usedSourceIds(): array
    {
        $lessons = $this->lessons->map(fn (Lesson $lesson): array => [
            'source_reference' => $lesson->source_reference ?? [],
            'questions' => $lesson->questions->map(fn (Question $question): array => ['source_reference' => $question->source_reference ?? []])->all(),
        ])->all();
        $questions = $this->poolQuestions->map(fn (Question $question): array => ['source_reference' => $question->source_reference ?? []])->all();

        return app(CollectContentSources::class)->usedSourceIds(['lessons' => $lessons, 'questions' => $questions]);
    }

    /**
     * @return array{lessonsWithReference: int, lessonsTotal: int, questionsWithReference: int, questionsTotal: int, unknownSourceIds: list<string>}
     */
    #[Computed]
    public function summary(): array
    {
        $lessons = $this->lessons;
        $questions = $lessons->flatMap->questions->merge($this->poolQuestions);

        return [
            'lessonsWithReference' => $lessons->filter(fn ($lesson) => filled($lesson->source_reference))->count(),
            'lessonsTotal' => $lessons->count(),
            'questionsWithReference' => $questions->filter(fn ($question) => filled($question->source_reference))->count(),
            'questionsTotal' => $questions->count(),
            'unknownSourceIds' => array_values(array_diff($this->usedSourceIds(), $this->sources->keys()->all())),
        ];
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Verify :name', ['name' => $this->learningPath->title]));
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

    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Verification') }}: {{ $learningPath->title }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$learningPath->status === PathStatus::Published ? 'green' : 'zinc'">
                    {{ $learningPath->status->label() }}
                </flux:badge>
                <flux:badge
                    size="sm"
                    :color="$learningPath->review_status === ReviewStatus::Verified ? 'green' : 'amber'"
                    data-test="review-status-badge"
                >
                    {{ $learningPath->review_status->label() }}
                </flux:badge>
                @if ($learningPath->review_status === ReviewStatus::Verified && $learningPath->changedSinceVerification())
                    <flux:badge size="sm" color="amber" data-test="changed-since-verification-badge">
                        {{ __('Changed since verification') }}
                    </flux:badge>
                @endif
            </div>

            @if ($learningPath->review_status === ReviewStatus::Verified && $learningPath->verifier)
                <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400" data-test="verified-by-line">
                    {{ __('Verified by :name on :date', [
                        'name' => $learningPath->verifier->name,
                        'date' => $learningPath->verified_at?->format('d.m.Y H:i'),
                    ]) }}
                </flux:text>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($learningPath->review_status === ReviewStatus::Verified)
                <flux:modal.trigger name="revoke-verification">
                    <flux:button variant="outline" data-test="revoke-verification-button">
                        {{ __('Revoke verification') }}
                    </flux:button>
                </flux:modal.trigger>
            @else
                <flux:modal.trigger name="grant-verification">
                    <flux:button variant="primary" data-test="verify-button">
                        {{ __('Grant verification') }}
                    </flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @if ($learningPath->status === PathStatus::Published && $learningPath->review_status !== ReviewStatus::Verified)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="published-unreviewed-warning">
            <flux:callout.text>
                {{ __('This path is published but not verified. It stays invisible to apprentices until the verification is granted.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:callout icon="document-magnifying-glass" data-test="review-summary">
        <flux:callout.text>
            {{ __(':lessonsWithReference of :lessonsTotal lessons and :questionsWithReference of :questionsTotal questions carry a source reference.', [
                'lessonsWithReference' => $this->summary['lessonsWithReference'],
                'lessonsTotal' => $this->summary['lessonsTotal'],
                'questionsWithReference' => $this->summary['questionsWithReference'],
                'questionsTotal' => $this->summary['questionsTotal'],
            ]) }}
            {{ __('Verification stays blocked until every lesson and question has a source reference.') }}
        </flux:callout.text>
    </flux:callout>

    @if ($this->summary['unknownSourceIds'] !== [])
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="unknown-sources-warning">
            <flux:callout.text>
                {{ __('These sources are unknown to this installation and block the verification: :ids', ['ids' => implode(', ', $this->summary['unknownSourceIds'])]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Lessons') }}</flux:heading>

        @forelse ($this->lessons as $lesson)
            <div wire:key="review-lesson-{{ $lesson->id }}" class="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="review-lesson">
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium">{{ $lesson->title }}</span>

                    @if ($lesson->source_reference)
                        <div class="flex flex-wrap justify-end gap-1">
                            @foreach ($lesson->source_reference as $reference)
                                @include('partials.admin.source-reference-badge', ['reference' => $reference, 'sources' => $this->sources])
                            @endforeach
                        </div>
                    @else
                        <flux:badge size="sm" color="amber" data-test="missing-reference-badge">
                            {{ __('No source reference') }}
                        </flux:badge>
                    @endif
                </div>

                @if ($lesson->questions->isNotEmpty())
                    <div class="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
                            {{ __('Check questions') }}
                        </flux:text>

                        @foreach ($lesson->questions as $question)
                            <div wire:key="review-check-{{ $question->id }}" class="flex items-center justify-between gap-3" data-test="review-question">
                                <flux:text class="text-sm">{{ $question->prompt }}</flux:text>

                                @if ($question->source_reference)
                                    <div class="flex flex-wrap justify-end gap-1">
                                        @foreach ($question->source_reference as $reference)
                                            @include('partials.admin.source-reference-badge', ['reference' => $reference, 'sources' => $this->sources])
                                        @endforeach
                                    </div>
                                @else
                                    <flux:badge size="sm" color="amber" data-test="missing-reference-badge">
                                        {{ __('No source reference') }}
                                    </flux:badge>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">{{ __('No lessons yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Pool questions') }}</flux:heading>

        <div class="space-y-2 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            @forelse ($this->poolQuestions as $question)
                <div wire:key="review-pool-{{ $question->id }}" class="flex items-center justify-between gap-3" data-test="review-question">
                    <flux:text class="text-sm">{{ $question->prompt }}</flux:text>

                    @if ($question->source_reference)
                        <div class="flex flex-wrap justify-end gap-1">
                            @foreach ($question->source_reference as $reference)
                                @include('partials.admin.source-reference-badge', ['reference' => $reference, 'sources' => $this->sources])
                            @endforeach
                        </div>
                    @else
                        <flux:badge size="sm" color="amber" data-test="missing-reference-badge">
                            {{ __('No source reference') }}
                        </flux:badge>
                    @endif
                </div>
            @empty
                <flux:text class="py-2 text-center text-zinc-500 dark:text-zinc-400">{{ __('No pool questions yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <flux:modal name="grant-verification" focusable class="max-w-lg">
        <form wire:submit="verify" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Grant verification') }}</flux:heading>
                <flux:subheading>
                    @if ($learningPath->status === PathStatus::Published)
                        {{ __('The path is published: granting the verification makes it visible to apprentices immediately.') }}
                    @else
                        {{ __('Do you want to verify ":title"? Confirm that you have checked the content against its sources.', ['title' => $learningPath->title]) }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="verify-confirm">
                    {{ __('Grant verification') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="revoke-verification" focusable class="max-w-lg">
        <form wire:submit="revokeVerification" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Revoke verification') }}</flux:heading>
                <flux:subheading>
                    @if ($learningPath->status === PathStatus::Published)
                        {{ __('The path is published: revoking the verification hides it from apprentices immediately.') }}
                    @else
                        {{ __('Do you want to revoke the verification of ":title"?', ['title' => $learningPath->title]) }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="revoke-verification-confirm">
                    {{ __('Revoke verification') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
