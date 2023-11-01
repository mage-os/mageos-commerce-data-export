<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model\Indexer;

use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Status\ExportStatusCodeProvider;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use \Magento\DataExporter\Export\Processor as ExportProcessor;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedHashBuilder;

/**
 * Base implementation of feed indexing behaviour, does not care about deleted entities
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FeedIndexProcessorCreateUpdate implements FeedIndexProcessorInterface
{
    private const MODIFIED_AT_FORMAT = 'Y-m-d H:i:s';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;
    private ExportProcessor $exportProcessor;
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @var ExportFeedInterface
     */
    private $exportFeedProcessor;
    private FeedUpdater $feedUpdater;
    private FeedHashBuilder $hashBuilder;
    private SerializerInterface $serializer;

    private $feedTablePrimaryKey;

    private DeletedEntitiesProviderInterface $deletedEntitiesProvider;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ExportProcessor $exportProcessor
     * @param ExportFeedInterface $exportFeedProcessor
     * @param FeedUpdater $feedUpdater
     * @param FeedHashBuilder $hashBuilder
     * @param SerializerInterface $serializer
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ExportProcessor $exportProcessor,
        ExportFeedInterface $exportFeedProcessor,
        FeedUpdater $feedUpdater,
        FeedHashBuilder $hashBuilder,
        SerializerInterface $serializer,
        CommerceDataExportLoggerInterface $logger,
        DeletedEntitiesProviderInterface $deletedEntitiesProvider = null
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->exportProcessor = $exportProcessor;
        $this->exportFeedProcessor = $exportFeedProcessor;
        $this->feedUpdater = $feedUpdater;
        $this->hashBuilder = $hashBuilder;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->deletedEntitiesProvider = $deletedEntitiesProvider ??
            ObjectManager::getInstance()->get(DeletedEntitiesProviderInterface::class);
    }

    /**
     * @inerhitDoc
     */
    public function partialReindex(
        FeedIndexMetadata $metadata,
        DataSerializerInterface $serializer,
        EntityIdsProviderInterface $idsProvider,
        array $ids = [],
        callable $callback = null
    ): void {
        $feedIdentity = $metadata->getFeedIdentity();
        $arguments = [];
        foreach ($idsProvider->getAffectedIds($metadata, $ids) as $id) {
            $arguments[] = [$feedIdentity => $id];
        }
        foreach (\array_chunk($arguments, $metadata->getBatchSize()) as $chunk) {
            $metadata->setCurrentModifiedAtTimeInDBFormat((new \DateTime())->format(self::MODIFIED_AT_FORMAT));
            $exportStatus = null;
            if ($metadata->isExportImmediately()) {
                $processedHashes = [];
                $dataProcessorCallback = function ($feedItems) use (
                    $exportStatus,
                    $metadata,
                    $serializer,
                    $chunk,
                    &$processedHashes
                ) {
                    //for backward compatibility:
                    //allows to execute plugins on Process method when callbacks are in place
                    $feedItems = $this->exportProcessor->process($metadata->getFeedName(), $chunk, $feedItems);
                    $feedItems = $this->addHashes($feedItems, $metadata);
                    $data = $this->filterFeedItems($feedItems, $metadata, $processedHashes);

                    if (empty($data)) {
                        return;
                    }
                    $exportStatus = $this->exportFeedProcessor->export(
                        array_column($data, 'feed'),
                        $metadata
                    );
                    $this->feedUpdater->execute($data, $exportStatus, $metadata, $serializer);
                };
                $this->exportProcessor->processWithCallback($metadata, $chunk, $dataProcessorCallback);

                $this->handleDeletedItems(
                    array_column($chunk, $feedIdentity),
                    $processedHashes,
                    $metadata,
                    $serializer
                );
                unset($processedHashes);
            } else {
                $this->feedUpdater->execute(
                    $this->exportProcessor->process($metadata->getFeedName(), $chunk),
                    $exportStatus,
                    $metadata,
                    $serializer
                );
            }
        }
    }

    /**
     * {@inerhitDoc}
     *
     * @param FeedIndexMetadata $metadata
     * @param DataSerializerInterface $serializer
     * @param EntityIdsProviderInterface $idsProvider
     */
    public function fullReindex(
        FeedIndexMetadata $metadata,
        DataSerializerInterface $serializer,
        EntityIdsProviderInterface $idsProvider
    ): void {
        try {
            $this->truncateIndexTable($metadata);
            foreach ($idsProvider->getAllIds($metadata) as $batch) {
                $ids = \array_column($batch, $metadata->getFeedIdentity());
                $this->partialReindex($metadata, $serializer, $idsProvider, $ids);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Data Exporter exception has occurred: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Truncates index table
     *
     * @param FeedIndexMetadata $metadata
     */
    private function truncateIndexTable(FeedIndexMetadata $metadata): void
    {
        if (!$metadata->isTruncateFeedOnFullReindex() || $metadata->isExportImmediately()) {
            return ;
        }
        $connection = $this->resourceConnection->getConnection();
        $feedTable = $this->resourceConnection->getTableName($metadata->getFeedTableName());
        $connection->truncateTable($feedTable);
    }

    /**
     * Remove feed items from further processing if all true:
     * - item hash didn't change
     * - previous export status is non-retryable
     *
     * @param array $feedItems
     * @param FeedIndexMetadata $metadata
     * @param null $processedHashes
     * @return array
     * @throws \Zend_Db_Statement_Exception
     */
    private function filterFeedItems(array $feedItems, FeedIndexMetadata $metadata, &$processedHashes = null) : array
    {
        if (empty($feedItems)) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $primaryKeyFields = $this->getFeedTablePrimaryKey($metadata);
        $primaryKeys = \array_keys($feedItems);
        $primaryKeys = count($primaryKeyFields) == 1
            ? \implode(',', $primaryKeys)
            : '(' . \implode('),(', $primaryKeys) . ')';

        $select = $connection->select()
            ->from(
                ['f' => $this->resourceConnection->getTableName($metadata->getFeedTableName())],
                array_merge($primaryKeyFields, ['feed_hash', 'status'])
            )->where(sprintf('(%s) IN (%s)', \implode(', ', $primaryKeyFields), $primaryKeys));

        $cursor = $connection->query($select);

        while ($row = $cursor->fetch()) {
            $identifier = $this->hashBuilder->buildIdentifierFromFeedTableRow($row, $metadata);
            $feedHash = $row['feed_hash'];

            if (\in_array((int)$row['status'], ExportStatusCodeProvider::NON_RETRYABLE_HTTP_STATUS_CODE, true)
                && isset($feedItems[$identifier]['hash'])
                && $feedHash == $feedItems[$identifier]['hash']) {
                unset($feedItems[$identifier]);
                if ($processedHashes !== null) {
                    $processedHashes[$feedHash] = true;
                }
            }
        }
        return $feedItems;
    }

    /**
     * Add hashes
     *
     * @param array $data
     * @param FeedIndexMetadata $metadata
     * @param bool $deleted
     * @return array
     */
    private function addHashes(array $data, FeedIndexMetadata $metadata, bool $deleted = false): array
    {
        foreach ($data as $key => $row) {
            if ($deleted) {
                if (!isset($row[FeedIndexMetadata::FEED_TABLE_FIELD_FEED_HASH])) {
                    $this->logger->error("Feed hash is not set for the product id: ". $row['productId']);
                    unset($data[$key]);
                    continue ;
                }
                $identifier = $this->hashBuilder->buildIdentifierFromFeedTableRow($row, $metadata);
                $row = $this->serializer->unserialize($row['feed_data']);
                $row['deleted'] = true;
            } else {
                if (!\array_key_exists('deleted', $row)) {
                    $row['deleted'] = false;
                }
                $identifier = $this->hashBuilder->buildIdentifierFromFeedItem($row, $metadata);
            }
            unset($data[$key]);
            if (empty($identifier)) {
                $this->logger->error(
                    'Identifier for feed item is empty. Skip sync for entity',
                    [
                        'feed' => $metadata->getFeedName(),
                        'item' => var_export($row, true)
                    ]
                );
                continue;
            }
            $hash = $this->hashBuilder->buildHash($row, $metadata);
            $this->addModifiedAtField($row, $metadata);
            $data[$identifier] = [
                'hash' => $hash,
                'feed' => $row,
                'deleted' => $deleted
            ];
        }
        return $data;
    }

    /**
     * Get feed table primary key
     *
     * @param FeedIndexMetadata $metadata
     * @return array
     */
    private function getFeedTablePrimaryKey(FeedIndexMetadata $metadata): array
    {
        if (!isset($this->feedTablePrimaryKey[$metadata->getFeedName()])) {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName($metadata->getFeedTableName());
            $indexList = $connection->getIndexList($table);
            $this->feedTablePrimaryKey[$metadata->getFeedName()] = $indexList[
                $connection->getPrimaryKeyName($table)
            ]['COLUMNS_LIST'];
        }
        return $this->feedTablePrimaryKey[$metadata->getFeedName()];
    }

    /**
     * Add modified at field to each row
     *
     * @param array $dataRow
     * @param FeedIndexMetadata $metadata
     * @return void
     */
    private function addModifiedAtField(&$dataRow, FeedIndexMetadata $metadata): void
    {
        $dataRow['modifiedAt'] = $metadata->getCurrentModifiedAtTimeInDBFormat();
    }

    /**
     * Algorithm to mark feed items deleted:
     * - select all feed items for <$ids> where modifiedAt < "currentModifiedAt" - e.g. product
     * - remove hashes that were already processed
     * - mark entity as "deleted"
     *
     * @param array $ids
     * @param array $processedHashes
     * @param FeedIndexMetadata $metadata
     * @param DataSerializerInterface $serializer
     * @throws \Zend_Db_Statement_Exception
     */
    public function handleDeletedItems(
        array                   $ids,
        array                   $processedHashes,
        FeedIndexMetadata       $metadata,
        DataSerializerInterface $serializer
    ): void {
        foreach ($this->deletedEntitiesProvider->get($ids, $processedHashes, $metadata) as $feedItems) {
            $feedItems = $this->addHashes($feedItems, $metadata, true);
            $data = $this->filterFeedItems($feedItems, $metadata);

            if (empty($data)) {
                continue;
            }
            $exportStatus = $this->exportFeedProcessor->export(
                array_column($data, 'feed'),
                $metadata
            );
            $this->feedUpdater->execute($data, $exportStatus, $metadata, $serializer);
        }
    }
}
