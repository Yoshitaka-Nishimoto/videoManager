"""tree-sitter で PHP ソースを解析し、構文エラーとシンボル一覧を JSON で返す。

stdin:  {"source": "<?php ...", "mode": "php" | "php_only"}
stdout: {"ok": bool, "errors": [{line, column, kind, snippet}], "symbols": ["Class", "Class::method", "function"]}

Blade は PHP 側でコンパイルしてから mode=php で渡す（HTML 混在の PHP として解析）。
"""

import json
import sys

import tree_sitter_php
from tree_sitter import Language, Parser

MAX_ERRORS = 20


def build_parser(mode: str) -> Parser:
    grammar = tree_sitter_php.language_php_only() if mode == "php_only" else tree_sitter_php.language_php()
    return Parser(Language(grammar))


def collect_errors(root, source: bytes) -> list[dict]:
    errors = []
    stack = [root]
    while stack and len(errors) < MAX_ERRORS:
        node = stack.pop()
        if node.is_error or node.is_missing:
            line = source.splitlines()[node.start_point[0]] if source else b""
            errors.append({
                "line": node.start_point[0] + 1,
                "column": node.start_point[1] + 1,
                "kind": f"missing {node.type}" if node.is_missing else "syntax error",
                "snippet": line.decode("utf-8", "replace").strip()[:200],
            })
            # ERROR ノードの内側を掘ると同じ箇所が重複して出るので子は見ない
            continue
        if node.has_error:
            stack.extend(reversed(node.children))
    return errors


def collect_symbols(root) -> list[str]:
    symbols = []

    def name_of(node):
        child = node.child_by_field_name("name")
        return child.text.decode("utf-8") if child else "?"

    def walk(node, owner):
        for child in node.children:
            if child.type in ("class_declaration", "interface_declaration", "trait_declaration", "enum_declaration"):
                name = name_of(child)
                symbols.append(name)
                walk(child, name)
            elif child.type == "method_declaration":
                symbols.append(f"{owner}::{name_of(child)}")
            elif child.type == "function_definition":
                symbols.append(name_of(child))
            else:
                walk(child, owner)

    walk(root, None)
    return sorted(set(symbols))


def main() -> None:
    payload = json.load(sys.stdin)
    source = payload["source"].encode("utf-8")
    tree = build_parser(payload.get("mode", "php")).parse(source)

    errors = collect_errors(tree.root_node, source) if tree.root_node.has_error else []
    json.dump({
        "ok": not errors,
        "errors": errors,
        "symbols": collect_symbols(tree.root_node),
    }, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
