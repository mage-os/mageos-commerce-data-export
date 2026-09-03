<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CategoryAttributeDataExporter\Test\Integration\Category;

use Magento\CategoryAttributeDataExporter\Test\Fixture\CategoryColorAttribute as CategoryColorAttributeFixture;
use Magento\DataExporter\Test\Integration\DisablesFeedReadinessCheckers;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Indexer\Model\Indexer;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Verifies that the category attribute metadata feed ("categoryAttributes") only exposes
 * fields consumed by the CCDM CategoryAttributeMetadata proto (attributeCode, storeCode,
 * websiteCode, storeViewCode, dataType, label, modifiedAt).
 *
 * Guards against reintroducing MDEE-1373-style over-exposure of product-only fields
 * (filterable/searchable/searchWeight/etc) that were removed from
 * CategoryAttributeDataExporter/etc/et_schema.xml's CategoryAttributeMetadata record.
 *
 * extractAttributeCode(), extractStoreViewCode() and assertEntryShape() are regular protected
 * methods with concrete implementations, not abstract - this class' shape is the feed's
 * canonical one. Subclasses that consume a differently-shaped representation of this same feed
 * may override them.
 *
 * @magentoAppArea adminhtml
 */
class CategoryAttributeExportTest extends \PHPUnit\Framework\TestCase
{
    use DisablesFeedReadinessCheckers;

    private const CATEGORY_ATTRIBUTES_FEED_INDEXER = 'catalog_data_exporter_category_attributes';
    private const CATEGORY_ATTRIBUTES_FEED_TABLE = 'cde_category_attributes_feed';

    /**
     * Fields that exist on ProductAttributeMetadata but must never appear for categories -
     * the CCDM CategoryAttributeMetadata proto does not define them.
     */
    private const NOT_CONSUMED_FIELDS = [
        'attributeType', 'multi', 'frontendInput', 'required', 'unique', 'global', 'visible',
        'searchable', 'filterable', 'visibleInCompareList', 'visibleInListing', 'sortable',
        'visibleInSearch', 'filterableInSearch', 'searchWeight', 'usedForRules', 'boolean',
        'systemAttribute', 'numeric',
    ];

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

    protected function setUp(): void
    {
        self::disableFeedReadinessCheckers();
        $this->resource = Bootstrap::getObjectManager()->create(ResourceConnection::class);
        $this->connection = $this->resource->getConnection();
        $this->indexer = Bootstrap::getObjectManager()->create(Indexer::class);

        Bootstrap::getObjectManager()->configure([
            'Magento\CategoryAttributeDataExporter\Model\Indexer\CategoryAttributeFeedIndexMetadata' => [
                'arguments' => [
                    'persistExportedFeed' => true
                ]
            ]
        ]);

        $this->indexer->load(self::CATEGORY_ATTRIBUTES_FEED_INDEXER);
        $this->indexer->reindexAll();
    }

    /**
     * Check that a custom category attribute appears in the categoryAttributes feed, once per
     * store view, carrying only the fields the CCDM proto consumes.
     */
    #[DbIsolation(false)]
    #[AppIsolation(true)]
    #[DataFixture(CategoryColorAttributeFixture::class)]
    public function testCategoryAttributeExport(): void
    {
        $entries = $this->getExtractedAttribute(CategoryColorAttributeFixture::ATTRIBUTE_CODE);

        $this->assertNotEmpty(
            $entries,
            sprintf(
                '"%s" attribute must appear in the categoryAttributes feed.',
                CategoryColorAttributeFixture::ATTRIBUTE_CODE
            )
        );
        $this->assertArrayHasKey('default', $entries, 'Attribute must be exported for the default store view.');
        $this->assertArrayHasKey(
            CategoryColorAttributeFixture::STORE_CODE,
            $entries,
            'Attribute must be exported for the fixture second store view.'
        );

        foreach ($entries as $storeViewCode => $entry) {
            $this->assertEntryShape($storeViewCode, $entry, CategoryColorAttributeFixture::ATTRIBUTE_CODE);
        }
    }

    /**
     * Extract the attribute code from a decoded feed row.
     *
     * @param array $feed
     * @return string|null
     */
    protected function extractAttributeCode(array $feed): ?string
    {
        return $feed['attributeCode'] ?? null;
    }

    /**
     * Extract the store view code from a decoded feed row.
     *
     * @param array $feed
     * @return string|null
     */
    protected function extractStoreViewCode(array $feed): ?string
    {
        return $feed['storeViewCode'] ?? null;
    }

    /**
     * Assert the shape-specific fields (attribute code, dataType, label, and absence of
     * product-only fields) on a single feed entry.
     *
     * @param string $storeViewCode
     * @param array $entry
     * @param string $expectedAttributeCode
     * @return void
     */
    protected function assertEntryShape(string $storeViewCode, array $entry, string $expectedAttributeCode): void
    {
        $this->assertSame($expectedAttributeCode, $entry['attributeCode']);
        $this->assertSame(
            'int',
            $entry['dataType'],
            "dataType must be the raw EAV backend type for store \"$storeViewCode\"."
        );
        $this->assertNotEmpty($entry['label'] ?? null, "label must be present for store \"$storeViewCode\".");
        $this->assertArrayHasKey('modifiedAt', $entry);

        foreach (self::NOT_CONSUMED_FIELDS as $field) {
            $this->assertArrayNotHasKey(
                $field,
                $entry,
                sprintf(
                    '"%s" must not be exposed by the categoryAttributes feed (store "%s").',
                    $field,
                    $storeViewCode
                )
            );
        }
    }

    /**
     * Extract feed rows for a given attribute code, keyed by store view code.
     *
     * @param string $attributeCode
     * @return array<string, array>
     */
    private function getExtractedAttribute(string $attributeCode): array
    {
        $query = $this->connection->select()->from(
            ['ex' => $this->connection->getTableName(self::CATEGORY_ATTRIBUTES_FEED_TABLE)]
        );
        $cursor = $this->connection->query($query);
        $data = [];
        while ($row = $cursor->fetch()) {
            $feed = \json_decode($row['feed_data'], true);
            if ($this->extractAttributeCode($feed) === $attributeCode) {
                $data[$this->extractStoreViewCode($feed)] = $feed;
            }
        }
        return $data;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $feedTable = $this->connection->getTableName(self::CATEGORY_ATTRIBUTES_FEED_TABLE);
        $this->connection->truncateTable($feedTable);
    }
}
