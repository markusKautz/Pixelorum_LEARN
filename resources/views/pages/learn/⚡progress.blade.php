<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Actions\Learning\BuildPracticeQuiz;
use App\Data\Analytics\LearnerReport;
use App\Data\Analytics\WeakSpot;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Ausschließlich eigene Daten (Entscheidung 7): kein {user}-Parameter,
     * keine Fremdsicht, deshalb auch keine Policy — die Sicherheit steckt
     * darin, dass es gar nichts zu autorisieren gibt.
     */
    #[Computed]
    public function report(): LearnerReport
    {
        return app(LearnerProgressReport::class)->handle(Auth::user());
    }

    /**
     * Die Pfade, aus denen die eigenen Schwachstellen stammen — Vorbelegung
     * für den Übungsquiz-Builder (Sprint 5.5).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function weakSpotPathIds(): array
    {
        return collect($this->report->weakSpots)
            ->map(fn (WeakSpot $spot): ?int => $spot->question->learning_path_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Eine einzelne Schwachstelle als Übungsrunde wiederholen (F2).
     *
     * Hat die Frage einen Herkunftspfad, wird eine Übungsrunde aus genau diesem
     * Pfad gebaut; die Schwachstellen-Frage ist darin fest gesetzt. Fragen ohne
     * Pfad bleiben beim alten Verhalten: Sprung auf das Quiz des jüngsten
     * falschen Versuchs.
     */
    public function practiseAgain(int $questionId): void
    {
        $spot = collect($this->report->weakSpots)
            ->first(fn (WeakSpot $s): bool => $s->question->id === $questionId);

        if (! $spot instanceof WeakSpot) {
            return;
        }

        $pathId = $spot->question->learning_path_id;

        if ($pathId === null) {
            if ($spot->quizId === null) {
                return;
            }

            $this->redirectRoute('learn.quizzes.show', ['quiz' => $spot->quizId], navigate: true);

            return;
        }

        $quiz = app(BuildPracticeQuiz::class)->handle(
            Auth::user(),
            [$pathId],
            BuildPracticeQuiz::DEFAULT_QUESTION_COUNT,
            [$questionId],
        );

        $this->redirectRoute('learn.quizzes.show', ['quiz' => $quiz], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('My progress'));
    }
}; ?>

