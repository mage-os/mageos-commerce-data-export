<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\EavAttributes;

use Magento\CatalogDataExporter\Model\Query\Eav\EntityCustomAttributesQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Framework\App\ResourceConnection;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Generic provider that returns custom EAV attribute values (with option labels resolved)
 * for the `attributes` feed field. The entity id field (productId / categoryId) is injected
 * via DI so the same class serves products and categories.
 */
class EntityCustomAttributesProvider
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var EntityCustomAttributesQuery
     */
    private EntityCustomAttributesQuery $attributeQuery;

    /**
     * @var EavAttributeOptionValueResolver
     */
    private EavAttributeOptionValueResolver $attributeMetadata;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var string
     */
    private string $entityIdField;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EntityCustomAttributesQuery $attributeQuery
     * @param EavAttributeOptionValueResolver $attributeMetadata
     * @param LoggerInterface $logger
     * @param string $entityIdField
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        EntityCustomAttributesQuery $attributeQuery,
        EavAttributeOptionValueResolver $attributeMetadata,
        LoggerInterface $logger,
        string $entityIdField = 'entityId'
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->attributeQuery = $attributeQuery;
        $this->attributeMetadata = $attributeMetadata;
        $this->logger = $logger;
        $this->entityIdField = $entityIdField;
    }

    /**
     * Get provider data
     *
     * @param array $values
     * @return array
     * @throws UnableRetrieveData
     * @throws \Zend_Db_Statement_Exception
     */
    public function get(array $values): array
    {
        $output = [];
        $connection = $this->resourceConnection->getConnection();
        $queryArguments = [];
        foreach ($values as $value) {
            $entityId = $value[$this->entityIdField];
            $queryArguments[$this->entityIdField][$entityId] = $entityId;
            $queryArguments['storeViewCode'][$value['storeViewCode']] = $value['storeViewCode'];
        }
        try {
            foreach ($queryArguments['storeViewCode'] as $storeViewCode) {
                $select = $this->attributeQuery->getQuery(
                    [
                        $this->entityIdField => $queryArguments[$this->entityIdField],
                        'storeViewCode' => $storeViewCode
                    ]
                );
                if ($select === null) {
                    continue;
                }

                $cursor = $connection->query($select);
                while ($row = $cursor->fetch()) {
                    $key = implode('-', [$storeViewCode, $row['entity_id'], $row['attribute_code']]);
                    $output[$key][$this->entityIdField] = $row['entity_id'];
                    $output[$key]['storeViewCode'] = $storeViewCode;
                    $output[$key]['attributes'] = [
                        'attributeCode' => $row['attribute_code'],
                        'value' => ($row['value'] !== null) ?
                            $this->attributeMetadata->getAttributeValue(
                                $row['attribute_code'],
                                $storeViewCode,
                                $row['value']
                            ) : null
                    ];
                }
            }
        } catch (\Exception $exception) {
            throw new UnableRetrieveData(
                sprintf('Unable to retrieve attributes data: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
        return $output;
    }
}
