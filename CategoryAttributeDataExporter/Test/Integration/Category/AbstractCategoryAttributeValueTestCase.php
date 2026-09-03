<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Integration\Category;

use Magento\DataExporter\Model\FeedInterface;
use Magento\DataExporter\Model\FeedPool;
use Magento\DataExporter\Test\Integration\DisablesFeedReadinessCheckers;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Indexer\Cron\UpdateMview;
use Magento\Indexer\Model\Indexer;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Shared scaffolding for tests that verify custom category attribute *values* surfaced through
 * the categories feed's "attributes" field (as opposed to AbstractCategoryAttributeExportTest,
 * which covers the categoryAttributes *metadata* feed). Handles indexer wiring, feed retrieval,
 * the mview cron, and locating a category's feed row / reading an attribute's value(s) from it.
 *
 * findCategoryEntry() and getAttributeValues() are declared here as regular protected methods
 * with concrete implementations, not abstract - this class' shape is the feed's canonical one.
 * Subclasses that consume a differently-shaped representation of this same feed may override
 * them.
 */
abstract class AbstractCategoryAttributeValueTestCase extends \PHPUnit\Framework\TestCase
{
    use DisablesFeedReadinessCheckers;

    private const CATEGORY_FEED_INDEXER = 'catalog_data_exporter_categories';
    private const CATEGORY_FEED_TABLE = 'cde_categories_feed';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @var Indexer
     */
    private Indexer $indexer;

    /**
     * @var FeedInterface
     */
    private FeedInterface $categoryFeed;

    /**
     * @var UpdateMview
     */
    protected UpdateMview $mViewCron;

    protected function setUp(): void
    {
        self::disableFeedReadinessCheckers();
        $objectManager = Bootstrap::getObjectManager();

        $this->resource = $objectManager->create(ResourceConnection::class);
        $this->connection = $this->resource->getConnection();
        $this->indexer = $objectManager->create(Indexer::class);
        $this->mViewCron = $objectManager->create(UpdateMview::class);

        $objectManager->configure([
            'Magento\CatalogDataExporter\Model\Indexer\CategoryFeedIndexMetadata' => [
                'arguments' => [
                    'persistExportedFeed' => true
                ]
            ]
        ]);
        $this->categoryFeed = $objectManager->get(FeedPool::class)->getFeed('categories');

        $this->indexer->load(self::CATEGORY_FEED_INDEXER);
        $this->indexer->reindexAll();
    }

    /**
     * Locate the feed row for a fixture category in a given store view.
     *
     * @param int $fixtureCategoryId
     * @param string $storeViewCode
     * @return array empty array if no matching row exists
     */
    protected function findCategoryEntry(int $fixtureCategoryId, string $storeViewCode): array
    {
        foreach ($this->getAllCategoryEntries() as $item) {
            if (isset($item['categoryId'])
                && $item['categoryId'] == $fixtureCategoryId
                && ($item['storeViewCode'] ?? null) === $storeViewCode
            ) {
                return $item;
            }
        }
        return [];
    }

    /**
     * Extract an attribute's value(s) from a category feed row.
     *
     * @param array $categoryEntry
     * @param string $attributeCode
     * @return string[]|null null if the attribute is absent from this category entry
     */
    protected function getAttributeValues(array $categoryEntry, string $attributeCode): ?array
    {
        foreach ($categoryEntry['attributes'] ?? [] as $entry) {
            if (($entry['attributeCode'] ?? null) === $attributeCode) {
                return $entry['value'] ?? [];
            }
        }
        return null;
    }

    /**
     * All rows currently in the categories feed.
     *
     * @return array
     */
    protected function getAllCategoryEntries(): array
    {
        return $this->categoryFeed->getFeedSince('1')['feed'];
    }

    /**
     * Drain the mview changelog to trigger a partial reindex of affected categories.
     *
     * @return void
     */
    protected function runMviewCron(): void
    {
        $this->mViewCron->execute();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $feedTable = $this->connection->getTableName(self::CATEGORY_FEED_TABLE);
        $this->connection->truncateTable($feedTable);
    }
}
