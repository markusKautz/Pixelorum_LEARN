<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Data\Analytics\GroupSummary;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * Der Überblick über die **laufenden** Gruppen aus einer
     * Sammelabfrage (Entscheidung 9) — je Gruppe einzeln zu rechnen wäre N+1.
     *
     * `active()` ist hier nicht Kosmetik (S5.7.2): eine abgeschlossene Gruppe
     * bleibt für immer in der Datenbank und verfälschte jede gruppenübergreifende
     * Zahl. Die Auswertung *einer* Gruppe zeigt archivierte weiterhin.
     *
     * @return Collection<int, GroupSummary>
     */
    #[Computed]
    public function summaries(): Collection
    {
        return app(GroupProgressReport::class)->summarize(
            Team::query()->groups()->active()->get()
        );
    }

    /**
     * Wie viele Teilnehmer_innen in den letzten
     * {@see GroupProgressReport::ACTIVE_WINDOW_DAYS} Tagen etwas getan haben.
     *
     * Eine **Flussgröße** und damit die einzige Form, die über mehrere
     * Gruppen trägt: eine fertige Gruppe trägt null bei statt 100 % (Ø) oder
     * lauter Karteileichen (Inaktive) und fällt von selbst heraus. Fällt die
     * Zahl deutlich, ist etwas los — Ferien, Prüfungswoche, kaputter Login.
     */
    #[Computed]
    public function activeCount(): int
    {
        return (int) $this->summaries->sum(fn (GroupSummary $summary): int => $summary->activeRecentlyCount);
    }

    /**
     * Der Anteil der aktiven Teilnehmer_innen, damit die Karte ohne Kopfrechnen
     * lesbar ist.
     */
    #[Computed]
    public function activePercent(): int
    {
        return $this->learnerCount === 0
            ? 0
            : intdiv($this->activeCount * 100, $this->learnerCount);
    }

    #[Computed]
    public function learnerCount(): int
    {
        return (int) $this->summaries->sum(fn (GroupSummary $summary): int => $summary->memberCount);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Dashboard'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <div>
        <flux:heading size="xl">
            {{ __('Hi :name!', ['name' => Str::before(auth()->user()->name, ' ')]) }}
        </flux:heading>
        <flux:subheading>{{ __('This is where your cohort stands.') }}</flux:subheading>
    </div>

    {{-- Drei Karten, alle über die laufenden Gruppen (S5.7.2). Ø-Lesefortschritt
         und Inaktive stehen weiterhin in der Gruppen-Auswertung — dort beziehen
         sie sich auf *eine* Gruppe samt ihrer eigenen Schwelle und bedeuten
         etwas; kohortenweit summiert taten sie das nicht. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <flux:card class="space-y-1" data-test="cohort-groups">
            <flux:text class="text-sm">{{ __('Groups') }}</flux:text>
            <flux:heading size="xl">{{ $this->summaries->count() }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('running years') }}
            </flux:text>
        </flux:card>

        <flux:card class="space-y-1" data-test="cohort-learners">
            <flux:text class="text-sm">{{ __('Apprentices') }}</flux:text>
            <flux:heading size="xl">{{ $this->learnerCount }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="cohort-active">
            <flux:text class="text-sm">{{ __('Active this week') }}</flux:text>
            <flux:heading size="xl">{{ $this->activeCount }}</flux:heading>
            <flux:progress :value="$this->activePercent" />
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="cohort-active-share">
                {{ __('of :total · :percent %', [
                    'total' => $this->learnerCount,
                    'percent' => $this->activePercent,
                ]) }}
            </flux:text>
        </flux:card>
    </div>

    <flux:button
        variant="primary"
        icon="user-group"
        :href="route('admin.groups.index')"
        wire:navigate
        data-test="cohort-groups-button"
    >
        {{ __('Open the groups') }}
    </flux:button>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Quick access') }}</flux:heading>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['route' => 'admin.learning-paths.index', 'icon' => 'academic-cap', 'label' => __('Learning paths')],
                ['route' => 'admin.questions.index', 'icon' => 'question-mark-circle', 'label' => __('Questions')],
                ['route' => 'admin.quizzes.index', 'icon' => 'clipboard-document-check', 'label' => __('Quizzes')],
                ['route' => 'admin.users.index', 'icon' => 'users', 'label' => __('Users')],
            ] as $shortcut)
                <flux:card
                    :href="route($shortcut['route'])"
                    wire:navigate
                    wire:key="shortcut-{{ $shortcut['route'] }}"
                    class="flex items-center gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    data-test="admin-shortcut"
                >
                    <flux:icon :name="$shortcut['icon']" class="size-6 text-zinc-500" />
                    <flux:heading>{{ $shortcut['label'] }}</flux:heading>
                </flux:card>
            @endforeach
        </div>
    </div>
</section>
