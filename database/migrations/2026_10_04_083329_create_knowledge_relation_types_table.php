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
        Schema::create('knowledge_relation_types', function (Blueprint $table) {
            $table->comment('関係の種類');

            $table->id()->comment('関係種類ID');
            $table->string('key', 64)->unique()->comment('関係キー');
            $table->string('label')->comment('表示名');
            $table->string('inverse_label')->comment('逆方向の表示名');
            $table->text('description')->nullable()->comment('説明');
            $table->boolean('allow_cycle')->default(false)->comment('循環を許可するか');
            $table->json('allowed_source_types')->nullable()->comment('接続元として許可するノード種類');
            $table->json('allowed_target_types')->nullable()->comment('接続先として許可するノード種類');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_relation_types');
    }
};
