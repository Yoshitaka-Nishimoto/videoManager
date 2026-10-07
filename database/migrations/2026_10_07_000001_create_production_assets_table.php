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
        Schema::create('production_assets', function (Blueprint $table) {
            $table->comment('制作の素材（人が用意したもの）');

            $table->id()->comment('素材ID');
            $table->foreignId('video_production_id')->index()->comment('制作')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->comment('種類（mock / image / video / audio）');
            $table->string('title')->nullable()->comment('名前・説明');
            $table->string('storage_path', 1024)->comment('保存先パス');
            $table->string('original_name')->nullable()->comment('アップロード時のファイル名');
            $table->string('mime_type', 64)->nullable()->comment('MIMEタイプ');
            $table->foreignId('uploaded_by')->nullable()->comment('アップロードした人')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_assets');
    }
};
