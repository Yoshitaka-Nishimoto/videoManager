<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 誤った関係を書き換えずに、正しい関係へ置き換えたことを記録する（knowledge_tables.md）。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_edges', function (Blueprint $table) {
            $table->foreignId('replaced_by_id')->nullable()->after('relation_type_id')->comment('この関係を置き換えた正しい関係')->constrained('knowledge_edges')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_edges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
        });
    }
};
