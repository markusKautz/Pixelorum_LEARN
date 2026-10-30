<?php

namespace App\Models;

use App\Actions\Analytics\ComputePathProgress;
use App\Enums\ProgressStatus;
use Database\Factories\PathProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gespeicherte Aggregation fürs Admin-Reporting: eine Zeile je User und Pfad.
 *
 * **Bleibt bewusst ungenutzt.** Sprint 5 rechnet den Fortschritt statt dessen
 * bei jedem Aufruf frisch aus `lesson_completions` und `quiz_attempts`
 * ({@see ComputePathProgress}) — Entscheidung 1 des
 * Auftraggebers und damit eine bewusste Abweichung vom technischen Konzept d.
 * Bei einer Gruppe kostet Live Millisekunden, während eine gespeicherte
 * Aggregation Trigger, Backfill und Veraltungs-Logik verlangt hätte. Tabelle
 * und Model bleiben stehen: sie sind die Option, wenn die Plattform je auf
 * mehrere tausend gleichzeitig ausgewertete Teilnehmer_innen wächst.
 *
 * @property int $id
 * @property int $user_id
 * @property int $learning_path_id
 * @property ProgressStatus $status
 * @property int $percent
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LearningPath $learningPath
 * @property-read User $user
 */
#[Fillable(['user_id', 'learning_path_id', 'status', 'percent', 'last_activity_at'])]
class PathProgress extends Model
{
    /** @use HasFactory<PathProgressFactory> */
    use HasFactory;

    /**
     * Get the user this progress row belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the path this progress row belongs to.
     *
     * @return BelongsTo<LearningPath, $this>
     */
    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProgressStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }
}
