# Graph Report - video_manager  (2026-09-28)

## Corpus Check
- Corpus is ~12,760 words - fits in a single context window. You may not need a graph.

## Summary
- 314 nodes · 400 edges · 32 communities (15 shown, 17 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 8 edges (avg confidence: 0.84)
- Token cost: 2,800 input · 950 output

## Community Hubs (Navigation)
- AI CodeFixer Agent
- Composer Dependencies
- Code Agent Services
- Database Migrations
- Console Commands
- Frontend Build Config
- User Model & Auth
- Agent Setup Docs
- Test Infrastructure
- Python AST Tools
- Admin Code Controller
- Dev Dependencies
- App Bootstrap
- CodeChange Applier Tests
- Composer Plugins Config
- Service Provider
- Logging Config
- Artisan Console Routes
- Unit Tests
- CLI Bootstrap
- Robots Policy

## God Nodes (most connected - your core abstractions)
1. `CodeChangeApplier` - 15 edges
2. `CodeChange` - 13 edges
3. `CodeFixer` - 11 edges
4. `ModifyCode` - 10 edges
5. `ReadCode` - 10 edges
6. `require-dev` - 10 edges
7. `CodeChangeApplierTest` - 10 edges
8. `User` - 9 edges
9. `scripts` - 9 edges
10. `AstResult` - 8 edges

## Surprising Connections (you probably didn't know these)
- `Laravel Boost` --semantically_similar_to--> `Laravel Boost (CLAUDE.md reference)`  [INFERRED] [semantically similar]
  AGENTS.md → CLAUDE.md
- `Laravel Boost` --semantically_similar_to--> `Laravel Boost for Agentic Development`  [INFERRED] [semantically similar]
  AGENTS.md → README.md
- `PHP and Composer Prerequisites` --semantically_similar_to--> `PHP CLI and Composer Installation Notes`  [INFERRED] [semantically similar]
  AGENTS.md → publicDocs/install.md
- `Eloquent ORM` --conceptually_related_to--> `PostgreSQL Database`  [INFERRED]
  README.md → publicDocs/purpose.md
- `Video Manager Project Purpose` --conceptually_related_to--> `MySQL 8.4 Service`  [INFERRED]
  publicDocs/purpose.md → compose.yaml

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Laravel Boost Agent Setup Pattern** — agents_md_laravel_boost, claude_md_laravel_boost, readme_laravel_boost_agentic [INFERRED 0.95]
- **Laravel Sail Docker Infrastructure** — compose_yaml_laravel_sail, compose_yaml_mysql, compose_yaml_redis, compose_yaml_meilisearch, compose_yaml_mailpit, compose_yaml_selenium [EXTRACTED 1.00]
- **Video Manager Data Layer (PostgreSQL + pgvector)** — publicdocs_purpose_video_manager_purpose, publicdocs_purpose_postgresql, publicdocs_purpose_pgvector [EXTRACTED 1.00]

## Communities (32 total, 17 thin omitted)

### Community 0 - "AI CodeFixer Agent"
Cohesion: 0.10
Nodes (15): CodeFixer, ModifyCode, ReadCode, Illuminate\Contracts\JsonSchema\JsonSchema, Laravel\Ai\Attributes\MaxSteps, Laravel\Ai\Attributes\Provider, Laravel\Ai\Attributes\Timeout, Laravel\Ai\Contracts\Agent (+7 more)

### Community 1 - "Composer Dependencies"
Cohesion: 0.06
Nodes (34): autoload, autoload-dev, psr-4, psr-4, description, extra, laravel, keywords (+26 more)

### Community 2 - "Code Agent Services"
Cohesion: 0.12
Nodes (9): AstResult, AstValidator, CodeChangeApplier, CodeChangeException, Illuminate\Support\Facades\Blade, Illuminate\Support\Facades\Process, PHPUnit\Framework\Attributes\DataProvider, RuntimeException (+1 more)

### Community 3 - "Database Migrations"
Cohesion: 0.14
Nodes (12): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}() (+4 more)

### Community 4 - "Console Commands"
Cohesion: 0.10
Nodes (12): {closure#1}(), RunCodeAgent, CodeChange, Illuminate\Console\Attributes\Description, Illuminate\Console\Attributes\Signature, Illuminate\Console\Command, Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Model (+4 more)

### Community 5 - "Frontend Build Config"
Cohesion: 0.10
Nodes (20): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, optionalDependencies, @laravel/multiplex (+12 more)

### Community 6 - "User Model & Auth"
Cohesion: 0.14
Nodes (12): User, UserFactory, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Seeder (+4 more)

### Community 7 - "Agent Setup Docs"
Cohesion: 0.11
Nodes (19): Laravel Application Agent Setup Guide, Laravel Boost, PHP and Composer Prerequisites, Laravel Application Claude Setup Guide, Laravel Boost (CLAUDE.md reference), Laravel Sail Docker Service, Mailpit Email Testing Service, Meilisearch Service (+11 more)

### Community 8 - "Test Infrastructure"
Cohesion: 0.18
Nodes (7): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\File, CodeAgentTestCase, CodeChangeHistoryPageTest, ExampleTest, TestCase

### Community 9 - "Python AST Tools"
Cohesion: 0.21
Nodes (12): json, Parser, sys, build_parser(), collect_errors(), collect_symbols(), name_of(), walk() (+4 more)

### Community 10 - "Admin Code Controller"
Cohesion: 0.24
Nodes (4): CodeChangeController, Controller, Illuminate\Contracts\View\View, Illuminate\Support\Facades\Route

### Community 11 - "Dev Dependencies"
Cohesion: 0.20
Nodes (10): require-dev, fakerphp/faker, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail, mockery/mockery (+2 more)

### Community 12 - "App Bootstrap"
Cohesion: 0.33
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request

### Community 14 - "Composer Plugins Config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 16 - "Logging Config"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

## Knowledge Gaps
- **62 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+57 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 150 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **17 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `CodeChange` connect `Console Commands` to `Test Infrastructure`, `Admin Code Controller`, `Code Agent Services`?**
  _High betweenness centrality (0.078) - this node is a cross-community bridge._
- **Why does `CodeChangeApplier` connect `Code Agent Services` to `AI CodeFixer Agent`, `Console Commands`?**
  _High betweenness centrality (0.060) - this node is a cross-community bridge._
- **Why does `CodeChangeApplierTest` connect `CodeChange Applier Tests` to `Test Infrastructure`, `Code Agent Services`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _62 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AI CodeFixer Agent` be split into smaller, more focused modules?**
  _Cohesion score 0.09682539682539683 - nodes in this community are weakly interconnected._
- **Should `Composer Dependencies` be split into smaller, more focused modules?**
  _Cohesion score 0.05714285714285714 - nodes in this community are weakly interconnected._
- **Should `Code Agent Services` be split into smaller, more focused modules?**
  _Cohesion score 0.12 - nodes in this community are weakly interconnected._