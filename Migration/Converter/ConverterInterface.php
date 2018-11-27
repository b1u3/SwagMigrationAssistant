<?php declare(strict_types=1);

namespace SwagMigrationNext\Migration\Converter;

use Shopware\Core\Framework\Context;
use SwagMigrationNext\Migration\MigrationContext;

interface ConverterInterface
{
    /**
     * Delivers the supported entity name of the converter implementation
     */
    public function getSupportedEntityName(): string;

    /**
     * Delivers the supported profile name of the converter implementation
     */
    public function getSupportedProfileName(): string;

    /**
     * Identifier which internal entity this converter supports
     */
    public function supports(string $profileName, string $entityName): bool;

    /**
     * Converts the given data into the internal structure
     */
    public function convert(array $data, Context $context, MigrationContext $migrationContext): ConvertStruct;

    public function writeMapping(Context $context): void;
}