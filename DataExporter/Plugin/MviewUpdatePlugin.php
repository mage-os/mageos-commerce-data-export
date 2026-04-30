<?php
/**
 * Copyright 2023 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Plugin;

use Magento\DataExporter\Model\Indexer\ViewMaterializer;
use Magento\DataExporter\Model\Indexer\FeedIndexer;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\Exception\BulkException;
use Magento\Framework\Mview\Processor;
use Magento\Framework\Mview\View\CollectionFactory;
use Magento\Framework\Mview\ViewInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Phrase;
use Magento\Framework\Exception\RuntimeException;

class MviewUpdatePlugin
{
    private CollectionFactory $viewsFactory;
    private ViewMaterializer $viewMaterializer;
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @param CollectionFactory $viewsFactory
     * @param ViewMaterializer $viewMaterializer
     * @param CommerceDataExportLoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $viewsFactory,
        ViewMaterializer $viewMaterializer,
        CommerceDataExportLoggerInterface $logger
    ) {
        $this->viewsFactory = $viewsFactory;
        $this->viewMaterializer = $viewMaterializer;
        $this->logger = $logger;
    }

    /**
     * Run custom mview::update logic for commerce data export indexers.
     *
     * @param Processor $subject
     * @param callable $proceed
     * @param string $group
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @throws BulkException
     * @throws \Throwable
     */
    public function aroundUpdate(Processor $subject, callable $proceed, $group = ''): void
    {
        $exception = new BulkException();
        $views = $this->getViewsByGroup($group);
        foreach ($views as $view) {
            if ($this->isDataExporterIndexer($view)) {
                try {
                    $this->viewMaterializer->execute($view);
                } catch (\Throwable $e) {
                    // Hot fix before AC-8768
                    $this->logger->warning(
                        sprintf(
                            'CDE04-03 Partial sync failed for changelog "%s". Should be retried. Error: %s',
                            $view->getChangelog()->getName(),
                            $e->getMessage()
                        ),
                        ['exception' => $e]
                    );
                    $exception->addException(
                        new RuntimeException(
                            new Phrase(
                                'Partial sync failed for "%1". Error: %2',
                                [$view->getId(), $e->getMessage()]
                            ),
                            $e
                        )
                    );
                }
            } else {
                $view->update();
            }
        }

        if ($exception->wasErrorAdded()) {
            // to re-start partial update
            throw $exception;
        }
    }

    /**
     * Returns list of views by group
     *
     * @param string $group
     * @return ViewInterface[]
     */
    private function getViewsByGroup(string $group = ''): array
    {
        $collection = $this->viewsFactory->create();
        return $group ? $collection->getItemsByColumnValue('group', $group) : $collection->getItems();
    }

    /**
     * Checks if view is data exporter indexer.
     *
     * @param ViewInterface $view
     * @return bool
     */
    private function isDataExporterIndexer(ViewInterface $view): bool
    {
        return is_subclass_of(ObjectManager::getInstance()->get($view->getActionClass()), FeedIndexer::class);
    }
}
