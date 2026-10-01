# Graph Report - video_manager  (2026-10-01)

## Corpus Check
- 125 files · ~39,986 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 21 file(s) not represented in the graph (top: (none) 17, .example 1, .xml 1)

## Summary
- 782 nodes · 922 edges · 84 communities (53 shown, 31 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 20 edges (avg confidence: 0.93)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `f7de0267`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- CodeChangeApplier
- composer.json
- Cloud CLI
- Illuminate\Database\Schema\Blueprint
- package.json
- User
- Laravel Boost
- TestCase
- ast_check.py
- Security Best Practices
- require-dev
- FortifyServiceProvider.php
- CodeChangeApplierTest
- config
- ExampleTest
- Robots.txt Crawler Policy
- index.blade.php
- show.blade.php
- Basic Usage Examples
- Livewire Development
- Detection Checklist
- Process
- testing-best-practices/SKILL.md
- Architecture Best Practices
- Tailwind CSS Development
- Events and Notifications Best Practices
- Migration Best Practices
- Fakes, Mocks, and Determinism
- laravel-best-practices/SKILL.md
- Advanced Query Best Practices
- Caching Best Practices
- Database Performance Best Practices
- Eloquent Best Practices
- Queue and Job Best Practices
- scripts
- Blade and View Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Endpoint Tests
- require
- Collection Best Practices
- HTTP Client Best Practices
- Mail Best Practices
- Convention and Style Best Practices
- Validation and Forms Best Practices
- Assertions
- Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。
- Configuration Best Practices
- Naming and Structure
- Test Suite Performance
- Reviewing Tests
- Laravel Sail Docker Service
- 開発環境
- psr-4
- From now on, please write in Japanese and answer in Japanese.
- purpose.md
- autoload-dev
- extra

## God Nodes (most connected - your core abstractions)
1. `User` - 22 edges
2. `CodeChangeApplier` - 15 edges
3. `TestCase` - 15 edges
4. `CodeChange` - 13 edges
5. `Cloud CLI` - 12 edges
6. `CodeFixer` - 11 edges
7. `Detection Checklist` - 11 edges
8. `Architecture Best Practices` - 11 edges
9. `Security Best Practices` - 11 edges
10. `ModifyCode` - 10 edges

## Surprising Connections (you probably didn't know these)
- `Follow Project Naming Conventions` --references--> `User`  [INFERRED]
  .claude/skills/laravel-best-practices/rules/style.md → app/Models/User.php
- `Test Class and Methods` --references--> `TestCase`  [INFERRED]
  .claude/skills/testing-best-practices/rules/naming.md → tests/TestCase.php
- `Names and Structure` --references--> `TestCase`  [INFERRED]
  .claude/skills/testing-best-practices/rules/review.md → tests/TestCase.php
- `Global Fakes` --references--> `TestCase`  [INFERRED]
  .claude/skills/testing-best-practices/rules/performance.md → tests/TestCase.php
- `Laravel Boost` --semantically_similar_to--> `Laravel Boost (CLAUDE.md reference)`  [INFERRED] [semantically similar]
  AGENTS.md → CLAUDE.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Laravel Sail Docker Infrastructure** — compose_yaml_laravel_sail, compose_yaml_mysql, compose_yaml_redis, compose_yaml_meilisearch, compose_yaml_mailpit, compose_yaml_selenium [EXTRACTED 1.00]
- **Laravel Boost Agent Setup Pattern** — agents_md_laravel_boost, claude_md_laravel_boost, readme_laravel_boost_agentic [INFERRED 0.95]

## Communities (84 total, 31 thin omitted)

### Community 0 - "CodeChangeApplier"
Cohesion: 0.06
Nodes (8): CodeFixer, ModifyCode, ReadCode, AstResult, AstValidator, CodeChangeApplier, CodeChangeException, ModifyCodeToolTest

### Community 1 - "composer.json"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 2 - "Cloud CLI"
Cohesion: 0.06
Nodes (30): Adding a cache to an existing environment, Adding a database to an existing environment, Checklists for Multi-Step Operations, Custom domain setup, Full environment setup (app + database + cache + domain), New app from scratch, Application Setup, Billing and Usage (+22 more)

### Community 3 - "Illuminate\Database\Schema\Blueprint"
Cohesion: 0.08
Nodes (13): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}() (+5 more)

### Community 5 - "package.json"
Cohesion: 0.09
Nodes (22): devDependencies, concurrently, fontaine, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, optionalDependencies (+14 more)

