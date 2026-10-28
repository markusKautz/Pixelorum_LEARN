<?php

namespace App\Actions\Analytics;

use App\Data\Analytics\GroupMemberRow;
use App\Data\Analytics\GroupPathRow;
use App\Data\Analytics\GroupQuizRow;
use App\Data\Analytics\GroupReport;
use App\Data\Analytics\GroupSummary;
use App\Data\Analytics\PathProgressRow;
use App\Enums\PathStatus;
use App\Enums\ProgressStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Die Auswertung einer Gruppe — je Pfad die Aggregate, je Lehrling die Zeile,
 * dazu der Ein-Zeilen-Überblick für Gruppen-Liste und Admin-Dashboard.
 *
 * Wie {@see LearnerProgressReport} **live** gerechnet (Entscheidung 1) und wie
 * dort N+1-frei: die Rohzahlen kommen über eine Handvoll `GROUP BY`-Abfragen
 * herein — auch `summarize()` über *alle* Gruppen braucht nicht mehr Abfragen
 * als eine einzelne Gruppe. Verrechnet wird in PHP, Zeile für Zeile durch
 * {@see ComputePathProgress}, damit Gruppen- und Einzelsicht garantiert
 * dieselbe Regel anwenden. „Zuletzt aktiv" kommt aus
 * {@see ComputeLastActivity}, damit die Admin-Nutzerliste dieselbe Auskunft
 * bekommt, ohne die Regel nachzubauen.
 */
class GroupProgressReport
{
    /**
     * Das Fenster für „aktiv diese Woche" (S5.7.2), in Tagen.
     *
     * Fix und ausdrücklich **nicht** `Team::inactivity_threshold_days`: diese
     * Schwelle ist pro Gruppe konfigurierbar, und eine kohortenweite Kennzahl
     * aus verschieden langen Fenstern wäre keine Zahl mehr, sondern eine Summe
     * unvergleichbarer Teilmengen. Die konfigurierbare Schwelle bleibt der
     * Inaktiv-Markierung *innerhalb* einer Gruppe vorbehalten.
     */
    public const ACTIVE_WINDOW_DAYS = 7;

    public function __construct(
        protected ComputePathProgress $computePathProgress,
        protected ComputeLastActivity $computeLastActivity,
    ) {
        //
    }

    /**
     * Build the full report for one group.
     */
    public function handle(Team $team): GroupReport
    {
        /** @var EloquentCollection<int, User> $members */
        $members = $team->apprentices()->orderBy('name')->get();

        $paths = $this->visiblePaths();
        $memberIds = $members->modelKeys();

        $matrix = $this->matrix($memberIds, $paths);
        $lastActivity = $this->computeLastActivity->handle($memberIds);
        $inactiveSince = Carbon::now()->subDays($team->inactivity_threshold_days);
        $activeSince = Carbon::now()->subDays(self::ACTIVE_WINDOW_DAYS);

        $memberStats = [];

        $memberRows = $members
            ->map(function (User $member) use ($matrix, $lastActivity, $inactiveSince, $activeSince, &$memberStats): GroupMemberRow {
                $rows = array_values($matrix[$member->id] ?? []);
                $activeAt = $lastActivity[$member->id] ?? null;
                $isInactive = $activeAt === null || $activeAt->lessThan($inactiveSince);

                $stats = $this->memberStats(
                    $rows,
                    $isInactive,
                    $activeAt !== null && $activeAt->greaterThanOrEqualTo($activeSince),
                );
                $memberStats[] = $stats;

                return new GroupMemberRow(
                    member: $member,
                    averagePercent: $stats['percent'],
                    completedPaths: $stats['completed'],
                    totalPaths: $stats['total'],
                    lastActivityAt: $activeAt,
                    isInactive: $isInactive,
                );
            })
            ->all();

        $pathRows = $paths
            ->map(function (LearningPath $path) use ($matrix, $memberIds): GroupPathRow {
                $rows = array_values(array_filter(array_map(
                    fn (int $memberId): ?PathProgressRow => $matrix[$memberId][$path->id] ?? null,
                    $memberIds,
                )));

                return new GroupPathRow(
                    path: $path,
                    memberCount: count($memberIds),
                    averagePercent: $this->averagePercent($rows),
                    completedCount: $this->countCompleted($rows),
                    passedCount: count(array_filter($rows, fn (PathProgressRow $row): bool => $row->quizPassed)),
                    hasQuiz: $path->finalQuiz !== null,
                );
            })
            ->all();

        return new GroupReport(
            team: $team,
            paths: $pathRows,
            quizzes: $this->assignedQuizRows($team, $memberIds),
            members: $memberRows,
            summary: $this->summarizeStats($team, $memberStats),
        );
    }

