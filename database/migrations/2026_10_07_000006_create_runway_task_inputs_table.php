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
        Schema::create('runway_task_inputs', function (Blueprint $table) {
            $table->comment('Runwayに渡した素材');

            $table->id()->comment('入力ID');
            $table->foreignId('runway_task_id')->comment('依頼')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->comment('役割（prompt_image / first_frame / last_frame / reference_image / video / audio）');
            $table->unsignedTinyInteger('position')->default(0)->comment('同じ役割の中の順番');
            $table->string('media_type', 16)->comment('種類（image / video / audio）');
            $table->string('storage_path', 1024)->nullable()->comment('手元の保存先パス');
            $table->foreignId('source_output_id')->nullable()->index()->comment('元にした生成物')->constrained('runway_task_outputs')->restrictOnDelete();
            $table->foreignId('production_asset_id')->nullable()->index()->comment('元にした素材')->constrained()->restrictOnDelete();
            $table->string('runway_uri', 2048)->nullable()->comment('Runwayに渡したURI');
            $table->timestamp('runway_uri_expires_at')->nullable()->comment('URIの有効期限');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['runway_task_id', 'role', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runway_task_inputs');
    }
};