### Community 6 - "User"
Cohesion: 0.07
Nodes (8): CreateNewUser, PasswordValidationRules, ResetUserPassword, UpdateUserPassword, UpdateUserProfileInformation, User, UserFactory, VideoFactory

### Community 7 - "Laravel Boost"
Cohesion: 0.22
Nodes (9): Laravel Application Agent Setup Guide, Laravel Boost, PHP and Composer Prerequisites, Laravel Application Claude Setup Guide, Laravel Boost (CLAUDE.md reference), Eloquent ORM, Laravel Boost for Agentic Development, Laravel Framework (+1 more)

### Community 8 - "TestCase"
Cohesion: 0.06
Nodes (12): {closure#1}(), RunCodeAgent, CodeChange, Video, DatabaseSeeder, VideoSeeder, AuthenticationTest, CodeAgentTestCase (+4 more)

### Community 9 - "ast_check.py"
Cohesion: 0.21
Nodes (6): build_parser(), collect_errors(), collect_symbols(), name_of(), walk(), main()

### Community 10 - "Security Best Practices"
Cohesion: 0.06
Nodes (23): CodeChangeController, Controller, Keep Controllers Focused on HTTP Concerns, Organize Controllers Around Resources, Routing and Controller Best Practices, Scope Nested Bindings, Use Implicit Route Model Binding, Use Resource Routes for Resourceful Actions (+15 more)

### Community 11 - "require-dev"
Cohesion: 0.20
Nodes (10): require-dev, fakerphp/faker, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail, mockery/mockery (+2 more)

### Community 12 - "FortifyServiceProvider.php"
Cohesion: 0.09
Nodes (9): AppServiceProvider, {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), FortifyServiceProvider, {closure#1}(), {closure#2}() (+1 more)

### Community 14 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 32 - "Basic Usage Examples"
Cohesion: 0.07
Nodes (28): Agent Configuration, Agents, Audio, Basic Usage Examples, Common Pitfalls, Conversation Context, Conversation Memory, Decision Workflow (+20 more)

### Community 33 - "Livewire Development"
Cohesion: 0.08
Nodes (24): Component-Scoped Interceptors, Intercept Messages, Intercept Requests, Interceptor System (v4), Livewire 4 JavaScript Integration, Magic Properties, Alpine & JavaScript, Basic Usage (+16 more)

### Community 34 - "Detection Checklist"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 35 - "Process"
Cohesion: 0.17
Nodes (11): Edge cases, Glob mapping, Ground Rules (read before you start), Infer Conventions, Process, Step 0: Orient, Step 1: Predefined sweep, Step 2: Open-ended pass (+3 more)

### Community 36 - "testing-best-practices/SKILL.md"
Cohesion: 0.17
Nodes (8): Built-in Laravel Assertion Methods, How to Find Test Framework Features, Security Tests, Consistency First, How to Apply, Rule Index, Testing Best Practices, What to Test

### Community 37 - "Architecture Best Practices"
Cohesion: 0.18
Nodes (11): Architecture Best Practices, Depend on Contracts at Boundaries, Extract Focused Business Operations, Follow Framework Conventions, Inject Required Dependencies, Specify a Deterministic Sort Order, Use Atomic Locks for Race Conditions, Use `Concurrency::run()` for Parallel Execution (+3 more)

### Community 38 - "Tailwind CSS Development"
Cohesion: 0.18
Nodes (10): Basic Usage, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Import Syntax, Replaced Utilities, Spacing (+2 more)

### Community 39 - "Events and Notifications Best Practices"
Cohesion: 0.20
Nodes (9): Cache Event Discovery During Production Deployment, Dispatch Queued Notifications After Commit, Events and Notifications Best Practices, Implement `HasLocalePreference` on Notifiable Models, Queue Slow Notifications, Rely on Event Discovery, Route Notification Channels to Dedicated Queues, Use On-Demand Notifications for Non-User Recipients (+1 more)

### Community 40 - "Migration Best Practices"
Cohesion: 0.20
Nodes (9): Define Foreign-Key Constraints Deliberately, Design Indexes for Real Queries, Generate Migrations with Artisan, Keep Migrations Focused, Make Rollbacks Honest, Migration Best Practices, Mirror Defaults Only When Unsaved Models Need Them, Stage Changes That Affect Existing Rows (+1 more)

### Community 41 - "Fakes, Mocks, and Determinism"
Cohesion: 0.20
Nodes (9): Database, Fakes, Mocks, and Determinism, Framework Fakes, How to Isolate a Dependency, Mocking, Outbound HTTP Testing, Time and Randomness, Global Fakes (+1 more)

### Community 42 - "laravel-best-practices/SKILL.md"
Cohesion: 0.22
Nodes (5): Consistency First, Decision Rules, How to Apply, Laravel Best Practices, Rule Index

### Community 43 - "Advanced Query Best Practices"
Cohesion: 0.22
Nodes (9): Advanced Query Best Practices, Combine Related Counts with Conditional Aggregates, Compare `whereHas()` with an `IN` Subquery, Consider a Correlated Subquery for Has-Many Ordering, Create Dynamic Relationships with a Subquery Foreign Key, Design Composite Indexes for the Query, Measure Two Simple Queries Against One Complex Query, Reuse Loaded Parent Models with `setRelation()` (+1 more)

### Community 44 - "Caching Best Practices"
Cohesion: 0.22
Nodes (8): Caching Best Practices, Configure Failover Cache Stores in Production, Consider `Cache::flexible()` for Stale-While-Revalidate, Use `Cache::add()` for Atomic Conditional Writes, Use `Cache::memo()` to Avoid Redundant Hits Within an Execution, Use `Cache::remember()` for Cache-Aside Reads, Use Cache Tags to Invalidate Related Groups, Use `once()` for In-Process Memoization

### Community 45 - "Database Performance Best Practices"
Cohesion: 0.22
Nodes (8): Add Indexes for Measured Query Patterns, Count Relationships Without Loading Them, Database Performance Best Practices, Eager Load Relationships Before Iterating, Keep Queries Out of Blade Templates, Prevent Lazy Loading in Development, Process Large Data Sets Incrementally, Select Only Needed Columns

### Community 46 - "Eloquent Best Practices"
Cohesion: 0.22
Nodes (8): Apply Global Scopes Sparingly, Cast Date and Time Attributes, Define Attribute Casts, Define Precise Relationship Types, Eloquent Best Practices, Keep Application Queries Model-Aware, Use Local Scopes for Reusable Queries, Use `whereBelongsTo()` for Relationship Queries

### Community 47 - "Queue and Job Best Practices"
Cohesion: 0.22
Nodes (9): Back Off Transient Failures, Batch Jobs for Group Coordination, Configure Time-Based Retry Limits Deliberately, Handle Terminal Failure When Needed, Keep Reservation Time Longer Than Execution Time, Queue and Job Best Practices, Rate Limit External Calls, Use Horizon for Redis Queue Operations (+1 more)

### Community 48 - "scripts"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 49 - "Blade and View Best Practices"
Cohesion: 0.25
Nodes (7): Blade and View Best Practices, Prefer Components for Explicit Interfaces, Return Blade Fragments for Partial Rendering, Share Compatible View Data with a View Composer, Share Parent Component Props with `@aware`, Use `$attributes->merge()` in Component Templates, Use `@pushOnce` for Per-Component Scripts

### Community 50 - "Error Handling Best Practices"
Cohesion: 0.25
Nodes (7): Add Context to Exception Classes, Choose Where to Report and Render Exceptions, Define JSON Rendering for API Routes, Error Handling Best Practices, Mark Exceptions the Handler Should Not Report, Prevent Duplicate Reports of One Exception Instance, Throttle High-Volume Exception Reports

### Community 51 - "Task Scheduling Best Practices"
Cohesion: 0.25
Nodes (7): Bound Work Inside the Task, Group Shared Configuration, Prevent Unwanted Overlap, Restrict Tasks by Environment, Run a Task on One Server, Run Eligible Commands in the Background, Task Scheduling Best Practices

### Community 52 - "Endpoint Tests"
Cohesion: 0.25
Nodes (7): Endpoint Coverage, Endpoint Tests, How to Write the Test, Tenant Isolation, Test Authorization at the Policy Level, Testing Validation, Which Layer Owns Which Case

### Community 53 - "require"
Cohesion: 0.25
Nodes (8): require, laravel/ai, laravel/fortify, laravel/framework, laravel/tinker, livewire/livewire, php, sebastian/diff

### Community 54 - "Collection Best Practices"
Cohesion: 0.29
Nodes (6): Choose Between `cursor()` and `lazy()`, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 55 - "HTTP Client Best Practices"
Cohesion: 0.29
Nodes (6): Fake HTTP Requests in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Pool Independent Requests, Retry Only Safe Operations, Set Explicit Timeouts

### Community 56 - "Mail Best Practices"
Cohesion: 0.29
Nodes (6): Assert the Delivery Mode, Dispatch Queued Mail After Commit, Mail Best Practices, Queue Slow Mail Delivery, Separate Content and Delivery Tests, Use Markdown Mailables When They Fit

### Community 57 - "Convention and Style Best Practices"
Cohesion: 0.29
Nodes (6): Convention and Style Best Practices, Follow Project Naming Conventions, Keep Presentation Code Maintainable, Prefer Clear, Idiomatic Syntax, Use Utilities When They Clarify Intent, Write Comments That Explain Why

### Community 58 - "Validation and Forms Best Practices"
Cohesion: 0.29
Nodes (6): Add Cross-Field Validation After Base Rules, Express Conditional Rules Clearly, Extract Validation When It Improves the Boundary, Prefer Readable Rule Syntax, Use Only Intended Validated Data, Validation and Forms Best Practices

### Community 59 - "Assertions"
Cohesion: 0.29
Nodes (6): Arrange, Act, Assert, Assert a Known Value, Assert the Complete Result, Assertions, How to Find the Correct Assertion, Named Response Assertions

### Community 60 - "Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。"
Cohesion: 0.29
Nodes (6): Graphify の graph.json だけを唯一の保存先にする設計だけでは、出来ない。pgvectorとの併用が必要。, Laravel の業務データと Graphify の生成グラフは役割を分ける, Livewire で作る画面, ダッシュボード設計ダッシュボードは二つ必要です, 動画設計, 必要環境

### Community 61 - "Configuration Best Practices"
Cohesion: 0.33
Nodes (5): Configuration Best Practices, Name Repeated Domain Values, Protect Production Secrets, Read Environment Variables in Configuration Files, Use `App::environment()` for Environment Checks

### Community 62 - "Naming and Structure"
Cohesion: 0.33
Nodes (5): File Layout, Grouping, Naming and Structure, Naming Tests, Test Class and Methods

### Community 63 - "Test Suite Performance"
Cohesion: 0.33
Nodes (5): Common Errors, How to Find a Slow Test, How to Run the Suite in Parallel, Test Environment, Test Suite Performance

### Community 64 - "Reviewing Tests"
Cohesion: 0.33
Nodes (5): Assertions, Coverage, Names and Structure, Reviewing Tests, Test Value

### Community 65 - "Laravel Sail Docker Service"
Cohesion: 0.33
Nodes (6): Laravel Sail Docker Service, Mailpit Email Testing Service, Meilisearch Service, MySQL 8.4 Service, Redis Service, Selenium Chromium Service

### Community 66 - "開発環境"
Cohesion: 0.33
Nodes (5): LiveWire, このプロジェクトに必要なものインストールリスト, 最初にインストールしたもの, 起動後にコンテナ側の拡張を確認するには、プロジェクト内で実行します。, 開発環境

### Community 67 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 68 - "From now on, please write in Japanese and answer in Japanese."
Cohesion: 0.50
Nodes (3): From now on, please write in Japanese and answer in Japanese., 本プロジェクトのコードベース管理, 目的：Youtubeなどの動画を解析、理解し、作成するシステムの構築。

### Community 69 - "purpose.md"
Cohesion: 0.50
Nodes (3): laravel 13で作成するのは、Youtubeの動画ID,タイトル、URLや, 役割分担：graphifyは、プロジェクトのコードベースの管理, 目的：video_managerプロジェクトは、動画データをlarave 13 のpgvectorを使う予定で、（postGreSQL+pgvector)

### Community 70 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 71 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **334 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+329 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 470 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **31 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `CodeChange` connect `TestCase` to `CodeChangeApplier`, `Security Best Practices`, `CodeChangeApplier.php`?**
  _High betweenness centrality (0.102) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `TestCase`, `Convention and Style Best Practices`, `Security Best Practices`?**
  _High betweenness centrality (0.091) - this node is a cross-community bridge._
- **Are the 3 inferred relationships involving `TestCase` (e.g. with `Test Class and Methods` and `Global Fakes`) actually correct?**
  _`TestCase` has 3 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _334 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `CodeChangeApplier` be split into smaller, more focused modules?**
  _Cohesion score 0.058469945355191254 - nodes in this community are weakly interconnected._
- **Should `Cloud CLI` be split into smaller, more focused modules?**
  _Cohesion score 0.0625 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Database\Schema\Blueprint` be split into smaller, more focused modules?**
  _Cohesion score 0.08048780487804878 - nodes in this community are weakly interconnected._