    /**
     * Die dieser Gruppe ausgegebenen freien Quizze, aggregiert (Sprint 5.6).
     *
     * Bewusst **nicht** in `summarize()` und **nicht** in den Ø-Fortschritt:
     * freie Quizze sind Zusatzaufgaben ohne Lesefortschritt, und der
     * Gruppen-Ø soll weiter die Pfad-Grundmenge mitteln — sonst hinge er
     * davon ab, wie viele Extra-Quizze eine Gruppe gerade bekommen hat und
     * wäre zwischen Gruppen nicht mehr vergleichbar.
     *
     * Eine `GROUP BY`-Abfrage über alle ausgegebenen Quizze zusammen; gewertet
     * wird je Mitglied der **beste** abgeschlossene Versuch.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, GroupQuizRow>
     */
    protected function assignedQuizRows(Team $team, array $memberIds): array
    {
        /** @var EloquentCollection<int, Quiz> $quizzes */
        $quizzes = $team->quizzes()
            ->where('kind', QuizKind::Custom)
            ->where('status', PathStatus::Published)
            ->orderBy('title')
            ->get();

        if ($quizzes->isEmpty()) {
            return [];
        }

        $stats = $memberIds === []
            ? []
            : $this->bestAttemptStats($memberIds, $quizzes->modelKeys());

        return $quizzes
            ->map(function (Quiz $quiz) use ($memberIds, $stats): GroupQuizRow {
                $rows = $stats[$quiz->id] ?? [];

                $sum = array_sum(array_map(
                    fn (array $row): int => Quiz::percentOf($row['score'], $row['max_score']),
                    $rows,
                ));

                $attemptedCount = count($rows);

                return new GroupQuizRow(
                    quiz: $quiz,
                    memberCount: count($memberIds),
                    attemptedCount: $attemptedCount,
                    passedCount: count(array_filter($rows, fn (array $row): bool => $row['passed'])),
                    averagePercent: $attemptedCount === 0 ? null : intdiv($sum, $attemptedCount),
                );
            })
            ->all();
    }

