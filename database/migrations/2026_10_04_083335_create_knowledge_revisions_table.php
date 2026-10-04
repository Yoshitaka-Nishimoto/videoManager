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
        Schema::create('knowledge_revisions', function (Blueprint $table) {
            $table->comment('知識の変更履歴');

            $table->id()->comment('履歴ID');
            $table->string('revisable_type')->comment('変更された対象の種類');
            $table->unsignedBigInteger('revisable_id')->comment('変更された対象のID');
            $table->string('action', 16)->comment('操作（create / update / confirm / deprecate / merge）');
            $table->json('before')->nullable()->comment('変更前');
            $table->json('after')->nullable()->comment('変更後');
            $table->text('reason')->nullable()->comment('変更理由');
            $table->foreignId('changed_by')->nullable()->comment('変更者（AIの場合は空）')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('変更日時');

            $table->index(['revisable_type', 'revisable_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_revisions');
    }
};
