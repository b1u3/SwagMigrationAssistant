<?php declare(strict_types=1);

namespace SwagMigrationNext\Profile\Shopware55\Converter;

interface ConverterRegistryInterface
{
    /**
     * Returns the converter which supports the given internal entity
     *
     * @param string $entity
     * @return ConverterInterface
     */
    public function getConverter(string $entity): ConverterInterface;
}
