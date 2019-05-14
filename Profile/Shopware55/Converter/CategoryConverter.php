<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware55\Converter;

use Shopware\Core\Framework\Context;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Mapping\MappingServiceInterface;
use SwagMigrationAssistant\Migration\Media\MediaFileServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware55\Exception\ParentEntityForChildNotFoundException;
use SwagMigrationAssistant\Profile\Shopware55\Logging\Shopware55LogTypes;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;

class CategoryConverter extends Shopware55Converter
{
    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    /**
     * @var MediaFileServiceInterface
     */
    private $mediaFileService;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var string
     */
    private $oldCategoryId;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    /**
     * @var string
     */
    private $locale;

    /**
     * @var string
     */
    private $runId;

    public function __construct(
        MappingServiceInterface $mappingService,
        MediaFileServiceInterface $mediaFileService,
        LoggingServiceInterface $loggingService
    ) {
        $this->mappingService = $mappingService;
        $this->mediaFileService = $mediaFileService;
        $this->loggingService = $loggingService;
    }

    public function getSupportedEntityName(): string
    {
        return DefaultEntities::CATEGORY;
    }

    public function getSupportedProfileName(): string
    {
        return Shopware55Profile::PROFILE_NAME;
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    /**
     * @throws ParentEntityForChildNotFoundException
     */
    public function convert(
        array $data,
        Context $context,
        MigrationContextInterface $migrationContext
    ): ConvertStruct {
        $this->connectionId = $migrationContext->getConnection()->getId();
        $this->context = $context;
        $this->oldCategoryId = $data['id'];
        $this->runId = $migrationContext->getRunUuid();

        if (!isset($data['_locale'])) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                Shopware55LogTypes::EMPTY_LOCALE,
                'Empty locale',
                'Category-Entity could not be converted cause of empty locale.',
                ['id' => $this->oldCategoryId]
            );

            return new ConvertStruct(null, $data);
        }
        $this->locale = $data['_locale'];

        // Legacy data which don't need a mapping or there is no equivalent field
        unset(
            $data['path'], // will be generated
            $data['left'],
            $data['right'],
            $data['added'],
            $data['changed'],
            $data['stream_id'],
            $data['metakeywords'],
            $data['metadescription'],
            $data['cmsheadline'],
            $data['meta_title'],
            $data['categorypath'],
            $data['shops'],

            // TODO check how to handle these
            $data['template'],
            $data['external_target'],
            $data['mediaID']
        );

        if (isset($data['parent'])) {
            $parentUuid = $this->mappingService->getUuid(
                $this->connectionId,
                DefaultEntities::CATEGORY,
                $data['parent'],
                $this->context
            );

            if ($parentUuid === null) {
                throw new ParentEntityForChildNotFoundException(DefaultEntities::CATEGORY, $this->oldCategoryId);
            }

            $converted['parentId'] = $parentUuid;
        }
        unset($data['parent']);

        $converted['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::CATEGORY,
            $this->oldCategoryId,
            $this->context
        );
        unset($data['id']);

        $this->convertValue($converted, 'description', $data, 'cmstext', self::TYPE_STRING);
        $this->convertValue($converted, 'position', $data, 'position', self::TYPE_INTEGER);
        $this->convertValue($converted, 'level', $data, 'level', self::TYPE_INTEGER);
        $this->convertValue($converted, 'active', $data, 'active', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'isBlog', $data, 'blog', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'external', $data, 'external');
        $this->convertValue($converted, 'hideFilter', $data, 'hidefilter', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'hideTop', $data, 'hidetop', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'productBoxLayout', $data, 'product_box_layout');
        $this->convertValue($converted, 'hideSortings', $data, 'hide_sortings', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'sortingIds', $data, 'sorting_ids');
        $this->convertValue($converted, 'facetIds', $data, 'facet_ids');

        if (isset($data['asset'])) {
            $converted['media'] = $this->getCategoryMedia($data['asset']);
            unset($data['asset']);
        }

        if (isset($data['attributes'])) {
            $converted['customFields'] = $this->getAttributes($data['attributes']);
        }
        unset($data['attributes']);

        $converted['translations'] = [];
        $this->setGivenCategoryTranslation($data, $converted);
        unset($data['_locale']);

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    private function setGivenCategoryTranslation(array &$data, array &$converted): void
    {
        $originalData = $data;
        $this->convertValue($converted, 'name', $data, 'description');

        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $data['_locale']) {
            return;
        }

        $localeTranslation = [];
        $localeTranslation['categoryId'] = $converted['id'];

        $this->convertValue($localeTranslation, 'name', $originalData, 'description');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::CATEGORY_TRANSLATION,
            $this->oldCategoryId . ':' . $data['_locale'],
            $this->context
        );

        try {
            $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $data['_locale'], $this->context);
        } catch (\Exception $exception) {
            $this->mappingService->deleteMapping($converted['id'], $this->connectionId, $this->context);
            throw $exception;
        }

        $localeTranslation['languageId'] = $languageUuid;

        if (isset($converted['customFields'])) {
            $localeTranslation['customFields'] = $converted['customFields'];
        }

        $converted['translations'][$languageUuid] = $localeTranslation;
    }

    private function getAttributes(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute => $value) {
            if ($attribute === 'id' || $attribute === 'categoryID') {
                continue;
            }
            $result[DefaultEntities::CATEGORY . '_' . $attribute] = $value;
        }

        return $result;
    }

    private function getCategoryMedia(array $media): array
    {
        $categoryMedia['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::MEDIA,
            $media['id'],
            $this->context
        );

        if (empty($media['name'])) {
            $media['name'] = $categoryMedia['id'];
        }

        $this->getMediaTranslation($categoryMedia, ['media' => $media]);

        $albumUuid = $this->mappingService->getUuid(
            $this->connectionId,
            DefaultEntities::MEDIA_FOLDER,
            $media['albumID'],
            $this->context
        );

        if ($albumUuid !== null) {
            $categoryMedia['mediaFolderId'] = $albumUuid;
        }

        $this->mediaFileService->saveMediaFile(
            [
                'runId' => $this->runId,
                'uri' => $media['uri'] ?? $media['path'],
                'fileName' => $media['name'],
                'fileSize' => (int) $media['file_size'],
                'mediaId' => $categoryMedia['id'],
            ]
        );

        return $categoryMedia;
    }

    private function getMediaTranslation(array &$media, array $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'name', $data['media'], 'name');
        $this->convertValue($localeTranslation, 'description', $data['media'], 'description');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::MEDIA_TRANSLATION,
            $data['media']['id'] . ':' . $this->locale,
            $this->context
        );

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $media['translations'][$languageUuid] = $localeTranslation;
    }
}
