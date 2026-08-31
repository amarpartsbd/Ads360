<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    {{--
        The tenant's own name, falling back to the platform's (spec §43).
        Nothing here is hard-coded: a white-labelled tenant should not see our
        name in their browser tab (Rule 6).
    --}}
    <title inertia>{{ $branding['name'] ?? config('platform.name') }}</title>

    @isset($branding['primary_color'])
        {{--
            The one design token a tenant may replace. Injected as a variable
            override rather than a stylesheet so the rest of the system — hover
            states, focus rings, charts — keeps deriving from it.

            The colour is validated for contrast against white before it is
            stored, so the foreground token below stays legible on it
            (spec §74, Tenant\Values\Branding).
        --}}
        <style>
            :root, :root[data-theme="dark"] {
                --primary: {{ $branding['primary_color'] }};
                --primary-hover: color-mix(in oklab, {{ $branding['primary_color'] }} 85%, black);
                --primary-foreground: #ffffff;
            }
        </style>
    @endisset

    <link rel="preconnect" href="https://fonts.bunny.net">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full bg-background font-sans text-foreground antialiased">
    @inertia
</body>
</html>
