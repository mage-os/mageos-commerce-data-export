<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ProductPriceDataExporter\Setup\Patch\Schema;

use Magento\CatalogDataExporter\Model\Indexer\IndexInvalidationManager as DeprecatedIndexerManager;
use Magento\DataExporter\Service\IndexInvalidationManager;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InvalidateDataExporterIndex implements SchemaPatchInterface
{
    private IndexInvalidationManager $invalidationManager;

    /**
     * @param SchemaSetupInterface $schemaSetup
     * @param DeprecatedIndexerManager $deprecatedInvalidationManager
     * @param IndexInvalidationManager|null $invalidationManager
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup,
        DeprecatedIndexerManager $deprecatedInvalidationManager,
        ?IndexInvalidationManager $invalidationManager = null
    ) {
        $this->invalidationManager = $invalidationManager
            ?? ObjectManager::getInstance()->get(IndexInvalidationManager::class);
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $this->schemaSetup->startSetup();

        $this->invalidationManager->invalidate('recalculate_prices');

        $this->schemaSetup->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
