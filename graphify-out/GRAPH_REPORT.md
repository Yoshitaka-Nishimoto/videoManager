# Graph Report - video_manager  (2026-10-02)

## Corpus Check
- 82 files · ~15,964 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 20 file(s) not represented in the graph (top: (none) 16, .example 1, .xml 1)

## Summary
- 425 nodes · 553 edges · 46 communities (19 shown, 27 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 5 edges (avg confidence: 0.89)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `06ace119`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- CodeChangeApplier
- composer.json
- Video
- Illuminate\Database\Schema\Blueprint
- Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。
- package.json
- User
- Laravel Boost
- CodeChange
- ast_check.py
- CodeChangeController
- require-dev
- FortifyServiceProvider.php
- CodeChangeApplierTest
- logging.php
- console.php
- ExampleTest
- artisan
- Robots.txt Crawler Policy
- CodeFixer.php
- Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。
- Laravel Sail Docker Service
- 開発環境
- From now on, please write in Japanese and answer in Japanese.
- fortify.php

## God Nodes (most connected - your core abstractions)
1. `User` - 21 edges
2. `CodeChangeApplier` - 15 edges
3. `CodeChange` - 13 edges
4. `CodeFixer` - 11 edges
5. `ModifyCode` - 10 edges
6. `ReadCode` - 10 edges
7. `require-dev` - 10 edges
8. `CodeChangeApplierTest` - 10 edges
9. `TestCase` - 10 edges
10. `scripts` - 9 edges

## Surprising Connections (you probably didn't know these)
- `Laravel Boost` --semantically_similar_to--> `Laravel Boost (CLAUDE.md reference)`  [INFERRED] [semantically similar]
  AGENTS.md → CLAUDE.md
- `Laravel Boost` --semantically_similar_to--> `Laravel Boost for Agentic Development`  [INFERRED] [semantically similar]
  AGENTS.md → README.md
- `{closure#1}()` --references--> `CodeChange`  [EXTRACTED]
  app/Console/Commands/RunCodeAgent.php → app/Models/CodeChange.php
- `CodeChangeApplierTest` --inherits--> `CodeAgentTestCase`  [EXTRACTED]
  tests/Feature/CodeAgent/CodeChangeApplierTest.php → tests/Feature/CodeAgent/CodeAgentTestCase.php
- `ModifyCodeToolTest` --inherits--> `CodeAgentTestCase`  [EXTRACTED]
  tests/Feature/CodeAgent/ModifyCodeToolTest.php → tests/Feature/CodeAgent/CodeAgentTestCase.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Laravel Sail Docker Infrastructure** — compose_yaml_laravel_sail, compose_yaml_mysql, compose_yaml_redis, compose_yaml_meilisearch, compose_yaml_mailpit, compose_yaml_selenium [EXTRACTED 1.00]
- **Laravel Boost Agent Setup Pattern** — agents_md_laravel_boost, claude_md_laravel_boost, readme_laravel_boost_agentic [INFERRED 0.95]

## Communities (46 total, 27 thin omitted)

### Community 0 - "CodeChangeApplier"
Cohesion: 0.09
Nodes (15): ModifyCode, ReadCode, AstResult, AstValidator, CodeChangeApplier, CodeChangeException, Illuminate\Contracts\JsonSchema\JsonSchema, Illuminate\Support\Facades\Blade (+7 more)

### Community 1 - "composer.json"
Cohesion: 0.05
Nodes (43): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+35 more)

### Community 2 - "Video"
Cohesion: 0.11
Nodes (12): Video, UserFactory, VideoFactory, DatabaseSeeder, VideoSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Factories\Factory (+4 more)

### Community 3 - "Illuminate\Database\Schema\Blueprint"
Cohesion: 0.09
Nodes (17): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}() (+9 more)

### Community 4 - "Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。"
Cohesion: 0.29
Nodes (6): Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。, Laravel の業務データと Graphify の生成グラフは役割を分ける, Livewire で作る画面, ダッシュボード設計ダッシュボードは二つ必要です, 動画設計, 必要環境

### Community 5 - "package.json"
Cohesion: 0.09
Nodes (22): devDependencies, concurrently, fontaine, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, optionalDependencies (+14 more)

