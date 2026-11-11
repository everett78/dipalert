<?php

use App\Enums\Statuses;
use App\Models\User;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title', 1024);
            $table->string('image', 1024)->nullable();
            $table->char('status', 1)->default(Statuses::Published->value);
            $table->float('notify_price')->nullable();
            $table->float('notify_percent')->nullable();
            $table->boolean('favourite')->default(true);
            $table->boolean('only_official')->default(false);
            $table->json('price_cache')->nullable();
            $table->foreignIdFor(User::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
