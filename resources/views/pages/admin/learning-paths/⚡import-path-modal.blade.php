<?php

use App\Actions\Content\ContentBundle;
use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\ReadContentBundle;
use App\Actions\Content\StoreBundledImages;
use App\Actions\Content\ValidateContentFile;
use App\Models\ContentSource;
use App\Models\LearningPath;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    /**
     * Was die geprüfte Datei anlegen würde.
     *
     * Zweistufig wie der Einladungsimport: Der Upload allein legt nichts an.
     * Bei einem Pfad mit sechs Lektionen und vierzig Fragen ist das der
     * Unterschied zwischen „falsche Datei erwischt" und „falscher Pfad steht
     * jetzt in der Datenbank und muss über den Papierkorb wieder weg".
     *
     * @var array<string, mixed>
     */
    public array $preview = [];

    /**
     * Die Datei prüfen und die Vorschau füllen, ohne etwas anzulegen.
     */
    public function previewFile(ValidateContentFile $validate, ReadContentBundle $readBundle, ImportLearningPath $import): void
    {
        Gate::authorize('create', LearningPath::class);

        $this->reset('preview');

        $this->validate([
            'file' => ['required', 'file', 'mimes:json,txt,zip', 'max:'.ContentBundle::MAX_ARCHIVE_KILOBYTES],
        ]);

        $validated = $this->validatedContent($validate, $readBundle);

        if ($validated === null) {
            return;
        }

        $content = $validated['content'];

        $checks = collect($content['lessons'])->sum(fn (array $lesson): int => count($lesson['questions']));

        $this->preview = [
            'slug' => $content['slug'],
            'title' => $content['title'],
            'copy' => $import->isSlugTaken((string) $content['slug']),
            'lessons' => count($content['lessons']),
            'checks' => $checks,
            'questions' => count($content['questions']),
            'quiz' => $content['quiz']['title'],
            'images' => count($validated['images']),
            'position' => $import->resolvePosition((int) $content['position']),
        ];
    }

    /**
     * Den Pfad anlegen — als Entwurf, ungeprüft, ausschließlich neu.
     */
    public function import(
        ValidateContentFile $validate,
        ReadContentBundle $readBundle,
        StoreBundledImages $storeImages,
        ImportLearningPath $import,
    ): void {
        Gate::authorize('create', LearningPath::class);

        if ($this->preview === []) {
            $this->addError('file', __('Check a file first.'));

            return;
        }

        // Erneut prüfen: Die Vorschau ist Anzeige, kein Vertrauensanker. Sie
        // liegt zwischen zwei Requests im Browser. Ob der Pfad als Kopie
        // entsteht, entscheidet `create()` erst beim Anlegen.
        $validated = $this->validatedContent($validate, $readBundle);

        if ($validated === null) {
            $this->reset('preview');

            return;
        }

        // Bilder vor dem Pfad ablegen; scheitert der Import, gehen nur die
        // in diesem Lauf neu geschriebenen Dateien wieder weg (DEV-14, E10).
        $writtenImages = [];

        try {
            $writtenImages = $storeImages->handle($validated['images']);
            $path = $import->create($validated['content']);
        } catch (LockTimeoutException) {
            $storeImages->discard($writtenImages);
            $this->addError('file', __('Another change to the learning path order is still running. Please try again.'));

            return;
        } catch (UniqueConstraintViolationException) {
            $storeImages->discard($writtenImages);

            // Ein zweiter Upload derselben Datei im selben Moment, an der
            // Sperre vorbei: Die Transaktion ist abgebrochen, hier wird nur
            // noch gemeldet.
            $this->addError('file', __('The same learning path was imported at the same moment. Please check the list and try again if needed.'));
            $this->reset('preview');

            return;
        } catch (\RuntimeException $exception) {
            $storeImages->discard($writtenImages);
            $this->addError('file', $exception->getMessage());
            $this->reset('preview');

            return;
        }

        $this->reset('file', 'preview');
        $this->modal('import-learning-path')->close();

        Flux::toast(variant: 'success', text: __('Learning path imported.'));

        $this->redirectRoute('admin.learning-paths.edit', ['learningPath' => $path->slug], navigate: true);
    }

    /**
     * Struktur prüfen; `null` heißt: Fehler steht schon am Feld.
     *
     * Die Erdung läuft hier bewusst **nicht** (E2). Sie braucht das
     * Quellenarchiv, das die Produktion nicht kennt, und findet lokal vor dem
     * Upload über `lapp:content:validate` statt.
     *
     * Eine Datei der Schema-Version 1 bringt keine Quellen-Metadaten mit. Sie
     * geht nur durch, wenn jede ihrer Quellen schon in `content_sources`
     * steht (DEV-3, E9); Version 2 bringt ihre Quellen selbst mit.
     *
     * Ein ZIP-Bündel (DEV-14) wird zuerst gelesen und geprüft; danach zeigen
     * seine Bildfelder auf die künftigen Dateien, und `content.json` läuft
     * durch dieselbe Prüfung wie eine JSON-Datei. Geschrieben wird hier nichts.
     *
     * @return array{content: array<string, mixed>, images: array<string, string>}|null
     */
    protected function validatedContent(ValidateContentFile $validate, ReadContentBundle $readBundle): ?array
    {
        $label = (string) $this->file->getClientOriginalName();

        try {
            if ($this->file->guessExtension() === 'zip') {
                ['json' => $json, 'images' => $images] = $readBundle->handle((string) $this->file->getRealPath(), $label);
            } elseif ($this->file->getSize() > ContentBundle::MAX_CONTENT_BYTES) {
                throw new \RuntimeException(__('A content file as .json may be at most 2 MB.'));
            } else {
                [$json, $images] = [(string) $this->file->get(), []];
            }

            $content = $validate->handleContents($json, $label, null, ContentSource::pluck('source_id')->all());
        } catch (\RuntimeException $exception) {
            // Der Text nennt bereits Pfad und Ursache, etwa
            // `lessons.3.blocks.2.content: …`. Nicht umformulieren.
            $this->addError('file', $exception->getMessage());

            return null;
        }

        return ['content' => $content, 'images' => $images];
    }
}; ?>

