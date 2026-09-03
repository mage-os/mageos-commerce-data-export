<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Query;

use Magento\CatalogDataExporter\Model\Query\Eav\EntityCustomAttributesQuery;

/**
 * Product attribute query for catalog data exporter.
 *
 * @see EntityCustomAttributesQuery for the actual implementation. Kept for backward
 *      compatibility; configured for products via DI (catalog_product_entity / productId).
 */
class ProductAttributeQuery extends EntityCustomAttributesQuery
{
}
