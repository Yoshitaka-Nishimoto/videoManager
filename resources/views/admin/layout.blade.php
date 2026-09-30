<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - 管理画面</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f6f6f4; color: #1b1b18; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-size: 1.4rem; }
        a { color: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #e5e5e0; text-align: left; font-size: .9rem; vertical-align: top; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .badge-applied { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .diff { background: #fff; border: 1px solid #e5e5e0; overflow-x: auto; font-size: .82rem; line-height: 1.45; padding: 8px 0; }
        .diff div { padding: 0 12px; white-space: pre; font-family: ui-monospace, monospace; }
        .diff .add { background: #e6ffec; } .diff .del { background: #ffebe9; } .diff .hunk { color: #6e7781; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; }
        dt { color: #6e6e68; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
