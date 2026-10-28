<?php

use App\Actions\Teams\AppendInvitationToCsv;
use App\Actions\Teams\SendInvitationNotification;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    /** Zielroute nach dem Einladen — die Admin-Gruppenverwaltung (S3.7) übergibt ihre Detailseite. */
    public string $redirectRoute = 'teams.edit';

    /**
     * Steuert, ob die Rollen-Auswahl angeboten wird. Im Gruppen-Kontext (Konzept c)
     * sind alle Eingeladenen Lehrlinge; die Team-Rollen dienen dort nicht der
     * Steuerung, deshalb blendet die Gruppenverwaltung die Auswahl aus.
     */
    public bool $roleSelectable = true;

    public function mount(Team $team, ?string $redirectRoute = null, bool $roleSelectable = true): void
    {
        $this->team = $team;
        $this->redirectRoute = $redirectRoute ?? 'teams.edit';
        $this->roleSelectable = $roleSelectable;
    }

    public function createInvitation(
        AppendInvitationToCsv $appendToCsv,
        SendInvitationNotification $sendNotification,
    ): void {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $role = $this->roleSelectable
            ? TeamRole::from($validated['inviteRole'])
            : TeamRole::Member;

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => $role,
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(TeamInvitation::EXPIRY_DAYS),
        ]);

        /*
         * Reihenfolge mit Absicht: erst die Einladung, dann die CSV-Zeile, dann
         * der Mailversuch. Die erste Instanz läuft lokal **ohne Mailserver** —
         * dort ist die Datei der Zustellweg, und eine Transport-Exception darf
         * die bereits gültige Einladung nicht mitreißen.
         */
        $appendToCsv->handle($invitation);

        $sendNotification->handle($invitation);

        $this->reset('inviteEmail', 'inviteRole');
        $this->modal('invite-member')->close();

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->redirectRoute($this->redirectRoute, ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<flux:modal name="invite-member" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a team member') }}</flux:heading>
            <flux:subheading>{{ __('Send an invitation to join this team.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />

            @if ($roleSelectable)
                <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                    @foreach ($this->availableRoles as $role)
                        <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
