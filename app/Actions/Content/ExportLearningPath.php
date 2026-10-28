<?php

namespace App\Actions\Content;

use App\Enums\LessonBlockType;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * Schreibt einen Lernpfad aus dem aktuellen Datenbankstand als Content-JSON (DEV-1).
 *
 * Der Export ist zuerst eine Sicherung des Contents, kein Datenbank-Backup:
 * IDs, Freigabe, Lernfortschritt und Versuche bleiben draußen. Er liefert
 * immer eine Datei (E3). Was einen erneuten Upload verhindern würde, steht in
 * den Warnungen; dazu prüft der Export seine eigene Ausgabe mit derselben
 * Strukturprüfung wie der Upload und nennt alle Verstöße (DEV-16).
 *
 * `status` ist immer `draft` (E4): Veröffentlichen und Freigeben bleiben
 * Handgriffe im Admin-UI.
 *
 * Hochgeladene Bilder reisen im ZIP-Bündel mit (DEV-14, {@see ContentBundle}).
 * Geprüft wird die Datei so, wie der Import sie nach dem Auflösen der Bündel
 * sieht, mit `/storage/lesson-media/<sha256>.<ext>`; ausgeliefert wird sie mit
 * `media/<sha256>.<ext>`. Ohne Bilder bleibt es eine einfache JSON-Datei.
 */
class ExportLearningPath
{
    /**
     * Was einen erneuten Upload verhindern würde; je Aufruf von `handle()` neu.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Die mitreisenden Bilder, je Bündel-Pfad; je Aufruf von `handle()` neu.
     *
     * @var array<string, array{entry: array{path: string, sha256: string, mime: string, bytes: int}, source: string}>
     */
    private array $media = [];

    public function __construct(
        private readonly ValidateContentFile $validate,
        private readonly CollectContentSources $collectSources,
        private readonly LocateLessonImages $locateImages,
    ) {}

    /**
     * Export the path and report anything that blocks a re-import.
     *
     * `media` ordnet jedem Bündel-Pfad die Quelldatei auf der `public`-Disk zu;
     * ist es nicht leer, gehört `json` als `content.json` in ein ZIP und
     * `filename` endet auf `.zip`.
     *
     * @return array{json: string, filename: string, warnings: list<string>, media: array<string, string>}
     */
    public function handle(LearningPath $path): array
    {
        $path->load([
            'lessons.blocks',
            'lessons.questions.options',
            'questions' => fn ($query) => $query->whereNull('lesson_id')->with('options'),
            'finalQuiz.questions',
        ]);

        $this->warnings = [];
        $this->media = [];
        $content = [
            'schema_version' => ValidateContentFile::SCHEMA_VERSION_CURRENT,
            'slug' => $path->slug,
            'title' => $path->title,
            'summary' => $path->summary,
            'objectives' => $path->objectives,
            'position' => $path->position,
            'difficulty' => $path->difficulty,
            'estimated_minutes' => $path->estimated_minutes,
            'pass_threshold' => $path->pass_threshold,
            'status' => 'draft',
            'sources' => [],
            'lessons' => [],
            'questions' => $path->questions->map(fn (Question $question): array => $this->question($question))->all(),
        ];

        foreach ($path->lessons as $lesson) {
            $content['lessons'][] = $this->lesson($lesson);
        }

        if ($path->finalQuiz !== null) {
            $content['quiz'] = $this->finalQuiz($path);
        }

        $content['sources'] = $this->sources($content);

        if ($this->media !== []) {
            $content = $this->withMediaBlock($content);
        }

        $label = "{$path->slug}.json";

        // Alle Verstöße, nicht nur der erste (DEV-16, E7).
        $this->warnings = [...$this->validate->problemsIn($this->encode($content), $label), ...$this->warnings];

        if ($this->media === []) {
            return ['json' => $this->encode($content), 'filename' => $label, 'warnings' => $this->warnings, 'media' => []];
        }

        return [
            'json' => $this->encode($this->withBundlePaths($content)),
            'filename' => "{$path->slug}.zip",
            'warnings' => $this->warnings,
            'media' => array_map(fn (array $image): string => $image['source'], $this->media),
        ];
    }

