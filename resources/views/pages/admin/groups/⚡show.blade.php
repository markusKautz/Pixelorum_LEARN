<?php

use App\Actions\Teams\DeleteTeam;
use App\Models\LearningPath;
use App\Models\Team;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $name = '';

    /** Ab wie vielen Tagen ohne Aktivität die Auswertung „inaktiv" meldet. */
    public int $inactivityThresholdDays = 14;

    /** Die Namenseingabe, mit der die Gruppenlöschung bestätigt wird. */
    public string $deleteConfirmation = '';

    /**
     * Ob die Konten der Lehrlinge mitgelöscht werden.
     *
     * Standard **aus** (Entscheidung 4): die zerstörungsfreie Variante ist die
     * Vorauswahl. Wer die Konten mitnehmen will, sagt es ausdrücklich.
     */
    public bool $deleteApprentices = false;

    public function mount(Team $team): void
    {
        // Persönliche Teams sind keine Gruppen — Detailseite nur für Gruppen
        abort_if($team->is_personal, 404);

        $this->team = $team;
        $this->name = $team->name;
        $this->inactivityThresholdDays = $team->inactivity_threshold_days;
    }

    public function renameGroup(): void
    {
        Gate::authorize('update', $this->team);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $this->team->update(['name' => $validated['name']]);

        Flux::toast(variant: 'success', text: __('Group renamed.'));

        // Der updating-Hook erneuert den Slug bei Namensänderung
        $this->redirectRoute('admin.groups.show', ['team' => $this->team->fresh()->slug], navigate: true);
    }

    /**
     * Die Schwelle, ab der die Gruppen-Auswertung einen Lehrling als inaktiv
     * markiert (Entscheidung 5). Gesetzt wird sie hier, gelesen von
     * `admin.groups.analytics` — pro Gruppe, weil ein Jahrgang im
     * Blockunterricht andere Rhythmen hat als einer ohne.
     */
    public function saveThreshold(): void
    {
        Gate::authorize('update', $this->team);

        $validated = $this->validate([
            'inactivityThresholdDays' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $this->team->update(['inactivity_threshold_days' => $validated['inactivityThresholdDays']]);

        Flux::toast(variant: 'success', text: __('Inactivity threshold saved.'));
    }

    /**
     * Einen Jahrgang abschließen bzw. wieder aufnehmen (S5.7.1).
     *
     * Umkehrbar und ohne Bestätigungsdialog: archivieren löscht nichts, es nimmt
     * die Gruppe nur aus den kohortenweiten Kennzahlen des Dashboards. Die
     * Gruppe bleibt in der Liste, ihre Auswertung bleibt erreichbar — der
     * Jahrgang ist Historie, keine Sperrzone.
     */
    public function toggleArchived(): void
    {
        Gate::authorize('update', $this->team);

        // Direkt zugewiesen statt per update(): `archived_at` steht bewusst
        // **nicht** in der Fillable-Liste des Models — der Zeitstempel kommt nie
        // aus einer Eingabe, sondern immer aus dieser einen Aktion.
        $this->team->archived_at = $this->team->isArchived() ? null : now();
        $this->team->save();

        Flux::toast(
            variant: 'success',
            text: $this->team->isArchived() ? __('Group archived.') : __('Group reactivated.'),
        );
    }

    /**
     * Den Löschdialog frisch öffnen (Review H1).
     *
     * Der Reset gehört ans **Öffnen**, nicht ans Schließen: `modal.trigger` und
     * `modal.close` sind reine Alpine-Interaktionen, bei denen kein PHP läuft.
     * Bestätigungstext und Häkchen leben aber im Livewire-Snapshot und
     * überlebten so „Abbrechen" — der nächste Klick auf „Endgültig löschen"
     * träfe ohne weiteres Zutun. Am Öffnen hängt nur *ein* Pfad, am Schließen
     * vier (Abbrechen, ESC, Hintergrund, Weg-Navigieren). Muster:
     * `admin/users/⚡index.blade.php`.
     */
    public function confirmDeleteGroup(): void
    {
        Gate::authorize('admin');

        $this->deleteConfirmation = '';
        $this->deleteApprentices = false;
        $this->resetErrorBag('deleteConfirmation');

        $this->modal('delete-group')->show();
    }

    /**
     * Die Gruppe endgültig löschen (S5.7.5).
     *
     * Autorisiert über `admin` und nicht über `TeamPolicy::delete()`: die Policy
     * verlangt `TeamPermission::DeleteTeam`, also eine Team-Rolle, die der
     * globale Admin gerade nicht hat — seine Autorität kommt aus `Gate::before`
     * (siehe Kopf von `routes/admin.php`).
     */
    public function deleteGroup(): void
    {
        Gate::authorize('admin');

        if ($this->deleteConfirmation !== $this->team->name) {
            $this->addError('deleteConfirmation', __('The team name does not match.'));

            return;
        }

        $deletedCount = app(DeleteTeam::class)->handle($this->team, $this->deleteApprentices);

        Flux::toast(variant: 'success', text: $deletedCount > 0
            ? __('Group and :count apprentice accounts deleted.', ['count' => $deletedCount])
            : __('Group deleted.'));

        $this->redirectRoute('admin.groups.index', navigate: true);
    }

    /**
     * Das Label der Bestätigungseingabe.
     *
     * Als Computed und nicht inline im Attribut: der Übersetzungsschlüssel
     * enthält selbst doppelte Anführungszeichen (`Type ":name" to confirm`), und
     * die brechen ein `:label="__(…)"` beim Blade-Parsen. Dasselbe Muster nutzt
     * `pages/teams/⚡delete-team-modal.blade.php`.
     */
    #[Computed]
    public function deleteConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->team->name]);
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team->apprentices()->orderBy('name')->get();
    }

    /**
     * Die freigegebenen Pfade, die diese Gruppe sieht (#9): allgemeine und
     * ihr zugewiesene, nach der Reihenfolge der Pfadliste.
     *
     * @return Collection<int, LearningPath>
     */
    #[Computed]
    public function visiblePaths(): Collection
    {
        return LearningPath::query()
            ->visibleToLearners()
            ->where(fn ($paths) => $paths
                ->whereDoesntHave('teams')
                ->orWhereHas('teams', fn ($teams) => $teams->whereKey($this->team->id)))
            ->withCount('teams')
            ->orderBy('position')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\TeamInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->team->invitations()
            ->whereNull('accepted_at')
            ->latest()
            ->get();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Group :name', ['name' => $this->team->name]));
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
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
            <div class="flex items-center gap-2">
                <flux:heading size="xl">{{ $team->name }}</flux:heading>

                @if ($team->isArchived())
                    <flux:badge color="zinc" data-test="group-archived-badge">{{ __('Archived') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>{{ __('Manage members and invitations of this group.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                variant="ghost"
                :icon="$team->isArchived() ? 'arrow-uturn-left' : 'archive-box'"
                wire:click="toggleArchived"
                data-test="group-archive-button"
            >
                {{ $team->isArchived() ? __('Reactivate year') : __('Archive year') }}
            </flux:button>

            <flux:button
                variant="ghost"
                icon="chart-bar"
                :href="route('admin.groups.analytics', $team)"
                wire:navigate
                data-test="group-analytics-link"
            >
                {{ __('Analytics') }}
            </flux:button>
        </div>
    </div>

    @if ($team->isArchived())
        <flux:callout icon="archive-box" data-test="group-archived-callout">
            <flux:callout.heading>{{ __('This year is archived') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('It no longer counts towards the dashboard figures. Nothing was deleted: members, progress and analytics stay available.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="renameGroup" class="flex items-end gap-2">
        <div class="flex-1">
            <flux:input wire:model="name" :label="__('Name')" data-test="group-name-input" />
        </div>
        <flux:button variant="primary" type="submit" data-test="group-rename-button">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveThreshold" class="flex items-end gap-2">
        <div class="flex-1">
            <flux:input
                type="number"
                min="1"
                max="365"
                wire:model="inactivityThresholdDays"
                :label="__('Inactivity threshold (days)')"
                :description="__('From this many days without activity an apprentice is flagged in the analytics.')"
                data-test="inactivity-threshold-input"
            />
        </div>
        <flux:button variant="filled" type="submit" data-test="inactivity-threshold-button">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">{{ __('Members') }}</flux:heading>
                <flux:subheading>{{ __('Who belongs to this group') }}</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                {{-- Kein wire:navigate: das Ziel ist ein Dateidownload, keine Seite. --}}
                <flux:button
                    variant="filled"
                    icon="arrow-down-tray"
                    :href="route('admin.groups.invitations', ['team' => $team->slug])"
                    data-test="download-invitations-button"
                >
                    {{ __('Activation links') }}
                </flux:button>

                <flux:modal.trigger name="import-invitations">
                    <flux:button variant="filled" icon="arrow-up-tray" data-test="import-invitations-button">
                        {{ __('Import list') }}
                    </flux:button>
                </flux:modal.trigger>

                <flux:modal.trigger name="invite-member">
                    <flux:button variant="primary" icon="user-plus" data-test="invite-member-button">
                        {{ __('Invite apprentice') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </div>

        <div class="space-y-3">
            @forelse ($this->members as $member)
                <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="member-row">
                    <div class="flex items-center gap-4">
                        <flux:avatar :name="$member->name" :initials="$member->initials()" />
                        <div>
                            <div class="font-medium">{{ $member->name }}</div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member->email }}</flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:badge color="zinc">{{ $member->pivot->role->label() }}</flux:badge>

                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Joined :date', ['date' => $member->pivot->created_at?->format('d.m.Y')]) }}
                        </flux:text>

                        <flux:modal.trigger name="remove-member-{{ $member->id }}">
                            <flux:tooltip :content="__('Remove member')">
                                <flux:button variant="ghost" size="sm" icon="x-mark" data-test="member-remove-button" />
                            </flux:tooltip>
                        </flux:modal.trigger>
                    </div>
                </div>

                <livewire:pages::teams.remove-member-modal
                    :team="$team"
                    :member-id="$member->id"
                    :member-name="$member->name"
                    :modal-name="'remove-member-'.$member->id"
                    redirect-route="admin.groups.show"
                    :key="'remove-member-modal-'.$member->id"
                />
            @empty
                <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('No members yet. Invite the first apprentice.') }}
                </flux:text>
            @endforelse
        </div>
    </div>

    @if ($this->pendingInvitations->isNotEmpty())
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Pending invitations') }}</flux:heading>
                <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
            </div>

            <div class="space-y-3">
                @foreach ($this->pendingInvitations as $invitation)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitation-row">
                        <div class="flex items-center gap-4">
                            <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="envelope" class="text-zinc-500" />
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $invitation->email }}</span>

                                    @if ($invitation->isExpired())
                                        <flux:badge size="sm" color="amber" data-test="invitation-expired-badge">
                                            {{ __('Expired') }}
                                        </flux:badge>
                                    @endif
                                </div>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{-- Ohne die Verzweigung stand hier „Läuft ab vor 3 Tagen":
                                         `diffForHumans()` formuliert Vergangenheit selbst, das
                                         Label unterstellte aber Zukunft (S5.7.6). --}}
                                    @if ($invitation->isExpired())
                                        {{ __('Expired :time', ['time' => $invitation->expires_at->diffForHumans()]) }}
                                    @elseif ($invitation->expires_at)
                                        {{ __('Expires :time', ['time' => $invitation->expires_at->diffForHumans()]) }}
                                    @else
                                        {{ $invitation->role->label() }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>

                        <flux:modal.trigger name="cancel-invitation-{{ $invitation->code }}">
                            <flux:tooltip :content="__('Cancel invitation')">
                                <flux:button variant="ghost" size="sm" icon="x-mark" data-test="invitation-cancel-button" />
                            </flux:tooltip>
                        </flux:modal.trigger>
                    </div>

                    <livewire:pages::teams.cancel-invitation-modal
                        :team="$team"
                        :invitation-code="$invitation->code"
                        :invitation-email="$invitation->email"
                        :modal-name="'cancel-invitation-'.$invitation->code"
                        redirect-route="admin.groups.show"
                        :key="'cancel-invitation-modal-'.$invitation->code"
                    />
                @endforeach
            </div>
        </div>
    @endif

    <div class="space-y-4" data-test="group-paths-section">
        <div>
            <flux:heading size="lg">{{ __('Learning paths') }}</flux:heading>
            <flux:subheading>{{ __('Released paths this group sees. You limit a path to groups on its page.') }}</flux:subheading>
        </div>

        <div class="space-y-2">
            @forelse ($this->visiblePaths as $path)
                <div wire:key="group-path-{{ $path->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="group-path-row">
                    <a href="{{ route('admin.learning-paths.edit', $path) }}" wire:navigate class="min-w-0 truncate font-medium hover:underline">{{ $path->title }}</a>
                    @if ($path->teams_count > 0)
                        <flux:badge size="sm" color="sky" icon="user-group" data-test="group-path-assigned-badge">{{ __('Assigned') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc" data-test="group-path-general-badge">{{ __('All groups') }}</flux:badge>
                    @endif
                </div>
            @empty
                <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400">{{ __('No released paths for this group yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <livewire:pages::teams.invite-member-modal :team="$team" redirect-route="admin.groups.show" :role-selectable="false" />

    <livewire:pages::admin.groups.import-invitations-modal :team="$team" />

    <flux:separator />

    <div class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Delete this group') }}</flux:heading>
            <flux:subheading>
                {{ __('Archiving is the usual way to close a year: it keeps everything. Deleting cannot be undone.') }}
            </flux:subheading>
        </div>

        {{-- Serverseitig geöffnet statt per `modal.trigger`: nur so wird der
             Dialog bei jedem Öffnen frisch (Review H1). --}}
        <flux:button
            variant="danger"
            icon="trash"
            wire:click="confirmDeleteGroup"
            data-test="group-delete-button"
        >
            {{ __('Delete group') }}
        </flux:button>
    </div>

    <flux:modal name="delete-group" class="max-w-lg" data-test="delete-group-modal">
        <form wire:submit="deleteGroup" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :name for good?', ['name' => $team->name]) }}</flux:heading>
                <flux:subheading>
                    {{ __('The group, its memberships, invitations and quiz assignments are removed.') }}
                </flux:subheading>
            </div>

            @if ($this->members->isNotEmpty())
                {{-- Die Zahl steht im Dialog, damit das Häkchen keine abstrakte
                     Option ist. Ausbildner sind nie betroffen (Entscheidung 4). --}}
                <flux:checkbox
                    wire:model="deleteApprentices"
                    :label="__('Also delete the :count apprentice accounts for good', ['count' => $this->members->count()])"
                    :description="__('Their progress, quiz attempts and logins are deleted with them. Trainers are never affected.')"
                    data-test="delete-group-with-apprentices"
                />
            @endif

            <flux:input
                wire:model="deleteConfirmation"
                :label="$this->deleteConfirmLabel"
                data-test="delete-group-confirmation"
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="delete-group-submit">
                    {{ __('Delete permanently') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
