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
        Schema::create('code_changes', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('language', 16);
            $table->string('status', 16)->index();
            $table->text('reason');
            $table->longText('diff');
            $table->json('ast_errors')->nullable();
            $table->json('symbols_added')->nullable();
            $table->json('symbols_removed')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_changes');
    }
};
