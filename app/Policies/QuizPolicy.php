<?php

namespace App\Policies;

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\Quiz;
use App\Models\User;

/**
 * Wie die LearningPathPolicy formuliert diese Policy nur die Lehrling-Sicht;
 * Admins passieren den Gate::before-Kurzschluss.
 */
class QuizPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Das Grounding-Gate hängt am Pfad (Konzept e): ein Quiz zu einem noch
     * nicht geprüften Pfad bleibt unsichtbar, auch wenn es selbst
     * veröffentlicht ist. Freie Quizze (ohne Pfad) hängen an keinem Gate.
     */
    public function view(User $user, Quiz $quiz): bool
    {
        /*
         * Ein Übungsquiz (Sprint 5.5) ist der persönliche Behälter genau eines
         * Lehrlings und gehört ihm allein — kein Published-Gate (es wird nie
         * redaktionell veröffentlicht) und kein Pfad-Gate (es hängt an keinem
         * Pfad). Die Erdung sitzt eine Stufe früher: BuildPracticeQuiz zieht
         * ausschließlich Fragen aus sichtbaren Pfaden.
         *
         * Vor der Status-Prüfung, weil der Behälter Entwurf bleibt.
         */
        if ($quiz->kind === QuizKind::Practice) {
            return $quiz->created_by === $user->id;
        }

        if ($quiz->status !== PathStatus::Published) {
            return false;
        }

        // Ob ein Pfad dranhängt, entscheidet die **Spalte**, nicht die
        // Relation: bei einem soft-gelöschten Pfad liefert die Relation null,
        // und das Quiz sähe fälschlich wie ein freies Quiz aus.
        if ($quiz->learning_path_id === null) {
            /*
             * Freies Quiz: sichtbar nur für Gruppen, denen es ausgegeben wurde
             * (Sprint 5.6). Vor diesem Sprint war jedes veröffentlichte freie
             * Quiz für jeden Lehrling per Direkt-URL offen — nur verlinkt war
             * es nirgends. Die Zuweisung schließt diese Lücke und macht aus
             * „liegt herum" ein „wurde ausgegeben".
             *
             * Persönliche Teams sind keine Lehrlingsgruppen und taugen
             * deshalb nicht als Zugang.
             */
            return $quiz->teams()
                ->where('teams.is_personal', false)
                ->whereIn('teams.id', $user->teams()->select('teams.id'))
                ->exists();
        }

        // Relation null = Pfad soft-gelöscht ⇒ kein Gate erfüllbar.
        return $quiz->learningPath?->isVisibleToLearners() ?? false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Quiz $quiz): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Quiz $quiz): bool
    {
        return false;
    }
}