### Community 6 - "User"
Cohesion: 0.09
Nodes (20): CreateNewUser, PasswordValidationRules, ResetUserPassword, UpdateUserPassword, UpdateUserProfileInformation, User, Illuminate\Contracts\Auth\MustVerifyEmail, Illuminate\Contracts\Validation\Rule (+12 more)

### Community 7 - "Laravel Boost"
Cohesion: 0.22
Nodes (9): Laravel Application Agent Setup Guide, Laravel Boost, PHP and Composer Prerequisites, Laravel Application Claude Setup Guide, Laravel Boost (CLAUDE.md reference), Eloquent ORM, Laravel Boost for Agentic Development, Laravel Framework (+1 more)

### Community 8 - "CodeChange"
Cohesion: 0.09
Nodes (11): CodeChange, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\File, SebastianBergmann\Diff\Differ, SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder, AuthenticationTest, CodeAgentTestCase (+3 more)

### Community 9 - "ast_check.py"
Cohesion: 0.21
Nodes (12): json, Parser, sys, build_parser(), collect_errors(), collect_symbols(), name_of(), walk() (+4 more)

### Community 10 - "CodeChangeController"
Cohesion: 0.24
Nodes (4): CodeChangeController, Controller, Illuminate\Contracts\View\View, Illuminate\Support\Facades\Route

### Community 11 - "require-dev"
Cohesion: 0.20
Nodes (10): require-dev, fakerphp/faker, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail, mockery/mockery (+2 more)

### Community 12 - "FortifyServiceProvider.php"
Cohesion: 0.08
Nodes (20): AppServiceProvider, {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), FortifyServiceProvider, {closure#1}(), {closure#2}() (+12 more)

### Community 16 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 32 - "CodeFixer.php"
Cohesion: 0.11
Nodes (14): CodeFixer, {closure#1}(), RunCodeAgent, Illuminate\Console\Attributes\Description, Illuminate\Console\Attributes\Signature, Illuminate\Console\Command, Laravel\Ai\Attributes\MaxSteps, Laravel\Ai\Attributes\Provider (+6 more)

### Community 60 - "Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。"
Cohesion: 0.29
Nodes (6): Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。, Laravel の業務データと Graphify の生成グラフは役割を分ける, ダッシュボード設計ダッシュボードは二つ必要です, 動画設計, 必要環境, 特に、次の機能を設計段階から入れることを勧めます。

### Community 65 - "Laravel Sail Docker Service"
Cohesion: 0.33
Nodes (6): Laravel Sail Docker Service, Mailpit Email Testing Service, Meilisearch Service, MySQL 8.4 Service, Redis Service, Selenium Chromium Service

### Community 66 - "開発環境"
Cohesion: 0.29
Nodes (6): LiveWire, このプロジェクトに必要なものインストールリスト, 実施内容（完了）, 最初にインストールしたもの, 起動後にコンテナ側の拡張を確認するには、プロジェクト内で実行します。, 開発環境

### Community 69 - "From now on, please write in Japanese and answer in Japanese."
Cohesion: 0.40
Nodes (4): From now on, please write in Japanese and answer in Japanese., 必要なテーブル, 本プロジェクトのコードベース管理, 目的：Youtubeなどの動画を解析、理解し、作成するシステムの構築。

## Knowledge Gaps
- **85 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+80 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 215 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **27 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `CodeChange` connect `CodeChange` to `CodeFixer.php`, `CodeChangeApplier`, `CodeChangeController`, `Video`?**
  _High betweenness centrality (0.076) - this node is a cross-community bridge._
- **Why does `CodeChangeApplier` connect `CodeChangeApplier` to `CodeChange`?**
  _High betweenness centrality (0.063) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `CodeChange`, `Video`?**
  _High betweenness centrality (0.058) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _85 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `CodeChangeApplier` be split into smaller, more focused modules?**
  _Cohesion score 0.08562367864693446 - nodes in this community are weakly interconnected._
- **Should `composer.json` be split into smaller, more focused modules?**
  _Cohesion score 0.045454545454545456 - nodes in this community are weakly interconnected._
- **Should `Video` be split into smaller, more focused modules?**
  _Cohesion score 0.11333333333333333 - nodes in this community are weakly interconnected._