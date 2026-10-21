<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Gestão de Contratos</title>
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f6fb;color:#172b4d;font-family:Arial,sans-serif}main{box-sizing:border-box;width:min(520px,calc(100% - 32px));padding:36px;background:#fff;border:1px solid #dce6f4;border-radius:24px;box-shadow:0 12px 40px #1d4f8e12}strong{color:#3478f6}h1{font-size:26px;line-height:1.2}p{color:#52657f;line-height:1.6}a{display:inline-block;padding:12px 18px;background:#2563eb;border-radius:12px;color:#fff;text-decoration:none;line-height:1.5}a:focus-visible{outline:3px solid #93b7fa;outline-offset:4px}</style>
</head>
<body><main><strong>Gestão de Contratos · {{ $code }}</strong><h1>{{ $title }}</h1><p>{{ $description }}</p><a href="{{ $returnUrl ?? url('/') }}">{{ $returnLabel ?? 'Voltar ao início' }}</a></main></body>
</html>
