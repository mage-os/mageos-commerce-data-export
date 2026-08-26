<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CustomizableOptionsDataExporter\Test\Unit\Model\Provider;

use Magento\CatalogDataExporter\Model\Provider\Product\CustomizableOptions\ProductShopperInputOptions;
use Magento\CatalogDataExporter\Model\Provider\Product\ProductOptions\SelectableOptions;
use Magento\CustomizableOptionsDataExporter\Model\Provider\CustomizableOptionsProvider;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the customizable-options assembly logic (keying, grouping, failure handling).
 */
class CustomizableOptionsProviderTest extends TestCase
{
    /** @var SelectableOptions&MockObject */
    private $selectableOptions;

    /** @var ProductShopperInputOptions&MockObject */
    private $shopperInputOptions;

    /** @var CommerceDataExportLoggerInterface&MockObject */
    private $logger;

    private CustomizableOptionsProvider $provider;

    protected function setUp(): void
    {
        $this->selectableOptions = $this->createMock(SelectableOptions::class);
        $this->shopperInputOptions = $this->createMock(ProductShopperInputOptions::class);
        $this->logger = $this->createMock(CommerceDataExportLoggerInterface::class);
        $this->provider = new CustomizableOptionsProvider(
            $this->selectableOptions,
            $this->shopperInputOptions,
            $this->logger
        );
    }

    public function testMergesSelectableAndShopperInputByProductAndStore(): void
    {
        $this->selectableOptions->method('get')->willReturn([
            '1default10' => [
                'productId' => '1',
                'storeViewCode' => 'default',
                'optionsV2' => ['id' => 10, 'label' => 'Council'],
            ],
        ]);
        $this->shopperInputOptions->method('get')->willReturn([
            '1default20' => [
                'productId' => '1',
                'storeViewCode' => 'default',
                'shopperInputOptions' => ['id' => 'uid', 'label' => 'Name'],
            ],
        ]);

        $result = $this->provider->execute([['productId' => '1', 'storeViewCode' => 'default']]);

        self::assertArrayHasKey('1-default', $result);
        self::assertSame([['id' => 10, 'label' => 'Council']], $result['1-default']['selectable']);
        self::assertSame([['id' => 'uid', 'label' => 'Name']], $result['1-default']['shopperInput']);
    }

    public function testGroupsMultipleOptionsAndSeparatesProducts(): void
    {
        $this->selectableOptions->method('get')->willReturn([
            'a' => ['productId' => '1', 'storeViewCode' => 'default', 'optionsV2' => ['id' => 10]],
            'b' => ['productId' => '1', 'storeViewCode' => 'default', 'optionsV2' => ['id' => 11]],
            'c' => ['productId' => '2', 'storeViewCode' => 'default', 'optionsV2' => ['id' => 12]],
        ]);
        $this->shopperInputOptions->method('get')->willReturn([]);

        $result = $this->provider->execute([]);

        self::assertCount(2, $result['1-default']['selectable']);
        self::assertCount(1, $result['2-default']['selectable']);
        // A product with only selectable options has no shopperInput bucket.
        self::assertArrayNotHasKey('shopperInput', $result['1-default']);
    }

    public function testReturnsEmptyAndLogsOnFailure(): void
    {
        $this->selectableOptions->method('get')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('ac_customizable_options'), $this->anything());

        self::assertSame([], $this->provider->execute([['productId' => '1', 'storeViewCode' => 'default']]));
    }
}
