<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notify when the price of a link changes by at least this much between two
     * reads: a percent (5.00 = 5%), an amount, and a direction (down, up, both;
     * null works as down).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('notify_change_percent', 5, 2)->nullable();
            $table->decimal('notify_change_amount', 10, 2)->nullable();
            $table->string('notify_change_direction')->nullable();
        });
    }
};
