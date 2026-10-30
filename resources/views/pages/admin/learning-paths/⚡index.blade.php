<?php

use App\Actions\Content\ExportLearningPath;
use App\Actions\Content\ReorderToPosition;
use App\Actions\Content\SetPathStatus;
use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $title = '';

    /** @var list<string> */
    public array $exportWarnings = [];

    public ?string $exportPathSlug = null;

    public function createPath(): void
    {
        Gate::authorize('create', LearningPath::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $path = $this->withOrderLock(fn (): LearningPath => LearningPath::create([
            'title' => $validated['title'],
            'position' => ((int) LearningPath::withTrashed()->max('position')) + 1,
        ]));

        if ($path === null) {
            return;
        }

        $this->modal('create-learning-path')->close();

        Flux::toast(variant: 'success', text: __('Learning path created.'));

        $this->redirectRoute('admin.learning-paths.edit', ['learningPath' => $path->slug], navigate: true);
    }

    /**
     * Move a path within the order without touching `updated_at`.
     *
     * Die Reihenfolge ist keine Inhaltsänderung. Ein bewegtes `updated_at`
     * läse `changedSinceVerification()` als solche, und weil das Verdichten
     * alle nachfolgenden Pfade mitverschiebt, trügen danach viele Pfade das
     * Badge „seit Freigabe geändert“ (wie beim Statuswechsel, `SetPathStatus`).
     */
    public function sortPaths(ReorderToPosition $reorderToPosition, int $pathId, int $position): void
    {
        $path = LearningPath::findOrFail($pathId);

        Gate::authorize('update', $path);

        $this->withOrderLock(fn () => LearningPath::withoutTimestamps(
            fn () => $reorderToPosition->handle(LearningPath::query(), $path, $position),
        ));
    }

    public function publishPath(SetPathStatus $setPathStatus, int $pathId): void
    {
        $path = LearningPath::findOrFail($pathId);

        Gate::authorize('publish', $path);

        $setPathStatus->handle($path, PathStatus::Published);

        $this->modal("publish-path-{$pathId}")->close();

        Flux::toast(variant: 'success', text: __('Learning path published.'));
    }

    public function unpublishPath(SetPathStatus $setPathStatus, int $pathId): void
    {
        $path = LearningPath::findOrFail($pathId);

        Gate::authorize('publish', $path);

        $setPathStatus->handle($path, PathStatus::Draft);

        $this->modal("unpublish-path-{$pathId}")->close();

        Flux::toast(variant: 'success', text: __('Learning path unpublished.'));
    }

    /**
     * Den Export anstoßen, wie in der Detailansicht (DEV-1, E3).
     *
     * Ohne Warnungen direkt herunterladen, sonst erst das Modal mit der Liste.
     * Die Datei kommt dort trotzdem, weil sie zuerst eine Sicherung ist.
     */
    public function exportPath(ExportLearningPath $export, int $pathId): void
    {
        $path = LearningPath::findOrFail($pathId);

        Gate::authorize('view', $path);

        $this->exportWarnings = $export->handle($path)['warnings'];
        $this->exportPathSlug = $path->slug;

        if ($this->exportWarnings === []) {
            $this->redirectRoute('admin.learning-paths.export', ['learningPath' => $path->slug]);

            return;
        }

        $this->modal('export-path')->show();
    }

    public function deletePath(int $pathId): void
    {
        $path = LearningPath::findOrFail($pathId);

        Gate::authorize('delete', $path);

        $path->delete();

        $this->modal("delete-path-{$pathId}")->close();

        Flux::toast(variant: 'success', text: __('Learning path deleted.'));
    }

    /**
     * Put a deleted path back, at the end of the order (E4).
     *
     * `max+1` wird über die lebenden Pfade und **vor** dem Restore bestimmt:
     * Die alte Position des gelöschten Pfades ist regelmäßig veraltet, weil
     * `ReorderToPosition` die Positionen der lebenden Zeilen verdichtet. Sie
     * einfach wiederzuverwenden hieße, eine Position doppelt zu vergeben.
     */
    public function restorePath(int $pathId): void
    {
        $path = LearningPath::onlyTrashed()->findOrFail($pathId);

        Gate::authorize('restore', $path);

        $restored = $this->withOrderLock(function () use ($path): bool {
            $path->position = ((int) LearningPath::max('position')) + 1;
            $path->restore();

            return true;
        });

        if ($restored === null) {
            return;
        }

        unset($this->paths, $this->trashedPaths);

        Flux::toast(variant: 'success', text: __('Learning path restored.'));
    }

    /**
     * Remove a deleted path and everything that belongs to it, for good (E3).
     */
    public function forceDeletePath(int $pathId): void
    {
        $path = LearningPath::onlyTrashed()->findOrFail($pathId);

        Gate::authorize('forceDelete', $path);

        $path->forceDelete();

        $this->modal("force-delete-path-{$pathId}")->close();

        unset($this->trashedPaths);

        Flux::toast(variant: 'success', text: __('Learning path deleted permanently.'));
    }

    /**
     * Run the callback while holding the shared learning-path order lock (E9).
     *
     * Anlegen, Sortieren, Wiederherstellen und der JSON-Upload berechnen alle
     * `max+1` beziehungsweise verdichtete Positionen. Ohne gemeinsamen Riegel
     * lesen zwei gleichzeitige Vorgänge dasselbe Maximum und vergeben dieselbe
     * Position. Der Upload nimmt denselben Riegel in `ImportLearningPath` — er
     * wird deshalb hier **nicht** zusätzlich genommen, ein Lock ist nicht
     * wiedereintrittsfähig.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue|null  null, wenn der Riegel nicht rechtzeitig frei wurde
     */
    protected function withOrderLock(callable $callback): mixed
    {
        try {
            return Cache::lock('learning-path-order', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            Flux::toast(
                variant: 'danger',
                text: __('Another change to the learning path order is still running. Please try again.'),
            );

            return null;
        }
    }

    /**
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        // withContentTimestamps: das Badge „seit Freigabe geändert" vergleicht
        // vier Aggregate je Zeile. Vorgeladen sind sie Subselects derselben
        // Abfrage; ohne den Scope holt sie jede Zeile einzeln nach.
        return LearningPath::query()
            ->withCount(['lessons', 'questions', 'quizzes', 'teams'])
            ->withContentTimestamps()
            ->orderBy('position')
            ->get();
    }

    /**
     * The soft-deleted paths, with what a permanent deletion would take along.
     *
     * Die Kennzahlen werden je Zeile abgefragt statt in einer gruppierten
     * Abfrage über alle: Eine Antwort kann auf zwei Wegen verschwinden — ihr
     * Versuch gehört zu einem Quiz des Pfades, oder ihre Frage gehört zum Pfad
     * — und beide Wege können auf verschiedene Pfade zeigen. Eine gruppierte
     * Zählung müsste jede Antwort genau einem Pfad zuordnen und zählte bei
     * einem freien Quiz mit Fragen aus zwei Pfaden falsch. Der Papierkorb ist
     * naturgemäß kurz, und die Abfragen laufen nur, wenn überhaupt etwas darin
     * liegt.
     *
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function trashedPaths(): Collection
    {
        $paths = LearningPath::onlyTrashed()
            ->withCount(['lessons', 'questions', 'quizzes'])
            ->orderByDesc('deleted_at')
            ->get();

        foreach ($paths as $path) {
            $lessonIds = Lesson::where('learning_path_id', $path->id)->select('id');
            $quizIds = Quiz::where('learning_path_id', $path->id)->select('id');
            $questionIds = Question::where('learning_path_id', $path->id)->select('id');
            $attemptIds = QuizAttempt::whereIn('quiz_id', $quizIds)->select('id');

            $path->setAttribute(
                'completions_count',
                LessonCompletion::whereIn('lesson_id', $lessonIds)->count(),
            );
            $path->setAttribute(
                'attempts_count',
                QuizAttempt::whereIn('quiz_id', $quizIds)->count(),
            );
            $path->setAttribute(
                'responses_count',
                QuestionResponse::query()
                    ->whereIn('question_id', $questionIds)
                    ->orWhereIn('quiz_attempt_id', $attemptIds)
                    ->count(),
            );
            // Freie und persönliche Quizze überleben, verlieren aber die
            // Fragen dieses Pfades — das muss vor dem Klick sichtbar sein.
            $path->setAttribute(
                'borrowing_quizzes_count',
                Quiz::query()
                    ->where(fn (Builder $quiz) => $quiz
                        ->whereNull('learning_path_id')
                        ->orWhere('learning_path_id', '!=', $path->id))
                    ->whereHas('questions', fn (Builder $question) => $question
                        ->where('questions.learning_path_id', $path->id))
                    ->count(),
            );
        }

        return $paths;
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Learning paths'));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Learning paths') }}</flux:heading>
            <flux:subheading>{{ __('Manage the learning paths, their order, and their publication.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:modal.trigger name="import-learning-path">
                <flux:button variant="filled" icon="arrow-up-tray" data-test="import-path-button">
                    {{ __('Import learning path') }}
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="create-learning-path">
                <flux:button variant="primary" icon="plus" data-test="new-path-button">
                    {{ __('New learning path') }}
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:table data-test="paths-table">
        <flux:table.columns>
            <flux:table.column></flux:table.column>
            <flux:table.column>{{ __('Title') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Verification') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Lessons') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Questions') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quizzes') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows wire:sort="sortPaths">
            @forelse ($this->paths as $path)
                <flux:table.row :key="$path->id" wire:sort:item="{{ $path->id }}" data-test="path-row">
                    <flux:table.cell class="w-10">
                        <div wire:sort:handle class="cursor-grab">
                            <flux:icon name="bars-3" variant="mini" class="text-zinc-400" />
                        </div>
                    </flux:table.cell>
                    <flux:table.cell variant="strong">
                        <span class="block max-w-xs truncate" title="{{ $path->title }}">{{ $path->title }}</span>
                        {{-- Ohne Zuweisung sieht den Pfad niemand (#9, E12, E17). --}}
                        @if ($path->teams_count > 0)
                            <flux:badge size="sm" color="sky" icon="user-group" class="mt-1" data-test="path-groups-badge">
                                {{ trans_choice('{1} :count group|[2,*] :count groups', $path->teams_count, ['count' => $path->teams_count]) }}
                            </flux:badge>
                        @else
                            <flux:badge size="sm" color="amber" icon="user-group" class="mt-1" data-test="path-no-group-badge">
                                {{ __('No group') }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$path->status === PathStatus::Published ? 'green' : 'zinc'">
                            {{ $path->status->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap items-center gap-1">
                            @if ($path->review_status === ReviewStatus::Verified)
                                <flux:badge size="sm" color="green">{{ $path->review_status->label() }}</flux:badge>
                                @if ($path->changedSinceVerification())
                                    <flux:badge size="sm" color="amber" data-test="changed-since-verification-badge">
                                        {{ __('Changed since verification') }}
                                    </flux:badge>
                                @endif
                            @elseif ($path->status === PathStatus::Published)
                                {{-- publiziert, aber ungeprüft: für Lehrlinge unsichtbar (Grounding-Gate) --}}
                                <flux:badge size="sm" color="amber" data-test="unreviewed-warning-badge">{{ $path->review_status->label() }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ $path->review_status->label() }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $path->lessons_count }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $path->questions_count }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $path->quizzes_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-1">
                            {{-- Veröffentlichen ohne Umweg über die Bearbeiten-Seite.
                                 Der Dialog bleibt, weil er beim Freigeben auf einen
                                 ungeprüften Pfad hinweist und das Zurückziehen den
                                 Lehrlingen Inhalt wegnimmt. --}}
                            @if ($path->status === PathStatus::Published)
                                <flux:modal.trigger :name="'unpublish-path-'.$path->id">
                                    <flux:tooltip :content="__('Unpublish')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="eye-slash"
                                            data-test="path-unpublish-button"
                                        />
                                    </flux:tooltip>
                                </flux:modal.trigger>
                            @else
                                <flux:modal.trigger :name="'publish-path-'.$path->id">
                                    <flux:tooltip :content="__('Publish')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="eye"
                                            data-test="path-publish-button"
                                        />
                                    </flux:tooltip>
                                </flux:modal.trigger>
                            @endif

                            <flux:tooltip :content="__('Verification')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="shield-check"
                                    :href="route('admin.learning-paths.review', $path)"
                                    wire:navigate
                                    data-test="path-review-button"
                                />
                            </flux:tooltip>

                            <flux:tooltip :content="__('Edit')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    :href="route('admin.learning-paths.edit', $path)"
                                    wire:navigate
                                    data-test="path-edit-button"
                                />
                            </flux:tooltip>

                            <flux:tooltip :content="__('Export')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-down-tray"
                                    wire:click="exportPath({{ $path->id }})"
                                    data-test="path-export-button"
                                />
                            </flux:tooltip>

                            <flux:modal.trigger :name="'delete-path-'.$path->id">
                                <flux:tooltip :content="__('Delete')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        data-test="path-delete-button"
                                    />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No learning paths yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Der Papierkorb bekommt keine eigene Seite: Er ist meistens leer, und
         eine leere Seite, die man erst ansteuern muss, sieht niemand. --}}
    @if ($this->trashedPaths->isNotEmpty())
        <div class="space-y-3" data-test="paths-trash">
            <div>
                <flux:heading size="lg">{{ __('Deleted learning paths') }}</flux:heading>
                <flux:subheading>
                    {{ __('A restored path returns with its content and the progress of the apprentices, at the end of the order. Deleting it permanently cannot be undone.') }}
                </flux:subheading>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Title') }}</flux:table.column>
                    <flux:table.column>{{ __('Deleted') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Lessons') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Questions') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Quizzes') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->trashedPaths as $path)
                        <flux:table.row :key="'trashed-'.$path->id" data-test="trashed-path-row">
                            <flux:table.cell variant="strong">
                                <span class="block max-w-xs truncate" title="{{ $path->title }}">{{ $path->title }}</span>
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $path->slug }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span title="{{ $path->deleted_at?->isoFormat('LLL') }}">
                                    {{ $path->deleted_at?->format('d.m.Y H:i') }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $path->lessons_count }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $path->questions_count }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $path->quizzes_count }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:tooltip :content="__('Restore')">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-uturn-left"
                                            wire:click="restorePath({{ $path->id }})"
                                            data-test="path-restore-button"
                                        />
                                    </flux:tooltip>

                                    <flux:modal.trigger :name="'force-delete-path-'.$path->id">
                                        <flux:tooltip :content="__('Delete permanently')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                data-test="path-force-delete-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        @foreach ($this->trashedPaths as $path)
            <flux:modal wire:key="force-delete-path-modal-{{ $path->id }}" :name="'force-delete-path-'.$path->id" focusable class="max-w-lg">
                <form wire:submit="forceDeletePath({{ $path->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete learning path permanently') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Do you want to delete ":title" for good? Its lessons, questions and quizzes go with it. This cannot be undone.', ['title' => $path->title]) }}
                        </flux:subheading>
                    </div>

                    {{-- Sind alle Zahlen null, ist der Text harmlos; sind es
                         vierzig, muss man das vor dem Klick sehen. --}}
                    @if ($path->completions_count + $path->attempts_count + $path->responses_count > 0)
                        <flux:callout variant="danger" icon="exclamation-triangle" data-test="force-delete-impact">
                            <flux:callout.text>
                                {{ __(':completions completed lessons, :attempts quiz attempts and :responses answers by apprentices are deleted with it.', [
                                    'completions' => $path->completions_count,
                                    'attempts' => $path->attempts_count,
                                    'responses' => $path->responses_count,
                                ]) }}
                            </flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:callout icon="information-circle" data-test="force-delete-impact">
                            <flux:callout.text>
                                {{ __('No apprentice has worked with this path yet.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($path->borrowing_quizzes_count > 0)
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="force-delete-borrowing-quizzes">
                            <flux:callout.text>
                                {{ trans_choice('{1} :count other quiz loses the questions it took from this path.|[2,*] :count other quizzes lose the questions they took from this path.', $path->borrowing_quizzes_count, ['count' => $path->borrowing_quizzes_count]) }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit" data-test="path-force-delete-confirm">
                            {{ __('Delete permanently') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endforeach
    @endif

    @foreach ($this->paths as $path)
        @if ($path->status === PathStatus::Published)
            <flux:modal wire:key="unpublish-path-modal-{{ $path->id }}" :name="'unpublish-path-'.$path->id" focusable class="max-w-lg">
                <form wire:submit="unpublishPath({{ $path->id }})" class="space-y-6">
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
                        <flux:button variant="danger" type="submit" data-test="path-unpublish-confirm">
                            {{ __('Unpublish') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @else
            <flux:modal wire:key="publish-path-modal-{{ $path->id }}" :name="'publish-path-'.$path->id" focusable class="max-w-lg">
                <form wire:submit="publishPath({{ $path->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Publish learning path') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Do you want to publish ":title"?', ['title' => $path->title]) }}
                        </flux:subheading>
                    </div>

                    @if ($path->review_status !== ReviewStatus::Verified)
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="path-publish-unverified-warning">
                            <flux:callout.text>
                                {{ __('This path has not been verified against its sources yet. It stays hidden from apprentices until it is verified.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit" data-test="path-publish-confirm">
                            {{ __('Publish') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        <flux:modal wire:key="delete-path-modal-{{ $path->id }}" :name="'delete-path-'.$path->id" focusable class="max-w-lg">
            <form wire:submit="deletePath({{ $path->id }})" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete learning path') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Are you sure you want to delete ":title"? The path disappears from the apprentice view; its lessons and quizzes remain attached to it.', ['title' => $path->title]) }}
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
    @endforeach

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
                @if ($exportPathSlug !== null)
                    <flux:button
                        variant="primary"
                        icon="arrow-down-tray"
                        :href="route('admin.learning-paths.export', ['learningPath' => $exportPathSlug])"
                        data-test="export-download-link"
                    >
                        {{ __('Download anyway') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    <flux:modal name="create-learning-path" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createPath" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a new learning path') }}</flux:heading>
                <flux:subheading>{{ __('Give the learning path a title. Everything else can be edited afterwards.') }}</flux:subheading>
            </div>

            <flux:input wire:model="title" :label="__('Title')" type="text" autofocus data-test="create-path-title" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-path-submit">
                    {{ __('Create learning path') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Ein Trigger allein rendert das Kind-Modal nicht; es wird wie in der
         Gruppenverwaltung ausdrücklich eingebunden. --}}
    <livewire:pages::admin.learning-paths.import-path-modal />
</section>
