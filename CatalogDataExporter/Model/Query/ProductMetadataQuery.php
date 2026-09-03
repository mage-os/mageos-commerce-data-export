<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Model\Query;

use Magento\CatalogDataExporter\Model\Query\Eav\EavAttributeMetadataQuery;

/**
 * Product attributes metadata query for catalog data exporter.
 *
 * All behaviour (including per-store-view scope filtering) lives in the generic
 * {@see EavAttributeMetadataQuery}, which defaults to the `catalog_product` entity type.
 * This subclass is kept only for backward compatibility with existing DI/3rd-party references.
 */
class ProductMetadataQuery extends EavAttributeMetadataQuery
{
}
