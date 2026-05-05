<?php

declare(strict_types=1);

namespace Tests\Unit;

use Anibalealvarezs\KlaviyoHubDriver\Drivers\KlaviyoDriver;
use PHPUnit\Framework\TestCase;

final class KlaviyoDriverCanonicalMetricDictionaryTest extends TestCase
{
    public function testExposesCanonicalMetricDictionary(): void
    {
        $dictionary = KlaviyoDriver::getCanonicalMetricDictionary();

        $this->assertArrayHasKey('conversions', $dictionary);
        $this->assertArrayHasKey('conversion_rate', $dictionary);
        $this->assertArrayHasKey('roas_purchase', $dictionary);
        $this->assertContains('conversion', $dictionary['conversions']);
    }
}

