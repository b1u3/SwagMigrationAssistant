<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Service;

use Shopware\Core\Framework\Context;
use SwagMigrationAssistant\Migration\EnvironmentInformation;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

interface MigrationDataFetcherInterface
{
    /**
     * Uses the given migration context and the shopware context to read data from an external source
     * and tries to convert it into the internal structure.
     * Returns the count of the imported data
     */
    public function fetchData(MigrationContextInterface $migrationContext, Context $context): int;

    /**
     * Reads the complete environment information from the source system
     */
    public function getEnvironmentInformation(MigrationContextInterface $migrationContext): EnvironmentInformation;
}
