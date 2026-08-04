<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model\Indexer;

use Magento\DataExporter\Lock\FeedLockManager;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Product export feed indexer class
 * Facade for IndexerProcessor, implements Magento native indexers interfaces
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FeedIndexer implements IndexerActionInterface, MviewActionInterface, FeedIndexMetadataProviderInterface
{
    /**
     * @var FeedIndexProcessorCreateUpdate
     */
    private $processor;

    /**
     * @var FeedIndexMetadata
     */
    protected $feedIndexMetadata;

    /**
     * @var DataSerializerInterface
     */
    protected $dataSerializer;

    /**
     * @var EntityIdsProviderInterface
     */
    private $entityIdsProvider;

    /**
     * @var CommerceDataExportLoggerInterface
     */
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @var FeedLockManager|null
     */
    private ?FeedLockManager $lockManager;

    /**
     * @var FeedReadinessCheckerPool
     */
    private FeedReadinessCheckerPool $readinessCheckerPool;

    /**
     * @param FeedIndexProcessorInterface $processor
     * @param DataSerializerInterface $serializer
     * @param FeedIndexMetadata $feedIndexMetadata
     * @param EntityIdsProviderInterface $entityIdsProvider
     * @param CommerceDataExportLoggerInterface|null $logger
     * @param FeedLockManager|null $lockManager
     * @param FeedReadinessCheckerPool|null $readinessCheckerPool
     */
    public function __construct(
        FeedIndexProcessorInterface $processor,
        DataSerializerInterface $serializer,
        FeedIndexMetadata $feedIndexMetadata,
        EntityIdsProviderInterface $entityIdsProvider,
        ?CommerceDataExportLoggerInterface $logger = null,
        ?FeedLockManager $lockManager = null,
        ?FeedReadinessCheckerPool $readinessCheckerPool = null
    ) {
        $this->processor = $processor;
        $this->feedIndexMetadata = $feedIndexMetadata;
        $this->dataSerializer = $serializer;
        $this->entityIdsProvider = $entityIdsProvider;
        $this->logger = $logger ??
            ObjectManager::getInstance()->get(CommerceDataExportLoggerInterface::class);
        $this->lockManager = $lockManager ?? ObjectManager::getInstance()->get(FeedLockManager::class);
        $this->readinessCheckerPool = $readinessCheckerPool
            ?? ObjectManager::getInstance()->get(FeedReadinessCheckerPool::class);
    }

    /**
     * Execute full indexation
     *
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function executeFull()
    {
        if (!$this->isReady()) {
            $this->logNotReady('full reindex');
            return;
        }

        $operation = $this->feedIndexMetadata->isExportImmediately() ? 'full sync' : 'full reindex(legacy)';
        $this->logger->initSyncLog($this->feedIndexMetadata, $operation);

        $unlock = true;
        $feedName = $this->feedIndexMetadata->getFeedName();
        if (!$this->lockManager->lock($feedName, $operation)) {
            $lockedBy = $this->lockManager->getLockedByName($feedName);
            // CLI command may initialize full resync, in this case ignore lock and let parent caller to unlock process
            if ($lockedBy === $this->getResyncLockedByName()) {
                $unlock = false;
            } else {
                $this->logger->info(sprintf('operation skipped - process locked by "%s"', $lockedBy));
                // feed marked as "invalid" in "indexer_state" table will be marked as "valid"
                // it's done intentionally since current full reindex process should handle it.
                // If needed to keep feed as "invalid" exception should be thrown here.
                return ;
            }
        }

        try {
            $this->processor->fullReindex(
                $this->feedIndexMetadata,
                $this->dataSerializer,
                $this->entityIdsProvider
            );
        } finally {
            if ($unlock) {
                $this->lockManager->unlock($feedName);
            }
            $this->logger->complete();
        }
    }

    /**
     * Build the "locked by" name used to tag the resync lock with the current PID.
     *
     * @return string
     * @see \Magento\DataExporter\Lock\FeedLockManager::lock for name patter
     */
    private function getResyncLockedByName(): string
    {
        // pid used to guarantee caller and current are the same processes
        return sprintf('resync(%s)', getmypid());
    }

    /**
     * Execute partial indexation by ID list
     *
     * @param int[] $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        if (!$this->isReady()) {
            $this->logNotReady('partial reindex');
            return;
        }

        $this->logWarningIfFeedIsNotLocked();
        $this->processor->partialReindex(
            $this->feedIndexMetadata,
            $this->dataSerializer,
            $this->entityIdsProvider,
            $ids
        );
        // track iteration completion
        $this->logger->logProgress();
    }

    /**
     * Execute partial indexation by ID
     *
     * @param int $id
     * @return void
     */
    public function executeRow($id)
    {
        if (!$this->isReady()) {
            $this->logNotReady('partial reindex');
            return;
        }

        $this->logWarningIfFeedIsNotLocked();
        $this->processor->partialReindex(
            $this->feedIndexMetadata,
            $this->dataSerializer,
            $this->entityIdsProvider,
            [$id]
        );
    }

    /**
     * Execute materialization on ids entities
     *
     * @param int[] $ids
     * @return void
     * @api
     */
    public function execute($ids)
    {
        if (!$this->isReady()) {
            $this->logNotReady('partial reindex');
            return;
        }

        $this->logWarningIfFeedIsNotLocked();
        $this->processor->partialReindex(
            $this->feedIndexMetadata,
            $this->dataSerializer,
            $this->entityIdsProvider,
            $ids
        );
        // track iteration completion
        $this->logger->logProgress();
    }

    /**
     * @inheritDoc
     */
    public function getFeedIndexMetadata(): FeedIndexMetadata
    {
        return $this->feedIndexMetadata;
    }

    /**
     * Returns false when any readiness checker for this feed reports not ready.
     *
     * @return bool
     */
    private function isReady(): bool
    {
        $checkers = $this->readinessCheckerPool->getCheckersForFeed($this->feedIndexMetadata->getFeedName());
        foreach ($checkers as $checker) {
            if (!$checker->isReady()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Log that indexation is being skipped because the feed is not ready to process data.
     *
     * @param string $operation
     * @return void
     */
    private function logNotReady(string $operation): void
    {
        $this->logger->info(
            sprintf(
                'Feed "%s" is not ready: connector configuration is missing or invalid. Skipping %s.',
                $this->feedIndexMetadata->getFeedName(),
                $operation
            )
        );
    }

    /**
     * Log a warning when the feed is not locked before a reindex call.
     *
     * @return void
     */
    private function logWarningIfFeedIsNotLocked()
    {
        if (!$this->lockManager->isLocked($this->feedIndexMetadata->getFeedName())) {
            $this->logger->warning(
                sprintf(
                    'CDE04-20 Unexpected call: feed "%s" is not locked, trace: %s',
                    $this->feedIndexMetadata->getFeedName(),
                    (new \Exception())->getTraceAsString()
                )
            );
        }
    }
}
