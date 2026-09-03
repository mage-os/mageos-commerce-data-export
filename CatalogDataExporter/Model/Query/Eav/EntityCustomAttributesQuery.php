<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Query\Eav;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Generic query that fetches user-defined (and configured system) EAV attribute values
 * for a given entity type. Entity specifics (main table, EAV entity interface, id field)
 * are injected via DI, so the same class serves products and categories.
 */
class EntityCustomAttributesQuery
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var string
     */
    private string $mainTable;

    /**
     * @var string
     */
    private string $entityType;

    /**
     * @var string
     */
    private string $entityIdField;

    /**
     * @var array
     */
    private array $systemAttributes;

    /**
     * @var EavAttributeQueryBuilderFactory
     */
    private ?EavAttributeQueryBuilderFactory $attributeQueryFactory;

    /**
     * @var array
     */
    private array $userDefinedAttributes;

    /**
     * Parameter order keeps positional backward compatibility with the former
     * ProductAttributeQuery signature; $entityType and $entityIdField are appended.
     *
     * @param ResourceConnection $resourceConnection
     * @param string $mainTable
     * @param EavAttributeQueryBuilderFactory|null $attributeQueryFactory
     * @param array $systemAttributes
     * @param string $entityType
     * @param string $entityIdField
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        string $mainTable = 'catalog_product_entity',
        ?EavAttributeQueryBuilderFactory $attributeQueryFactory = null,
        array $systemAttributes = [],
        string $entityType = \Magento\Catalog\Api\Data\ProductInterface::class,
        string $entityIdField = 'entityId'
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->mainTable = $mainTable;
        $this->attributeQueryFactory = $attributeQueryFactory
            ?? ObjectManager::getInstance()->get(EavAttributeQueryBuilderFactory::class);
        $this->systemAttributes = $systemAttributes;
        $this->entityType = $entityType;
        $this->entityIdField = $entityIdField;
    }

    /**
     * Get query for provider
     *
     * @param array $arguments
     * @return Select|null
     * @throws \Zend_Db_Select_Exception
     */
    public function getQuery(array $arguments): ?Select
    {
        $entityIds = $arguments[$this->entityIdField] ?? [];
        $storeViewCode = $arguments['storeViewCode'] ?? [];

        $attributesToSearch = array_merge(
            $this->getUserDefinedAttributes(),
            $this->systemAttributes
        );
        $attributeQueryBuilder = $this->attributeQueryFactory->create(
            [
                'entityType' => $this->entityType,
            ]
        );

        return !empty($attributesToSearch)
            ? $attributeQueryBuilder->build($entityIds, $attributesToSearch, $storeViewCode)
            : null;
    }

    /**
     * Get user defined attributes codes from EAV
     *
     * @return array
     */
    private function getUserDefinedAttributes(): array
    {
        if (isset($this->userDefinedAttributes)) {
            return $this->userDefinedAttributes;
        }
        $connection = $this->resourceConnection->getConnection();
        $attributes = $connection->fetchCol(
            $connection->select()
                ->from(['a' => $this->resourceConnection->getTableName('eav_attribute')], [])
                ->join(
                    ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('a.is_user_defined  = 1')
                ->where('t.entity_table = ?', $this->mainTable)
                ->where('a.backend_type != ?', 'static')
                ->columns(
                    [
                        'code' => 'a.attribute_code',
                    ]
                )
        );
        $this->userDefinedAttributes = array_combine($attributes, $attributes);

        return $this->userDefinedAttributes;
    }
}