    /**
     * Der beste abgeschlossene Versuch je Mitglied und Quiz — eine Abfrage.
     *
     * „Bester" heißt: höchster Prozentwert, bei Gleichstand höherer Score,
     * dann der jüngere Versuch. Der Rang entsteht per `ROW_NUMBER()` über die
     * Partition `(quiz_id, user_id)`; der Subquery gibt nur Rang 1 heraus — so
     * stammen `score` und `max_score` garantiert **aus derselben Zeile**.
     * Unabhängige `MAX(score)` / `MAX(max_score)` hätten bei Versuchen mit
     * unterschiedlichem `max_score` (Admin hängt eine Frage zwischen zwei
     * Versuchen an) einen falschen, nach unten abweichenden Bestwert geliefert:
     * aus 8/10 und 7/11 wurde 8/11.
     *
     * Der Rang-Filter sitzt bewusst in SQL und nicht in PHP: sonst lädt die
     * Seite **alle** Versuche aller Mitglieder, um die meisten wegzuwerfen.
     *
     * „Bestanden" gilt je Mitglied, sobald es **je** gelang (unbegrenzte
     * Wiederholungen, Konzept f) — `MAX(passed)` über die Partition, also
     * unabhängig davon, ob der beste Versuch gerade der bestandene war. Das
     * Aggregat ist hier korrekt, weil die Aussage zeilenübergreifend gemeint
     * ist.
     *
     * @param  array<int, int>  $memberIds
     * @param  array<int, int|string>  $quizIds
     * @return array<int, array<int, array{score: int, max_score: int, passed: bool}>>
     */
    protected function bestAttemptStats(array $memberIds, array $quizIds): array
    {
        $ranked = DB::table('quiz_attempts')
            ->whereIn('user_id', $memberIds)
            ->whereIn('quiz_id', $quizIds)
            ->whereNotNull('completed_at')
            ->select('quiz_id', 'user_id', 'score', 'max_score')
            // `MAX(CASE WHEN passed THEN 1 ELSE 0 END)` statt `MAX(passed)`:
            // PostgreSQL kennt keine Aggregatfunktion `max(boolean)`. SQLite
            // schluckt beides, weil es Booleans als 0/1 speichert — die Form
            // hier läuft auf beiden (siehe technical-concept.md, „Datenbank").
            ->selectRaw('MAX(CASE WHEN passed THEN 1 ELSE 0 END) OVER (PARTITION BY quiz_id, user_id) AS ever_passed')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY quiz_id, user_id ORDER BY score * 1.0 / NULLIF(max_score, 0) DESC, score DESC, completed_at DESC) AS rn');

        $rows = DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->get();

