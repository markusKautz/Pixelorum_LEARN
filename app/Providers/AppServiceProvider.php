<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureViews();
    }

    /**
     * Bind the data the shared layouts need on every render.
     *
     * Die Sidebar hängt an keiner Seite, sondern am Layout — ihr Zähler
     * ausgegebener Quizze gehört deshalb hierher und nicht als `@php`-Aufruf
     * ins Markup (Sprint-5.5/5.6-Review N1). Eine Abfrage je Seitenaufruf
     * bleibt es so oder so; sie hat jetzt aber einen benannten Ort, einen
     * eigenen Test und eine Stelle, an der ein Cache ansetzen könnte, falls
     * die Sidebar je auf einem hochfrequenten Pfad landet.
     */
    protected function configureViews(): void
    {
        // Das Wildcard ist Absicht: `<x-layouts::app.sidebar>` ist eine anonyme
        // Blade-Komponente, und die löst Blade über einen **gehashten**
        // View-Namespace auf (BladeCompiler::anonymousComponentPath, aktuell
        // `hash('xxh128', 'layouts')`) — nicht über `layouts::app.sidebar` und
        // erst recht nicht über den Punkt-Pfad. `*::app.sidebar` trifft jede
        // dieser Schreibweisen und überlebt einen Wechsel des Hash-Verfahrens.
        View::composer('*::app.sidebar', function (ViewContract $view): void {
            $user = Auth::user();

            // Nur Lehrlinge zahlen die Abfrage: der Ausbildner übt nicht, seine
            // Sidebar zeigt den Eintrag gar nicht (`@cannot('admin')`).
            $view->with('openAssignedQuizCount', $user instanceof User && ! $user->can('admin')
                ? $user->openAssignedQuizCount()
                : 0);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure authorization gates for global admins.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => $user->is_admin ? true : null);

        Gate::define('admin', fn (User $user): bool => $user->is_admin);
    }
}