<flux:modal name="import-learning-path" :show="$errors->isNotEmpty()" focusable class="max-w-2xl">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Import a learning path') }}</flux:heading>
            <flux:subheading>
                {{ __('One content file per import, as .json or, with images, as .zip from the export. The path is created as an unverified draft; publishing and verifying stay separate steps.') }}
            </flux:subheading>
        </div>

        <form wire:submit="previewFile" class="space-y-4">
            <flux:input
                type="file"
                wire:model="file"
                accept=".json,.zip"
                :label="__('Content file')"
                data-test="import-path-file"
            />

            <div class="flex justify-end">
                <flux:button variant="filled" type="submit" data-test="import-path-preview-submit">
                    {{ __('Check file') }}
                </flux:button>
            </div>
        </form>

        @if ($preview !== [])
            <div class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="import-path-preview">
                <flux:heading size="base">{{ $preview['title'] }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $preview['slug'] }}</flux:text>

                @if ($preview['copy'])
                    <flux:callout variant="warning" icon="document-duplicate" data-test="import-path-preview-copy">
                        <flux:callout.text>
                            {{ __('A learning path with this name already exists, possibly in the trash. The import creates a copy: the identifier gets four random characters appended and the title gets ":suffix".', ['suffix' => __('(copy)')]) }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:text class="text-sm">
                    {{ __(':lessons lessons with :checks check questions, :questions pool questions, final quiz ":quiz".', [
                        'lessons' => $preview['lessons'],
                        'checks' => $preview['checks'],
                        'questions' => $preview['questions'],
                        'quiz' => $preview['quiz'],
                    ]) }}
                </flux:text>

                @if ($preview['images'] > 0)
                    <flux:text class="text-sm" data-test="import-path-preview-images">
                        {{ trans_choice('{1} It brings :count image along.|[2,*] It brings :count images along.', $preview['images'], ['count' => $preview['images']]) }}
                    </flux:text>
                @endif

                <flux:text class="text-sm">
                    {{ __('It lands at position :position of the learning path order.', ['position' => $preview['position']]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="import" data-test="import-path-submit">
                    {{ __('Import now') }}
                </flux:button>
            </div>
        @endif
    </div>
</flux:modal>
