win > wsl -d Ubuntu-26.04
Ubuntu > sail up -d
Ubuntu > sail artisan dev
## ----------------------------------
pg_dump
pgsql pg_dump -U sail -d laravel -Fc > xxx.dump
. 戻す
pgsql pg_restore -U sail -d laravel --clean --if-exists  < xxx.dump 

sail exec -T pgsql pg_restore -U sail -d laravel --clean --if-exists < storage/app/backups/laravel_20261108_164458.dump

## ----------------------------------
> sail artisan tinker で、ベクトル検索のやり方。
$user     = App\Models\User::find(4);
$embedder = app(App\Services\Knowledge\KnowledgeEmbedder::class);
$ctx      = app(App\Services\Knowledge\KnowledgeChatContext::class);

 ### ----
$question = 'goal について';   // ← 調べたい質問
$query = $embedder->similarTo($embedder->vectorFor($question), 0.7);
$query->visibleTo($user->id)->
    where('node_type', App\Models\KnowledgeNode::TYPE_CONCEPT)->
    inGenre('ai')->
    reorder()->orderByDesc('similarity')->
    limit(40)->
    get()->
    map(fn ($n) => [
        'id'         => $n->id,
        'status'     => $n->status,
        'similarity' => round($n->similarity, 3),
        'title'      => $n->title,
    ])->
    all();

## -----------------------------
Bash Check knowledge data and list page search code
IN
wsl.exe -d Ubuntu-26.04 -- bash -s <<'EOF' 2>&1 | grep -v WWW
cd ~/video_manager
docker compose exec -T pgsql psql -U sail -d laravel -c "select node_type, status, count(*), round(avg(length(coalesce(description,'')))) as avg_desc from knowledge_nodes group by 1,2 order by 1,2"
docker compose exec -T pgsql psql -U sail -d laravel -c "select title, left(description,80) from knowledge_nodes where node_type='concept' and status='confirmed' limit 5"
docker compose exec -T pgsql psql -U sail -d laravel -c "select count(*) as analyses, round(avg(length(coalesce(summary,'')))) as avg_summary from video_analyses"
docker compose exec -T pgsql psql -U sail -d laravel -c "\d knowledge_sources" | head -30
EOF
sed -n 60,140p "//wsl.localhost/Ubuntu-26.04/home/user/video_manager/resources/views/pages/knowledge/⚡index.blade.php"

OUT
   node_type    |  status   | count | avg_desc 
----------------+-----------+-------+----------
 analysis       | confirmed |    76 |      248
 ## -------------------
 アプリの中での扱い
アプリのコードで graphify の graph.json を読んでいるのは、コードのグラフ（graphify-out/graph.json）だけです。

使っている場所	何に使っているか
ExportKnowledgeGraph.php と KnowledgeGraphExporter.php	コードのグラフに、知識（概念 → 設計判断 → 実装）と、実装とコードの対応を加えて書き出します
CodeLocator.php:199	実装のノードをコードにつなぐときに、graph.json のノード ID を補います
スキーマのグラフ（graphify-out/postgres/）を読むコードはありません。
## ------------------------
変更した内容
方針の文書（実装より先に書きました）

design.md の「検索」に、次のことを書き足しました。
埋め込みだけでは見落とす理由（測った数値つき）
4つの一覧を順位で合わせる方法（RRF）
何をどう並べるか
knowledge_tables.md の「意味で検索」の説明を、新しい動きに合わせて直しました。
コード

KnowledgeSearch.php（新規）：次の4つの一覧を、それぞれの順位で合わせて並べます。

名称に一致
説明に一致
出典に一致（出典の抜き書き、出典の動画のタイトル・要約。関係の出典も含みます）
意味が近い（類似度0.6以上）
キーワードで一致したものは、類似度にかかわらず出ます。知識への質問にも使えるように、画面から分けて作りました。

KnowledgeEmbedder.php：類似度の列を加える withSimilarity() を足しました。similarTo() もこれを使うようにしました。

知識一覧の画面：

各ノードの下に「見つかった理由：名称に一致、出典に一致」のように表示します。
埋め込みを作れなかったときは、キーワードの一致だけで探します。前は名称の一致だけでした。
テスト

KnowledgeEmbeddingTest.php にテストを1つ足し、既存の2つを新しい動きに合わせて直しました。足したテストでは、次のことを確かめています。
名称・説明・出典（関係の出典も含む）の一致で見つかること
2つの一覧で見つかったものが、先に並ぶこと
他の人の個人の知識は出ないこと
意味だけが近いもの（下限未満）は出ないこと
テストは全体で256件通りました。16件はスキップです。設定のキャッシュがないことを確かめてから実行したので、開発用のデータベースは消えていません（ノード349件のまま）。
コミットに入れたのは、今回変更した次の5ファイルだけです。

KnowledgeEmbedder.php：概念の埋め込みの元の文に、出典の動画のタイトルを加えました。
KnowledgeSource.php：出典が増えたり消えたりしたら、その概念の埋め込みを作り直すようにしました。
design.md、knowledge_tables.md：方針と、作り直して測った結果です。
KnowledgeEmbeddingTest.php：追加したテストです。