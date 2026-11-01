# Graph Report - video_manager  (2026-11-01)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 122 nodes · 162 edges · 3 communities (0 shown, 3 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 28,918 input · 265 output

## Graph Freshness
- Built from commit: `b882e9ce`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## God Nodes (most connected - your core abstractions)

## Surprising Connections (you probably didn't know these)
- `"public"."knowledge_edges"` --references--> `"public"."knowledge_nodes"`  [EXTRACTED]
  postgresql:/127.0.0.1/laravel → postgresql:/127.0.0.1/laravel  _Bridges community 1 → community 2_

## Import Cycles
- None detected.

## Communities (3 total, 3 thin omitted)

## Knowledge Gaps
- **3 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Should `Laravel基盤とベクトル関数` be split into smaller, more focused modules?**
  _Cohesion score 0.019417475728155338 - nodes in this community are weakly interconnected._