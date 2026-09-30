# 開発環境
Windows 11
├─ VS Code ＋ WSL 拡張       編集・差分確認・デバッグ
├─ Docker Desktop           Sail のコンテナ実行
└─ WSL2 Ubuntu 26.04
   └── VideoManager           Laravel 13・Livewire・Remotion
    ├─ Codex CLI              設計、実装、修正、テスト
    ├─ Graphify               コードと知識の探索
    └─ Sail                   PHP・PostgreSQL・Queue
## このプロジェクトに必要なものインストールリスト
## 最初にインストールしたもの
0. Laravel 13に必要なもの
apt-get install php8.5-cli  # version 8.5.4-0ubuntu1.1
apt-get install composer
------------------------
vscodeを落としてclaudeが落ちるため手動でインストール 
./vendor/bin/sail build --no-cache
./vendor/bin/sail build up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:model Video -m
./vendor/bin/sail composer require laravel/ai
------------------------
1. node.js npm 関連
Node.js v24.21.0 と npm 12.1.0 は最初から入っていたので、追加のインストールはしていません。
./vendor/bin/sail npm install で依存パッケージ 96 件を入れました（脆弱性 0 件）。
./vendor/bin/sail npm run build で Vite のビルドが通り、public/build に出力されました。
2. PostgreSql+pgvectorのインストール
compose.yaml の mysql サービスを pgsql（イメージ pgvector/pgvector:pg18）に置き換え。.env は DB_CONNECTION=pgsql / DB_HOST=pgsql / DB_PORT=5432 に変更。
pgvector 拡張はマイグレーション 0001_01_01_000003_enable_pgvector_extension.php で有効化（testing DB にも適用される）。
./vendor/bin/sail down --remove-orphans
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
PostgreSQL 18.6 / vector 0.8.6 を確認。
## LiveWire
3.livewireの導入
既存プロジェクトのため、スターターキットではなくパッケージとして追加。先に Livewire を入れてから Boost を入れると、Livewire 用のガイドラインも生成される。
./vendor/bin/sail composer require livewire/livewire        # Livewire v4.4.7
./vendor/bin/sail composer require laravel/boost --dev
./vendor/bin/sail artisan boost:install --guidelines --skills -n   # AGENTS.md と .claude/skills を生成
./vendor/bin/sail artisan livewire:config                   # config/livewire.php
./vendor/bin/sail artisan livewire:layout                   # resources/views/layouts/app.blade.php
/livewire-xxxx/livewire.js が 200 を返し、既存テストが通ることを確認。
##  起動後にコンテナ側の拡張を確認するには、プロジェクト内で実行します。
./vendor/bin/sail php -m | grep -Ei '^(PDO|pdo_pgsql|pgsql)$'
実施内容
手順	コマンド	結果
Livewire の追加	sail composer require livewire/livewire	v4.4.7
Boost の追加	sail composer require laravel/boost --dev	v2.10
ガイドラインの生成	sail artisan boost:install --guidelines --skills -n	AGENTS.md と .claude/skills/（livewire-development など 7 つ）を生成
設定ファイルの公開	sail artisan livewire:config	config/livewire.php を作成
共通レイアウトの作成	sail artisan livewire:layout	resources/views/layouts/app.blade.php を作成
同じ手順を install.md の「3.」に記録しました。
PDO と pdo_pgsql が表示されれば、Laravel から PostgreSQL へ接続するための PHP 拡張を確認できます。Ubuntu 側で php artisan migrate を実行する予定がなければ、Ubuntu 側の php-pgsql は後から必要になった時に入れれば十分です。
design.mdを必ず参照して、下記のインストールを設計目的に合わせてインストールしてください。
4. Graphify の導入、ますグラフ本体の graph.jsonを作成する前に、video_manager内の不要な部分を、.graphifyignoreを作成。
.graphifyignoreは、.gitignoreの内容と同じとする。

5．remotion :動画生成用
https://www.remotion.dev/docs/assets?utm_source=chatgpt.com

6.　Runway api による、高度な動画生成

7.　動画設計に必要なもの
　postgresql+pgvector によるデータ構造と、agentは、composer/aiにより導入する。
