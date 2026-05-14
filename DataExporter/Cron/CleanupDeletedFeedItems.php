<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Cron;

use Magento\DataExporter\Model\FeedMetadataPool;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Removes successfully-synced deleted feed items older than the retention period.
 */
class CleanupDeletedFeedItems
{
    private const RETENTION_DAYS = 7;

    /**
     * @param FeedMetadataPool $feedMetadataPool
     * @param ResourceConnection $resourceConnection
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        private readonly FeedMetadataPool $feedMetadataPool,
        private readonly ResourceConnection $resourceConnection,
        private readonly CommerceDataExportLoggerInterface $logger,
    ) {
    }

    /**
     * Delete successfully-synced deleted items older than the retention period from all immediate-export feed tables.
     */
    public function execute(): void
    {
        $threshold = date('Y-m-d H:i:s', strtotime(sprintf('-%d days', self::RETENTION_DAYS)));
        $connection = $this->resourceConnection->getConnection();

        foreach ($this->feedMetadataPool->getAll() as $metadata) {
            if (!$metadata->isExportImmediately()) {
                continue;
            }

            try {
                $deletedN = $connection->delete(
                    $this->resourceConnection->getTableName($metadata->getFeedTableName()),
                    [
                        'is_deleted = ?' => 1,
                        'status = ?' => 200,
                        'modified_at < ?' => $threshold,
                    ]
                );
                if ($deletedN <= 0) {
                    continue;
                }
                $this->logger->info(
                    sprintf(
                        'Cleaned up "%s" deleted feed items for feed "%s"',
                        $deletedN,
                        $metadata->getFeedName(),
                    )
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf(
                        'CDE04-21 Failed to clean up deleted feed items for feed "%s". Error: %s',
                        $metadata->getFeedName(),
                        $e->getMessage()
                    ),
                    ['feedName' => $metadata->getFeedName(), 'error' => $e->getMessage()]
                );
            }
        }
    }
}
