<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\DataExporter\Test\Integration\Unit\Model\Indexer;

use Magento\DataExporter\Lock\FeedLockManager;
use Magento\DataExporter\Model\Indexer\DataSerializerInterface;
use Magento\DataExporter\Model\Indexer\EntityIdsProviderInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexer;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Model\Indexer\FeedIndexProcessorInterface;
use Magento\DataExporter\Model\Indexer\FeedReadinessCheckerInterface;
use Magento\DataExporter\Model\Indexer\FeedReadinessCheckerPool;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies FeedIndexer skips processing when a registered readiness checker reports "not ready",
 * and preserves existing behavior otherwise.
 */
class FeedIndexerReadinessTest extends TestCase
{
    private FeedIndexProcessorInterface&MockObject $processor;
    private DataSerializerInterface&MockObject $serializer;
    private FeedIndexMetadata&MockObject $metadata;
    private EntityIdsProviderInterface&MockObject $idsProvider;
    private CommerceDataExportLoggerInterface&MockObject $logger;
    private FeedLockManager&MockObject $lockManager;
    private FeedReadinessCheckerPool&MockObject $readinessCheckerPool;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(FeedIndexProcessorInterface::class);
        $this->serializer = $this->createMock(DataSerializerInterface::class);
        $this->metadata = $this->createMock(FeedIndexMetadata::class);
        $this->metadata->method('getFeedName')->willReturn('products');
        $this->idsProvider = $this->createMock(EntityIdsProviderInterface::class);
        $this->logger = $this->createMock(CommerceDataExportLoggerInterface::class);
        $this->lockManager = $this->createMock(FeedLockManager::class);
        $this->readinessCheckerPool = $this->createMock(FeedReadinessCheckerPool::class);
    }

    private function createIndexer(): FeedIndexer
    {
        return new FeedIndexer(
            $this->processor,
            $this->serializer,
            $this->metadata,
            $this->idsProvider,
            $this->logger,
            $this->lockManager,
            $this->readinessCheckerPool
        );
    }

    private function makeNotReadyChecker(): FeedReadinessCheckerInterface
    {
        $checker = $this->createMock(FeedReadinessCheckerInterface::class);
        $checker->method('isReady')->willReturn(false);
        return $checker;
    }

    public function testExecuteFullSkipsProcessingWhenNotReady(): void
    {
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([$this->makeNotReadyChecker()]);

        $this->processor->expects($this->never())->method('fullReindex');
        $this->lockManager->expects($this->never())->method('lock');
        $this->logger->expects($this->once())->method('info');

        $this->createIndexer()->executeFull();
    }

    public function testExecuteListSkipsProcessingWhenNotReady(): void
    {
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([$this->makeNotReadyChecker()]);

        $this->processor->expects($this->never())->method('partialReindex');
        $this->logger->expects($this->once())->method('info');

        $this->createIndexer()->executeList([1, 2]);
    }

    public function testExecuteRowSkipsProcessingWhenNotReady(): void
    {
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([$this->makeNotReadyChecker()]);

        $this->processor->expects($this->never())->method('partialReindex');
        $this->logger->expects($this->once())->method('info');

        $this->createIndexer()->executeRow(1);
    }

    public function testExecuteSkipsProcessingWhenNotReady(): void
    {
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([$this->makeNotReadyChecker()]);

        $this->processor->expects($this->never())->method('partialReindex');
        $this->logger->expects($this->once())->method('info');

        $this->createIndexer()->execute([1, 2]);
    }

    public function testExecuteListProcessesNormallyWhenNoCheckerRegistered(): void
    {
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([]);
        $this->lockManager->method('isLocked')->willReturn(true);

        $this->processor->expects($this->once())->method('partialReindex');
        $this->logger->expects($this->never())->method('info');

        $this->createIndexer()->executeList([1, 2]);
    }

    public function testExecuteListProcessesNormallyWhenReady(): void
    {
        $readyChecker = $this->createMock(FeedReadinessCheckerInterface::class);
        $readyChecker->method('isReady')->willReturn(true);
        $this->readinessCheckerPool->method('getCheckersForFeed')
            ->with('products')
            ->willReturn([$readyChecker]);
        $this->lockManager->method('isLocked')->willReturn(true);

        $this->processor->expects($this->once())->method('partialReindex');

        $this->createIndexer()->executeList([1, 2]);
    }
}
