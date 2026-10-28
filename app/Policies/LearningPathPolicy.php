<?php

namespace App\Policies;

use App\Models\LearningPath;
use App\Models\User;

/**
 * Inhalte werden ausschließlich von globalen Admins verwaltet (Konzept c).
 * Diese passieren den Gate::before-Kurzschluss, weshalb hier nur die
 * Lehrling-Sicht formuliert wird (Muster TeamPolicy).
 */
class LearningPathPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Die Liste selbst steht jedem Eingeloggten offen; welche Pfade darin
     * auftauchen, entscheidet der visibleToLearners-Scope.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LearningPath $learningPath): bool
    {
        return $learningPath->isVisibleToLearners();
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
    public function update(User $user, LearningPath $learningPath): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LearningPath $learningPath): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the soft-deleted model
     * (Papierkorb, genutzt ab S6.4).
     */
    public function restore(User $user, LearningPath $learningPath): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * Nimmt Lektionen, Fragen, Quizze und die daran hängenden Lernendendaten
     * mit (S6.4, E3) und ist nur über den Papierkorb erreichbar.
     */
    public function forceDelete(User $user, LearningPath $learningPath): bool
    {
        return false;
    }

    /**
     * Determine whether the user can publish the path (genutzt ab S3.2).
     */
    public function publish(User $user, LearningPath $learningPath): bool
    {
        return false;
    }

    /**
     * Determine whether the user can verify the path against its sources
     * (Grounding-Gate, genutzt ab S3.6).
     */
    public function verify(User $user, LearningPath $learningPath): bool
    {
        return false;
    }
}
