<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Media;

use SwagMigrationAssistant\Exception\ProcessorNotFoundException;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

interface MediaFileProcessorRegistryInterface
{
    /**
     * @throws ProcessorNotFoundException
     */
    public function getProcessor(MigrationContextInterface $context): MediaFileProcessorInterface;
}
