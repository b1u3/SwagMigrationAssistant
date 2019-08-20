import MigrationApiService from '../core/service/api/swag-migration.api.service';
import ProcessStoreInitService from '../core/service/migration/swag-migration-process-store-init.service';
import UiStoreInitService from '../core/service/migration/swag-migration-ui-store-init.service';

const { Application } = Shopware;

Application.addServiceProvider('migrationService', (container) => {
    const initContainer = Application.getContainer('init');
    return new MigrationApiService(initContainer.httpClient, container.loginService);
});

Application.addServiceProvider('processStoreInitService', (container) => {
    return new ProcessStoreInitService(container.migrationService);
});

Application.addServiceProvider('uiStoreInitService', (container) => {
    return new UiStoreInitService(container.migrationService);
});
