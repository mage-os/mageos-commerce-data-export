<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CustomizableOptionsDataExporter\Test\Unit\Plugin;

use Magento\CustomizableOptionsDataExporter\Model\Provider\CustomizableOptionsProvider;
use Magento\CustomizableOptionsDataExporter\Plugin\AddCustomizableOptionsToProductFeed;
use Magento\DataExporter\Export\Processor;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the products-feed enrichment plugin (feed filtering, attribute shape, skip behavior).
 */
class AddCustomizableOptionsToProductFeedTest extends TestCase
{
    /** @var CustomizableOptionsProvider&MockObject */
    private $provider;

    /** @var SerializerInterface&MockObject */
    private $serializer;

    /** @var Processor&MockObject */
    private $processor;

    private AddCustomizableOptionsToProductFeed $plugin;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(CustomizableOptionsProvider::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->processor = $this->createMock(Processor::class);
        $this->plugin = new AddCustomizableOptionsToProductFeed($this->provider, $this->serializer);
    }

    public function testNonProductsFeedIsReturnedUnchanged(): void
    {
        $this->provider->expects($this->never())->method('execute');

        $feed = [['productId' => '1', 'storeViewCode' => 'default']];
        self::assertSame($feed, $this->plugin->afterProcess($this->processor, $feed, 'categories'));
    }

    public function testAppendsSerializedAttributeOnlyForItemsWithOptions(): void
    {
        $feed = [
            ['productId' => '1', 'storeViewCode' => 'default'],
            ['productId' => '2', 'storeViewCode' => 'default'],
        ];
        $this->provider->method('execute')->willReturn([
            '1-default' => ['selectable' => [['id' => 10]]],
        ]);
        $this->serializer->method('serialize')->willReturnCallback(
            static fn ($data): string => (string)json_encode($data)
        );

        $result = $this->plugin->afterProcess($this->processor, $feed, 'products');

        // Product 1 has options -> attribute appended with schemaVersion + selectable payload.
        self::assertArrayHasKey('attributes', $result[0]);
        self::assertSame('ac_customizable_options', $result[0]['attributes'][0]['attributeCode']);
        $decoded = json_decode($result[0]['attributes'][0]['value'][0], true);
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertSame([['id' => 10]], $decoded['selectable']);

        // Product 2 has no options -> untouched.
        self::assertArrayNotHasKey('attributes', $result[1]);
    }
}
