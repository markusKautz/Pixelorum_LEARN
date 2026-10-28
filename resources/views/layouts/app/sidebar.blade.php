<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                {{-- Rollenabhängiges Ziel (Entscheidung 8): der Umweg über
                     `dashboard` würde den Admin nur weiterleiten. --}}
                <flux:sidebar.item
                    icon="home"
                    :href="auth()->user()->can('admin') ? route('admin.dashboard') : route('dashboard')"
                    :current="request()->routeIs('dashboard') || request()->routeIs('admin.dashboard')"
                    wire:navigate
                >
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                {{-- Nur für Lehrlinge: der Admin wertet über die Gruppen aus
                     (Sprint-5-Entscheidung 2), nicht über eine Selbstsicht. --}}
                @cannot('admin')
                    <flux:sidebar.item icon="chart-bar" :href="route('learn.progress')" :current="request()->routeIs('learn.progress')" wire:navigate>
                        {{ __('My progress') }}
                    </flux:sidebar.item>

                    {{-- Selbstgesteuertes Üben (Sprint 5.5) — ebenfalls nur
                         für Lehrlinge; der Ausbildner übt nicht.

                         Der Zähler meldet ausgegebene Quizze, die noch
                         offen sind (Sprint 5.6): das Dashboard bleibt bei
                         den Lernpfaden, das Signal steht trotzdem da, wo
                         der Klick hinführt. Amber, nicht rot — es ist eine
                         Aufgabe, kein Versäumnis.

                         `$openAssignedQuizCount` kommt aus dem View-Composer
                         in AppServiceProvider::configureViews(); die
                         Abfrage steht damit nicht im Markup. --}}
                    <flux:sidebar.item
                        icon="academic-cap"
                        :href="route('learn.practice.create')"
                        :current="request()->routeIs('learn.practice.*')"
                        :badge="$openAssignedQuizCount > 0 ? $openAssignedQuizCount : null"
                        badge-color="amber"
                        wire:navigate
                    >
                        {{ __('Practise') }}
                    </flux:sidebar.item>
                @endcannot

                @can('admin')
                    {{-- Die Verwaltung ist in zwei Gruppen getrennt: die
                         Lerninhalte, an denen der Ausbildner täglich baut, und
                         die Nutzerverwaltung, die er selten anfasst. --}}
                    <flux:sidebar.group :heading="__('Content')" class="grid">
                        <flux:sidebar.item icon="academic-cap" :href="route('admin.learning-paths.index')" :current="request()->routeIs('admin.learning-paths.*') || request()->routeIs('admin.lessons.*')" wire:navigate>
                            {{ __('Learning paths') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('admin.quizzes.index')" :current="request()->routeIs('admin.quizzes.*')" wire:navigate>
                            {{ __('Quizzes') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="question-mark-circle" :href="route('admin.questions.index')" :current="request()->routeIs('admin.questions.*')" wire:navigate>
                            {{ __('Questions') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group :heading="__('User management')" class="grid">
                        <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="user-group" :href="route('admin.groups.index')" :current="request()->routeIs('admin.groups.*')" wire:navigate>
                            {{ __('Groups') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                        <flux:menu.item :href="route('about')" icon="information-circle" wire:navigate>
                            {{ __('About') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
