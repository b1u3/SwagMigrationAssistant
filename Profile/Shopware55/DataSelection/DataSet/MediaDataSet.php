<?php declare(strict_types=1);

namespace SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet;

use SwagMigrationNext\Migration\DataSelection\DefaultEntities;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;

class MediaDataSet extends Shopware55DataSet
{
    public static function getEntity(): string
    {
        return DefaultEntities::MEDIA;
    }

    public function supports(string $profileName, string $entity): bool
    {
        return $profileName === Shopware55Profile::PROFILE_NAME && $entity === self::getEntity();
    }

    public function getApiRoute(): string
    {
        return 'SwagMigrationAssets';
    }

    public function getExtraQueryParameters(): array
    {
        return [];
    }
}
