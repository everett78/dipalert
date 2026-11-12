<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collections: a tree of at most two levels (a parent cannot have a parent),
     * products in any number of collections, and product attributes.
     */
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            // Children are removed by DeleteCollectionAction before the parent.
            $table->foreignId('parent_id')->nullable()->constrained('collections')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('weight')->default(0);
            $table->json('template')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['collection_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('attributes')->nullable();
        });
    }
};
