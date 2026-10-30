<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Data\Analytics\GroupReport;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    /**
     * Die can:admin-Middleware schützt nur das Laden — die Autorisierung
     * steht trotzdem hier, weil jede Livewire-Anfrage ein eigener Endpunkt
     * ist (Sicherheits-Grundmuster seit Sprint 3).
     */
    public function mount(Team $team): void
    {
        Gate::authorize('admin');

        // Persönliche Teams sind keine Gruppen.
        abort_if($team->is_personal, 404);

        $this->team = $team;
    }

    /**
     * Live gerechnet (Entscheidung 1): fügt der Admin einem Pfad eine Lektion
     * hinzu, fällt der Fortschritt hier beim nächsten Aufruf — ohne Cache,
     * ohne „Neu berechnen"-Knopf.
     */
    #[Computed]
    public function report(): GroupReport
    {
        return app(GroupProgressReport::class)->handle($this->team);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Analytics: :name', ['name' => $this->team->name]));
    }
}; ?>

@php
    $report = $this->report;
    $summary = $report->summary;
@endphp

<section class="w-full space-y-8">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.groups.index')"
            wire:navigate
            data-test="back-to-groups-button"
        >
            {{ __('Groups') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $team->name }}</flux:heading>
            <flux:subheading>{{ __('How far this group has come.') }}</flux:subheading>
        </div>

        <flux:button
            variant="ghost"
            icon="user-group"
            :href="route('admin.groups.show', $team)"
            wire:navigate
            data-test="manage-members-button"
        >
            {{ __('Manage members') }}
        </flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="flex flex-col justify-between gap-2" data-test="summary-average">
            <flux:text class="text-sm">{{ __('Average reading progress') }}</flux:text>
            <flux:heading size="xl">{{ $summary->averagePercent }} %</flux:heading>
            <flux:progress :value="$summary->averagePercent" />
        </flux:card>

        {{-- Verteilung statt Summe (DEV-11): Die frühere Karte zählte
             Pfad-Belegungen (Mitglieder × Pfade), deren Nenner mit jedem neuen
             Pfad wächst. Grundlage hier ist derselbe Ø-Wert wie in der Spalte
             „Ø gelesen" der Teilnehmer_innen-Tabelle. --}}
        <flux:card class="space-y-2" data-test="summary-distribution">
            <flux:text class="text-sm">{{ __('Apprentices by reading progress') }}</flux:text>
            <dl class="space-y-1 text-sm">
                @foreach ([
                    'below25' => __('below 25 %'),
                    'below50' => __('25 to under 50 %'),
                    'below75' => __('50 to under 75 %'),
                    'from75' => __('75 % and above'),
                ] as $band => $label)
                    <div class="flex items-center justify-between gap-3" data-test="distribution-{{ $band }}">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="font-medium tabular-nums text-zinc-800 dark:text-white">{{ $summary->progressBands[$band] }}</dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>

        <flux:card class="flex flex-col justify-between gap-2" data-test="summary-inactive">
            <flux:text class="text-sm">{{ __('Inactive apprentices') }}</flux:text>
            <flux:heading size="xl">{{ $summary->inactiveCount }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('no activity for over :count days', ['count' => $team->inactivity_threshold_days]) }}
            </flux:text>
        </flux:card>
    </div>

    <flux:separator variant="subtle" />

    {{-- Ein Accordion für alles Aggregierte, die Teilnehmer_innen-Tabelle bleibt darunter
         offen stehen (Retest 2026-07-29): der Admin öffnet diese Seite, um zu
         sehen, wer Hilfe braucht — die Zahlen je Pfad und je Quiz holt er dazu. --}}
    <x-ui.accordion data-test="group-accordion">
        <x-ui.accordion.item data-test="paths-accordion-item">
            <x-ui.accordion.heading>
                <span class="flex items-center gap-3">
                    <span>{{ __('Progress per path') }}</span>
                    <flux:badge size="sm" color="zinc">{{ count($report->paths) }}</flux:badge>
                </span>
            </x-ui.accordion.heading>

            <x-ui.accordion.content class="space-y-4">
                <flux:subheading>
                    {{ __('The pass rate counts all members of the group, not only those who attempted.') }}
                </flux:subheading>

                <flux:table data-test="paths-table">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Learning path') }}</flux:table.column>
                        <flux:table.column>{{ __('Average read') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Completed') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Pass rate') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($report->paths as $row)
                            <flux:table.row :key="'path-'.$row->path->id" data-test="path-row">
                                <flux:table.cell variant="strong">
                                    <span class="block max-w-xs truncate" title="{{ $row->path->title }}">
                                        {{ $row->path->title }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-3">
                                        <flux:progress :value="$row->averagePercent" class="w-24" />
                                        <span class="text-sm tabular-nums" data-test="path-average">{{ $row->averagePercent }} %</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end" data-test="path-completed">
                                    {{ __(':done of :total done', ['done' => $row->completedCount, 'total' => $row->memberCount]) }}
                                </flux:table.cell>
                                <flux:table.cell align="end" data-test="path-pass-rate">
                                    @if ($row->hasQuiz)
                                        {{ __(':percent % (:passed of :total)', [
                                            'percent' => $row->passRate(),
                                            'passed' => $row->passedCount,
                                            'total' => $row->memberCount,
                                        ]) }}
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('No final quiz') }}</flux:badge>
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
            </x-ui.accordion.content>
        </x-ui.accordion.item>

        {{-- Freie Quizze, die dieser Gruppe ausgegeben wurden (Sprint 5.6).
             Eigener Abschnitt statt einer Zeile in der Pfad-Tabelle: sie haben
             keinen Lesefortschritt, dafür eine Antritts-Quote. --}}
        @if ($report->quizzes !== [])
            <x-ui.accordion.item data-test="quizzes-accordion-item">
                <x-ui.accordion.heading>
                    <span class="flex items-center gap-3">
                        <span>{{ __('Quizzes handed out') }}</span>
                        <flux:badge size="sm" color="zinc">{{ count($report->quizzes) }}</flux:badge>
                    </span>
                </x-ui.accordion.heading>

                <x-ui.accordion.content class="space-y-4">
                    <flux:subheading>
                        {{ __('The pass rate counts all members of the group; the average score only those who attempted.') }}
                    </flux:subheading>

                    <flux:table data-test="assigned-quizzes-table">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Quiz') }}</flux:table.column>
                            <flux:table.column>{{ __('Average of those who attempted') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Attempted') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Pass rate') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($report->quizzes as $row)
                                <flux:table.row :key="'quiz-'.$row->quiz->id" data-test="assigned-quiz-row">
                                    <flux:table.cell variant="strong">
                                        <span class="block max-w-xs truncate" title="{{ $row->quiz->title }}">
                                            {{ $row->quiz->title }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($row->wasAttempted())
                                            <div class="flex items-center gap-3">
                                                <flux:progress :value="$row->averagePercent" class="w-24" />
                                                <span class="text-sm tabular-nums" data-test="assigned-quiz-average">
                                                    {{ $row->averagePercent }} %
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No value') }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" data-test="assigned-quiz-attempted">
                                        @if ($row->wasAttempted())
                                            {{ __(':done of :total done', [
                                                'done' => $row->attemptedCount,
                                                'total' => $row->memberCount,
                                            ]) }}
                                        @else
                                            {{-- „Noch niemand" ist ein anderer Befund als
                                                 „alle durchgefallen" — amber statt einer 0. --}}
                                            <flux:badge size="sm" color="amber" data-test="assigned-quiz-untouched">
                                                {{ __('Nobody yet') }}
                                            </flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" data-test="assigned-quiz-pass-rate">
                                        {{ __(':percent % (:passed of :total)', [
                                            'percent' => $row->passRate(),
                                            'passed' => $row->passedCount,
                                            'total' => $row->memberCount,
                                        ]) }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </x-ui.accordion.content>
            </x-ui.accordion.item>
        @endif
    </x-ui.accordion>

    <flux:separator variant="subtle" />

    <div class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Apprentices') }}</flux:heading>
            <flux:subheading>{{ __('Who needs support. Click through for the details.') }}</flux:subheading>
        </div>

        <flux:table data-test="members-table">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Average read') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Paths completed') }}</flux:table.column>
                <flux:table.column>{{ __('Last activity') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($report->members as $row)
                    <flux:table.row :key="'member-'.$row->member->id" data-test="member-row">
                        <flux:table.cell variant="strong">{{ $row->member->name }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:progress :value="$row->averagePercent" class="w-24" />
                                <span class="text-sm tabular-nums" data-test="member-average">{{ $row->averagePercent }} %</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" data-test="member-completed">
                            {{ __(':done of :total done', ['done' => $row->completedPaths, 'total' => $row->totalPaths]) }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <span data-test="member-last-activity">
                                    {{ $row->lastActivityAt?->format('d.m.Y') ?? __('never') }}
                                </span>

                                {{-- Amber statt rot: die Markierung ist ein Hinweis für
                                     den Admin, kein Verstoß (Merkmal 3). --}}
                                @if ($row->isInactive)
                                    <flux:badge size="sm" color="amber" data-test="member-inactive-badge">
                                        {{ __('Inactive') }}
                                    </flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:tooltip :content="__('Show details')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-right"
                                    :href="route('admin.groups.learner', ['team' => $team, 'member' => $row->member])"
                                    wire:navigate
                                    data-test="learner-drilldown-button"
                                />
                            </flux:tooltip>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No members yet. Invite the first apprentice.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
