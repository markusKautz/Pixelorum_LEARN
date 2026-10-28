<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{--
            Landing von „LEARN". Sie ist die einzige Seite, die
            Gäste zu sehen bekommen: sie muss sagen, was die Plattform ist, dass
            sie im Testbetrieb läuft, und den Weg zur Anmeldung zeigen. Alles
            andere liegt hinter dem Login.
        --}}
        <div class="relative isolate flex min-h-svh flex-col overflow-hidden">
            {{--
                Hintergrund: Pixelraster als Anspielung auf die Bildmarke, zu den
                Rändern hin ausgeblendet, dazu ein weicher Schein in Markenblau.
                Rein dekorativ, deshalb aria-hidden und pointer-events-none.
            --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div
                    class="absolute inset-0 bg-[linear-gradient(to_right,var(--color-zinc-200)_1px,transparent_1px),linear-gradient(to_bottom,var(--color-zinc-200)_1px,transparent_1px)] bg-[size:48px_48px] [mask-image:radial-gradient(ellipse_75%_60%_at_50%_35%,black,transparent)] dark:bg-[linear-gradient(to_right,var(--color-zinc-800)_1px,transparent_1px),linear-gradient(to_bottom,var(--color-zinc-800)_1px,transparent_1px)]"
                ></div>
                <div class="absolute -top-40 left-1/2 h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-brand-500/15 blur-[140px] dark:bg-brand-500/25"></div>
                <div class="absolute -bottom-32 -right-24 h-96 w-96 rounded-full bg-highlight/10 blur-[130px]"></div>
            </div>

            <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-6 lg:px-8">
                <a href="{{ route('home') }}" class="shrink-0" wire:navigate>
                    <x-app-wordmark class="h-11 sm:h-14" />
                </a>

                @guest
                    <flux:button :href="route('login')" variant="subtle" size="sm" wire:navigate>
                        {{ __('Log in') }}
                    </flux:button>
                @endguest
            </header>

            <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-6 py-12 lg:px-8 lg:py-20">
                <div class="flex max-w-2xl flex-col items-start gap-6">
                    {{-- Testbetrieb steht bewusst vor der Überschrift: wer die
                         Seite zum ersten Mal sieht, soll die Einordnung zuerst
                         lesen und nicht erst nach dem Versprechen. --}}
                    <span class="inline-flex items-center gap-2 rounded-full border border-warning-subtle-border bg-warning-subtle px-3 py-1 text-sm font-medium text-warning-content">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-warning opacity-60"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-warning"></span>
                        </span>
                        {{ __('Test mode') }}
                    </span>

                    <h1 class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        {{ __('Step by step to your final apprenticeship exam.') }}
                    </h1>

                    <p class="max-w-xl text-lg/relaxed text-zinc-600 dark:text-zinc-400">
                        {{ __('LEARN bundles the material of your apprenticeship into short lessons and reinforces it with practice questions, so you can explain it in the technical interview. Where you stand, you see at any time.') }}
                    </p>

                    <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                        @auth
                            @php($dashboardTeam = auth()->user()->currentTeam ?? auth()->user()->personalTeam())

                            @if ($dashboardTeam)
                                <flux:button
                                    :href="route('dashboard', ['current_team' => $dashboardTeam->slug])"
                                    variant="primary"
                                    size="base"
                                    icon-trailing="arrow-right"
                                    wire:navigate
                                >
                                    {{ __('Dashboard') }}
                                </flux:button>
                            @else
                                {{--
                                    Angemeldet, aber ohne Gruppe: ohne diesen Zweig bliebe die
                                    Seite hier leer, der Gast-Zweig mit „Anmelden" greift nicht,
                                    weil @auth zutrifft (Browser-Test S3, F3).
                                --}}
                                <div class="flex flex-col items-start gap-3">
                                    <flux:text class="max-w-md text-zinc-600 dark:text-zinc-400" data-test="landing-no-team-hint">
                                        {{ __('You are currently not assigned to a group. Please contact your training supervisor.') }}
                                    </flux:text>

                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <flux:button variant="primary" type="submit" class="cursor-pointer" data-test="logout-button">
                                            {{ __('Log out') }}
                                        </flux:button>
                                    </form>
                                </div>
                            @endif
                        @else
                            <flux:button
                                :href="route('login')"
                                variant="primary"
                                size="base"
                                icon-trailing="arrow-right"
                                wire:navigate
                                data-test="landing-login-button"
                            >
                                {{ __('Log in') }}
                            </flux:button>

                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('Your training supervisor sets up your access.') }}
                            </p>
                        @endauth
                    </div>
                </div>

                {{--
                    Die drei Karten greifen die Farben der Bildmarke auf (Blau,
                    Grün, Amber). Hier stehen sie als Markenfarben, nicht in der
                    Status-Semantik des Design-Systems.
                --}}
                <div class="mt-16 grid gap-4 sm:grid-cols-3 lg:mt-24">
                    @foreach ([
                        ['icon' => 'map', 'accent' => 'text-brand-500', 'tint' => 'bg-brand-500/10', 'title' => __('Learning paths'), 'body' => __('Each topic in short lessons that build on one another, from the basic term to the whole picture.')],
                        ['icon' => 'academic-cap', 'accent' => 'text-success', 'tint' => 'bg-success/10', 'title' => __('Questions with explanation'), 'body' => __('After every answer you get the reasoning behind the correct solution. Repeating costs nothing.')],
                        ['icon' => 'chart-bar', 'accent' => 'text-warning', 'tint' => 'bg-warning/10', 'title' => __('Progress in view'), 'body' => __('You see what already fits and where another round of practice pays off.')],
                    ] as $feature)
                        <div class="rounded-xl border border-zinc-200 bg-white/70 p-5 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-900/50">
                            <span class="flex size-10 items-center justify-center rounded-md {{ $feature['tint'] }}">
                                <flux:icon :name="$feature['icon']" variant="mini" class="{{ $feature['accent'] }}" />
                            </span>

                            <h2 class="mt-4 font-semibold">{{ $feature['title'] }}</h2>

                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $feature['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </main>

            <footer class="mx-auto flex w-full max-w-6xl flex-col gap-2 border-t border-zinc-200 px-6 py-8 text-sm text-zinc-500 sm:flex-row sm:items-center sm:justify-between lg:px-8 dark:border-zinc-800 dark:text-zinc-400">
                <p>{{ __('The platform is currently running in test mode. Learning paths and questions are still being added, and single features may still change.') }}</p>

                <p class="shrink-0">{{ config('app.name') }}</p>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
