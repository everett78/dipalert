<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comparison offer lists: one link to a Ceneo product gives a child link
     * (a price line) for every store on its offer list. Plus the reference
     * price for change alerts per visible line.
     */
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->foreignId('parent_url_id')->nullable()->constrained('urls')->cascadeOnDelete();
            $table->string('comparison_key')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['parent_url_id', 'comparison_key']);
        });

        Schema::create('price_alert_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('ref_key');
            $table->foreignId('url_id')->constrained('urls')->cascadeOnDelete();
            $table->unsignedBigInteger('price_id')->nullable();
            $table->decimal('price', 12, 2);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['product_id', 'ref_key']);
        });

        // Existing Ceneo stores become comparison stores (always plain http).
        DB::table('stores')->orderBy('id')->get(['id', 'domains', 'settings'])->each(function ($store): void {
            $domains = collect(json_decode((string) $store->domains, true) ?: [])
                ->map(fn ($domain) => is_array($domain) ? ($domain['domain'] ?? '') : (string) $domain)
                ->map(fn (string $domain) => strtolower(preg_replace('/^www\./', '', $domain)));

            if (! $domains->contains('ceneo.pl')) {
                return;
            }

            $settings = json_decode((string) $store->settings, true) ?: [];
            $settings['comparison'] = 'ceneo';
            $settings['scraper_service'] = 'http';

            DB::table('stores')->where('id', $store->id)->update(['settings' => json_encode($settings)]);
        });
    }
};
