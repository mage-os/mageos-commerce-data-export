<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\CatalogDataExporter\Plugin;

use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Setup\Model\FixtureGenerator\SqlCollector;

/**
 * Filter out changelog tables by pattern {*_cl}: fixture generation (bin/magento setup:performance:generate-fixtures)
 * running in Update on Schedule mode, however it doesn't have knowledge how to hande changelog table
 */
class FilterChangeLogTable
{
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->logger = $logger;
    }
    /**
     * Filter out changelog tables by pattern {*_cl}
     *
     * @param SqlCollector $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetSql(SqlCollector $subject, array $result): array
    {
        try {
            return array_filter($result, static fn($item) => !str_ends_with((string) $item[1], '_cl'));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CDE04-18 Fixture generator: failed to filter indexer changelog tables from fixture SQL: '
                . $e->getMessage(),
                ['exception' => $e]
            );
            return $result;
        }
    }
}
