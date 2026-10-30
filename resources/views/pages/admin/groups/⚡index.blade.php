<?php

use App\Actions\Analytics\GroupProgressReport;
use App\Data\Analytics\GroupSummary;
use App\Models\Team;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    /**
     * Entscheidung 2: direktes Team::create ohne Owner-Membership —
     * CreateTeam wird bewusst nicht wiederverwendet (die Action hängt eine
     * Membership an und ruft switchTeam(); die Autorität des Admins kommt
     * aus Gate::before, nicht aus Team-Rollen).
     */
    public function createGroup(): void
    {
        Gate::authorize('create', Team::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = Team::create(['name' => $validated['name']]);

        $this->modal('create-group')->close();

        Flux::toast(variant: 'success', text: __('Group created.'));

        $this->redirectRoute('admin.groups.show', ['team' => $team->slug], navigate: true);
    }

    /**
     * @return Collection<int, Team>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Team::query()
            ->groups()
            ->withCount(['apprentices', 'invitations'])
            // Archivierte Gruppen nach unten, laufende zuerst. Die Sortierung
            // läuft über einen CASE und nicht über `orderBy('archived_at')`:
            // SQLite sortiert NULL nach vorne, PostgreSQL nach hinten (siehe
            // technical-concept.md, „Datenbank") — die Reihenfolge hinge sonst
            // davon ab, wo die App läuft.
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();
    }

    /**
     * Der Ø-Fortschritt aller Gruppen aus **einer** Sammelabfrage
     * (Entscheidung 9) — je Zeile einzeln zu rechnen wäre N+1.
     *
     * @return BaseCollection<int, GroupSummary>
     */
    #[Computed]
    public function summaries(): BaseCollection
    {
        return app(GroupProgressReport::class)->summarize($this->groups);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Groups'));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Groups') }}</flux:heading>
            <flux:subheading>{{ __('All apprentice groups across the platform.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="create-group">
            <flux:button variant="primary" icon="plus" data-test="new-group-button">
                {{ __('New group') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table data-test="groups-table">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Members') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Open invitations') }}</flux:table.column>
            <flux:table.column>{{ __('Average progress') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->groups as $group)
                @php($summary = $this->summaries->get($group->id))

                <flux:table.row
                    :key="$group->id"
                    data-test="group-row"
                    @class(['opacity-60' => $group->isArchived()])
                >
                    <flux:table.cell variant="strong">
                        <div class="flex items-center gap-2">
                            <span class="block max-w-xs truncate" title="{{ $group->name }}">{{ $group->name }}</span>

                            @if ($group->isArchived())
                                <flux:badge size="sm" color="zinc" data-test="group-archived-badge">
                                    {{ __('Archived') }}
                                </flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $group->apprentices_count }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $group->invitations_count }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:progress :value="$summary?->averagePercent ?? 0" class="w-24" />
                            {{-- „Pfaden" statt bloß „fertig": die Zahl zählt Pfad-Belegungen
                                 (Teilnehmer_innen x Pfade), steht hier aber neben der Mitglieder-Spalte
                                 und wurde deshalb als „x von y Teilnehmer_innen" gelesen (Review M1).
                                 Die Auswertungsseite klärt das über eine Subline; in der
                                 Tabellenzelle ist dafür kein Platz, also trägt das Label die
                                 Einheit selbst. --}}
                            <span class="text-sm whitespace-nowrap tabular-nums" data-test="group-progress-label">
                                {{ __(':done of :total paths done', [
                                    'done' => $summary?->completedPathCount ?? 0,
                                    'total' => $summary?->pathSlotCount ?? 0,
                                ]) }}
                            </span>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:tooltip :content="__('Analytics')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="chart-bar"
                                    :href="route('admin.groups.analytics', $group)"
                                    wire:navigate
                                    data-test="group-analytics-button"
                                />
                            </flux:tooltip>

                            <flux:tooltip :content="__('Manage group')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    :href="route('admin.groups.show', $group)"
                                    wire:navigate
                                    data-test="group-show-button"
                                />
                            </flux:tooltip>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No groups yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="create-group" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createGroup" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a new group') }}</flux:heading>
                <flux:subheading>{{ __('Apprentices are invited afterwards from the group page.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" autofocus data-test="create-group-name" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-group-submit">
                    {{ __('Create group') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
