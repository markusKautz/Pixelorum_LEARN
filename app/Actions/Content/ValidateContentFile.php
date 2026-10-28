<?php

namespace App\Actions\Content;

use App\Enums\LessonBlockType;
use App\Enums\QuestionType;
use App\Enums\SourceKind;
use App\Rules\LessonBlockContent;
use App\Rules\QuestionOptionsMatchType;
use App\Rules\SourceReferences;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Prüft eine Content-JSON, bevor sie in die Datenbank geschrieben wird.
 *
 * Die Prüfung ist zweistufig (S6.4, Entscheidung E2):
 *
 * - **Struktur** — Blocktypen, Fragetypen, eindeutige Positionen, Quiz-Referenzen
 *   und genau ein positiver Locator je Quellenangabe. Läuft ohne Quellenarchiv
 *   und ist das technische Gate des Admin-Uploads.
 * - **Erdung** — `source_id` gegen die Registry, Locator passend zum Quellentyp,
 *   Belegdatei vorhanden und tief genug. Braucht das Quellenarchiv der lokalen
 *   Pipeline und bleibt Autorenwerkzeug: `lapp:content:validate` ruft sie mit
 *   Registry auf, der Upload ohne.
 *
 * Schema-Versionen (DEV-2): Eine Datei ohne `schema_version` ist Version 1.
 * Gelesen wird jede Version von `SCHEMA_VERSION_MIN` bis
 * `SCHEMA_VERSION_CURRENT`, eine neuere wird abgewiesen, bevor irgendeine
 * Strukturregel greift. Version 2 führt den Block `sources` mit den
 * transportierbaren Metadaten der verwendeten Quellen ein (DEV-3). Version 3
 * erlaubt den Block `media`, das Bild-Manifest eines ZIP-Bündels (DEV-14,
 * {@see ContentBundle}). Geprüft wird die Datei nach dem Auflösen der
 * Bündel-Verweise: Die Bilder zeigen dann schon auf `/storage/lesson-media/…`.
 */
class ValidateContentFile
{
    public const int SCHEMA_VERSION_MIN = 1;

    public const int SCHEMA_VERSION_CURRENT = 3;

    public function __construct(private readonly CollectContentSources $collectSources = new CollectContentSources) {}

