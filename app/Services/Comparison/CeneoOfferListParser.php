<?php

namespace App\Services\Comparison;

use App\Dto\ComparisonOfferDto;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Reads the offer list of a Ceneo product page. The store key is the domain
 * from data-shopurl; redirect links of the offers are never opened. The
 * selectors live in config/dipalert.php (comparison.ceneo), so a change of
 * the Ceneo layout is fixed there.
 */
class CeneoOfferListParser implements OfferListParser
{
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $config = (array) config('dipalert.comparison.ceneo', []);
        $selector = (string) ($config['offer_selector'] ?? 'li.product-offers__list__item[data-shopurl]');
        $domainAttribute = (string) ($config['domain_attribute'] ?? 'data-shopurl');
        $priceAttribute = (string) ($config['price_attribute'] ?? 'data-price');
        $nameSelector = (string) ($config['name_selector'] ?? '.product-offer__logo img');
        $nameAttribute = (string) ($config['name_attribute'] ?? 'alt');

        /** @var array<string, ComparisonOfferDto> $offers */
        $offers = [];

        try {
            (new Crawler($html))->filter($selector)->each(function (Crawler $node) use (&$offers, $domainAttribute, $priceAttribute, $nameSelector, $nameAttribute): void {
                $domain = self::normaliseDomain((string) $node->attr($domainAttribute));
                $price = self::parsePrice((string) $node->attr($priceAttribute));

                if ($domain === '' || $price === null || $price <= 0) {
                    return;
                }

                $name = '';
                $nameNode = $node->filter($nameSelector);

                if ($nameNode->count() > 0) {
                    $name = trim((string) $nameNode->first()->attr($nameAttribute));
                }

                if (isset($offers[$domain]) && $offers[$domain]->price <= $price) {
                    return;
                }

                $offers[$domain] = new ComparisonOfferDto(
                    shopKey: $domain,
                    shopName: $name !== '' ? $name : $domain,
                    price: $price,
                );
            });
        } catch (Throwable $e) {
            logger()->warning('Could not read the Ceneo offer list: '.$e->getMessage());

            return [];
        }

        return array_values($offers);
    }

    /**
     * "1699,0000" or "2401.99" → 1699.0 / 2401.99.
     */
    public static function parsePrice(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    public static function normaliseDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
