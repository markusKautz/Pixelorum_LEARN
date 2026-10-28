<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Data\Analytics\LearnerReport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public User $member;

    /**
     * Die Route ist über `scopeBindings` verschachtelt: ein Lehrling einer
     * fremden Gruppe ergibt bereits 404, bevor `mount()` läuft. Die
     * Gate-Prüfung deckt den Livewire-Endpunkt selbst ab.
     */
    public function mount(Team $team, User $member): void
    {
        Gate::authorize('admin');

        abort_if($team->is_personal, 404);

        $this->team = $team;
        $this->member = $member;
    }

    #[Computed]
    public function report(): LearnerReport
    {
        return app(LearnerProgressReport::class)->handle($this->member);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Analytics: :name', ['name' => $this->member->name]));
    }
}; ?>

@php($report = $this->report)
{{-- Einmal gruppieren: die Zahl steht in der Accordion-Überschrift, die Zeilen
     in der Tabelle darunter. --}}
@php($attemptRows = $report->attemptsByQuiz())

<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.groups.analytics', $team)"
            wire:navigate
            data-test="back-to-analytics-button"
        >
            {{ $team->name }}
        </flux:button>
    </div>

    <div class="flex items-center gap-4">
        <flux:avatar :name="$member->name" :initials="$member->initials()" size="lg" />
        <div>
            <flux:heading size="xl">{{ $member->name }}</flux:heading>
            <flux:subheading>{{ $member->email }}</flux:subheading>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="space-y-1" data-test="learner-average">
            <flux:text class="text-sm">{{ __('Average reading progress') }}</flux:text>
            <flux:heading size="xl">{{ $report->averagePercent() }} %</flux:heading>
            <flux:progress :value="$report->averagePercent()" />
        </flux:card>

        <flux:card class="space-y-1" data-test="learner-completed">
            <flux:text class="text-sm">{{ __('Paths completed') }}</flux:text>
            <flux:heading size="xl">
                {{ __(':done of :total done', ['done' => $report->completedPaths(), 'total' => count($report->paths)]) }}
            </flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="learner-last-activity">
            <flux:text class="text-sm">{{ __('Last activity') }}</flux:text>
            <flux:heading size="xl">{{ $report->lastActivityAt?->format('d.m.Y') ?? __('Never') }}</flux:heading>
        </flux:card>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Progress per path') }}</flux:heading>

        <flux:table data-test="learner-paths-table">
            <flux:table.columns>
                <flux:table.column>{{ __('Learning path') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Lessons read') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Final quiz') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($report->paths as $row)
                    <flux:table.row :key="'path-'.$row->path->id" data-test="learner-path-row">
                        <flux:table.cell variant="strong">
                            <span class="block max-w-xs truncate" title="{{ $row->path->title }}">
                                {{ $row->path->title }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge
                                size="sm"
                                :color="$row->status->color()"
                                data-test="learner-path-status"
                            >
                                {{ $row->status->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:progress :value="$row->percent" class="w-24" />
                                <span class="text-sm whitespace-nowrap tabular-nums" data-test="learner-path-lessons">
                                    {{ __(':done of :total lessons', [
                                        'done' => $row->completedLessons,
                                        'total' => $row->totalLessons,
                                    ]) }}
                                </span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if (! $row->hasQuiz)
                                <flux:badge size="sm" color="zinc">{{ __('No final quiz') }}</flux:badge>
                            @elseif ($row->quizPassed)
                                <flux:badge size="sm" color="green" icon="check-circle">{{ __('Passed') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Open') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No learning paths have been released yet.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:separator variant="subtle" />

    {{-- Gegenstück zur Gruppen-Auswertung (Retest 2026-07-29): dort bleibt die
         Lehrlings-Tabelle offen, hier die Pfad-Tabelle — das ist die Frage, mit
         der der Ausbildner auf diese Seite kommt. Versuche und Schwachstellen
         sind die Nachschau danach und klappen zu. --}}
    <x-ui.accordion data-test="learner-accordion">
        <x-ui.accordion.item data-test="attempts-accordion-item">
            <x-ui.accordion.heading>
                <span class="flex items-center gap-3">
                    <span>{{ __('Quiz attempts') }}</span>
                    <flux:badge size="sm" color="zinc">{{ count($attemptRows) }}</flux:badge>
                </span>
            </x-ui.accordion.heading>

            <x-ui.accordion.content class="space-y-4">
                <flux:table data-test="learner-attempts-table">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Quiz') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Attempts') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Best result') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($attemptRows as $row)
                            <flux:table.row :key="'quiz-attempts-'.$row->quiz->id" data-test="learner-attempt-row">
                                <flux:table.cell variant="strong">{{ $row->quiz->title }}</flux:table.cell>
                                <flux:table.cell align="end" data-test="learner-attempt-count">
                                    {{ $row->attemptCount }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:badge
                                        size="sm"
                                        :color="$row->bestAttempt->passed ? 'green' : 'amber'"
                                        data-test="learner-attempt-score"
                                    >
                                        {{ __(':score of :max (:percent %)', [
                                            'score' => $row->bestAttempt->score,
                                            'max' => $row->bestAttempt->max_score,
                                            'percent' => $row->bestAttempt->percent,
                                        ]) }}
                                    </flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    {{ __('No quiz attempted yet.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-ui.accordion.content>
        </x-ui.accordion.item>

        <x-ui.accordion.item data-test="weak-spots-accordion-item">
            <x-ui.accordion.heading>
                <span class="flex items-center gap-3">
                    <span>{{ __('Weak spots') }}</span>
                    <flux:badge size="sm" color="zinc">{{ count($report->weakSpots) }}</flux:badge>
                </span>
            </x-ui.accordion.heading>

            <x-ui.accordion.content class="space-y-4">
                {{-- Bewusst schlank: der Ausbildner diagnostiziert und arbeitet
                     gezielt nach; die richtige Antwort samt Erklärung steht in der
                     Lehrling-Sicht (Entscheidung 6/7). --}}
                <flux:subheading>
                    {{ __('The :count questions with the highest error rate: the recurring patterns, not every single slip.', ['count' => App\Actions\Analytics\LearnerProgressReport::TOP_WEAK_SPOTS]) }}
                </flux:subheading>

                <flux:table data-test="learner-weak-spots-table">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Question') }}</flux:table.column>
                        <flux:table.column>{{ __('Learning path') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Wrong') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($report->weakSpots as $spot)
                            <flux:table.row :key="'weak-spot-'.$spot->question->id" data-test="learner-weak-spot-row">
                                <flux:table.cell variant="strong">{{ $spot->question->prompt }}</flux:table.cell>
                                <flux:table.cell>{{ $spot->pathTitle ?? __('No path') }}</flux:table.cell>
                                <flux:table.cell align="end" data-test="learner-weak-spot-count">
                                    {{ __(':wrong× of :asked', ['wrong' => $spot->wrongCount, 'asked' => $spot->askedCount]) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    {{ __('No weak spots so far.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-ui.accordion.content>
        </x-ui.accordion.item>
    </x-ui.accordion>
</section>
