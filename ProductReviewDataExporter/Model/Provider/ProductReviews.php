<?php
/**
 * Copyright 2022 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\ProductReviewDataExporter\Model\Provider;

use Magento\DataExporter\Exception\UnableRetrieveData;
use Magento\Framework\App\ResourceConnection;
use Magento\ProductReviewDataExporter\Model\Query\ProductReviewsQuery;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface as LoggerInterface;

/**
 * Product reviews data provider
 */
class ProductReviews
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ProductReviewsQuery
     */
    private $productReviewsQuery;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ProductReviewsQuery $productReviewsQuery
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        ProductReviewsQuery $productReviewsQuery,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productReviewsQuery = $productReviewsQuery;
        $this->logger = $logger;
    }

    /**
     * Returns attribute data
     *
     * @param array $values
     *
     * @return array
     *
     * @throws UnableRetrieveData
     */
    public function get(array $values): array
    {
        $output = [];
        $queryArguments = [];

        try {
            foreach ($values as $value) {
                $queryArguments[$value['reviewId']] = $value['reviewId'];
            }

            $connection = $this->resourceConnection->getConnection();
            $cursor = $connection->query($this->productReviewsQuery->getQuery($queryArguments));

            while ($row = $cursor->fetch()) {
                $key = $row['reviewId'];
                $output[$key] = $output[$key] ?? $this->formatReviewRow($row);

                if (null !== $row['ratingId']) {
                    $output[$key]['ratings'][] = [
                        'ratingId' => \base64_encode($row['ratingId']),
                        'value' => $row['ratingValue'],
                    ];
                }
            }
        } catch (\Throwable $exception) {
            throw new UnableRetrieveData(
                sprintf('Unable to retrieve product reviews data: %s', $exception->getMessage()),
                0,
                $exception
            );
        }

        return \array_values($output);
    }

    /**
     * Format review row
     *
     * @param array $row
     *
     * @return array
     */
    private function formatReviewRow(array $row): array
    {
        return [
            'reviewId' => $row['reviewId'],
            'productId' => $row['productId'],
            'visibility' => \explode(',', $row['visibility']),
            'title' => $row['title'],
            'nickname' => $row['nickname'],
            'text' => $row['text'],
            'customerId' => $row['customerId'],
        ];
    }
}
