<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Profile\Shopware55\Converter;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationAssistant\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationAssistant\Migration\MigrationContext;
use SwagMigrationAssistant\Migration\Profile\SwagMigrationProfileEntity;
use SwagMigrationAssistant\Profile\Shopware55\Converter\CustomerAttributeConverter;
use SwagMigrationAssistant\Profile\Shopware55\DataSelection\DataSet\CustomerAttributeDataSet;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;
use SwagMigrationAssistant\Test\Mock\Migration\Mapping\DummyMappingService;

class CustomerAttributeConverterTest extends TestCase
{
    /**
     * @var MigrationContext
     */
    private $migrationContext;

    /**
     * @var CustomerAttributeConverter
     */
    private $converter;

    protected function setUp(): void
    {
        $this->converter = new CustomerAttributeConverter(new DummyMappingService());

        $runId = Uuid::randomHex();
        $connection = new SwagMigrationConnectionEntity();
        $connection->setProfile(new SwagMigrationProfileEntity());
        $connection->setId(Uuid::randomHex());
        $connection->setName('ConntectionName');

        $this->migrationContext = new MigrationContext(
            $connection,
            $runId,
            new CustomerAttributeDataSet(),
            0,
            250
        );
    }

    public function testSupports(): void
    {
        $supportsDefinition = $this->converter->supports(Shopware55Profile::PROFILE_NAME, new CustomerAttributeDataSet());

        static::assertTrue($supportsDefinition);
    }

    public function testConvertTextAttribute(): void
    {
        $categoryData = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($categoryData[3], $context, $this->migrationContext);
        $this->converter->writeMapping($context);
        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('customer', $converted['relations'][0]['entityName']);
        static::assertSame('customer_migration_ConntectionName_attr6', $converted['customFields'][0]['name']);
        static::assertSame('text', $converted['customFields'][0]['config']['type']);
        static::assertSame('text', $converted['customFields'][0]['config']['customFieldType']);
    }
}