<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Sign Up - ERIAO</title>
    @vite(['resources/js/app.js', 'resources/css/app.css'])
</head>
<body class="flex flex-col w-screen h-screen text-zinc-700">
    <div class="grid grid-cols-3 w-full h-full">
        <div class="flex flex-col col-span-1">
            <livewire:authentication.sign-up-form />
        </div>

        <div class="flex flex-col col-span-2 bg-primary w-full h-full">

        </div>
    </div>

    @livewireScripts()
    @fluxScripts()
</body>
</html>