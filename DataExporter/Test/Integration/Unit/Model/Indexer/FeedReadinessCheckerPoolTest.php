<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\DataExporter\Test\Integration\Unit\Model\Indexer;

use Magento\DataExporter\Model\Indexer\FeedReadinessCheckerInterface;
use Magento\DataExporter\Model\Indexer\FeedReadinessCheckerPool;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeedReadinessCheckerPoolTest extends TestCase
{
    private ObjectManagerInterface&MockObject $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
    }

    public function testReturnsEmptyArrayWhenNoCheckerRegisteredForFeed(): void
    {
        $pool = new FeedReadinessCheckerPool($this->objectManager, []);

        $this->assertSame([], $pool->getCheckersForFeed('products'));
    }

    public function testReturnsRegisteredCheckerForFeed(): void
    {
        $checker = $this->createMock(FeedReadinessCheckerInterface::class);
        $this->objectManager->method('get')
            ->with('SomeChecker')
            ->willReturn($checker);

        $pool = new FeedReadinessCheckerPool($this->objectManager, [
            'products' => ['aco' => 'SomeChecker'],
        ]);

        $this->assertSame([$checker], $pool->getCheckersForFeed('products'));
    }

    public function testThrowsWhenRegisteredCheckerDoesNotImplementInterface(): void
    {
        $this->objectManager->method('get')
            ->with('NotAChecker')
            ->willReturn(new \stdClass());

        $pool = new FeedReadinessCheckerPool($this->objectManager, [
            'products' => ['aco' => 'NotAChecker'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $pool->getCheckersForFeed('products');
    }
}
