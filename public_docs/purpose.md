# From now on, please write in Japanese and answer in Japanese.
## 目的：Youtubeなどの動画を解析、理解し、作成するシステムの構築。
（postGreSQL+pgvector) によろ構造を表現する。
 | 目的 | Runway APIで選ぶ方法 | 添付動画との関係 |
 |---|---|---|
 | 写真から顔の向き・表情が変わる短い動画 | **Image to Video**。写真と「少し顔を傾けて微笑む」などの動作指示を渡す | 見た目の動きに近い。狙った角度や同一人物らしさは試作して確認が必要 |
 | 写真の人物に日本語の挨拶を話させる | **Avatarを作成 → Avatar Videoを文章または音声から生成** | 台詞と口の動きを重視する場合に適した経路 |
 | 生成動画に字幕やBGMを加える | 生成したMP4を**Remotion**で編集 | これまでのRemotionの作業をそのまま生かせる |

## 必要なテーブル
分類	テーブル	内容
利用者	users	ログインする人
動画	videos	YouTube ID、タイトル、URLなど。youtube_video_id は一意にする
分析	video_analyses	対象動画、要約、分析内容、使用モデル、分析日時
知識	knowledge_nodes	動画・分析・概念などのノード
関係	knowledge_edges	ノード間の向きと関係の種類
出典	knowledge_sources	ノードやエッジの根拠となった動画、分析、会話メッセージ
AI 会話	agent_conversations、agent_conversation_messages	Laravel AI SDK による会話履歴
非同期処理	jobs など	Queue をデータベースで運用する場合



## 本プロジェクトのコードベース管理
Graphify+tree-siterによる、コードベース管理、
