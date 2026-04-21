<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\Category;

use Magento\CatalogDataExporter\Model\Query\CategoryMainQuery;
use Magento\Framework\App\ResourceConnection;

/**
 * Builds url_path strings for categories from their url_key EAV values.
 */
class CategoryUrlPathBuilder
{
    /**
     * [storeViewCode][rawPath] => urlPath
     *
     * Keyed by the raw path string (e.g. "1/2/812/813/810") rather than categoryId so that
     * a category move - which changes the raw path - is a natural cache miss without requiring
     * any explicit invalidation.
     *
     * @var array<string, array<string, string>>
     */
    private array $urlPathCache = [];

    /**
     * [storeId][entityId => int] => urlKey|null
     *
     * @var array<int, array<int, string|null>>
     */
    private array $urlKeyCache = [];

    /**
     * [storeViewCode] => ['storeId' => int]
     *
     * @var array<string, array{storeId: int}>
     */
    private array $storeMetaCache = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param CategoryMainQuery $categoryMainQuery
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CategoryMainQuery $categoryMainQuery
    ) {
    }

    /**
     * Returns [categoryId => urlPath] for the given [categoryId => rawPath] map.
     *
     * The built url_path is cached by rawPath string, so a category move (which changes
     * the raw path) is automatically a cache miss - no explicit invalidation required.
     *
     * @param array  $pathsByEntityId [categoryId => raw path], e.g. [10 => '1/2/4/10']
     * @param string $storeViewCode
     * @return array
     */
    public function resolveUrlPaths(array $pathsByEntityId, string $storeViewCode): array
    {
        if (empty($pathsByEntityId)) {
            return [];
        }

        $storeMeta = $this->resolveStoreMeta($storeViewCode);
        $storeId = $storeMeta['storeId'];

        $result = [];
        $missing = [];

        foreach ($pathsByEntityId as $entityId => $rawPath) {
            $entityId = (int)$entityId;
            if (isset($this->urlPathCache[$storeViewCode][$rawPath])) {
                $result[$entityId] = $this->urlPathCache[$storeViewCode][$rawPath];
            } else {
                $missing[$entityId] = $rawPath;
            }
        }

        if (empty($missing)) {
            return $result;
        }

        // Collect every ancestor ID appearing in the uncached paths.
        $allIds = [];
        foreach ($missing as $rawPath) {
            foreach (explode('/', $rawPath) as $id) {
                $allIds[(int)$id] = true;
            }
        }

        $urlKeyMap = $this->resolveUrlKeys(array_keys($allIds), $storeId);

        foreach ($missing as $entityId => $rawPath) {
            $urlPath = $this->buildUrlPath($rawPath, $urlKeyMap);
            $this->urlPathCache[$storeViewCode][$rawPath] = $urlPath;
            $result[$entityId] = $urlPath;
        }

        return $result;
    }

    /**
     * Fetches url_key for the given entity IDs at the given store, with per-store caching.
     *
     * Only IDs missing from the cache trigger a DB query.
     *
     * @param int[] $entityIds
     * @param int   $storeId
     * @return array<int, string|null>  [entityId => urlKey|null]
     */
    private function resolveUrlKeys(array $entityIds, int $storeId): array
    {
        $missing = [];
        foreach ($entityIds as $id) {
            if (!array_key_exists($id, $this->urlKeyCache[$storeId] ?? [])) {
                $missing[] = $id;
            }
        }

        if (!empty($missing)) {
            $rows = $this->resourceConnection->getConnection()->fetchAll(
                $this->categoryMainQuery->getUrlKeyQuery($missing, $storeId)
            );
            foreach ($rows as $row) {
                $this->urlKeyCache[$storeId][(int)$row['entity_id']] = $row['url_key'];
            }
            // Mark IDs with no DB row so they are not re-fetched on future calls.
            foreach ($missing as $id) {
                $this->urlKeyCache[$storeId][$id] ??= null;
            }
        }

        $result = [];
        foreach ($entityIds as $id) {
            $result[$id] = $this->urlKeyCache[$storeId][$id] ?? null;
        }
        return $result;
    }

    /**
     * Resolves storeId for the given store view code, with caching.
     *
     * @param string $storeViewCode
     * @return array{storeId: int}
     */
    private function resolveStoreMeta(string $storeViewCode): array
    {
        if (isset($this->storeMetaCache[$storeViewCode])) {
            return $this->storeMetaCache[$storeViewCode];
        }

        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    ['s' => $this->resourceConnection->getTableName('store')],
                    ['store_id']
                )
                ->where('s.code = ?', $storeViewCode)
        );

        $this->storeMetaCache[$storeViewCode] = [
            'storeId'        => (int)($row['store_id'] ?? 0),
        ];

        return $this->storeMetaCache[$storeViewCode];
    }

    /**
     * Builds a url_path string by joining the url_key of each category segment in $rawPath.
     *
     * Example: rawPath="1/2/4/10", urlKeyMap={4:"phones", 10:"smartphones"}
     *   => "phones/smartphones" rootCategoryIndex is always 1 (category ID "2" in this example)
     *
     * @param string $rawPath   Raw path from catalog_category_entity.path
     * @param array  $urlKeyMap [entityId => urlKey|null]
     * @return string
     */
    private function buildUrlPath(string $rawPath, array $urlKeyMap): string
    {
        $ids = array_map('intval', explode('/', $rawPath));
        $rootCategoryIndex = 1;

        $parts = [];
        foreach (array_slice($ids, $rootCategoryIndex + 1) as $id) {
            $urlKey = $urlKeyMap[$id] ?? null;
            if ($urlKey !== null) {
                $parts[] = $urlKey;
            }
        }

        return implode('/', $parts);
    }
}
