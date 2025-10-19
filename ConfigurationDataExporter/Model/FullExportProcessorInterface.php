<?php
/**
 * Copyright 2021 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\ConfigurationDataExporter\Model;

/**
 * Perform full export of system configuration
 */
interface FullExportProcessorInterface
{
    /**
     * Process full export of system configuration.
     *
     * @param int|null $storeId
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return void
     */
    public function process(?int $storeId = null): void;
}
