<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 仕組みを作る（plotdesign.md）。目標から、前提条件・不足情報・判断点を確かめながら仕組みを設計する。
 *
 * 仕組み（mechanisms）─< 周（mechanism_rounds）─┬─< 前提条件・不足情報・判断点（mechanism_findings）
 *                                              └─< 使った根拠（mechanism_sources）
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mechanisms', function (Blueprint $table) {
            $table->comment('仕組み（目標から設計する仕組み）');

            $table->id()->comment('仕組みID');
            $table->string('title')->comment('仕組みの名前');
            $table->text('goal')->comment('利用者が書いた目標・質問');
            $table->string('status', 16)->default('active')->index()->comment('状態（active / completed / abandoned）');
            $table->unsignedTinyInteger('current_round')->default(1)->comment('今の周');
            $table->string('agent_conversation_id', 36)->nullable()->index()->comment('SDK の会話（agent_conversations.id）');
            $table->foreignId('decision_node_id')->nullable()->comment('完了時に作った設計判断ノード')->constrained('knowledge_nodes')->nullOnDelete();
            $table->foreignId('owner_id')->comment('作った人（本人だけが見られる）')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });

        Schema::create('mechanism_rounds', function (Blueprint $table) {
            $table->comment('仕組みのループの周');

            $table->id()->comment('周ID');
            $table->foreignId('mechanism_id')->comment('仕組み')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number')->comment('何周目か');
            $table->string('phase', 16)->default('understand')->comment('段階（understand / search / premise / confirm / build / report / done）');
            $table->string('phase_status', 16)->default('queued')->comment('段階の状態（queued / running / waiting / failed / done）');
            $table->text('instruction')->nullable()->comment('「まだ回す」のときの追加の指示');
            $table->jsonb('understanding')->nullable()->comment('① 目標の理解の結果');
            $table->jsonb('search')->nullable()->comment('② 知識検索の結果');
            $table->jsonb('design')->nullable()->comment('⑤ 仕組みの設計');
            $table->jsonb('program')->nullable()->comment('⑤ プログラムの案');
            $table->jsonb('report')->nullable()->comment('⑥ 結果報告');
            $table->boolean('continue')->nullable()->comment('「まだ回すか」の答え');
            $table->text('error')->nullable()->comment('失敗の内容');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['mechanism_id', 'number']);
        });

        Schema::create('mechanism_sources', function (Blueprint $table) {
            $table->comment('仕組みに使った根拠（知識・Web）');

            $table->id()->comment('根拠ID');
            $table->foreignId('mechanism_round_id')->comment('周')->constrained()->cascadeOnDelete();
            $table->string('source_type', 16)->comment('種類（knowledge / web）');
            $table->foreignId('knowledge_node_id')->nullable()->comment('知識ノード')->constrained()->nullOnDelete();
            $table->text('url')->nullable()->comment('Web のページ');
            $table->string('title')->nullable()->comment('ページ名・ノード名');
            $table->text('excerpt')->nullable()->comment('使った要点');
            $table->text('reason')->nullable()->comment('使った理由（AI の説明）');
            $table->foreignId('video_id')->nullable()->comment('「情報源に登録」した後の情報源')->constrained()->nullOnDelete();
            $table->timestamp('fetched_at')->nullable()->comment('Web を取得した日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });

        Schema::create('mechanism_findings', function (Blueprint $table) {
            $table->comment('仕組みの前提条件・不足情報・判断点');

            $table->id()->comment('項目ID');
            $table->foreignId('mechanism_round_id')->comment('周')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('種類（premise / missing / decision）');
            $table->text('content')->comment('内容');
            $table->string('basis', 16)->nullable()->comment('前提条件の根拠の種類（knowledge / web / user）');
            $table->foreignId('knowledge_node_id')->nullable()->comment('根拠の知識ノード')->constrained()->nullOnDelete();
            $table->foreignId('mechanism_source_id')->nullable()->comment('根拠の Web')->constrained()->nullOnDelete();
            $table->text('question')->nullable()->comment('不足情報の質問');
            $table->jsonb('candidates')->nullable()->comment('候補 2〜3 個（label・detail・pros・cons）');
            $table->unsignedTinyInteger('recommended')->nullable()->comment('勧める候補の番号');
            $table->text('answer')->nullable()->comment('利用者の回答・選んだ候補');
            $table->boolean('tentative')->default(false)->comment('「分からない」で勧める候補を仮に採用したか');
            $table->timestamp('answered_at')->nullable()->comment('回答日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mechanism_findings');
        Schema::dropIfExists('mechanism_sources');
        Schema::dropIfExists('mechanism_rounds');
        Schema::dropIfExists('mechanisms');
    }
};
