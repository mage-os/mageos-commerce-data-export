<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\ProductPriceDataExporter\Model\Query;

use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Get _current_ date in website timezone in format [website_id => website_date, ...]
 */
class DateWebsiteProvider
{
    protected TimezoneInterface $localeDate;
    private ResourceConnection $resourceConnection;
    private CommerceDataExportLoggerInterface $logger;
    private DateTime $dateTime;
    private array $data = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param DateTime $dateTime
     * @param TimezoneInterface $localeDate
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        DateTime $dateTime,
        TimezoneInterface $localeDate,
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->localeDate = $localeDate;
    }

    /**
     * @return array
     */
    public function getWebsitesDate(): array
    {
        if (!empty($this->data)) {
            return $this->data;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            ['cw' => $this->resourceConnection->getTableName('store_website')],
            ['website_id']
        )->join(
            ['csg' => $this->resourceConnection->getTableName('store_group')],
            'cw.default_group_id = csg.group_id',
            ['store_id' => 'default_store_id']
        )->where(
            'cw.website_id != 0'
        );

        foreach ($connection->fetchAll($select) as $item) {
            try {
                $timestamp = $this->localeDate->scopeTimeStamp($item['store_id']);
            } catch (\Throwable $e) {
                // use current timestamp
                $timestamp = true;
                $this->logger->warning("can't obtain website datetime", ['exception' => $e]);
            }

            $this->data[$item['website_id']] = $this->dateTime->formatDate($timestamp, false);
        }
        return $this->data;
    }
}
