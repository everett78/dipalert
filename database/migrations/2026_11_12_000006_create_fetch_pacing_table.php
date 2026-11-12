<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pacing state per store host (last request, pause after a block). It lives
     * in the database, not the cache, because the cache is cleared on every
     * container start.
     */
    public function up(): void
    {
        Schema::create('fetch_pacing', function (Blueprint $table) {
            $table->id();
            $table->string('host')->unique();
            $table->timestamp('last_fetch_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->unsignedTinyInteger('pause_level')->default(0);
            $table->timestamps();
        });
    }
};
