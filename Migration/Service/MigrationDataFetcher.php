<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\ShopwareHttpException;
use SwagMigrationAssistant\Migration\EnvironmentInformation;
use SwagMigrationAssistant\Migration\Gateway\GatewayFactoryRegistryInterface;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Migration\Profile\ProfileRegistryInterface;

class MigrationDataFetcher implements MigrationDataFetcherInterface
{
    /**
     * @var ProfileRegistryInterface
     */
    private $profileRegistry;

    /**
     * @var GatewayFactoryRegistryInterface
     */
    private $gatewayFactoryRegistry;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    public function __construct(
        ProfileRegistryInterface $profileRegistry,
        GatewayFactoryRegistryInterface $gatewayFactoryRegistry,
        LoggingServiceInterface $loggingService
    ) {
        $this->profileRegistry = $profileRegistry;
        $this->gatewayFactoryRegistry = $gatewayFactoryRegistry;
        $this->loggingService = $loggingService;
    }

    public function fetchData(MigrationContextInterface $migrationContext, Context $context): int
    {
        $returnCount = 0;
        try {
            $profile = $this->profileRegistry->getProfile($migrationContext->getProfileName());
            $gateway = $this->gatewayFactoryRegistry->createGateway($migrationContext);
            $data = $gateway->read();

            if (\count($data) === 0) {
                return $returnCount;
            }
            $returnCount = $profile->convert($data, $migrationContext, $context);
        } catch (\Exception $exception) {
            $code = $exception->getCode();
            if (is_subclass_of($exception, ShopwareHttpException::class, false)) {
                $code = $exception->getErrorCode();
            }

            $dataSet = $migrationContext->getDataSet();
            $this->loggingService->addError($migrationContext->getRunUuid(), (string) $code, '', $exception->getMessage(), ['entity' => $dataSet::getEntity()]);
            $this->loggingService->saveLogging($context);
        }

        return $returnCount;
    }

    public function getEnvironmentInformation(MigrationContextInterface $migrationContext): EnvironmentInformation
    {
        $profile = $this->profileRegistry->getProfile($migrationContext->getProfileName());
        $gateway = $this->gatewayFactoryRegistry->createGateway($migrationContext);

        return $profile->readEnvironmentInformation($gateway);
    }
}
