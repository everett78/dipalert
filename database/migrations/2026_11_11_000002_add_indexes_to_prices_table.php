<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * prices.url_id was created with foreignIdFor() and has no index, so every
     * "previous price" lookup scans the whole table. Price history is kept
     * forever (about 1.6 million rows a year), so add the indexes now.
     */
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->index(['url_id', 'id'], 'prices_url_id_id_index');
            $table->index(['url_id', 'created_at'], 'prices_url_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropIndex('prices_url_id_id_index');
            $table->dropIndex('prices_url_id_created_at_index');
        });
    }
};
