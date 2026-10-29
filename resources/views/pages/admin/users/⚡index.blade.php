<?php

use App\Actions\Analytics\ComputeLastActivity;
use App\Actions\Users\CreateAdmin;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\DeleteUsers;
use App\Concerns\ProfileValidationRules;
use App\Exceptions\LastAdminException;
use App\Models\LessonCompletion;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use ProfileValidationRules;
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
     * Die für die Mehrfachlöschung angehakten Konten (#6).
     *
     * Nur auf der aktuellen Seite (E7): Filter- und Seitenwechsel leeren sie,
     * damit keine unsichtbaren Zeilen in der Löschung landen.
     *
     * @var array<int, int|string>
     */
    public array $selected = [];

    /** Die eingetippte Anzahl, mit der die Mehrfachlöschung bestätigt wird (E6). */
    public string $bulkDeleteConfirmation = '';

    public string $newAdminName = '';

    public string $newAdminEmail = '';

    public string $newAdminPassword = '';

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
        $this->selected = [];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedPaginators(): void
    {
        $this->selected = [];
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

    /**
     * Die Lehrlinge, die die Mehrfachlöschung träfe: die Auswahl ohne Admins
     * und ohne den Handelnden, so wie {@see DeleteUsers} sie löscht.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function selectedUsers(): Collection
    {
        /** @var User $actor */
        $actor = Auth::user();

        return app(DeleteUsers::class)->deletable($this->selected, $actor);
    }

    /**
     * Die IDs der Zeilen dieser Seite, die sich anhaken lassen.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function selectableIds(): array
    {
        return $this->users->getCollection()
            ->filter(fn (User $user) => ! $user->is_admin)
            ->modelKeys();
    }

    /**
     * „Alle auf dieser Seite“ an- oder abwählen.
     */
    public function toggleSelectPage(): void
    {
        $pageIds = $this->selectableIds;

        $allSelected = array_diff($pageIds, array_map(intval(...), $this->selected)) === [];

        $this->selected = $allSelected ? [] : $pageIds;
    }

    /**
     * Was die Mehrfachlöschung mitnimmt, in Summen (E6).
     *
     * @return array{completions: int, attempts: int}
     */
    #[Computed]
    public function bulkStats(): array
    {
        $ids = $this->selectedUsers->modelKeys();

        return [
            'completions' => LessonCompletion::query()->whereIn('user_id', $ids)->count(),
            'attempts' => QuizAttempt::query()->whereIn('user_id', $ids)->count(),
        ];
    }

    /**
     * Das Label der Anzahl-Eingabe; als Computed aus demselben Grund wie
     * {@see deleteConfirmLabel()}.
     */
    #[Computed]
    public function bulkDeleteConfirmLabel(): string
    {
        return __('Type ":count" to confirm', ['count' => $this->selectedUsers->count()]);
    }

    /**
     * Den Dialog der Mehrfachlöschung aufziehen.
     */
    public function confirmBulkDelete(): void
    {
        Gate::authorize('admin');

        $this->bulkDeleteConfirmation = '';

        $this->resetErrorBag();

        $this->modal('bulk-delete-users')->show();
    }

    /**
     * Die ausgewählten Lehrlinge endgültig löschen (#6).
     *
     * Bestätigt wird mit der Anzahl, nicht mit Namen (E6): zwölf Namen
     * einzutippen ist nicht praktikabel, die Zahl zwingt trotzdem zum Hinsehen.
     */
    public function bulkDeleteUsers(DeleteUsers $deleteUsers): void
    {
        Gate::authorize('admin');

        $expected = $this->selectedUsers->count();

        if ($expected === 0 || trim($this->bulkDeleteConfirmation) !== (string) $expected) {
            $this->addError('bulkDeleteConfirmation', __('The number does not match.'));

            return;
        }

        /** @var User $actor */
        $actor = Auth::user();

        $deleted = $deleteUsers->handle($this->selectedUsers->modelKeys(), $actor);

        $this->selected = [];
        $this->bulkDeleteConfirmation = '';

        unset($this->users, $this->lastActivity, $this->selectedUsers, $this->selectableIds, $this->bulkStats);

        $this->modal('bulk-delete-users')->close();

        Flux::toast(variant: 'success', text: trans_choice(':count user deleted.|:count users deleted.', $deleted, ['count' => $deleted]));
    }

    /**
     * Ein weiteres Admin-Konto mit Startpasswort anlegen (#13, E3).
     *
     * Nur neue Konten: eine vergebene Adresse ist ein Validierungsfehler,
     * Befördern bleibt bei `lapp:make-admin` (E3a). Die Adresse wird vor der
     * Prüfung kleingeschrieben, weil `User` sie so speichert und die
     * Eindeutigkeit sonst an der Schreibweise vorbeiprüfte. Die Regeln stehen
     * hier statt über `emailRules()`: deren `Rule::unique` leitet die Spalte
     * aus dem Feldnamen ab und suchte nach `newAdminEmail`.
     */
    public function createAdmin(CreateAdmin $createAdmin): void
    {
        Gate::authorize('admin');

        $this->newAdminEmail = Str::lower(trim($this->newAdminEmail));

        $validated = $this->validate(
            [
                'newAdminName' => $this->nameRules(),
                'newAdminEmail' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'newAdminPassword' => ['required', 'string', Password::default()],
            ],
            attributes: [
                'newAdminName' => __('Name'),
                'newAdminEmail' => __('Email'),
                'newAdminPassword' => __('Password'),
            ],
        );

        $createAdmin->handle($validated['newAdminName'], $validated['newAdminEmail'], $validated['newAdminPassword']);

        $this->reset('newAdminName', 'newAdminEmail', 'newAdminPassword');

        unset($this->users, $this->lastActivity);

        $this->modal('create-admin')->close();

        Flux::toast(variant: 'success', text: __('Administrator created.'));
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
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('Everyone with an account: apprentices and trainers.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="create-admin">
            <flux:button icon="plus" data-test="create-admin-button">{{ __('Create administrator') }}</flux:button>
        </flux:modal.trigger>
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

    @if ($selected !== [])
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700" data-test="bulk-delete-bar">
            <flux:text>{{ trans_choice(':count selected|:count selected', count($selected), ['count' => count($selected)]) }}</flux:text>
            <flux:button variant="danger" size="sm" icon="trash" wire:click="confirmBulkDelete" data-test="bulk-delete-button">
                {{ __('Delete selected') }}
            </flux:button>
        </div>
    @endif

    <flux:table :paginate="$this->users" data-test="users-table">
        <flux:table.columns>
            <flux:table.column class="w-0">
                @if ($this->selectableIds !== [])
                    <flux:checkbox
                        wire:click="toggleSelectPage"
                        :checked="array_diff($this->selectableIds, array_map('intval', $selected)) === []"
                        :aria-label="__('Select all on this page')"
                        data-test="bulk-select-page"
                    />
                @endif
            </flux:table.column>
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
                    <flux:table.cell>
                        {{-- Admins werden einzeln gelöscht (E5). --}}
                        @unless ($user->is_admin)
                            <flux:checkbox
                                wire:model.live="selected"
                                value="{{ $user->id }}"
                                :aria-label="__('Select :name', ['name' => $user->name])"
                                data-test="bulk-select-user"
                            />
                        @endunless
                    </flux:table.cell>
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
                    <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No users match this filter.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="create-admin" class="max-w-lg" data-test="create-admin-modal">
        <form wire:submit="createAdmin" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create administrator') }}</flux:heading>
                <flux:subheading>
                    {{ __('The new administrator signs in with this start password and should change it under Settings.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="newAdminName" :label="__('Name')" required autocomplete="off" data-test="create-admin-name" />
            <flux:input wire:model="newAdminEmail" :label="__('Email')" type="email" required autocomplete="off" data-test="create-admin-email" />
            <flux:input wire:model="newAdminPassword" :label="__('Start password')" type="password" required viewable autocomplete="new-password" data-test="create-admin-password" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-admin-submit">
                    {{ __('Create') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="bulk-delete-users" class="max-w-lg" data-test="bulk-delete-modal">
        <form wire:submit="bulkDeleteUsers" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ trans_choice('Delete :count apprentice for good?|Delete :count apprentices for good?', $this->selectedUsers->count(), ['count' => $this->selectedUsers->count()]) }}
                </flux:heading>
                <flux:subheading>
                    {{ __('This cannot be undone. The accounts and all of their personal data are removed. Administrators are deleted one by one and are not part of this.') }}
                </flux:subheading>
            </div>

            <ul class="list-inside list-disc text-sm" data-test="bulk-delete-names">
                @foreach ($this->selectedUsers->take(10) as $user)
                    <li wire:key="bulk-name-{{ $user->id }}">{{ $user->name }}</li>
                @endforeach
                @if ($this->selectedUsers->count() > 10)
                    <li class="list-none text-zinc-500 dark:text-zinc-400">{{ __('and :count more', ['count' => $this->selectedUsers->count() - 10]) }}</li>
                @endif
            </ul>

            <flux:callout icon="exclamation-triangle" variant="warning" data-test="bulk-delete-stats">
                <flux:callout.text>
                    {{ __(':completions completed lessons and :attempts quiz attempts are deleted with it.', [
                        'completions' => $this->bulkStats['completions'],
                        'attempts' => $this->bulkStats['attempts'],
                    ]) }}
                </flux:callout.text>
            </flux:callout>

            <flux:input
                wire:model="bulkDeleteConfirmation"
                :label="$this->bulkDeleteConfirmLabel"
                inputmode="numeric"
                autocomplete="off"
                data-test="bulk-delete-confirmation"
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="bulk-delete-submit">
                    {{ __('Delete permanently') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

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
