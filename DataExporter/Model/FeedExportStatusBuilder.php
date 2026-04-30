<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model;

use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Status\ExportStatusCode;
use Magento\DataExporter\Status\ExportStatusCodeFactory;

/**
 * Build FeedExportStatus class
 */
class FeedExportStatusBuilder
{
    /**
     * @var FeedExportStatusFactory
     */
    private FeedExportStatusFactory $feedExportStatusFactory;

    /**
     * @var ExportStatusCodeFactory
     */
    private ExportStatusCodeFactory $exportStatusCodeFactory;

    /**
     * @param FeedExportStatusFactory $feedExportStatusFactory
     * @param ExportStatusCodeFactory $exportStatusCodeFactory
     * @param CommerceDataExportLoggerInterface $logger
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        FeedExportStatusFactory $feedExportStatusFactory,
        ExportStatusCodeFactory $exportStatusCodeFactory,
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->feedExportStatusFactory = $feedExportStatusFactory;
        $this->exportStatusCodeFactory = $exportStatusCodeFactory;
    }

    /**
     * Build data
     *
     * @param int $status
     * @param string $reasonPhrase
     * @param array $failedItems
     * @param array $metadata
     * @return FeedExportStatus
     */
    public function build(
        int $status,
        string $reasonPhrase = '',
        array $failedItems = [],
        array $metadata = []
    ) : FeedExportStatus {
        return $this->feedExportStatusFactory->create(
            [
                'status' => $this->exportStatusCodeFactory->create(['statusCode' => $status]),
                'reasonPhrase' => $reasonPhrase,
                'failedItems' => $failedItems,
                'metadata' => $metadata
            ]
        );
    }
}
