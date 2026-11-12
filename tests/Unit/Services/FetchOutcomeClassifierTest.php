<?php

namespace Tests\Unit\Services;

use App\Enums\FetchOutcome;
use App\Services\Scrapers\FetchOutcomeClassifier;
use Tests\TestCase;

class FetchOutcomeClassifierTest extends TestCase
{
    public function test_datadome_page_is_a_block(): void
    {
        $body = '<html><script src="https://ct.captcha-delivery.com/c.js"></script></html>';

        $this->assertTrue(FetchOutcomeClassifier::isBlocked(200, $body));
        $this->assertSame(FetchOutcome::Blocked, FetchOutcomeClassifier::classify(200, $body, [], false, false));
    }

    public function test_cloudflare_503_with_marker_is_a_block(): void
    {
        $body = '<html><head><title>Just a moment...</title></head></html>';

        $this->assertSame(FetchOutcome::Blocked, FetchOutcomeClassifier::classify(503, $body, [], false, false));
    }

    public function test_amazon_captcha_is_a_block(): void
    {
        $body = '<form action="/errors/validateCaptcha" method="get"></form>';

        $this->assertTrue(FetchOutcomeClassifier::isBlocked(200, $body));
    }

    public function test_403_and_429_are_blocks(): void
    {
        $this->assertTrue(FetchOutcomeClassifier::isBlocked(403, ''));
        $this->assertTrue(FetchOutcomeClassifier::isBlocked(429, 'slow down'));
    }

    public function test_503_without_marker_is_a_network_error(): void
    {
        $this->assertFalse(FetchOutcomeClassifier::isBlocked(503, 'Service Unavailable'));
        $this->assertSame(FetchOutcome::NetworkError, FetchOutcomeClassifier::classify(503, 'Service Unavailable', [], false, false));
    }

    public function test_ceneo_page_with_captcha_word_in_script_is_not_a_block(): void
    {
        $body = '<html><script>var config = {"captchaEnabled": false, "recaptcha": "x"};</script>'
            .'<script type="application/ld+json">{"@type":"Product","offers":{"lowPrice":1699}}</script></html>';

        $this->assertFalse(FetchOutcomeClassifier::isBlocked(200, $body));
        $this->assertSame(FetchOutcome::Ok, FetchOutcomeClassifier::classify(200, $body, [], true, false));
    }

    public function test_unavailable_product_without_price_is_ok(): void
    {
        $this->assertSame(FetchOutcome::Ok, FetchOutcomeClassifier::classify(200, '<html></html>', [], false, true));
    }

    public function test_missing_price_is_no_price(): void
    {
        $this->assertSame(FetchOutcome::NoPrice, FetchOutcomeClassifier::classify(200, '<html></html>', [], false, false));
    }

    public function test_error_keys(): void
    {
        $error = ['message' => 'x', 'code' => 0];

        $this->assertSame(FetchOutcome::UrlRefused, FetchOutcomeClassifier::classify(null, '', ['url_refused' => $error], false, false));
        $this->assertSame(FetchOutcome::Skipped, FetchOutcomeClassifier::classify(null, '', ['skipped' => $error], false, false));
        $this->assertSame(FetchOutcome::Skipped, FetchOutcomeClassifier::classify(null, '', ['busy' => $error], false, false));
        $this->assertSame(FetchOutcome::NetworkError, FetchOutcomeClassifier::classify(null, '', ['network' => $error], false, false));
    }

    public function test_refused_address_wins_over_a_block(): void
    {
        $this->assertSame(
            FetchOutcome::UrlRefused,
            FetchOutcomeClassifier::classify(403, '', ['url_refused' => ['message' => 'x']], false, false),
        );
    }
}
