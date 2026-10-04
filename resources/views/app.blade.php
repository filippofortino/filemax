<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="2400">
        <meta property="og:image:height" content="1260">
        <meta property="og:image:alt" content="Filemax — Large files, one link.">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ asset('og-image.png') }}">
        <meta name="twitter:image:alt" content="Filemax — Large files, one link.">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        @fonts
        @viteReactRefresh
        @vite('resources/scripts/app.tsx')
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
