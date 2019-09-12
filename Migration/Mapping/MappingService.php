<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Mapping;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Cms\CmsPageDefinition;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeEntity;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Language\LanguageEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\NumberRange\NumberRangeEntity;
use Shopware\Core\System\Tax\TaxEntity;
use SwagMigrationAssistant\Exception\LocaleNotFoundException;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class MappingService implements MappingServiceInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $mediaDefaultFolderRepo;

    protected $uuids = [];

    protected $values = [];

    protected $uuidLists = [];

    protected $migratedSalesChannels = [];

    protected $writeArray = [];

    protected $languageData = [];

    protected $locales = [];

    /**
     * @var EntityRepositoryInterface
     */
    private $migrationMappingRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $localeRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $currencyRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelTypeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $shippingMethodRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $taxRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $numberRangeRepo;

    /**
     * @var LanguageEntity
     */
    private $defaultLanguageData;

    /**
     * @var EntityRepositoryInterface
     */
    private $ruleRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $thumbnailSizeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $categoryRepo;

    /**
     * @var EntityWriterInterface
     */
    private $entityWriter;

    /**
     * @var string
     */
    private $defaultAvailabilityRule;

    /**
     * @var EntityDefinition
     */
    private $mappingDefinition;

    /**
     * @var EntityRepositoryInterface
     */
    private $cmsPageRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $themeRepo;

    public function __construct(
        EntityRepositoryInterface $migrationMappingRepo,
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $salesChannelRepo,
        EntityRepositoryInterface $salesChannelTypeRepo,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $shippingMethodRepo,
        EntityRepositoryInterface $taxRepo,
        EntityRepositoryInterface $numberRangeRepo,
        EntityRepositoryInterface $ruleRepo,
        EntityRepositoryInterface $thumbnailSizeRepo,
        EntityRepositoryInterface $mediaDefaultRepo,
        EntityRepositoryInterface $categoryRepo,
        EntityRepositoryInterface $cmsPageRepo,
        EntityWriterInterface $entityWriter,
        EntityDefinition $mappingDefinition
    ) {
        $this->migrationMappingRepo = $migrationMappingRepo;
        $this->localeRepository = $localeRepository;
        $this->languageRepository = $languageRepository;
        $this->countryRepository = $countryRepository;
        $this->currencyRepository = $currencyRepository;
        $this->salesChannelRepo = $salesChannelRepo;
        $this->salesChannelTypeRepo = $salesChannelTypeRepo;
        $this->paymentRepository = $paymentRepository;
        $this->shippingMethodRepo = $shippingMethodRepo;
        $this->taxRepo = $taxRepo;
        $this->numberRangeRepo = $numberRangeRepo;
        $this->ruleRepo = $ruleRepo;
        $this->thumbnailSizeRepo = $thumbnailSizeRepo;
        $this->mediaDefaultFolderRepo = $mediaDefaultRepo;
        $this->categoryRepo = $categoryRepo;
        $this->cmsPageRepo = $cmsPageRepo;
        $this->entityWriter = $entityWriter;
        $this->mappingDefinition = $mappingDefinition;
    }

    public function getUuidsByEntity(string $connectionId, string $entityName, Context $context): array
    {
        /** @var SwagMigrationMappingEntity[] $entities */
        $entities = $context->disableCache(function (Context $context) use ($connectionId, $entityName) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', $entityName));

            return $this->migrationMappingRepo->search($criteria, $context)->getEntities();
        });

        $entityUuids = [];
        foreach ($entities as $entity) {
            $entityUuids[] = $entity->getEntityUuid();
        }

        return $entityUuids;
    }

    public function getUuid(string $connectionId, string $entityName, string $oldId, Context $context): ?string
    {
        if (isset($this->uuids[$entityName][$oldId])) {
            return $this->uuids[$entityName][$oldId];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId, $entityName, $oldId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', $entityName));
            $criteria->addFilter(new EqualsFilter('oldIdentifier', $oldId));
            $criteria->setLimit(1);

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();
            $uuid = $element->getEntityUuid();

            $this->uuids[$entityName][$oldId] = $uuid;

            return $uuid;
        }

        return null;
    }

    public function getValue(string $connectionId, string $entityName, string $oldId, Context $context): ?string
    {
        if (isset($this->values[$entityName][$oldId])) {
            return $this->values[$entityName][$oldId];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId, $entityName, $oldId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', $entityName));
            $criteria->addFilter(new EqualsFilter('oldIdentifier', $oldId));
            $criteria->setLimit(1);

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();
            $value = $element->getEntityValue();

            $this->values[$entityName][$oldId] = $value;

            return $value;
        }

        return null;
    }

    public function createNewUuidListItem(
        string $connectionId,
        string $entityName,
        string $oldId,
        Context $context,
        ?array $additionalData = null,
        ?string $newUuid = null
    ): void {
        $uuid = Uuid::randomHex();
        if ($newUuid !== null) {
            $uuid = $newUuid;

            if ($this->isUuidDuplicate($connectionId, $entityName, $oldId, $newUuid, $context)) {
                return;
            }
        }

        $this->saveListMapping(
            [
                'connectionId' => $connectionId,
                'entity' => $entityName,
                'oldIdentifier' => $oldId,
                'entityUuid' => $uuid,
                'additionalData' => $additionalData,
            ]
        );
    }

    public function getUuidList(string $connectionId, string $entityName, string $identifier, Context $context): array
    {
        if (isset($this->uuidLists[$entityName][$identifier])) {
            return $this->uuidLists[$entityName][$identifier];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId, $entityName, $identifier) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', $entityName));
            $criteria->addFilter(new EqualsFilter('oldIdentifier', $identifier));

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        $uuidList = [];
        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $entity */
            foreach ($result->getEntities() as $entity) {
                $uuidList[] = $entity->getEntityUuid();
            }
        }

        $this->uuidLists[$entityName][$identifier] = $uuidList;

        return $uuidList;
    }

    public function createNewUuid(
        string $connectionId,
        string $entityName,
        string $oldId,
        Context $context,
        ?array $additionalData = null,
        ?string $newUuid = null
    ): string {
        $uuid = $this->getUuid($connectionId, $entityName, $oldId, $context);
        if ($uuid !== null) {
            return $uuid;
        }

        $uuid = Uuid::randomHex();
        if ($newUuid !== null) {
            $uuid = $newUuid;
        }

        $this->saveMapping(
            [
                'connectionId' => $connectionId,
                'entity' => $entityName,
                'oldIdentifier' => $oldId,
                'entityUuid' => $uuid,
                'additionalData' => $additionalData,
            ]
        );

        return $uuid;
    }

    public function getDefaultCmsPageUuid(string $connectionId, Context $context): ?string
    {
        $uuid = $this->getUuid($connectionId, CmsPageDefinition::ENTITY_NAME, 'default_cms_page', $context);
        if ($uuid !== null) {
            return $uuid;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('type', 'product_list'));
        $criteria->addFilter(new EqualsFilter('locked', true));

        /** @var CmsPageEntity|null $cmsPage */
        $cmsPage = $this->cmsPageRepo->search($criteria, $context)->first();

        if ($cmsPage === null) {
            return null;
        }

        $uuid = $cmsPage->getId();

        $this->saveMapping(
            [
                'connectionId' => $connectionId,
                'entity' => CmsPageDefinition::ENTITY_NAME,
                'oldIdentifier' => 'default_cms_page',
                'entityUuid' => $uuid,
            ]
        );

        return $uuid;
    }

    public function getLanguageUuid(string $connectionId, string $localeCode, Context $context, bool $withoutMapping = false): ?string
    {
        if (!$withoutMapping && isset($this->languageData[$localeCode])) {
            return $this->languageData[$localeCode];
        }

        $languageUuid = $this->searchLanguageInMapping($localeCode, $context);
        if (!$withoutMapping && $languageUuid !== null) {
            return $languageUuid;
        }

        $localeUuid = $this->searchLocale($localeCode, $context);

        $languageUuid = $this->searchLanguageByLocale($localeUuid, $context);

        if ($languageUuid === null) {
            return $languageUuid;
        }
        $this->languageData[$localeCode] = $languageUuid;

        return $languageUuid;
    }

    public function getLocaleUuid(string $connectionId, string $localeCode, Context $context): string
    {
        if (isset($this->locales[$localeCode])) {
            return $this->locales[$localeCode];
        }

        $localeUuid = $this->getUuid($connectionId, DefaultEntities::LOCALE, $localeCode, $context);

        if ($localeUuid !== null) {
            $this->locales[$localeCode] = $localeUuid;

            return $localeUuid;
        }

        $localeUuid = $this->searchLocale($localeCode, $context);
        $this->locales[$localeCode] = $localeUuid;

        return $localeUuid;
    }

    public function getDefaultLanguage(Context $context): LanguageEntity
    {
        if (!empty($this->defaultLanguageData)) {
            return $this->defaultLanguageData;
        }

        $languageUuid = $context->getLanguageId();

        /** @var LanguageEntity $language */
        $language = $context->disableCache(function (Context $context) use ($languageUuid) {
            $criteria = new Criteria([$languageUuid]);
            $criteria->addAssociation('locale');

            return $this->languageRepository->search($criteria, $context)->first();
        });

        $this->defaultLanguageData = $language;

        return $language;
    }

    public function getCountryUuid(string $oldId, string $iso, string $iso3, string $connectionId, Context $context): ?string
    {
        $countryUuid = $this->getUuid($connectionId, DefaultEntities::COUNTRY, $oldId, $context);

        if ($countryUuid !== null) {
            return $countryUuid;
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($iso, $iso3) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $iso));
            $criteria->addFilter(new EqualsFilter('iso3', $iso3));
            $criteria->setLimit(1);

            return $this->countryRepository->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var CountryEntity $element */
            $element = $result->getEntities()->first();

            $countryUuid = $element->getId();

            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::COUNTRY,
                    'oldIdentifier' => $oldId,
                    'entityUuid' => $countryUuid,
                ]
            );

            return $countryUuid;
        }

        return null;
    }

    public function getCurrencyUuid(string $connectionId, string $oldIsoCode, Context $context): ?string
    {
        $currencyUuid = $this->getUuid($connectionId, DefaultEntities::CURRENCY, $oldIsoCode, $context);

        if ($currencyUuid !== null) {
            return $currencyUuid;
        }

        $currencyUuid = $this->getCurrencyUuidWithoutMapping($connectionId, $oldIsoCode, $context);
        if ($currencyUuid !== null) {
            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::CURRENCY,
                    'oldIdentifier' => $oldIsoCode,
                    'entityUuid' => $currencyUuid,
                ]
            );
        }

        return $currencyUuid;
    }

    public function getDefaultCurrency(Context $context): CurrencyEntity
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) {
            $criteria = new Criteria([Defaults::CURRENCY]);
            $criteria->setLimit(1);

            return $this->currencyRepository->search($criteria, $context);
        });

        /** @var CurrencyEntity $currency */
        $currency = $result->getEntities()->first();

        return $currency;
    }

    public function getCurrencyUuidWithoutMapping(string $connectionId, string $oldIsoCode, Context $context): ?string
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($oldIsoCode) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('isoCode', $oldIsoCode));
            $criteria->setLimit(1);

            return $this->currencyRepository->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var CurrencyEntity $element */
            $element = $result->getEntities()->first();

            return $element->getId();
        }

        return null;
    }

    public function getTaxUuid(string $connectionId, float $taxRate, Context $context): ?string
    {
        $taxUuid = $this->getUuid($connectionId, DefaultEntities::TAX, (string) $taxRate, $context);

        if ($taxUuid !== null) {
            return $taxUuid;
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($taxRate) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('taxRate', $taxRate));
            $criteria->setLimit(1);

            return $this->taxRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var TaxEntity $tax */
            $tax = $result->getEntities()->first();
            $taxUuid = $tax->getId();

            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::TAX,
                    'oldIdentifier' => (string) $taxRate,
                    'entityUuid' => $taxUuid,
                ]
            );

            return $taxUuid;
        }

        return null;
    }

    public function getNumberRangeUuid(string $type, string $oldId, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($type) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter(
                'number_range.type.technicalName',
                $type
            ));

            return $this->numberRangeRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var NumberRangeEntity $numberRange */
            $numberRange = $result->getEntities()->first();

            $this->saveMapping(
                [
                    'connectionId' => $migrationContext->getConnection()->getId(),
                    'entity' => DefaultEntities::NUMBER_RANGE,
                    'oldIdentifier' => $oldId,
                    'entityUuid' => $numberRange->getId(),
                ]
            );

            return $numberRange->getId();
        }

        return null;
    }

    public function getDefaultFolderIdByEntity(string $entityName, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connectionId = $migrationContext->getConnection()->getId();
        $defaultFolderUuid = $this->getUuid($connectionId, DefaultEntities::MEDIA_DEFAULT_FOLDER, $entityName, $context);

        if ($defaultFolderUuid !== null) {
            return $defaultFolderUuid;
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($entityName) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('entity', $entityName));

            return $this->mediaDefaultFolderRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var MediaDefaultFolderEntity $mediaDefaultFolder */
            $mediaDefaultFolder = $result->getEntities()->first();

            if ($mediaDefaultFolder->getFolder() === null) {
                return null;
            }

            $this->saveMapping(
                [
                    'connectionId' => $migrationContext->getConnection()->getId(),
                    'entity' => DefaultEntities::MEDIA_DEFAULT_FOLDER,
                    'oldIdentifier' => $entityName,
                    'entityUuid' => $mediaDefaultFolder->getFolder()->getId(),
                ]
            );

            return $mediaDefaultFolder->getFolder()->getId();
        }

        return null;
    }

    public function getThumbnailSizeUuid(int $width, int $height, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $sizeString = $width . '-' . $height;
        $connectionId = $migrationContext->getConnection()->getId();
        $thumbnailSizeUuid = $this->getUuid($connectionId, DefaultEntities::MEDIA_THUMBNAIL_SIZE, $sizeString, $context);

        if ($thumbnailSizeUuid !== null) {
            return $thumbnailSizeUuid;
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($width, $height) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('width', $width));
            $criteria->addFilter(new EqualsFilter('height', $height));

            return $this->thumbnailSizeRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var MediaThumbnailSizeEntity $thumbnailSize */
            $thumbnailSize = $result->getEntities()->first();

            $this->saveMapping(
                [
                    'connectionId' => $migrationContext->getConnection()->getId(),
                    'entity' => DefaultEntities::MEDIA_THUMBNAIL_SIZE,
                    'oldIdentifier' => $sizeString,
                    'entityUuid' => $thumbnailSize->getId(),
                ]
            );

            return $thumbnailSize->getId();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getMigratedSalesChannelUuids(string $connectionId, Context $context): array
    {
        if (isset($this->migratedSalesChannels[$connectionId])) {
            return $this->migratedSalesChannels[$connectionId];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', DefaultEntities::SALES_CHANNEL));

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        /** @var SwagMigrationMappingCollection $saleschannelMappingCollection */
        $saleschannelMappingCollection = $result->getEntities();

        $uuids = [];
        foreach ($saleschannelMappingCollection as $swagMigrationMappingEntity) {
            $uuid = $swagMigrationMappingEntity->getEntityUuid();
            $uuids[] = $uuid;
            $this->migratedSalesChannels[$connectionId][] = $uuid;
        }

        return $uuids;
    }

    //Todo: Remove if we migrate every data of the shipping method
    public function getDefaultAvailabilityRule(Context $context): ?string
    {
        if (isset($this->defaultAvailabilityRule)) {
            return $this->defaultAvailabilityRule;
        }

        /** @var RuleEntity $result */
        $result = $context->disableCache(function (Context $context) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', 'Cart >= 0'));

            return $this->ruleRepo->search($criteria, $context)->first();
        });

        $uuids = null;
        if ($result !== null) {
            $uuids = $result->getId();
        }

        return $uuids;
    }

    public function deleteMapping(string $entityUuid, string $connectionId, Context $context): void
    {
        foreach ($this->writeArray as $key => $writeMapping) {
            if ($writeMapping['connectionId'] === $connectionId && $writeMapping['entityUuid'] === $entityUuid) {
                unset($this->writeArray[$key]);
                $this->writeArray = array_values($this->writeArray);
                break;
            }
        }

        if (!empty($this->uuids)) {
            foreach ($this->uuids as $entityName => $entityArray) {
                foreach ($entityArray as $oldId => $uuid) {
                    if ($uuid === $entityUuid) {
                        unset($this->uuids[$entityName][$oldId]);
                        break;
                    }
                }
            }
        }

        /** @var IdSearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($entityUuid, $connectionId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('entityUuid', $entityUuid));
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->setLimit(1);

            return $this->migrationMappingRepo->searchIds($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            $this->migrationMappingRepo->delete(array_values($result->getData()), $context);
        }
    }

    public function bulkDeleteMapping(array $mappingUuids, Context $context): void
    {
        if (!empty($mappingUuids)) {
            $deleteArray = [];
            foreach ($mappingUuids as $uuid) {
                $deleteArray[] = [
                    'id' => $uuid,
                ];
            }

            $this->migrationMappingRepo->delete($deleteArray, $context);
        }
    }

    public function writeMapping(Context $context): void
    {
        if (empty($this->writeArray)) {
            return;
        }

        $this->entityWriter->insert(
            $this->mappingDefinition,
            $this->writeArray,
            WriteContext::createFromContext($context)
        );

        $this->writeArray = [];
        $this->uuids = [];
    }

    public function pushMapping(string $connectionId, string $entity, string $oldIdentifier, string $uuid): void
    {
        $this->saveMapping([
            'connectionId' => $connectionId,
            'entity' => $entity,
            'oldIdentifier' => $oldIdentifier,
            'entityUuid' => $uuid,
        ]);
    }

    public function pushValueMapping(string $connectionId, string $entity, string $oldIdentifier, string $value): void
    {
        $this->saveMapping([
            'connectionId' => $connectionId,
            'entity' => $entity,
            'oldIdentifier' => $oldIdentifier,
            'entityValue' => $value,
        ]);
    }

    public function getLowestRootCategoryUuid(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', null));

        $result = $this->categoryRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var CategoryCollection $collection */
            $collection = $result->getEntities();
            $collection->sortByPosition()->last()->getId();
        }

        return null;
    }

    protected function saveMapping(array $mapping): void
    {
        $entity = $mapping['entity'];
        $oldId = $mapping['oldIdentifier'];

        if (isset($mapping['entityUuid'])) {
            $newIdentifier = $mapping['entityUuid'];
        } else {
            $newIdentifier = $mapping['entityValue'];
        }

        $this->uuids[$entity][$oldId] = $newIdentifier;
        $this->writeArray[] = $mapping;
    }

    protected function saveListMapping(array $mapping): void
    {
        $entity = $mapping['entity'];
        $oldId = $mapping['oldIdentifier'];
        $uuid = $mapping['entityUuid'];

        $this->uuids[$entity][$oldId][] = $uuid;
        $this->writeArray[] = $mapping;
    }

    private function isUuidDuplicate(string $connectionId, string $entityName, string $id, string $uuid, Context $context): bool
    {
        foreach ($this->writeArray as $item) {
            if (
                $item['connectionId'] === $connectionId
                && $item['entity'] === $entityName
                && $item['oldIdentifier'] === $id
                && $item['entityUuid'] === $uuid
            ) {
                return true;
            }
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId, $entityName, $id, $uuid) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', $entityName));
            $criteria->addFilter(new EqualsFilter('oldIdentifier', $id));
            $criteria->addFilter(new EqualsFilter('entityUuid', $uuid));

            return $this->migrationMappingRepo->searchIds($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            return true;
        }

        return false;
    }

    private function searchLanguageInMapping(string $localeCode, Context $context): ?string
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($localeCode) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('entity', DefaultEntities::LANGUAGE));
            $criteria->addFilter(new EqualsFilter('oldIdentifier', $localeCode));
            $criteria->setLimit(1);

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();

            return $element->getEntityUuid();
        }

        return null;
    }

    /**
     * @throws LocaleNotFoundException
     */
    private function searchLocale(string $localeCode, Context $context): string
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($localeCode) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $localeCode));
            $criteria->setLimit(1);

            return $this->localeRepository->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var LocaleEntity $element */
            $element = $result->getEntities()->first();

            return $element->getId();
        }

        throw new LocaleNotFoundException($localeCode);
    }

    private function searchLanguageByLocale(string $localeUuid, Context $context): ?string
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($localeUuid) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('localeId', $localeUuid));
            $criteria->setLimit(1);

            return $this->languageRepository->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var LanguageEntity $element */
            $element = $result->getEntities()->first();

            return $element->getId();
        }

        return null;
    }
}
