<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Provider;

use Magento\CatalogDataExporter\Model\Provider\EavAttributes\EavAttributeMetadataProvider;

/**
 * Product attributes metadata provider.
 *
 * All behaviour (including per-store-view scope iteration) lives in the generic
 * {@see EavAttributeMetadataProvider}, configured for the `catalog_product` entity type via DI.
 * This subclass is kept only for backward compatibility with the et_schema provider reference
 * and existing DI/3rd-party references.
 */
class ProductMetadata extends EavAttributeMetadataProvider
{
}
