<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Goalz helps you manage personal finances, track expenses, and create savings goals.">
        <title>Goalz — Personal finances with purpose</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body data-goalz-theme="sage" class="flex min-h-svh flex-col bg-[var(--goalz-background)] font-sans text-[var(--goalz-foreground)] antialiased">
        <header class="bg-[var(--goalz-toolbar)]">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5 sm:px-10">
                <a href="{{ route('home') }}" aria-label="Goalz home" class="text-xl font-bold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--goalz-accent)]">Goalz<span class="text-[var(--goalz-accent)]" aria-hidden="true">.</span></a>
                <nav aria-label="Account navigation" class="flex items-center gap-3 text-sm font-semibold">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-full bg-[var(--goalz-accent)] px-5 py-3 text-white transition hover:bg-[var(--goalz-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--goalz-accent)]">Dashboard <span aria-hidden="true">↗</span></a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-full px-4 py-3 transition hover:bg-white/60 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--goalz-accent)]">Log in</a>
                        @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
                            <a href="{{ route('register') }}" class="rounded-full bg-[var(--goalz-accent)] px-5 py-3 text-white transition hover:bg-[var(--goalz-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--goalz-accent)]">Register <span aria-hidden="true">↗</span></a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main class="flex flex-1 flex-col items-center justify-center gap-5 px-6 py-16 text-center">
            <div class="flex flex-col items-center justify-center gap-4 sm:flex-row sm:items-center sm:gap-6">
                <img
                    src="{{ asset('images/goalz-hill.png') }}"
                    alt=""
                    class="w-24 object-contain sm:w-32 lg:w-36"
                    aria-hidden="true"
                >

                <h1 class="text-7xl font-semibold tracking-tighter sm:text-8xl lg:text-9xl">
                    Goalz
                </h1>
            </div>

            <p class="max-w-xl text-xl leading-relaxed font-medium tracking-tight text-[var(--goalz-muted)] sm:text-2xl">
                There is always one more hill to climb
            </p>
        </main>
    </body>
</html>