@php($report = $this->report)

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

    <div>
        <flux:heading size="xl">{{ __('My progress') }}</flux:heading>
        <flux:subheading>{{ __('Where you stand, and what is worth practising next.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card class="space-y-3" data-test="stat-average-read">
            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Average read') }}</flux:heading>
            {{-- Nur der Prozentwert: averagePercent() mittelt die Pfade gleich
                 gewichtet, ein Lektionszähler daneben summiert über alle Pfade.
                 Bei 1/1 und 0/9 stünde „50 %" neben „1 von 10" — beides richtig
                 gerechnet, nebeneinander aber ein Widerspruch. Die Karte des
                 Admin-Drilldowns zeigt aus demselben Grund nur die Zahl. --}}
            <flux:heading size="xl">{{ $report->averagePercent() }} %</flux:heading>
            <flux:progress :value="$report->averagePercent()" />
        </flux:card>

        <flux:card class="space-y-3" data-test="stat-paths-completed">
            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Learning paths completed') }}</flux:heading>
            {{-- Zähler und Nenner in einer Zeile statt große Zahl plus Badge,
                 das die Zahl wiederholt — dieselbe Form wie die Karte im
                 Admin-Drilldown (⚡admin/analytics/learner.blade.php). --}}
            <flux:heading size="xl">{{ __(':done of :total done', [
                'done' => $report->completedPaths(),
                'total' => count($report->paths),
            ]) }}</flux:heading>
        </flux:card>
    </div>

    <flux:separator />

    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ __('What you should practise') }}</flux:heading>
                {{-- Sagt, dass es eine Auswahl ist: sonst wirkten die zehn wie
                     eine abgeschnittene Liste statt wie die größten Baustellen. --}}
                <flux:subheading>
                    {{ __('Your biggest gaps, with the correct answer and why.') }}
                </flux:subheading>
            </div>

            {{-- Vom Befund direkt ins Üben (Sprint 5.5): der Builder startet
                 mit genau den Pfaden vorbelegt, aus denen die Schwachstellen
                 stammen. --}}
            @if ($this->weakSpotPathIds !== [])
                <flux:button
                    variant="primary"
                    size="sm"
                    icon="academic-cap"
                    :href="route('learn.practice.create', ['paths' => implode(',', $this->weakSpotPathIds)])"
                    wire:navigate
                    data-test="practise-weak-spots-button"
                >
                    {{ __('Practise these topics') }}
                </flux:button>
            @endif
        </div>

        @if ($report->weakSpots === [])
            {{-- Positiv formuliert, nicht als Mangel (Merkmal 3). --}}
            <flux:callout color="green" icon="check-circle" data-test="weak-spots-empty-state">
                <flux:callout.text>{{ __('No weak spots yet. Keep it up.') }}</flux:callout.text>
            </flux:callout>
        @else
            <x-ui.accordion data-test="weak-spots-accordion">
                @foreach ($report->weakSpots as $spot)
                    <x-ui.accordion.item wire:key="weak-spot-{{ $spot->question->id }}" data-test="weak-spot-item">
                        <x-ui.accordion.heading>
                            <span class="flex flex-wrap items-center gap-2">
                                <span>{{ $spot->question->prompt }}</span>
                                <flux:badge size="sm" color="amber" data-test="weak-spot-count">
                                    {{ __(':wrong× of :asked', [
                                        'wrong' => $spot->wrongCount,
                                        'asked' => $spot->askedCount,
                                    ]) }}
                                </flux:badge>
                            </span>
                        </x-ui.accordion.heading>

                        <x-ui.accordion.content class="space-y-3">
                            @if ($spot->correctLabels !== [])
                                <div>
                                    <flux:text class="text-sm font-medium">{{ __('Correct answer') }}</flux:text>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($spot->correctLabels as $label)
                                            <li class="flex items-start gap-2" data-test="weak-spot-correct-answer">
                                                <flux:icon
                                                    name="check"
                                                    variant="mini"
                                                    class="mt-0.5 shrink-0 text-green-600 dark:text-green-400"
                                                />
                                                <flux:text>{{ $label }}</flux:text>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if (filled($spot->question->explanation))
                                {{-- Merkmal 8: die Begründung, nicht nur das Urteil. --}}
                                <flux:callout color="amber" icon="light-bulb" data-test="weak-spot-explanation">
                                    <flux:callout.text>{{ $spot->question->explanation }}</flux:callout.text>
                                </flux:callout>
                            @endif

                            <div class="flex flex-wrap items-center gap-3">
                                <flux:button
                                    variant="primary"
                                    size="sm"
                                    icon="arrow-path"
                                    wire:click="practiseAgain({{ $spot->question->id }})"
                                    data-test="weak-spot-retry-button"
                                >
                                    {{ __('Practise again') }}
                                </flux:button>

                                @if ($spot->pathTitle !== null)
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $spot->pathTitle }}
                                    </flux:text>
                                @endif
                            </div>
                        </x-ui.accordion.content>
                    </x-ui.accordion.item>
                @endforeach
            </x-ui.accordion>
        @endif
    </div>

    <flux:separator />

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Recent attempts') }}</flux:heading>

        <flux:table data-test="my-attempts-table">
            <flux:table.columns>
                <flux:table.column>{{ __('Quiz') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Score') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Date') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($report->attempts->take(5) as $attempt)
                    <flux:table.row :key="'attempt-'.$attempt->id" data-test="my-attempt-row">
                        <flux:table.cell variant="strong">{{ $attempt->quiz->title }}</flux:table.cell>
                        <flux:table.cell align="end">
                            {{-- Amber statt rot: ein nicht bestandener Versuch ist eine
                                 Zwischenstation, das Quiz ist beliebig wiederholbar. --}}
                            <flux:badge size="sm" :color="$attempt->passed ? 'green' : 'amber'" data-test="my-attempt-score">
                                {{ __(':score of :max (:percent %)', [
                                    'score' => $attempt->score,
                                    'max' => $attempt->max_score,
                                    'percent' => $attempt->percent,
                                ]) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $attempt->completed_at?->format('d.m.Y') }}</flux:table.cell>
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
    </div>
</section>