    /**
     * Insert the `media` block right after `sources`, as in the format description.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function withMediaBlock(array $content): array
    {
        $ordered = [];

        foreach ($content as $key => $value) {
            $ordered[$key] = $value;

            if ($key === 'sources') {
                $ordered['media'] = array_values(array_map(fn (array $image): array => $image['entry'], $this->media));
            }
        }

        return $ordered;
    }

    /**
     * Point the image fields at the files inside the bundle.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function withBundlePaths(array $content): array
    {
        foreach ($content['lessons'] as $lessonIndex => $lesson) {
            foreach ($lesson['blocks'] as $blockIndex => $block) {
                $field = $this->locateImages->imageField(LessonBlockType::from($block['type']), $block['content']);
                $url = $field !== null ? ($block['content'][$field] ?? null) : null;

                if (! is_string($url)) {
                    continue;
                }

                $bundlePath = ContentBundle::bundlePath(basename($url));

                if ($url === $this->locateImages->publicUrl(ContentBundle::diskPath(basename($url))) && isset($this->media[$bundlePath])) {
                    $content['lessons'][$lessonIndex]['blocks'][$blockIndex]['content'][$field] = $bundlePath;
                }
            }
        }

        return $content;
    }

    /**
     * Encode content like the versioned files: two-space indent, final newline.
     *
     * Ein leerer Blockinhalt ist nach dem Dekodieren ein PHP-Array und käme als
     * `[]` heraus; der Validator verlangt dort ein JSON-Objekt.
     *
     * @param  array<string, mixed>  $content
     */
    public function encode(array $content): string
    {
        foreach ($content['lessons'] ?? [] as $lessonIndex => $lesson) {
            foreach ($lesson['blocks'] ?? [] as $blockIndex => $block) {
                if (($block['content'] ?? null) === []) {
                    $content['lessons'][$lessonIndex]['blocks'][$blockIndex]['content'] = new stdClass;
                }
            }
        }

        $json = json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // PHP rückt mit vier Leerzeichen ein, die versionierten Dateien mit zwei.
        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)),
            $json,
        )."\n";
    }

    /**
     * Note something that blocks a re-import of the exported file.
     *
     * @param  array<string, string|int>  $replace
     */
    protected function warn(string $key, array $replace = []): void
    {
        $this->warnings[] = trans($key, $replace);
    }

    /**
     * Export a lesson; collects its bundled images and warnings on the way.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function lesson(Lesson $lesson): array
    {
        return [
            'position' => $lesson->position,
            'title' => $lesson->title,
            'source_reference' => $lesson->source_reference ?? [],
            'blocks' => $lesson->blocks->map(function (LessonBlock $block) use ($lesson): array {
                $content = $this->withBundledImage($lesson, $block);

                // Ein Upload-Pfad außerhalb des Bildfeldes, etwa ein Link im
                // Markdown, reist nicht mit; das soll der Admin wissen.
                if ($this->hasUploadPathOutsideImageField($block, $content)) {
                    $this->warn('Lesson :lesson, block :block: the uploaded image is not part of the export.', [
                        'lesson' => $lesson->position,
                        'block' => $block->position,
                    ]);
                }

                return [
                    'position' => $block->position,
                    'type' => $block->type->value,
                    'content' => $content,
                ];
            })->all(),
            'questions' => $lesson->questions->map(fn (Question $question): array => $this->question($question))->all(),
        ];
    }

    /**
     * The block content with an uploaded image taken into the bundle (DEV-14).
     *
     * Das Bildfeld zeigt danach auf `/storage/lesson-media/<sha256>.<ext>`, so
     * wie der Import die Datei anlegt. Fehlt die Datei oder ist ihr Typ nicht
     * zugelassen, bleibt der Inhalt unverändert und der Admin bekommt eine
     * Warnung.
     *
     * @return array<string, mixed>
     */
    protected function withBundledImage(Lesson $lesson, LessonBlock $block): array
    {
        /** @var array<string, mixed> $content */
        $content = $block->content ?? [];
        $field = $this->locateImages->imageField($block->type, $content);
        $url = $field !== null ? ($content[$field] ?? null) : null;
        $diskPath = is_string($url) ? $this->locateImages->diskPath($url) : null;

        if ($field === null || $diskPath === null) {
            return $content;
        }

        $disk = Storage::disk('public');
        $replace = ['lesson' => $lesson->position, 'block' => $block->position];

        if (! $disk->exists($diskPath)) {
            $this->warn('Lesson :lesson, block :block: the uploaded image file is missing and not part of the export.', $replace);

            return $content;
        }

        $mime = (string) $disk->mimeType($diskPath);
        $bytes = (int) $disk->size($diskPath);

        if (! isset(ContentBundle::MIME_EXTENSIONS[$mime]) || $bytes < 1 || $bytes > ContentBundle::MAX_IMAGE_BYTES) {
            $this->warn('Lesson :lesson, block :block: the uploaded image is not a PNG, JPEG, GIF or WebP file of at most 4 MB and is not part of the export.', $replace);

            return $content;
        }

        $sha256 = (string) hash_file('sha256', $disk->path($diskPath));
        $fileName = ContentBundle::fileName($sha256, $mime);
        $bundlePath = ContentBundle::bundlePath($fileName);

        $this->media[$bundlePath] = [
            'entry' => ['path' => $bundlePath, 'sha256' => $sha256, 'mime' => $mime, 'bytes' => $bytes],
            'source' => $diskPath,
        ];

        $content[$field] = $this->locateImages->publicUrl(ContentBundle::diskPath($fileName));

        return $content;
    }

    /**
     * Whether the content refers to an upload anywhere but in its image field.
     *
     * Das Bildfeld selbst behandelt {@see withBundledImage()} samt Warnung.
     *
     * @param  array<string, mixed>  $content
     */
    protected function hasUploadPathOutsideImageField(LessonBlock $block, array $content): bool
    {
        $field = $this->locateImages->imageField($block->type, $content);

        if ($field !== null) {
            unset($content[$field]);
        }

        return str_contains((string) json_encode($content, JSON_UNESCAPED_SLASHES), '"'.LocateLessonImages::PUBLIC_PREFIX);
    }

    /**
     * @return array<string, mixed>
     */
    protected function question(Question $question): array
    {
        return [
            'position' => $question->position,
            'type' => $question->type->value,
            'prompt' => $question->prompt,
            'explanation' => $question->explanation,
            'source_reference' => $question->source_reference ?? [],
            'options' => $question->options->sortBy('position')->values()->map(fn (QuestionOption $option): array => [
                'position' => $option->position,
                'label' => $option->label,
                'is_correct' => (bool) $option->is_correct,
            ])->all(),
        ];
    }

    /**
     * The final quiz; questions the format cannot address are reported (E3).
     *
     * `question_positions` adressiert nur Pool-Fragen des eigenen Pfades. Seit
     * DEV-15 lässt das Admin-UI nichts anderes mehr ins Abschlussquiz; ein
     * Zwischen-Check oder eine fremde Frage aus Altbestand fällt heraus, aber
     * nicht stillschweigend.
     *
     * @return array<string, mixed>
     */
    protected function finalQuiz(LearningPath $path): array
    {
        /** @var Quiz $quiz */
        $quiz = $path->finalQuiz;
        $positions = [];

        foreach ($quiz->questions as $question) {
            if ($question->isPoolQuestionOf($path->id)) {
                $positions[] = $question->position;

                continue;
            }

            $this->warn('Final quiz: question ":prompt" is not a pool question of this path and is left out. Remove it from the quiz.', [
                'prompt' => str($question->prompt)->limit(60)->toString(),
            ]);
        }

        return [
            'title' => $quiz->title,
            'description' => $quiz->description,
            'pass_threshold' => $quiz->pass_threshold,
            'status' => 'draft',
            'question_positions' => $positions,
        ];
    }

    /**
     * The `sources` block from `content_sources`; unknown IDs are reported.
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    protected function sources(array $content): array
    {
        $sourceIds = $this->collectSources->usedSourceIds($content);
        $known = ContentSource::whereIn('source_id', $sourceIds)->get()->keyBy('source_id');
        $sources = [];

        foreach ($sourceIds as $sourceId) {
            $source = $known->get($sourceId);

            if ($source === null) {
                $this->warn('Source :id is unknown in this installation and missing from the sources block.', ['id' => $sourceId]);

                continue;
            }

            $sources[] = $source->toTransportable();
        }

        return $sources;
    }
}
