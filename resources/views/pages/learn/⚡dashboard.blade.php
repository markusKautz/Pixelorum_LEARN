<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Actions\Learning\ResolveContinuationPoint;
use App\Data\Analytics\PathProgressRow;
use App\Data\Analytics\WeakSpot;
use App\Data\ContinuationPoint;
use App\Enums\ProgressStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Admins sehen diese Seite nie: `EnsureTeamMembership` verzweigt sie im
 * Teilnehmer_innen-Bereich auf ihr eigenes Dashboard (Entscheidung 8). Die Regel steht
 * dort und nicht hier in `mount()`, weil die Middleware bei einer *fremden*
 * Gruppe schon mit 403 abbrach, bevor die Komponente überhaupt gemountet wurde.
 *
 * Der Routenname `dashboard` bleibt bewusst an dieser Seite — Login-Redirect,
 * Sidebar, Team-Switcher und die Einladungs-Flows hängen daran.
 */
new class extends Component {
    /**
     * Der Fortschritt der Teilnehmer_in auf allen sichtbaren Pfaden —
     * nach Pfad-ID indiziert (F5).
     *
     * Dieselbe Quelle, aus der `/my-progress` und `ResolveContinuationPoint`
     * lesen; so antwortet die Karte dieselbe Frage wie die Detailseite
     * (Sprint-5-Entscheidung 3).
     *
     * @return array<int, PathProgressRow>
     */
    #[Computed]
    public function pathProgress(): array
    {
        return app(LearnerProgressReport::class)->pathProgress(Auth::user());
    }

    /**
     * Der Einstiegspunkt „Weiterlernen, wo aufgehört" (Konzept h, Merkmal 2).
     */
    #[Computed]
    public function continuation(): ?ContinuationPoint
    {
        return app(ResolveContinuationPoint::class)->handle(Auth::user(), $this->pathProgress);
    }

    /**
     * Die drei dringendsten eigenen Schwachstellen (Merkmal 7).
     *
     * Bewusst nur die Spitze und bewusst nicht über den vollen Report: die
     * Karte ist ein Anstoß, die ganze Liste samt Erklärung und
     * Wiederholen-Link steht auf `learn.progress`.
     *
     * @return array<int, WeakSpot>
     */
    #[Computed]
    public function weakSpots(): array
    {
        return app(LearnerProgressReport::class)->weakSpots(Auth::user(), limit: 3);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Dashboard'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <livewire:pages::teams.pending-invitations-modal />

    <div>
        <flux:heading size="xl">
            {{ __('Hi :name!', ['name' => Str::before(auth()->user()->name, ' ')]) }}
        </flux:heading>
        <flux:subheading>{{ __('These are your learning paths.') }}</flux:subheading>
    </div>

    @if ($this->continuation !== null)
        {{-- Merkmal 2 (hoher Anteil echter Lernzeit): ein Klick vom Login
             zum Weiterlernen. --}}
        <flux:card
            class="flex flex-wrap items-center justify-between gap-4 border-[var(--color-accent)]/40 bg-[var(--color-accent)]/5"
            data-test="continuation-card"
        >
            <div>
                @if ($this->continuation->context !== null)
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $this->continuation->context }}
                    </flux:text>
                @endif
                <flux:heading size="lg">{{ $this->continuation->title }}</flux:heading>
            </div>

            <flux:button
                variant="primary"
                icon="play"
                :href="route($this->continuation->routeName, $this->continuation->routeParameters)"
                wire:navigate
                data-test="continuation-button"
            >
                {{ $this->continuation->label }}
            </flux:button>
        </flux:card>
    @endif

    @if ($this->weakSpots !== [])
        {{-- Merkmal 7 (individuelles Fördern): die Teilnehmer_in sieht selbst, was
             er als Nächstes üben sollte. Amber statt rot — das ist ein
             Vorschlag, kein Tadel (Merkmal 3). --}}
        <flux:card class="space-y-4" data-test="practice-card">
            <div>
                <flux:heading size="lg">{{ __('What you should practise') }}</flux:heading>
                <flux:subheading>{{ __('These questions tripped you up most often.') }}</flux:subheading>
            </div>

            <ul class="space-y-2">
                @foreach ($this->weakSpots as $spot)
                    <li class="flex items-start gap-2" wire:key="weak-spot-{{ $spot->question->id }}" data-test="practice-item">
                        <flux:icon name="light-bulb" variant="mini" class="mt-0.5 shrink-0 text-amber-500" />
                        <flux:text>{{ $spot->question->prompt }}</flux:text>
                    </li>
                @endforeach
            </ul>

            <flux:button
                variant="filled"
                icon="chart-bar"
                :href="route('learn.progress')"
                wire:navigate
                data-test="practice-card-button"
            >
                {{ __('Show my progress') }}
            </flux:button>
        </flux:card>
    @endif

    @if ($this->pathProgress === [])
        <flux:card class="text-center" data-test="paths-empty-state">
            <flux:icon name="academic-cap" class="mx-auto mb-3 size-8 text-zinc-400" />
            <flux:heading size="lg">{{ __('Nothing to learn yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('No learning paths have been released yet.') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->pathProgress as $row)
                @php
                    $path = $row->path;
                    $isCompleted = $row->status === ProgressStatus::Completed;
                @endphp

                {{-- Kein grüner Rahmen am fertigen Pfad: `flux:card` bringt
                     `border-zinc-200` mit, und Tailwind gibt die Farben in
                     Palettenreihenfolge aus — zinc steht im Stylesheet nach green.
                     Bei gleicher Spezifität gewinnt die spätere Regel, der Rahmen
                     war also nie zu sehen. Beim Retest abgenommen (F5): die zwei
                     grünen Badges tragen das Signal. --}}
                <flux:card
                    wire:key="path-{{ $path->id }}"
                    class="flex flex-col gap-4"
                    data-test="path-card"
                >
                    <div class="space-y-2">
                        <flux:heading size="lg">{{ $path->title }}</flux:heading>

                        @if (filled($path->summary))
                            <flux:text class="line-clamp-3">{{ $path->summary }}</flux:text>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if (filled($path->difficulty))
                            <flux:badge size="sm" color="zinc" data-test="path-difficulty">
                                {{ Str::ucfirst($path->difficulty) }}
                            </flux:badge>
                        @endif

                        @if ($path->estimated_minutes !== null)
                            <flux:badge size="sm" color="zinc" icon="clock">
                                {{ __('approx. :count min', ['count' => $path->estimated_minutes]) }}
                            </flux:badge>
                        @endif

                        @if (! $row->hasQuiz)
                            <flux:badge size="sm" color="zinc" data-test="quiz-badge-none">{{ __('No final quiz') }}</flux:badge>
                        @elseif ($row->quizPassed)
                            <flux:badge size="sm" color="green" icon="check-circle" data-test="quiz-badge-passed">
                                {{ __('Final quiz passed') }}
                            </flux:badge>
                        @else
                            <flux:badge size="sm" color="amber" data-test="quiz-badge-open">{{ __('Final quiz open') }}</flux:badge>
                        @endif

                        @if ($isCompleted)
                            <flux:badge size="sm" :color="$row->status->color()" data-test="path-status-badge">
                                {{ $row->status->label() }}
                            </flux:badge>
                        @endif
                    </div>

                    <div class="mt-auto space-y-2">
                        <flux:progress :value="$row->percent" />
                        <flux:text class="text-sm" data-test="path-progress-label">
                            {{ __(':done of :total lessons', ['done' => $row->completedLessons, 'total' => $row->totalLessons]) }}
                        </flux:text>
                    </div>

                    <flux:button
                        variant="primary"
                        :href="route('learn.paths.show', $path)"
                        wire:navigate
                        class="w-full"
                        data-test="path-open-button"
                    >
                        @if ($isCompleted)
                            {{ __('Review again') }}
                        @elseif ($row->status === ProgressStatus::InProgress)
                            {{ __('Resume') }}
                        @else
                            {{ __('Start') }}
                        @endif
                    </flux:button>
                </flux:card>
            @endforeach
        </div>
    @endif
</section>
