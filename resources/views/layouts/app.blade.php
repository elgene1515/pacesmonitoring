<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PACES Monitoring System</title>

    {{-- Set the colour theme before anything renders so the page never flashes light. Keep in step with resources/js/stores/theme.js. --}}
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('paces.theme');

                if (theme !== 'light' && theme !== 'dark') {
                    theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }

                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {
                // Storage unavailable: the app applies the theme once it loads.
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
