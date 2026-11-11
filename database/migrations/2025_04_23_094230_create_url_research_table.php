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
        Schema::create('url_research', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->longText('html')->nullable();
            $table->string('title', 2048)->nullable();
            $table->string('image', 2048)->nullable();
            $table->float('price')->nullable();
            $table->integer('store_id')->nullable();
            $table->json('strategies')->nullable();
            $table->float('execution_time')->nullable()->comment('Duration in seconds');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('url_research');
    }
};
