<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DipAlert removes the SearXNG product search, so its tables go too.
     * Migrations only go forward; restore a pre-migration dump to undo.
     */
    public function up(): void
    {
        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('url_research');
    }

    public function down(): void
    {
        // Not restorable: the search feature no longer exists.
    }
};
