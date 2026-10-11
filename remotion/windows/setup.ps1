# Windows ネイティブの静止画書き出し（scripts/still-windows.mjs）に必要な依存を入れる。
#
# npm は \\wsl.localhost\ の UNC パスへインストールできない（arborist の realpath が無限再帰する）。
# そのため依存は Windows ローカルのフォルダ（既定 %LOCALAPPDATA%\video-manager-remotion-win）に入れ、
# この package.json を正本としてコピーして npm install する。
#
# 使い方（Windows の PowerShell）：
#   remotion\windows\setup.ps1
#   $env:REMOTION_WIN_DIR = 'D:\path' でインストール先を変えられる（書き出し時も同じ値にする）。

$ErrorActionPreference = 'Stop'

$dir = if ($env:REMOTION_WIN_DIR) { $env:REMOTION_WIN_DIR } else { Join-Path $env:LOCALAPPDATA 'video-manager-remotion-win' }

Write-Host "依存のインストール先：$dir"
New-Item -ItemType Directory -Force -Path $dir | Out-Null

# package.json を正本からコピー（UNC からの読み取りは問題ない）。
Copy-Item -Path (Join-Path $PSScriptRoot 'package.json') -Destination (Join-Path $dir 'package.json') -Force

Push-Location $dir
try {
    # 進捗バーと監査を切る（PowerShell の出力処理が npm の進捗で落ちるため）。
    # cmd 経由で実行し、リダイレクトは cmd に任せる。
    cmd /c "npm install --no-fund --no-audit --no-progress"
    if ($LASTEXITCODE -ne 0) { throw "npm install が失敗しました（終了コード $LASTEXITCODE）" }
}
finally {
    Pop-Location
}

Write-Host ""
Write-Host "完了。静止画の書き出し例："
Write-Host "  cd <リポジトリ>\remotion"
Write-Host "  node scripts\still-windows.mjs fixtures\sample.json"
Write-Host ""
Write-Host "制作画面の「静止画」ボタンから作るときは、静止画のサーバーを起動したままにする："
Write-Host "  cd <リポジトリ>\remotion"
Write-Host "  node scripts\still-server-windows.mjs"
