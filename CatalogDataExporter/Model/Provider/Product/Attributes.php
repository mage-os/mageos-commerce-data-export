<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider\Product;

use Magento\CatalogDataExporter\Model\Provider\EavAttributes\EntityCustomAttributesProvider;

/**
 * Product attributes data provider.
 *
 * @see EntityCustomAttributesProvider for the actual implementation. Kept for backward
 *      compatibility; configured for products via DI (productId).
 */
class Attributes extends EntityCustomAttributesProvider
{
}
