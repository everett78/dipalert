<?php

namespace App\Dto;

/**
 * One store's offer on a comparison site's offer list.
 */
final class ComparisonOfferDto
{
    public function __construct(
        public readonly string $shopKey,
        public readonly string $shopName,
        public readonly float $price,
        public readonly ?string $availability = null,
    ) {}
}
