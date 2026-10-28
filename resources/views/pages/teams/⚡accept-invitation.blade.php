<?php

use App\Actions\Users\CreateUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    public TeamInvitation $invitation;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(TeamInvitation $invitation): void
    {
        $this->invitation = $invitation;

        $user = Auth::user();

        if ($user !== null) {
            $this->acceptFor($user);

            return;
        }

        if ($this->invitation->isAccepted() || $this->invitation->isExpired()) {
            return;
        }

        if ($this->invitedUserExists()) {
            $this->redirectToLogin();
        }
    }

    /**
     * Create the invited account and accept the invitation as a guest.
     *
     * The signed middleware only protects the initial GET; Livewire actions
     * run through /livewire/update, so everything is re-validated here.
     */
    public function register(CreateUser $createUser): void
    {
        abort_if(Auth::check(), 403);

        if ($this->invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has already been accepted.')],
            ]);
        }

        if ($this->invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if ($this->invitedUserExists()) {
            $this->redirectToLogin();

            return;
        }

        $this->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($createUser): User {
            $this->lockInvitation();

            // Die zugestellte, signierte Einladung belegt den Mailbox-Besitz;
            // Lehrlinge erhalten bewusst keine persönliche Gruppe (Konzept c).
            $user = $createUser->handle($this->name, $this->invitation->email, $this->password, verified: true);

            $this->invitation->team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $this->invitation->role],
            );

            $this->invitation->update(['accepted_at' => now()]);

            $user->switchTeam($this->invitation->team);

            return $user;
        });

        Auth::login($user);

        session()->regenerate();

        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Accept the invitation for an already authenticated user.
     */
    private function acceptFor(User $user): void
    {
        $this->validateInvitation($user, $this->invitation);

        DB::transaction(function () use ($user) {
            $this->lockInvitation();

            $team = $this->invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $this->invitation->role]
            );

            $this->invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function validateInvitation(User $user, TeamInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has already been accepted.')],
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if ($invitation->email !== $user->email) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation was sent to a different email address.')],
            ]);
        }

        // Lehrlinge gehören genau einer Gruppe (Konzept c); nur globale
        // Admins dürfen weiteren Gruppen beitreten.
        if (! $user->is_admin && $user->teams()
            ->where('is_personal', false)
            ->whereKeyNot($invitation->team_id)
            ->exists()) {
            throw ValidationException::withMessages([
                'invitation' => [__('You already belong to a group.')],
            ]);
        }
    }

    /**
     * Re-read the invitation under a row lock and re-check the status,
     * so parallel accepts serialize on the invitation row.
     */
    private function lockInvitation(): void
    {
        $this->invitation = TeamInvitation::query()
            ->lockForUpdate()
            ->findOrFail($this->invitation->id);

        if ($this->invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has already been accepted.')],
            ]);
        }
    }

    private function invitedUserExists(): bool
    {
        return User::query()
            ->where('email', $this->invitation->email)
            ->exists();
    }

    private function redirectToLogin(): void
    {
        session()->put('url.intended', URL::signedRoute('invitations.accept', [
            'invitation' => $this->invitation->code,
        ]));

        $this->redirectRoute('login', ['invitation' => $this->invitation->code], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Accept invitation'));
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($invitation->isAccepted())
        <flux:callout icon="check-circle" variant="success" data-test="invitation-accepted-callout">
            <flux:callout.heading>{{ __('This invitation has already been accepted.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Log in to continue to your dashboard.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('login')" variant="primary" wire:navigate>{{ __('Log in') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($invitation->isExpired())
        <flux:callout icon="clock" variant="warning" data-test="invitation-expired-callout">
            <flux:callout.heading>{{ __('This invitation has expired.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Ask the person who invited you to send a new invitation.') }}</flux:callout.text>
        </flux:callout>
    @else
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <x-team-invitation-alert
            :invitation="['code' => $invitation->code, 'teamName' => $invitation->team->name]"
            :action="__('Register')"
        />

        @error('invitation')
            <flux:callout icon="x-circle" variant="danger" :heading="$message" />
        @enderror

        <form wire:submit="register" class="flex flex-col gap-6">
            <!-- Name -->
            <flux:input
                wire:model="name"
                :label="__('Name')"
                type="text"
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address (aus der Einladung, nicht änderbar) -->
            <flux:input
                :label="__('Email address')"
                type="email"
                :value="$invitation->email"
                disabled
            />

            <!-- Password -->
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
