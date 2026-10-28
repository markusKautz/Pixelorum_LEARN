<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, LessonCompletion> $lessonCompletions
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, PathProgress> $pathProgress
 * @property-read Collection<int, QuizAttempt> $quizAttempts
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Spiegelt den DB-Default, damit `is_admin` auch auf frisch erstellten
     * Instanzen ohne refresh() ein bool ist (Cast greift nur auf gesetzte
     * Attribute).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Normalize emails to lowercase so lookups can compare exactly.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }

    /**
     * Darf dieser Nutzer von `$actor` endgültig gelöscht werden (S5.7.4)?
     *
     * Bewusst **keine** Policy: `Gate::before` gibt einem Admin jede Fähigkeit
     * frei (siehe `AppServiceProvider::configureAuthorization()`), eine
     * `UserPolicy::delete()` würde für ihn also nie ausgeführt und die beiden
     * Wächter unten wären Dekoration. Sie sind ohnehin keine Berechtigungs-,
     * sondern Bestandsfragen — sie gelten für *jeden* Handelnden:
     *
     * - **Nicht sich selbst.** Wer sich löscht, bricht mitten in der Aktion die
     *   eigene Sitzung ab; die Selbstlöschung hat ihren Platz in den
     *   Kontoeinstellungen, wo sie ausloggt und umleitet.
     * - **Nicht den letzten Ausbildner.** Sonst sperrt sich das Projekt aus:
     *   neue Admins entstehen nur über `lapp:make-admin` auf der Kommandozeile.
     */
    public function isDeletableBy(self $actor): bool
    {
        return $this->id !== $actor->id && ! $this->isLastAdmin();
    }

    /**
     * Ist dies der einzige verbliebene Ausbildner?
     */
    public function isLastAdmin(): bool
    {
        return $this->is_admin
            && self::query()->where('is_admin', true)->whereKeyNot($this->id)->doesntExist();
    }

    /**
     * Der Filterwert der Admin-Nutzerliste für „gehört keiner Gruppe an".
     *
     * Eine Sentinel-Konstante statt eines leeren Strings, weil „" schon „alle"
     * bedeutet — das Muster stammt von {@see Question::NO_LESSON}.
     */
    public const NO_GROUP = 'none';

    /**
     * Scope a query to users whose name or email matches the given term.
     *
     * `whereLike` statt `where(..., 'like', ...)`: SQLites LIKE ist von Haus aus
     * schreibungsunabhängig, PostgreSQLs nicht — ohne das findet die Suche nach
     * „müller" produktiv kein „Müller" (derselbe Fallstrick wie beim
     * Fragen-Pool, siehe {@see Question::search()}).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when(filled($term), function (Builder $query) use ($term) {
            $escaped = addcslashes(trim((string) $term), '%_\\');

            $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$escaped}%")
                ->orWhereLike('email', "%{$escaped}%"));
        });
    }

    /**
     * Scope a query to the members of one group; self::NO_GROUP liefert alle,
     * die keiner Gruppe angehören, ein leerer Wert lässt den Filter aus.
     *
     * Persönliche Teams zählen dabei **nicht** als Gruppe: jeder Nutzer hat
     * eines, „ohne Gruppe" wäre sonst immer leer.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function inGroup(Builder $query, int|string|null $group): void
    {
        if (blank($group)) {
            return;
        }

        $isGroup = fn (Builder $teams) => $teams->where('teams.is_personal', false);

        if ($group === self::NO_GROUP) {
            $query->whereDoesntHave('teams', $isGroup);

            return;
        }

        $query->whereHas('teams', fn (Builder $teams) => $isGroup($teams)->whereKey((int) $group));
    }

    /**
     * Get the lessons this user has completed.
     *
     * @return HasMany<LessonCompletion, $this>
     */
    public function lessonCompletions(): HasMany
    {
        return $this->hasMany(LessonCompletion::class);
    }

    /**
     * Get this user's quiz attempts.
     *
     * @return HasMany<QuizAttempt, $this>
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Get this user's aggregated progress per learning path.
     *
     * @return HasMany<PathProgress, $this>
     */
    public function pathProgress(): HasMany
    {
        return $this->hasMany(PathProgress::class);
    }

    /**
     * Count the handed out quizzes this apprentice has not finished yet
     * (Sprint 5.6) — der Zähler am Sidebar-Eintrag „Üben".
     *
     * Ohne ihn erführe ein Lehrling von einer Ausgabe nur, wenn er von sich aus
     * auf „Üben" klickt; eine gelenkte Aufgabe, die niemand sieht, lenkt nicht.
     * Eine einzige COUNT-Abfrage, weil die Sidebar auf jeder Seite rendert.
     */
    public function openAssignedQuizCount(): int
    {
        return Quiz::query()
            ->assignedTo($this)
            ->whereDoesntHave('attempts', fn (Builder $query) => $query
                ->where('user_id', $this->id)
                ->whereNotNull('completed_at'))
            ->count();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
