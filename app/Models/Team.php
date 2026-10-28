<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property int $inactivity_threshold_days
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Quiz> $quizzes
 */
#[Fillable(['name', 'slug', 'is_personal', 'inactivity_threshold_days'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueSlugs, HasFactory, SoftDeletes;

    /**
     * Spiegelt den DB-Default, damit die Auswertung auch auf einer noch nicht
     * gespeicherten Gruppe eine Schwelle vorfindet (Muster aus LearningPath).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'inactivity_threshold_days' => 14,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * **Achtung:** „alle" heißt wirklich alle — auch ein Ausbildner, der in
     * dieser Gruppe steckt. Für Auswertungen und Lehrlings-Zählungen ist
     * {@see self::apprentices()} gemeint; diese Relation trägt die
     * Mitgliedschaft an sich (Einladungs-Dubletten, Rollenverwaltung,
     * Owner-Suche).
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get the apprentices of this group — the members the reports are about.
     *
     * Die eine Stelle, die „wer ist hier Lehrling" beantwortet. Vorher stand
     * dafür überall `members()`, und das zählte einen Ausbildner, der in der
     * Gruppe steckt, als Lehrling mit 0 % mit — sowohl im Ø-Fortschritt als
     * auch in der Mitgliederliste. Erreichbar ist das heute auf zwei Wegen:
     * über das allgemeine „Team erstellen" (hängt den Anleger als Owner an,
     * anders als `admin.groups`) und über eine angenommene Gruppen-Einladung.
     *
     * Gefiltert wird über `users.is_admin`, nicht über die Pivot-Rolle: die
     * Autorität in diesem Projekt kommt aus `Gate::before`/`is_admin`, die
     * Team-Rolle wird dafür ausdrücklich **nicht** benutzt. Beide Filter
     * liefern heute auf jeder erreichbaren Zeile dasselbe Ergebnis, bis auf
     * eine — ein Ausbildner, der eine Gruppeneinladung annimmt, trägt
     * `role = member` und wäre über die Pivot-Rolle nicht auszuschließen.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function apprentices(): BelongsToMany
    {
        return $this->members()->where('users.is_admin', false);
    }

    /**
     * Get the free quizzes handed out to this group (Sprint 5.6).
     *
     * @return BelongsToMany<Quiz, $this>
     */
    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_team')->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Determine if this group is an archived year (Sprint 5.7).
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Scope a query to the groups of a running year.
     *
     * Die Grundmenge jeder **kohortenweiten** Kennzahl: ein abgeschlossener
     * Jahrgang bleibt für immer in der Datenbank, und jedes Aggregat über
     * Gruppen in verschiedenen Lebensphasen driftet dadurch monoton. Beim
     * Ø-Lesefortschritt stieg mit jedem fertigen Jahrgang der Boden (zwei
     * Gruppen → 50–100 %, drei → 66–100 %), bei den Inaktiven sammelten sich
     * Karteileichen.
     *
     * Bewusst **nicht** in einem Global Scope: die Gruppen-Liste und die
     * Auswertung eines abgeschlossenen Jahrgangs sollen ihn weiterhin finden
     * (archivieren ist kein Verstecken). Nur wer über Gruppen *hinweg*
     * aggregiert, filtert hier durch.
     *
     * @param  Builder<Team>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope a query to archived years.
     *
     * @param  Builder<Team>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /**
     * Scope a query to the apprentice groups — everything but personal teams.
     *
     * Die drei Stellen, die Gruppen auflisten, schrieben `where('is_personal',
     * false)` je einzeln ab; als `archived_at` dazukam, war das die Stelle, an
     * der eine davon vergessen worden wäre.
     *
     * @param  Builder<Team>  $query
     */
    #[Scope]
    protected function groups(Builder $query): void
    {
        $query->where('is_personal', false);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'inactivity_threshold_days' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