        $stats = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $stats[(int) $values['quiz_id']][(int) $values['user_id']] = [
                'score' => (int) $values['score'],
                'max_score' => (int) $values['max_score'],
                'passed' => (bool) $values['ever_passed'],
            ];
        }

        return $stats;
    }

    /**
     * Der Ein-Zeilen-Überblick für **mehrere** Gruppen, in einem Rutsch —
     * die Ø-Spalte der Gruppen-Liste und der Kohorten-Überblick des
     * Admin-Dashboards lesen ihn. Eine Sammelabfrage statt einer je Gruppe.
     *
     * @param  EloquentCollection<int, Team>  $teams
     * @return Collection<int, GroupSummary> nach Gruppen-ID
     */
    public function summarize(EloquentCollection $teams): Collection
    {
        if ($teams->isEmpty()) {
            return collect();
        }

        // Der Join ersetzt hier Team::apprentices(): die Sammelabfrage geht
        // bewusst an Eloquent vorbei (eine Abfrage für alle Gruppen), muss aber
        // dieselbe Menge liefern — Ausbildner in einer Gruppe zählen nicht mit.
        // `= false` statt `= 0`: PostgreSQL kennt keinen Integer-Vergleich auf
        // boolean (siehe technical-concept.md, Abschnitt „Datenbank").
        $memberships = DB::table('team_members')
            ->join('users', 'users.id', '=', 'team_members.user_id')
            ->whereIn('team_members.team_id', $teams->modelKeys())
            ->where('users.is_admin', false)
            ->get(['team_members.team_id', 'team_members.user_id']);

        $memberIdsByTeam = $memberships
            ->groupBy('team_id')
            ->map(fn (Collection $rows): array => $rows->pluck('user_id')->map(intval(...))->all());

        $userIds = $memberships->pluck('user_id')->unique()->map(intval(...))->values()->all();

        $paths = $this->visiblePaths();
        $matrix = $this->matrix($userIds, $paths);
        $lastActivity = $this->computeLastActivity->handle($userIds);

        $activeSince = Carbon::now()->subDays(self::ACTIVE_WINDOW_DAYS);

        return $teams
            ->mapWithKeys(function (Team $team) use ($memberIdsByTeam, $matrix, $lastActivity, $activeSince): array {
                $inactiveSince = Carbon::now()->subDays($team->inactivity_threshold_days);

                $stats = array_map(function (int $memberId) use ($matrix, $lastActivity, $inactiveSince, $activeSince): array {
                    $memberRows = array_values($matrix[$memberId] ?? []);
                    $activeAt = $lastActivity[$memberId] ?? null;

                    return $this->memberStats(
                        $memberRows,
                        $activeAt === null || $activeAt->lessThan($inactiveSince),
                        $activeAt !== null && $activeAt->greaterThanOrEqualTo($activeSince),
                    );
                }, $memberIdsByTeam->get($team->id, []));

                return [$team->id => $this->summarizeStats($team, $stats)];
            });
    }

    /**
     * Die für Lehrlinge freigegebenen Pfade samt Lektionszahl.
     *
     * Nur sichtbare Pfade fließen in Quoten ein — ein Entwurf darf die Gruppe
     * nicht künstlich schlecht aussehen lassen. Ein unveröffentlichtes
     * Abschluss-Quiz zählt wie „kein Quiz" (kann niemand ablegen).
     *
     * @return EloquentCollection<int, LearningPath>
     */
    protected function visiblePaths(): EloquentCollection
    {
        return LearningPath::query()
            ->visibleToLearners()
            ->withCount('lessons')
            ->withPlayableFinalQuiz()
            ->orderBy('position')
            ->get();
    }

    /**
     * Die Fortschrittszeile jedes Mitglieds auf jedem Pfad: `[userId][pathId]`.
     *
     * Zwei `GROUP BY`-Abfragen über *alle* Nutzer und *alle* Pfade, danach
     * reine PHP-Verrechnung — der Aufwand wächst weder mit der Zahl der
     * Lehrlinge noch mit der der Pfade.
     *
     * @param  array<int, int>  $userIds
     * @param  EloquentCollection<int, LearningPath>  $paths
     * @return array<int, array<int, PathProgressRow>>
     */
    protected function matrix(array $userIds, EloquentCollection $paths): array
    {
        if ($userIds === [] || $paths->isEmpty()) {
            return [];
        }

        $pathIds = $paths->modelKeys();
        $lessonCounts = $this->completedLessonCounts($userIds, $pathIds);
        $quizStats = $this->finalQuizStats($userIds, $pathIds);

        $matrix = [];

        foreach ($userIds as $userId) {
            foreach ($paths as $path) {
                $key = $userId.'-'.$path->id;
                $stats = $quizStats[$key] ?? null;

                $matrix[$userId][$path->id] = $this->computePathProgress->handle(
                    path: $path,
                    totalLessons: (int) $path->lessons_count,
                    completedLessons: $lessonCounts[$key] ?? 0,
                    hasQuiz: $path->finalQuiz !== null,
                    quizPassed: $stats['passed'] ?? false,
                    completedAttempts: $stats['attempts'] ?? 0,
                );
            }
        }

        return $matrix;
    }

    /**
     * Erledigte Lektionen je Nutzer und Pfad.
     *
     * `COUNT(DISTINCT lesson_id)`, damit eine doppelt eingetragene Erledigung
     * niemals einen Prozentwert über 100 erzeugen kann.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int|string>  $pathIds
     * @return array<string, int>
     */
    protected function completedLessonCounts(array $userIds, array $pathIds): array
    {
        $rows = DB::table('lesson_completions')
            ->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
            ->whereIn('lesson_completions.user_id', $userIds)
            ->whereIn('lessons.learning_path_id', $pathIds)
            ->groupBy('lesson_completions.user_id', 'lessons.learning_path_id')
            ->select('lesson_completions.user_id', 'lessons.learning_path_id')
            ->selectRaw('COUNT(DISTINCT lesson_completions.lesson_id) as completed_count')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $counts[$values['user_id'].'-'.$values['learning_path_id']] = (int) $values['completed_count'];
        }

        return $counts;
    }

    /**
     * Bestanden-Flag und Versuchszahl je Nutzer und Pfad.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int|string>  $pathIds
     * @return array<string, array{passed: bool, attempts: int}>
     */
    protected function finalQuizStats(array $userIds, array $pathIds): array
    {
        $rows = DB::table('quiz_attempts')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->whereIn('quiz_attempts.user_id', $userIds)
            ->whereNotNull('quiz_attempts.completed_at')
            ->where('quizzes.status', PathStatus::Published->value)
            ->whereIn('quizzes.learning_path_id', $pathIds)
            ->groupBy('quiz_attempts.user_id', 'quizzes.learning_path_id')
            ->select('quiz_attempts.user_id', 'quizzes.learning_path_id')
            // Kein `MAX(boolean)` — PostgreSQL kennt das nicht.
            ->selectRaw('MAX(CASE WHEN quiz_attempts.passed THEN 1 ELSE 0 END) as ever_passed')
            ->selectRaw('COUNT(*) as attempts_count')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $stats[$values['user_id'].'-'.$values['learning_path_id']] = [
                'passed' => (bool) $values['ever_passed'],
                'attempts' => (int) $values['attempts_count'],
            ];
        }

        return $stats;
    }

    /**
     * Die Kennzahlen eines Mitglieds — die Zwischenform, aus der sowohl die
     * Mitgliedszeile als auch der Gruppen-Überblick entstehen.
     *
     * Bewusst **ohne** den `User`: `summarize()` rechnet über alle Gruppen und
     * kennt von den Mitgliedern nur die IDs. Ein Platzhalter-Model nur für die
     * Summe wäre eine Attrappe, die wie ein echter Nutzer aussieht.
     *
     * @param  array<int, PathProgressRow>  $rows
     * @return array{percent: int, completed: int, total: int, inactive: bool, active_recently: bool}
     */
    protected function memberStats(array $rows, bool $isInactive, bool $isRecentlyActive): array
    {
        return [
            'percent' => $this->averagePercent($rows),
            'completed' => $this->countCompleted($rows),
            'total' => count($rows),
            'inactive' => $isInactive,
            'active_recently' => $isRecentlyActive,
        ];
    }

    /**
     * Den Gruppen-Überblick aus den Mitglieds-Kennzahlen ziehen.
     *
     * @param  array<int, array{percent: int, completed: int, total: int, inactive: bool, active_recently: bool}>  $stats
     */
    protected function summarizeStats(Team $team, array $stats): GroupSummary
    {
        $pathSlots = array_sum(array_column($stats, 'total'));

        $percentSum = array_sum(array_map(
            fn (array $row): int => $row['percent'] * $row['total'],
            $stats,
        ));

        return new GroupSummary(
            teamId: $team->id,
            memberCount: count($stats),
            averagePercent: $pathSlots > 0 ? intdiv($percentSum, $pathSlots) : 0,
            completedPathCount: array_sum(array_column($stats, 'completed')),
            pathSlotCount: $pathSlots,
            inactiveCount: count(array_filter($stats, fn (array $row): bool => $row['inactive'])),
            activeRecentlyCount: count(array_filter($stats, fn (array $row): bool => $row['active_recently'])),
            progressBands: $this->progressBands(array_column($stats, 'percent')),
        );
    }

    /**
     * Mitglieder nach Ø-Lesefortschritt in vier Bereiche zählen: unter 25,
     * 25 bis unter 50, 50 bis unter 75 und ab 75 Prozent.
     *
     * @param  array<int, int>  $percents
     * @return array{below25: int, below50: int, below75: int, from75: int}
     */
    protected function progressBands(array $percents): array
    {
        $bands = ['below25' => 0, 'below50' => 0, 'below75' => 0, 'from75' => 0];

        foreach ($percents as $percent) {
            $band = match (true) {
                $percent < 25 => 'below25',
                $percent < 50 => 'below50',
                $percent < 75 => 'below75',
                default => 'from75',
            };

            $bands[$band]++;
        }

        return $bands;
    }

    /**
     * @param  array<int, PathProgressRow>  $rows
     */
    protected function averagePercent(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return intdiv(
            array_sum(array_map(fn (PathProgressRow $row): int => $row->percent, $rows)),
            count($rows),
        );
    }

    /**
     * @param  array<int, PathProgressRow>  $rows
     */
    protected function countCompleted(array $rows): int
    {
        return count(array_filter(
            $rows,
            fn (PathProgressRow $row): bool => $row->status === ProgressStatus::Completed,
        ));
    }
}
