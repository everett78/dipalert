<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every fetch of a link, successful or not (about 450 rows a day, pruned
     * after 30 days), and the last outcome on the link itself.
     */
    public function up(): void
    {
        Schema::create('fetch_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('url_id')->constrained('urls')->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('outcome', 32);
            $table->string('source', 16);
            $table->string('batch_id')->nullable();
            $table->string('method', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('final_url')->nullable();
            $table->text('message')->nullable();
            $table->string('page_path')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['outcome', 'created_at']);
            $table->index(['url_id', 'created_at']);
            $table->index('batch_id');
        });

        Schema::table('urls', function (Blueprint $table) {
            $table->string('last_outcome', 32)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
        });
    }
};
