<?php

namespace Tests\Unit\Services;

use App\Dto\ComparisonOfferDto;
use App\Services\Comparison\CeneoOfferListParser;
use Tests\TestCase;

class CeneoOfferListParserTest extends TestCase
{
    /**
     * @return array<string, ComparisonOfferDto>
     */
    protected function parse(string $sample): array
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/Comparison/ceneo-'.$sample.'.html'));

        return collect((new CeneoOfferListParser)->parse($html))->keyBy('shopKey')->all();
    }

    public function test_number_of_stores_and_domains_from_the_samples(): void
    {
        $this->assertCount(10, $this->parse('19'));
        $this->assertCount(13, $this->parse('20'));
        $this->assertCount(12, $this->parse('21'));

        $this->assertEqualsCanonicalizing(
            ['allegro.pl', 'buy-it.pl', 'ctistore.pl', 'empik.com', 'krsystem.pl', 'morele.net', 'techlord.pl', 'vist.eu', 'zadowolenie.pl', 'zizako.pl'],
            array_keys($this->parse('19')),
        );
    }

    public function test_promoted_offers_of_other_products_are_ignored(): void
    {
        // Sample 19 promotes an x-kom.pl offer for another product (471,60 zł).
        $offers = $this->parse('19');

        $this->assertArrayNotHasKey('x-kom.pl', $offers);
        $this->assertSame(2450.0, $offers['krsystem.pl']->price);
    }

    public function test_prices_and_names(): void
    {
        $offers = $this->parse('20');

        $this->assertSame(1699.0, $offers['mediamarkt.pl']->price);
        $this->assertSame(1799.99, $offers['mediaexpert.pl']->price);
        $this->assertSame('mediamarkt.pl', $offers['mediamarkt.pl']->shopName);
    }

    public function test_one_offer_per_store_with_the_lowest_price(): void
    {
        $html = '<ul>'
            .'<li class="product-offers__list__item" data-shopurl="www.Shop.PL" data-price="120,5000"></li>'
            .'<li class="product-offers__list__item" data-shopurl="shop.pl" data-price="99.90"></li>'
            .'<li class="product-offers__list__item" data-shopurl="other.pl" data-price="1699,0000"></li>'
            .'<li class="product-offers__list__item" data-shopurl="" data-price="10"></li>'
            .'</ul>';

        $offers = collect((new CeneoOfferListParser)->parse($html))->keyBy('shopKey');

        $this->assertCount(2, $offers);
        $this->assertSame(99.9, $offers['shop.pl']->price);
        $this->assertSame(1699.0, $offers['other.pl']->price);
    }

    public function test_price_formats(): void
    {
        $this->assertSame(1699.0, CeneoOfferListParser::parsePrice('1699,0000'));
        $this->assertSame(2401.99, CeneoOfferListParser::parsePrice('2401.99'));
        $this->assertNull(CeneoOfferListParser::parsePrice('n/a'));
    }
}
