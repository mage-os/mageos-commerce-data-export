<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProductDataExporter\Model\Provider\Product;

use Magento\ConfigurableProductDataExporter\Model\Query\VariantsQuery;
use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Configurable product variant data provider
 *
 *  TODO: Deprecated - remove this class and its query. https://github.com/magento/catalog-storefront/issues/419
 *
 * @deprecated products.variants feed is no longer consumed by SaaS;
 * kept only to preserve backwards-compatible DI wiring
 * @see \Magento\ConfigurableProductDataExporter\Model\Provider\Product\Options
 */
class Variants
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var VariantsQuery
     */
    private $variantQuery;

    /**
     * @var Config
     */
    private Config $eavConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Variants constructor.
     * @param ResourceConnection $resourceConnection
     * @param VariantsQuery $variantQuery
     * @param ?Config $eavConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        VariantsQuery $variantQuery,
        ?Config $eavConfig,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->variantQuery = $variantQuery;
        $this->eavConfig = $eavConfig ?? ObjectManager::getInstance()->get(Config::class);
        $this->logger = $logger;
    }

    /**
     * Get provider data
     *
     * @param array $values
     * @return array
     * @throws UnableRetrieveData
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function get(array $values) : array
    {
        // intentionally return empty array to avoid extra load. API does not use products.variants anymore.
        return [];
    }
}
