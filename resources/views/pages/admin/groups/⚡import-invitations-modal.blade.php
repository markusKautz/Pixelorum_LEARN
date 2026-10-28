<?php

use App\Actions\Teams\ImportInvitations;
use App\Actions\Teams\ParseInvitationImport;
use App\Enums\InvitationImportStatus;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Team $team;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    /**
     * Die bewerteten Zeilen der hochgeladenen Datei.
     *
     * Bewusst zweistufig: der Upload legt noch nichts an, erst der zweite Klick
     * lädt ein. Bei einer einzelnen Person wäre das umständlich, bei zwanzig
     * Zeilen ist es der Unterschied zwischen „eine Adresse vertippt" und
     * „zwanzig Fehleinträge, die ich jetzt einzeln zurückziehe".
     *
     * @var array<int, array{email: string, status: string}>
     */
    public array $preview = [];

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    /**
     * Die Datei einlesen und die Vorschau füllen, ohne etwas anzulegen.
     */
    public function previewFile(ParseInvitationImport $parse): void
    {
        Gate::authorize('inviteMember', $this->team);

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $this->preview = $parse->handle($this->team, $this->file->get())
            ->map(fn (array $row): array => [
                'email' => $row['email'],
                'status' => $row['status']->value,
            ])
            ->all();

        if ($this->preview === []) {
            $this->addError('file', __('The file contains no email addresses.'));
        }
    }

    /**
     * Die gültigen Zeilen einladen und die übrigen namentlich melden.
     */
    public function import(ImportInvitations $import): void
    {
        Gate::authorize('inviteMember', $this->team);

        if ($this->preview === []) {
            $this->addError('file', __('Upload a list first.'));

            return;
        }

        $result = $import->handle($this->team, $this->parsedPreview(), Auth::user());

        $this->reset('file', 'preview');
        $this->modal('import-invitations')->close();

        Flux::toast(
            variant: 'success',
            text: $result['skipped'] === []
                ? __(':count apprentices invited.', ['count' => $result['invited']])
                : __(':count apprentices invited, :skipped lines skipped.', [
                    'count' => $result['invited'],
                    'skipped' => count($result['skipped']),
                ]),
        );

        $this->redirectRoute('admin.groups.show', ['team' => $this->team->slug], navigate: true);
    }

    /**
     * Die Vorschau zurück in das Shape bringen, das die Import-Action erwartet.
     *
     * Livewire hält öffentliche Eigenschaften als einfache Arrays, der Enum
     * überlebt den Roundtrip zum Browser also nicht.
     *
     * @return Collection<int, array{email: string, status: InvitationImportStatus}>
     */
    protected function parsedPreview(): Collection
    {
        return collect($this->preview)->map(fn (array $row): array => [
            'email' => $row['email'],
            'status' => InvitationImportStatus::from($row['status']),
        ]);
    }

    /**
     * Wie viele Zeilen tatsächlich zu einer Einladung führen.
     */
    public function invitableCount(): int
    {
        return $this->parsedPreview()
            ->filter(fn (array $row): bool => $row['status']->isInvitable())
            ->count();
    }
}; ?>

<flux:modal name="import-invitations" :show="$errors->isNotEmpty()" focusable class="max-w-2xl">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a whole year') }}</flux:heading>
            <flux:subheading>
                {{ __('One email address per line, as .csv or .txt. A header line is skipped, further columns are ignored.') }}
            </flux:subheading>
        </div>

        <form wire:submit="previewFile" class="space-y-4">
            <flux:input
                type="file"
                wire:model="file"
                accept=".csv,.txt"
                :label="__('Address list')"
                data-test="import-file"
            />

            <div class="flex justify-end">
                <flux:button variant="filled" type="submit" data-test="import-preview-submit">
                    {{ __('Check list') }}
                </flux:button>
            </div>
        </form>

        @if ($preview !== [])
            <div class="max-h-80 space-y-2 overflow-y-auto" data-test="import-preview">
                @foreach ($preview as $row)
                    @php($status = App\Enums\InvitationImportStatus::from($row['status']))

                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700" data-test="import-preview-row">
                        <span class="text-sm">{{ $row['email'] }}</span>
                        <flux:badge size="sm" :color="$status->color()">{{ $status->label() }}</flux:badge>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __(':count of :total lines will be invited.', ['count' => $this->invitableCount(), 'total' => count($preview)]) }}
                </flux:text>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button
                        variant="primary"
                        wire:click="import"
                        :disabled="$this->invitableCount() === 0"
                        data-test="import-submit"
                    >
                        {{ __('Invite now') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </div>
</flux:modal>
