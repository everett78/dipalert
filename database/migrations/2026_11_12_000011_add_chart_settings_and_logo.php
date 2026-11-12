<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chart settings kept per product ({stores: list<int>, period: 7|30|90|365|"all"})
     * and the store logo (a path on the public disk).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('chart_settings')->nullable();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
    }
};
