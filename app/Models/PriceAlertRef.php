<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The reference price for change alerts of one visible line of a product
 * (store:<id>, or url:<id> when a product has several direct links to one store).
 *
 * @property int $id
 * @property int $product_id
 * @property string $ref_key
 * @property int $url_id
 * @property ?int $price_id
 * @property string $price
 * @property Carbon $updated_at
 */
class PriceAlertRef extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'product_id',
        'ref_key',
        'url_id',
        'price_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
