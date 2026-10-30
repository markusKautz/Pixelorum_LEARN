<?php

use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'remove-member';

    /** Zielroute nach dem Entfernen — die Admin-Gruppenverwaltung (S3.7) übergibt ihre Detailseite. */
    public string $redirectRoute = 'teams.edit';

    public function mount(
        Team $team,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
        ?string $redirectRoute = null,
    ): void
    {
        $this->team = $team;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "remove-member-{$memberId}" : 'remove-member');
        $this->redirectRoute = $redirectRoute ?? 'teams.edit';
    }

    public function removeMember(): void
    {
        Gate::authorize('removeMember', $this->team);

        $user = User::findOrFail($this->memberId);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $this->team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($this->team)) {
            // Teilnehmer_innen haben kein persönliches Team (Konzept c): bleibt kein
            // Team übrig, wird die Zuordnung genullt statt zu crashen.
            $fallbackTeam = $user->fallbackTeam($this->team);

            $fallbackTeam !== null
                ? $user->switchTeam($fallbackTeam)
                : $user->update(['current_team_id' => null]);
        }

        $this->modal($this->modalName)->close();

        Flux::toast(variant: 'success', text: __('Member removed.'));

        $this->redirectRoute($this->redirectRoute, ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<flux:modal :name="$modalName" focusable class="max-w-lg">
    <form wire:submit="removeMember" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Remove team member') }}</flux:heading>
            <flux:subheading>
                {{ __('Are you sure you want to remove :name from this team?', ['name' => $memberName]) }}
            </flux:subheading>
        </div>
        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="remove-member-confirm">{{ __('Remove member') }}</flux:button>
        </div>
    </form>
</flux:modal>
