<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->comment('AI使用量');

            $table->id()->comment('ログID');
            $table->string('provider', 32)->comment('提供元（anthropic / openai / gemini / dummy など）');
            $table->string('model')->comment('モデル');
            $table->string('feature', 64)->index()->comment('機能（analysis / chat など）');
            $table->string('usable_type')->nullable()->comment('呼び出し元の種類');
            $table->unsignedBigInteger('usable_id')->nullable()->comment('呼び出し元のID');
            $table->unsignedInteger('input_tokens')->default(0)->comment('入力トークン数');
            $table->unsignedInteger('output_tokens')->default(0)->comment('出力トークン数');
            $table->decimal('estimated_cost', 12, 6)->nullable()->comment('推定費用（米ドル）');
            $table->boolean('succeeded')->comment('成功したか');
            $table->text('error_message')->nullable()->comment('エラー内容');
            $table->timestamp('created_at')->useCurrent()->index()->comment('実行日時');

            $table->index(['usable_type', 'usable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