    /**
     * Validate a content file from disk, grounding included.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @return array<string, mixed>
     */
    public function handle(string $file, array $registry): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("{$file}: Datei fehlt.");
        }

        if (! is_readable($file)) {
            throw new RuntimeException("{$file}: Datei ist nicht lesbar.");
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("{$file}: Datei ist nicht lesbar.");
        }

        return $this->handleContents($contents, $file, $registry);
    }

    /**
     * Validate already-read content; `null` as registry skips the grounding.
     *
     * Der `$label` steht in jeder Fehlermeldung an der Stelle des Dateinamens.
     * Beim Upload ist der echte Pfad eine Livewire-Temp-Datei, die dem Admin
     * nichts sagt — dort steht der ursprüngliche Dateiname.
     *
     * `$knownSourceIds` gilt nur für Version 1 ohne Registry: Eine solche
     * Datei bringt keine Quellen-Metadaten mit, also muss jede `source_id`
     * schon bekannt sein (Upload gegen `content_sources`, E9). `null` prüft
     * das nicht.
     *
     * @param  array<string, array<string, mixed>>|null  $registry
     * @param  list<string>|null  $knownSourceIds
     * @return array<string, mixed>
     */
    public function handleContents(string $contents, string $label, ?array $registry, ?array $knownSourceIds = null): array
    {
        [$validator, $content] = $this->validatorFor($contents, $label, $registry, $knownSourceIds);

        if ($validator->fails()) {
            $path = (string) array_key_first($validator->errors()->toArray());

            throw new RuntimeException("{$label}: {$path}: {$validator->errors()->first($path)}");
        }

        return $content;
    }

    /**
     * Every problem that would make `handleContents()` reject the content (DEV-16).
     *
     * Dieselben Regeln, aber gesammelt statt beim ersten Fehler geworfen: je
     * Feld die erste Meldung, im Format von `handleContents()`, ohne Folgefehler
     * unter einem schon gemeldeten Feld. Ein Fehler vor
     * dem Validator (ungültiges JSON, Schema-Version, unbekanntes Root-Feld)
     * bleibt eine einzelne Meldung. Die Prüfungen verschachtelter Inhalte
     * laufen wie beim Upload erst, wenn die Grundstruktur stimmt.
     *
     * @param  array<string, array<string, mixed>>|null  $registry
     * @param  list<string>|null  $knownSourceIds
     * @return list<string>
     */
    public function problemsIn(string $contents, string $label, ?array $registry = null, ?array $knownSourceIds = null): array
    {
        try {
            [$validator] = $this->validatorFor($contents, $label, $registry, $knownSourceIds);
        } catch (RuntimeException $exception) {
            return [$exception->getMessage()];
        }

        if ($validator->passes()) {
            return [];
        }

        $errors = $validator->errors()->toArray();
        $problems = [];

        foreach ($errors as $path => $messages) {
            // Fehlt `quiz` ganz, meldet der Validator auch jedes Pflichtfeld
            // darin; der Admin braucht nur die oberste Meldung.
            if ($this->hasReportedParent((string) $path, array_keys($errors))) {
                continue;
            }

            $problems[] = "{$label}: {$path}: {$messages[0]}";
        }

        return $problems;
    }

    /**
     * Whether an error is already covered by an error on one of its parent fields.
     *
     * @param  list<int|string>  $reportedPaths
     */
    private function hasReportedParent(string $path, array $reportedPaths): bool
    {
        foreach ($reportedPaths as $reported) {
            if (str_starts_with($path, $reported.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode the content and build the validator both entry points share.
     *
     * @param  array<string, array<string, mixed>>|null  $registry
     * @param  list<string>|null  $knownSourceIds
     * @return array{0: LaravelValidator, 1: array<string, mixed>}
     *
     * @throws RuntimeException bei Fehlern vor dem Validator
     */
    protected function validatorFor(string $contents, string $label, ?array $registry, ?array $knownSourceIds): array
    {
        try {
            $root = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $content = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$label}: \$: Ungültiges JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! $root instanceof stdClass || ! is_array($content)) {
            throw new RuntimeException("{$label}: \$: Root muss ein JSON-Objekt sein.");
        }

        $schemaVersion = $this->assertSchemaVersion($label, $content);
        $this->assertRootKeys($label, $content, $schemaVersion);
        $validator = Validator::make($content, [
            ...$this->baseRules(),
            ...$this->questionRules('questions'),
            ...$this->questionRules('lessons.*.questions'),
            ...($schemaVersion >= 2 ? $this->sourceRules() : []),
            ...($schemaVersion >= 3 ? $this->mediaRules() : []),
        ]);

        $validator->after(function (LaravelValidator $validator) use ($content, $registry, $root, $schemaVersion, $knownSourceIds): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateRawContainerShapes($validator, $root);

            if ($schemaVersion >= 2) {
                $this->validateRawObjectList($validator, $root->sources ?? null, 'sources');
            }

            if ($schemaVersion >= 3 && property_exists($root, 'media')) {
                $this->validateRawObjectList($validator, $root->media, 'media');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateNestedContent($validator, $content, $registry, $schemaVersion, $knownSourceIds);
        });

        /** @var array<string, mixed> $content */
        return [$validator, $content];
    }

    /**
     * The schema version of a decoded content file; a missing field means 1.
     *
     * @param  array<string, mixed>  $content
     */
    public function schemaVersionOf(array $content): int
    {
        $version = $content['schema_version'] ?? self::SCHEMA_VERSION_MIN;

        return is_int($version) ? $version : self::SCHEMA_VERSION_MIN;
    }

    /**
     * Reject unknown schema versions before any structural rule runs.
     *
     * Eine zu neue Datei soll nicht an einem Feld scheitern, das diese
     * Installation noch nicht kennt, sondern mit dem eigentlichen Grund.
     *
     * @param  array<string, mixed>  $content
     */
    protected function assertSchemaVersion(string $label, array $content): int
    {
        if (! array_key_exists('schema_version', $content)) {
            return self::SCHEMA_VERSION_MIN;
        }

        $version = $content['schema_version'];

        if (! is_int($version) || $version < self::SCHEMA_VERSION_MIN) {
            throw new RuntimeException("{$label}: schema_version: ".__('The schema version must be a whole number of at least :min.', [
                'min' => self::SCHEMA_VERSION_MIN,
            ]));
        }

        if ($version > self::SCHEMA_VERSION_CURRENT) {
            throw new RuntimeException("{$label}: schema_version: ".__('Schema version :version is newer than this installation supports (up to :current). Update the application first.', [
                'version' => $version,
                'current' => self::SCHEMA_VERSION_CURRENT,
            ]));
        }

        return $version;
    }

    /** @param array<string, mixed> $content */
    protected function assertRootKeys(string $label, array $content, int $schemaVersion): void
    {
        $allowed = [
            'schema_version', 'slug', 'title', 'summary', 'objectives', 'position', 'difficulty',
            'estimated_minutes', 'pass_threshold', 'status', 'lessons', 'questions', 'quiz',
            ...($schemaVersion >= 2 ? ['sources'] : []),
            ...($schemaVersion >= 3 ? ['media'] : []),
        ];
        $unexpected = array_values(array_diff(array_keys($content), $allowed));

        if ($unexpected !== []) {
            throw new RuntimeException("{$label}: {$unexpected[0]}: Unerwartetes Feld.");
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function baseRules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'objectives' => ['required', 'array', 'list', 'min:1'],
            'objectives.*' => ['required', 'string'],
            'position' => ['required', 'integer:strict', 'min:1'],
            'difficulty' => ['nullable', 'string', 'max:100'],
            'estimated_minutes' => ['nullable', 'integer:strict', 'min:1'],
            'pass_threshold' => ['required', 'integer:strict', 'between:1,100'],
            'status' => ['required', 'in:draft'],
            'lessons' => ['required', 'array', 'list', 'min:1'],
            'lessons.*' => ['required', 'array:position,title,source_reference,blocks,questions'],
            'lessons.*.position' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'lessons.*.title' => ['required', 'string', 'max:255'],
            'lessons.*.source_reference' => ['required', 'array', 'list', 'min:1'],
            'lessons.*.source_reference.*' => ['required', 'array:source_id,page,slide,section'],
            'lessons.*.source_reference.*.source_id' => ['required', 'string', 'max:100'],
            'lessons.*.source_reference.*.page' => ['nullable', 'integer:strict', 'min:1'],
            'lessons.*.source_reference.*.slide' => ['nullable', 'integer:strict', 'min:1'],
            'lessons.*.source_reference.*.section' => ['nullable', 'string', 'max:255'],
            'lessons.*.blocks' => ['required', 'array', 'list', 'min:1'],
            'lessons.*.blocks.*' => ['required', 'array:position,type,content'],
            'lessons.*.blocks.*.position' => ['required', 'integer:strict', 'min:1'],
            'lessons.*.blocks.*.type' => ['required', Rule::enum(LessonBlockType::class)],
            'lessons.*.blocks.*.content' => ['present', 'array'],
            'lessons.*.questions' => ['present', 'array', 'list'],
            'questions' => ['required', 'array', 'list', 'min:1'],
            'quiz' => ['required', 'array:title,description,pass_threshold,status,question_positions'],
            'quiz.title' => ['required', 'string', 'max:255'],
            'quiz.description' => ['nullable', 'string'],
            'quiz.pass_threshold' => ['required', 'integer:strict', 'between:1,100'],
            'quiz.status' => ['required', 'in:draft'],
            'quiz.question_positions' => ['required', 'array', 'list', 'min:1'],
            'quiz.question_positions.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }

    /**
     * Rules for the `sources` block of schema version 2.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sourceRules(): array
    {
        return [
            'sources' => ['required', 'array', 'list', 'min:1'],
            'sources.*' => ['required', 'array:'.implode(',', CollectContentSources::TRANSPORTABLE_FIELDS)],
            'sources.*.source_id' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
            'sources.*.kind' => ['required', Rule::enum(SourceKind::class)],
            'sources.*.title' => ['required', 'string', 'max:255'],
            'sources.*.publisher' => ['nullable', 'string', 'max:255'],
            'sources.*.url' => ['nullable', 'required_if:sources.*.kind,web', 'string', 'max:2048', 'url:http,https'],
            'sources.*.version' => ['nullable', 'string', 'max:100'],
            'sources.*.published_at' => ['nullable', 'date_format:Y-m-d'],
            'sources.*.accessed_at' => ['nullable', 'date_format:Y-m-d'],
            'sources.*.content_sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    /**
     * Rules for the `media` block of schema version 3 (DEV-14).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function mediaRules(): array
    {
        return [
            'media' => ['sometimes', 'array', 'list', 'max:'.ContentBundle::MAX_IMAGES],
            'media.*' => ['required', 'array:path,sha256,mime,bytes'],
            'media.*.path' => ['required', 'string', 'regex:'.ContentBundle::bundlePathPattern(), 'distinct:strict'],
            'media.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'media.*.mime' => ['required', Rule::in(array_keys(ContentBundle::MIME_EXTENSIONS))],
            'media.*.bytes' => ['required', 'integer:strict', 'between:1,'.ContentBundle::MAX_IMAGE_BYTES],
        ];
    }

    /**
     * Each media path must be named after its hash and type, as the bundle stores it.
     *
     * @param  array<string, mixed>  $content
     */
    protected function validateMediaNames(LaravelValidator $validator, array $content): void
    {
        /** @var list<array{path: string, sha256: string, mime: string, bytes: int}> $media */
        $media = $content['media'] ?? [];

        foreach ($media as $index => $entry) {
            $expected = ContentBundle::bundlePath(ContentBundle::fileName($entry['sha256'], $entry['mime']));

            if ($entry['path'] !== $expected) {
                $validator->errors()->add("media.{$index}.path", __('The media path must be :path.', ['path' => $expected]));
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function questionRules(string $prefix): array
    {
        return [
            "{$prefix}.*" => ['required', 'array:position,type,prompt,explanation,source_reference,options'],
            "{$prefix}.*.position" => ['required', 'integer:strict', 'min:1'],
            "{$prefix}.*.type" => ['required', Rule::enum(QuestionType::class)],
            "{$prefix}.*.prompt" => ['required', 'string', 'max:2000'],
            "{$prefix}.*.explanation" => ['required', 'string', 'max:2000'],
            "{$prefix}.*.source_reference" => ['required', 'array', 'list', 'min:1'],
            "{$prefix}.*.source_reference.*" => ['required', 'array:source_id,page,slide,section'],
            "{$prefix}.*.source_reference.*.source_id" => ['required', 'string', 'max:100'],
            "{$prefix}.*.source_reference.*.page" => ['nullable', 'integer:strict', 'min:1'],
            "{$prefix}.*.source_reference.*.slide" => ['nullable', 'integer:strict', 'min:1'],
            "{$prefix}.*.source_reference.*.section" => ['nullable', 'string', 'max:255'],
            "{$prefix}.*.options" => ['required', 'array', 'list', 'min:2'],
            "{$prefix}.*.options.*" => ['required', 'array:position,label,is_correct'],
            "{$prefix}.*.options.*.position" => ['required', 'integer:strict', 'min:1'],
            "{$prefix}.*.options.*.label" => ['required', 'string', 'max:500'],
            "{$prefix}.*.options.*.is_correct" => ['required', 'boolean:strict'],
        ];
    }

    private function validateRawContainerShapes(LaravelValidator $validator, stdClass $root): void
    {
        $this->expectJsonList($validator, $root->objectives ?? null, 'objectives');
        $lessons = $this->expectJsonList($validator, $root->lessons ?? null, 'lessons');

        if ($lessons !== null) {
            foreach ($lessons as $lessonIndex => $rawLesson) {
                $lessonPath = "lessons.{$lessonIndex}";
                $lesson = $this->expectJsonObject($validator, $rawLesson, $lessonPath);

                if ($lesson === null) {
                    continue;
                }

                $this->validateRawSourceReferences($validator, $lesson->source_reference ?? null, "{$lessonPath}.source_reference");
                $this->validateRawBlocks($validator, $lesson->blocks ?? null, "{$lessonPath}.blocks");
                $this->validateRawQuestions($validator, $lesson->questions ?? null, "{$lessonPath}.questions");
            }
        }

        $this->validateRawQuestions($validator, $root->questions ?? null, 'questions');
        $quiz = $this->expectJsonObject($validator, $root->quiz ?? null, 'quiz');

        if ($quiz !== null) {
            $this->expectJsonList($validator, $quiz->question_positions ?? null, 'quiz.question_positions');
        }
    }

    private function validateRawSourceReferences(LaravelValidator $validator, mixed $value, string $path): void
    {
        $references = $this->expectJsonList($validator, $value, $path);

        if ($references === null) {
            return;
        }

        foreach ($references as $referenceIndex => $reference) {
            $this->expectJsonObject($validator, $reference, "{$path}.{$referenceIndex}");
        }
    }

    private function validateRawBlocks(LaravelValidator $validator, mixed $value, string $path): void
    {
        $blocks = $this->expectJsonList($validator, $value, $path);

        if ($blocks === null) {
            return;
        }

        foreach ($blocks as $blockIndex => $rawBlock) {
            $blockPath = "{$path}.{$blockIndex}";
            $block = $this->expectJsonObject($validator, $rawBlock, $blockPath);

            if ($block === null) {
                continue;
            }

            $contentPath = "{$blockPath}.content";
            $content = $this->expectJsonObject($validator, $block->content ?? null, $contentPath);

            if ($content === null) {
                continue;
            }

            $listField = match ($block->type ?? null) {
                'accordion' => 'items',
                'glossary' => 'terms',
                default => null,
            };

            if ($listField !== null) {
                $this->validateRawObjectList(
                    $validator,
                    $content->{$listField} ?? null,
                    "{$contentPath}.{$listField}",
                );
            }
        }
    }

    private function validateRawObjectList(LaravelValidator $validator, mixed $value, string $path): void
    {
        $items = $this->expectJsonList($validator, $value, $path);

        if ($items === null) {
            return;
        }

        foreach ($items as $itemIndex => $item) {
            $this->expectJsonObject($validator, $item, "{$path}.{$itemIndex}");
        }
    }

    private function validateRawQuestions(LaravelValidator $validator, mixed $value, string $path): void
    {
        $questions = $this->expectJsonList($validator, $value, $path);

        if ($questions === null) {
            return;
        }

        foreach ($questions as $questionIndex => $rawQuestion) {
            $questionPath = "{$path}.{$questionIndex}";
            $question = $this->expectJsonObject($validator, $rawQuestion, $questionPath);

            if ($question === null) {
                continue;
            }

            $this->validateRawSourceReferences($validator, $question->source_reference ?? null, "{$questionPath}.source_reference");
            $options = $this->expectJsonList($validator, $question->options ?? null, "{$questionPath}.options");

            if ($options === null) {
                continue;
            }

            foreach ($options as $optionIndex => $option) {
                $this->expectJsonObject($validator, $option, "{$questionPath}.options.{$optionIndex}");
            }
        }
    }

    /** @return list<mixed>|null */
    private function expectJsonList(LaravelValidator $validator, mixed $value, string $path): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $validator->errors()->add($path, __('Value must be a JSON list.'));

            return null;
        }

        return $value;
    }

    private function expectJsonObject(LaravelValidator $validator, mixed $value, string $path): ?stdClass
    {
        if (! $value instanceof stdClass) {
            $validator->errors()->add($path, __('Value must be a JSON object.'));

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>|null  $registry
     * @param  list<string>|null  $knownSourceIds
     */
    protected function validateNestedContent(
        LaravelValidator $validator,
        array $content,
        ?array $registry,
        int $schemaVersion,
        ?array $knownSourceIds,
    ): void {
        /** @var list<array<string, mixed>> $lessons */
        $lessons = $content['lessons'];
        /** @var list<array<string, mixed>> $poolQuestions */
        $poolQuestions = $content['questions'];
        $sourceReferences = $this->sourceReferenceChecks($content, $registry, $schemaVersion, $knownSourceIds);

        foreach ($lessons as $lessonIndex => $lesson) {
            $lessonPath = "lessons.{$lessonIndex}";
            /** @var list<array<string, mixed>> $questions */
            $questions = $lesson['questions'];

            $this->validateSourceReferences($validator, $sourceReferences, $lesson['source_reference'], "{$lessonPath}.source_reference");

            foreach ($questions as $questionIndex => $question) {
                $this->validateSourceReferences(
                    $validator,
                    $sourceReferences,
                    $question['source_reference'],
                    "{$lessonPath}.questions.{$questionIndex}.source_reference",
                );
            }
        }

        foreach ($poolQuestions as $questionIndex => $question) {
            $this->validateSourceReferences(
                $validator,
                $sourceReferences,
                $question['source_reference'],
                "questions.{$questionIndex}.source_reference",
            );
        }

        if ($schemaVersion >= 2) {
            $this->validateDeclaredSources($validator, $content, $registry);
        }

        if ($schemaVersion >= 3) {
            $this->validateMediaNames($validator, $content);
        }

        foreach ($lessons as $lessonIndex => $lesson) {
            $lessonPath = "lessons.{$lessonIndex}";
            /** @var list<array<string, mixed>> $blocks */
            $blocks = $lesson['blocks'];
            /** @var list<array<string, mixed>> $questions */
            $questions = $lesson['questions'];

            $this->validateUniquePositions($validator, $blocks, "{$lessonPath}.blocks");
            $this->validateUniquePositions($validator, $questions, "{$lessonPath}.questions");

            foreach ($blocks as $blockIndex => $block) {
                $blockValidator = Validator::make(
                    ['content' => $block['content']],
                    ['content' => [new LessonBlockContent(LessonBlockType::from((string) $block['type']))]],
                );

                if ($blockValidator->fails()) {
                    $validator->errors()->add(
                        "{$lessonPath}.blocks.{$blockIndex}.content",
                        $blockValidator->errors()->first('content'),
                    );
                }
            }

            foreach ($questions as $questionIndex => $question) {
                $this->validateQuestion($validator, $question, "{$lessonPath}.questions.{$questionIndex}");
            }
        }

        $this->validateUniquePositions($validator, $poolQuestions, 'questions');

        foreach ($poolQuestions as $questionIndex => $question) {
            $this->validateQuestion($validator, $question, "questions.{$questionIndex}");
        }

        $poolPositions = array_map(
            fn (array $question): int => (int) $question['position'],
            $poolQuestions,
        );
        /** @var array<string, mixed> $quiz */
        $quiz = $content['quiz'];
        /** @var list<int> $quizPositions */
        $quizPositions = $quiz['question_positions'];

        foreach ($quizPositions as $index => $position) {
            if (! in_array($position, $poolPositions, true)) {
                $validator->errors()->add(
                    "quiz.question_positions.{$index}",
                    __('Final quiz positions must reference an existing pool question.'),
                );
            }
        }
    }

    /**
     * The checks every source reference list of this file runs through.
     *
     * - Version 2: gegen den eigenen `sources`-Block, nur Metadaten.
     * - Mit Registry: zusätzlich die volle Erdung wie bisher.
     * - Version 1 ohne Registry: nur der Locator, und falls bekannt, ob die
     *   Quelle in dieser Installation existiert.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>|null  $registry
     * @param  list<string>|null  $knownSourceIds
     * @return list<Closure(list<array<string, mixed>>): list<array{path: string, message: string}>>
     */
    protected function sourceReferenceChecks(array $content, ?array $registry, int $schemaVersion, ?array $knownSourceIds): array
    {
        $checks = [];

        if ($schemaVersion >= 2) {
            $declared = new SourceReferences($this->declaredSources($content), metadataOnly: true);
            $checks[] = fn (array $references): array => $declared->errorsFor($references);
        }

        if ($registry !== null) {
            $grounding = new SourceReferences($registry);
            $checks[] = fn (array $references): array => $grounding->errorsFor($references);

            return $checks;
        }

        if ($schemaVersion < 2) {
            $checks[] = $this->locatorErrors(...);

            if ($knownSourceIds !== null) {
                $checks[] = $this->unknownSourceCheck(array_fill_keys($knownSourceIds, true));
            }
        }

        return $checks;
    }

    /**
     * The file's `sources` block, keyed by source ID.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, array<string, mixed>>
     */
    protected function declaredSources(array $content): array
    {
        $declared = [];

        foreach ($content['sources'] ?? [] as $source) {
            $declared[(string) $source['source_id']] = $source;
        }

        return $declared;
    }

    /**
     * Every declared source is used, and with a registry it matches it (E9).
     *
     * Ein ungenutzter Eintrag würde den Block still veralten lassen; eine
     * Abweichung zur Registry hieße, die Datei behauptet über eine Quelle
     * etwas anderes als das Archiv, gegen das sie geerdet wurde.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>|null  $registry
     */
    protected function validateDeclaredSources(LaravelValidator $validator, array $content, ?array $registry): void
    {
        $used = array_fill_keys($this->collectSources->usedSourceIds($content), true);

        foreach ($content['sources'] as $index => $source) {
            $sourceId = (string) $source['source_id'];

            if (! isset($used[$sourceId])) {
                $validator->errors()->add("sources.{$index}.source_id", __('Source :id is declared but never referenced.', ['id' => $sourceId]));

                continue;
            }

            if ($registry === null || ! isset($registry[$sourceId])) {
                continue;
            }

            $expected = $this->collectSources->transportable($registry[$sourceId]);
            $actual = $this->collectSources->transportable($source);

            foreach ($expected as $field => $value) {
                if ($actual[$field] !== $value) {
                    $validator->errors()->add("sources.{$index}.{$field}", __('Source :id differs from the source registry in :field.', [
                        'id' => $sourceId,
                        'field' => $field,
                    ]));

                    break;
                }
            }
        }
    }

    /**
     * Run the checks in order; the first one that fails reports for this list.
     *
     * @param  list<Closure(list<array<string, mixed>>): list<array{path: string, message: string}>>  $checks
     * @param  list<array<string, mixed>>  $references
     */
    protected function validateSourceReferences(
        LaravelValidator $validator,
        array $checks,
        array $references,
        string $path,
    ): void {
        foreach ($checks as $check) {
            $errors = $check($references);

            foreach ($errors as $error) {
                $errorPath = $error['path'] === '' ? $path : "{$path}.{$error['path']}";
                $validator->errors()->add($errorPath, $error['message']);
            }

            if ($errors !== []) {
                return;
            }
        }
    }

    /**
     * Version 1 without metadata: every source must already be known (E9).
     *
     * @param  array<string, true>  $known
     * @return Closure(list<array<string, mixed>>): list<array{path: string, message: string}>
     */
    protected function unknownSourceCheck(array $known): Closure
    {
        return fn (array $references): array => $this->unknownSourceErrors($references, $known);
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @param  array<string, true>  $known
     * @return list<array{path: string, message: string}>
     */
    protected function unknownSourceErrors(array $references, array $known): array
    {
        $errors = [];

        foreach ($references as $index => $reference) {
            $sourceId = $reference['source_id'] ?? null;

            if (! is_string($sourceId) || ! isset($known[$sourceId])) {
                $errors[] = [
                    'path' => "{$index}.source_id",
                    'message' => __('Source reference :index uses a source this installation does not know. Upgrade the file with lapp:content:upgrade.', ['index' => $index + 1]),
                ];
            }
        }

        return $errors;
    }

    /**
     * Die registry-unabhängige Hälfte der Quellenprüfung.
     *
     * Ohne Quellenarchiv lässt sich weder die `source_id` auflösen noch der
     * Locator gegen den Quellentyp halten. Die Formaussage bleibt aber prüfbar:
     * genau ein gefüllter Locator je Beleg. Ein Beleg ohne Locator oder mit
     * zweien ist unabhängig von jeder Registry falsch — mit zweien wüsste
     * niemand, welcher gilt.
     *
     * @param  list<array<string, mixed>>  $references
     * @return list<array{path: string, message: string}>
     */
    protected function locatorErrors(array $references): array
    {
        $errors = [];

        foreach ($references as $index => $reference) {
            $locators = array_filter(
                [
                    'page' => $reference['page'] ?? null,
                    'slide' => $reference['slide'] ?? null,
                    'section' => $reference['section'] ?? null,
                ],
                fn (mixed $locator): bool => filled($locator),
            );

            if (count($locators) !== 1) {
                $errors[] = [
                    'path' => (string) $index,
                    'message' => __('Source reference :index needs exactly one page, slide, or section.', ['index' => $index + 1]),
                ];
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $question */
    protected function validateQuestion(LaravelValidator $validator, array $question, string $path): void
    {
        /** @var list<array<string, mixed>> $options */
        $options = $question['options'];
        $this->validateUniquePositions($validator, $options, "{$path}.options");
        $optionsValidator = Validator::make(
            ['options' => $options],
            ['options' => [new QuestionOptionsMatchType(QuestionType::from((string) $question['type']))]],
        );

        if ($optionsValidator->fails()) {
            $validator->errors()->add("{$path}.options", $optionsValidator->errors()->first('options'));
        }
    }

    /** @param list<array<string, mixed>> $items */
    protected function validateUniquePositions(LaravelValidator $validator, array $items, string $path): void
    {
        $seen = [];

        foreach ($items as $index => $item) {
            $position = (int) $item['position'];

            if (isset($seen[$position])) {
                $validator->errors()->add("{$path}.{$index}.position", __('Positions must be unique within their parent.'));
            }

            $seen[$position] = true;
        }
    }
}
