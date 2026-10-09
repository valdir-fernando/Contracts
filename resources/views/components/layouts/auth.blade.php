<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'Entrar' }} · Gestão de Contratos</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>{{ $slot }}</body>
</html>
