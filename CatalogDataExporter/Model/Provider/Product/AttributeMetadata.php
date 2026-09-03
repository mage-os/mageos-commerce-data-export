<?php
/**
 * Copyright 2022 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\Product;

use Magento\CatalogDataExporter\Model\Provider\EavAttributes\EavAttributeOptionValueResolver;
use Magento\Framework\App\ResourceConnection;

/**
 * Class for Attribute Metadata
 *
 * @see EavAttributeOptionValueResolver for the actual implementation. Kept for backward
 *      compatibility: resolves option labels for `catalog_product_entity`.
 */
class AttributeMetadata extends EavAttributeOptionValueResolver
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        parent::__construct($resourceConnection, 'catalog_product_entity');
    }
}
