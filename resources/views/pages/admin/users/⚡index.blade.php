<?php

use App\Actions\Analytics\ComputeLastActivity;
use App\Actions\Users\DeleteUser;
use App\Exceptions\LastAdminException;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /** @var int|string|null */
    public $groupFilter = null;

    public string $search = '';

    /**
     * Wen der Löschen-Dialog gerade betrifft.
     *
     * **Ein** Dialog für die ganze Seite, gesteuert über die ID — nicht einer je
     * Zeile: bei zwanzig Zeilen wären das zwanzig Livewire-Komponenten für einen
     * Knopf, den man höchstens einmal drückt.
     */
    public ?int $deletingId = null;

    /** Die Namenseingabe, mit der die Löschung bestätigt wird. */
    public string $deleteConfirmation = '';

    /**
     * Anders als die Geschwister-Listen autorisiert diese Seite schon beim
     * Laden: ihr Inhalt *ist* personenbezogen (Namen und E-Mail-Adressen aller
     * Nutzer). Die `can:admin`-Middleware der Route schützt den HTTP-Aufruf, ein
     * direkt adressierter Livewire-Request umgeht sie.
     */
    public function mount(): void
    {
        Gate::authorize('admin');
    }

    public function updatedGroupFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            // Nur echte Gruppen mitladen: das persönliche Team hat jeder, es in
            // der Gruppen-Spalte zu zeigen wäre Rauschen. Eager geladen, weil die
            // Spalte sonst je Zeile nachfragt (N+1).
            ->with(['teams' => fn (BelongsToMany $teams) => $teams
                ->where('teams.is_personal', false)
                ->orderByPivot('created_at', 'desc')])
            ->inGroup($this->groupFilter)
            ->search($this->search)
            ->orderBy('name')
            // Ohne eindeutigen Zweitschlüssel springen Namensgleiche über die
            // Seitengrenzen (dasselbe Muster wie im Fragen-Pool)
            ->orderBy('id')
            ->paginate(20);
    }

    /**
     * „Zuletzt aktiv" für die Nutzer **dieser Seite** — zwei Abfragen für
     * zwanzig Zeilen, nicht zwanzig Abfragen.
     *
     * @return array<int, Carbon>
     */
    #[Computed]
    public function lastActivity(): array
    {
        /** @var array<int, int> $ids */
        $ids = $this->users->getCollection()->modelKeys();

        return app(ComputeLastActivity::class)->handle($ids);
    }

    /**
     * @return Collection<int, Team>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Team::query()->groups()->orderBy('name')->get();
    }

    /**
     * Die Gruppe, deren Auswertung der Zeilen-Link öffnet.
     *
     * Ein Lehrling gehört laut Konzept genau einer Gruppe an; steckt er entgegen
     * dem Konzept in mehreren, gewinnt die zuletzt beigetretene (die Relation
     * ist danach sortiert). Eine Auswahl an dieser Stelle wäre Bedienlast für
     * einen Datenfehler.
     */
    public function analyticsGroup(User $user): ?Team
    {
        return $user->is_admin ? null : $user->teams->first();
    }

    /**
     * Den Löschen-Dialog für einen Nutzer aufziehen.
     */
    public function confirmDelete(int $userId): void
    {
        Gate::authorize('admin');

        $this->deletingId = $userId;
        $this->deleteConfirmation = '';

        $this->resetErrorBag();

        $this->modal('delete-user')->show();
    }

    #[Computed]
    public function deletingUser(): ?User
    {
        return $this->deletingId === null ? null : User::find($this->deletingId);
    }

    /**
     * Das Label der Bestätigungseingabe.
     *
     * Als Computed und nicht inline im Attribut: der Übersetzungsschlüssel
     * enthält selbst doppelte Anführungszeichen (`Type ":name" to confirm`), und
     * die brechen ein `:label="__(…)"` beim Blade-Parsen.
     */
    #[Computed]
    public function deleteConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->deletingUser?->name]);
    }

    /**
     * Was die Löschung mitnimmt, in Zahlen — der Dialog soll nicht verschweigen,
     * dass hier Lernverlauf verschwindet.
     *
     * @return array{completions: int, attempts: int}
     */
    #[Computed]
    public function deletingStats(): array
    {
        $user = $this->deletingUser;

        return [
            'completions' => $user?->lessonCompletions()->count() ?? 0,
            'attempts' => $user?->quizAttempts()->count() ?? 0,
        ];
    }

    /**
     * Ein Konto endgültig löschen.
     *
     * Die beiden Wächter sitzen in {@see User::isDeletableBy()} und nicht in
     * einer Policy — `Gate::before` gibt einem Admin jede Fähigkeit frei, eine
     * Policy liefe für ihn nie. Hier stehen sie ein zweites Mal, obwohl die
     * Zeile den Knopf schon verbirgt: das Markup ist keine Zugriffskontrolle.
     * Verbindlich prüft {@see DeleteUser} den letzten Admin noch einmal unter
     * Sperre; verliert die Liste diesen Wettlauf, meldet sie es als Toast.
     */
    public function deleteUser(): void
    {
        Gate::authorize('admin');

        $user = $this->deletingUser;

        abort_if($user === null, 404);

        /** @var User $actor */
        $actor = Auth::user();

        abort_unless($user->isDeletableBy($actor), 403);

        if ($this->deleteConfirmation !== $user->name) {
            $this->addError('deleteConfirmation', __('The name does not match.'));

            return;
        }

        try {
            app(DeleteUser::class)->handle($user);
        } catch (LastAdminException) {
            $this->closeDeleteModal();

            Flux::toast(variant: 'danger', text: __('The last administrator cannot be deleted.'));

            return;
        }

        $this->closeDeleteModal();

        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    private function closeDeleteModal(): void
    {
        $this->deletingId = null;
        $this->deleteConfirmation = '';

        unset($this->users, $this->lastActivity, $this->deletingUser, $this->deletingStats);

        $this->modal('delete-user')->close();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Users'));
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Users') }}</flux:heading>
        <flux:subheading>{{ __('Everyone with an account: apprentices and trainers.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model.live="groupFilter" :label="__('Group')" data-test="filter-group-select">
            <flux:select.option value="">{{ __('All groups') }}</flux:select.option>
            <flux:select.option value="{{ User::NO_GROUP }}">{{ __('Without a group') }}</flux:select.option>
            @foreach ($this->groups as $group)
                <flux:select.option value="{{ $group->id }}">
                    {{ $group->name }}@if ($group->isArchived()) ({{ __('archived') }})@endif
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            :placeholder="__('Search by name or email …')"
            icon="magnifying-glass"
            data-test="filter-search-input"
        />
    </div>

    <flux:table :paginate="$this->users" data-test="users-table">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Group') }}</flux:table.column>
            <flux:table.column>{{ __('Last activity') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                @php($analyticsGroup = $this->analyticsGroup($user))
                @php($activeAt = $this->lastActivity[$user->id] ?? null)

                <flux:table.row :key="$user->id" data-test="user-row">
                    <flux:table.cell variant="strong">
                        <span class="block max-w-3xs truncate" title="{{ $user->name }}">{{ $user->name }}</span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="block max-w-3xs truncate" title="{{ $user->email }}">{{ $user->email }}</span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge size="sm" :color="$user->is_admin ? 'indigo' : 'zinc'" data-test="user-role-badge">
                            {{ $user->is_admin ? __('Trainer') : __('Apprentice') }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell>
                        @forelse ($user->teams as $team)
                            <flux:badge size="sm" color="zinc" class="max-w-3xs" data-test="user-group-badge">
                                <span class="truncate">{{ $team->name }}</span>
                            </flux:badge>
                        @empty
                            <span class="text-zinc-500 dark:text-zinc-400" data-test="user-without-group">{{ __('No group') }}</span>
                        @endforelse
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($activeAt !== null)
                            <span title="{{ $activeAt->isoFormat('LLL') }}">{{ $activeAt->diffForHumans() }}</span>
                        @else
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('never') }}</span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-1">
                            {{-- Nur wo es ein Ziel gibt: die Auswertung hängt unter der
                                 Gruppe (`{team}/learners/{member}`), ein Ausbildner oder
                                 ein Lehrling ohne Gruppe hat keine. Ein abgeschalteter
                                 Knopf würde eine Funktion vorspiegeln, die es hier
                                 nicht gibt. --}}
                            @if ($analyticsGroup !== null)
                                <flux:tooltip :content="__('Analytics')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="chart-bar"
                                        :href="route('admin.groups.learner', ['team' => $analyticsGroup, 'member' => $user])"
                                        wire:navigate
                                        data-test="user-analytics-button"
                                    />
                                </flux:tooltip>
                            @endif

                            @if ($user->isDeletableBy(auth()->user()))
                                <flux:tooltip :content="__('Delete')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        wire:click="confirmDelete({{ $user->id }})"
                                        data-test="user-delete-button"
                                    />
                                </flux:tooltip>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No users match this filter.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="delete-user" class="max-w-lg" data-test="delete-user-modal">
        @if ($this->deletingUser !== null)
            <form wire:submit="deleteUser" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ __('Delete :name for good?', ['name' => $this->deletingUser->name]) }}
                    </flux:heading>
                    <flux:subheading>
                        {{ __('This cannot be undone. The account and all of its personal data are removed.') }}
                    </flux:subheading>
                </div>

                {{-- Was verschwindet, im Klartext: eine Löschung, die den
                     Lernverlauf mitnimmt, soll das vorher sagen. --}}
                <flux:callout icon="exclamation-triangle" variant="warning" data-test="delete-user-stats">
                    <flux:callout.text>
                        {{ __(':completions completed lessons and :attempts quiz attempts are deleted with it.', [
                            'completions' => $this->deletingStats['completions'],
                            'attempts' => $this->deletingStats['attempts'],
                        ]) }}
                    </flux:callout.text>
                </flux:callout>

                <flux:input
                    wire:model="deleteConfirmation"
                    :label="$this->deleteConfirmLabel"
                    data-test="delete-user-confirmation"
                />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" data-test="delete-user-submit">
                        {{ __('Delete permanently') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</section>
