<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Export;

use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;

/**
 * Resolves the ordered list of scope ids that a feed must extract data for.
 *
 * A "scope" is the store-level dimension a feed fans out over - store view ids for catalog
 * feeds (products, categories), website ids for the price feed. Implementations decide which
 * scopes are relevant so extraction can iterate them in configurable-size chunks instead of
 * fanning every entity out across all scopes in a single pass.
 *
 * The default implementation returns every scope; the ACO adapter returns only discoverable
 * scopes so non-discoverable ones are never extracted.
 */
interface ScopeResolverInterface
{
    /**
     * Get the ordered list of scope ids to extract for the given feed.
     *
     * @param FeedIndexMetadata $metadata
     * @return int[]
     */
    public function getScopes(FeedIndexMetadata $metadata): array;
}
