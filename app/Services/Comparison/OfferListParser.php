<?php

namespace App\Services\Comparison;

use App\Dto\ComparisonOfferDto;

interface OfferListParser
{
    /**
     * Offers on the page, one per store (the lowest price of that store).
     *
     * @return list<ComparisonOfferDto>
     */
    public function parse(string $html): array;
}
