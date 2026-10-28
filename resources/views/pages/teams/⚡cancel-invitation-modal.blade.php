<?php

use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $invitationCode = '';

    public string $invitationEmail = '';

    public string $modalName = 'cancel-invitation';

    /** Zielroute nach dem Zurückziehen — die Admin-Gruppenverwaltung (S3.7) übergibt ihre Detailseite. */
    public string $redirectRoute = 'teams.edit';

    public function mount(
        Team $team,
        ?string $invitationCode = null,
        ?string $invitationEmail = null,
        ?string $modalName = null,
        ?string $redirectRoute = null,
    ): void
    {
        $this->team = $team;
        $this->invitationCode = $invitationCode ?? '';
        $this->invitationEmail = $invitationEmail ?? '';
        $this->modalName = $modalName ?? ($invitationCode ? "cancel-invitation-{$invitationCode}" : 'cancel-invitation');
        $this->redirectRoute = $redirectRoute ?? 'teams.edit';
    }

    public function cancelInvitation(): void
    {
        $invitation = $this->team->invitations()->where('code', $this->invitationCode)->firstOrFail();

        if ($this->invitationEmail === '') {
            $this->invitationEmail = $invitation->email;
        }

        Gate::authorize('cancelInvitation', $this->team);

        $invitation->delete();

        $this->modal($this->modalName)->close();

        Flux::toast(variant: 'success', text: __('Invitation cancelled.'));

        $this->redirectRoute($this->redirectRoute, ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<flux:modal :name="$modalName" focusable class="max-w-lg">
    <form wire:submit="cancelInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Cancel invitation') }}</flux:heading>
            <flux:subheading>
                {{ __('Are you sure you want to cancel the invitation for :email?', ['email' => $invitationEmail]) }}
            </flux:subheading>
        </div>
        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Keep invitation') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="cancel-invitation-confirm">{{ __('Cancel invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
