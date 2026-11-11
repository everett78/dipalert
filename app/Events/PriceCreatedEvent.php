<?php

namespace App\Events;

use App\Models\Price;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PriceCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Price $price) {}
}
