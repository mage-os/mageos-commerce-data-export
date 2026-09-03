<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\EavAttributes;

use Magento\CatalogDataExporter\Model\Provider\Product\Formatter\FormatterInterface;
use Magento\CatalogDataExporter\Model\Query\Eav\EavAttributeMetadataQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Export\ScopeResolverInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ResourceConnection;

/**
 * Generic EAV attribute metadata provider for catalog entities (products / categories).
 * The feed entity type label (catalog_product / catalog_category) and the query are
 * injected via DI.
 */
class EavAttributeMetadataProvider implements DataProcessorInterface
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var EavAttributeMetadataQuery
     */
    private EavAttributeMetadataQuery $metadataQuery;

    /**
     * @var FormatterInterface
     */
    private FormatterInterface $formatter;

    /**
     * @var string
     */
    private string $attributeType;

    /**
     * @var ScopeResolverInterface
     */
    private ScopeResolverInterface $scopeResolver;

    /**
     * @var string[]
     */
    private array $excludeAttributes;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EavAttributeMetadataQuery $metadataQuery
     * @param FormatterInterface $formatter
     * @param string $attributeType
     * @param ScopeResolverInterface $scopeResolver
     * @param string[] $excludeAttributes
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        EavAttributeMetadataQuery $metadataQuery,
        FormatterInterface $formatter,
        ScopeResolverInterface $scopeResolver,
        string $attributeType = 'catalog_product',
        array $excludeAttributes = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->metadataQuery = $metadataQuery;
        $this->formatter = $formatter;
        $this->attributeType = $attributeType;
        $this->scopeResolver = $scopeResolver;
        $this->excludeAttributes = $excludeAttributes;
    }

    /**
     * Format provider data
     *
     * @param array $row
     * @return array
     */
    private function format(array $row): array
    {
        $output = $this->formatter->format($row);

        if (true === $output['boolean']) {
            $output['numeric'] = false;
        }

        $output['attributeType'] = $this->attributeType;

        return $output;
    }

    /**
     * @inheritdoc
     *
     * @throws UnableRetrieveData
     * @throws \Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        $node = null,
        $info = null
    ): void {
        $queryArguments = [];
        try {
            foreach ($arguments as $value) {
                $queryArguments['id'][$value['id']] = $value['id'];
            }
            if (empty($queryArguments)) {
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $scopeIds = $this->scopeResolver->getScopes($metadata);
            $scopeBatches = empty($scopeIds)
                ? []
                : array_chunk($scopeIds, $metadata->getStoreViewBatchSize());

            // TODO: do we want to support edge case when all store-views deleted?
            if (empty($scopeBatches)) {
                return;
            }

            foreach ($scopeBatches as $scopeBatch) {
                $output = [];
                $select = $this->metadataQuery->getQuery($queryArguments, $scopeBatch);
                $cursor = $connection->query($select);
                while ($row = $cursor->fetch()) {
                    if (!empty($this->excludeAttributes) && isset($row['attributeCode'])
                        && \in_array($row['attributeCode'], $this->excludeAttributes, true)
                    ) {
                        continue;
                    }
                    $output[] = $this->format($row);
                }
                $dataProcessorCallback($this->get($output));
            }
        } catch (\Throwable $exception) {
            throw new UnableRetrieveData(
                sprintf('Unable to retrieve attributes metadata: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * For backward compatibility with existing 3-rd party plugins.
     *
     * @param array $values
     * @return array
     * @deprecated
     * @see self::execute
     */
    public function get(array $values): array
    {
        return $values;
    }
}
