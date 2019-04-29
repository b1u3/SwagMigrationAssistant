<?php declare(strict_types=1);

namespace SwagMigrationNext\Profile\Shopware55\Converter;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\DiscountSurcharge\Cart\DiscountSurchargeCollector;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodTranslation\ShippingMethodTranslationDefinition;
use Shopware\Core\Checkout\Shipping\ShippingMethodDefinition;
use Shopware\Core\Content\DeliveryTime\DeliveryTimeDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateDefinition;
use Shopware\Core\System\Country\Aggregate\CountryStateTranslation\CountryStateTranslationDefinition;
use Shopware\Core\System\Country\Aggregate\CountryTranslation\CountryTranslationDefinition;
use Shopware\Core\System\Country\CountryDefinition;
use Shopware\Core\System\Currency\Aggregate\CurrencyTranslation\CurrencyTranslationDefinition;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use SwagMigrationNext\Migration\Converter\ConvertStruct;
use SwagMigrationNext\Migration\Logging\LoggingServiceInterface;
use SwagMigrationNext\Migration\Mapping\MappingServiceInterface;
use SwagMigrationNext\Migration\MigrationContextInterface;
use SwagMigrationNext\Profile\Shopware55\Exception\AssociationEntityRequiredMissingException;
use SwagMigrationNext\Profile\Shopware55\Logging\Shopware55LogTypes;
use SwagMigrationNext\Profile\Shopware55\Premapping\OrderStateReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\PaymentMethodReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\SalutationReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\TransactionStateReader;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;

class OrderConverter extends Shopware55Converter
{
    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    /**
     * @var string
     */
    private $mainLocale;

    /**
     * @var TaxCalculator
     */
    private $taxCalculator;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    /**
     * @var string
     */
    private $oldId;

    /**
     * @var string
     */
    private $uuid;

    /**
     * @var string
     */
    private $runId;

    /**
     * @var string[]
     */
    private $requiredDataFieldKeys = [
        'customer',
        'currency',
        'currencyFactor',
        'payment',
        'paymentcurrency',
        'status',
    ];

    /**
     * @var string[]
     */
    private $requiredAddressDataFieldKeys = [
        'firstname',
        'lastname',
        'zipcode',
        'city',
        'street',
        'salutation',
    ];

    public function __construct(
        MappingServiceInterface $mappingService,
        TaxCalculator $taxCalculator,
        LoggingServiceInterface $loggingService
    ) {
        $this->mappingService = $mappingService;
        $this->taxCalculator = $taxCalculator;
        $this->loggingService = $loggingService;
    }

    public function getSupportedEntityName(): string
    {
        return OrderDefinition::getEntityName();
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
     * @throws AssociationEntityRequiredMissingException
     */
    public function convert(
        array $data,
        Context $context,
        MigrationContextInterface $migrationContext
    ): ConvertStruct {
        $this->oldId = $data['id'];
        $this->runId = $migrationContext->getRunUuid();

        $fields = $this->checkForEmptyRequiredDataFields($data, $this->requiredDataFieldKeys);
        if (empty($data['billingaddress']['id'])) {
            $fields[] = 'billingaddress';
        }
        if (isset($data['payment']) && empty($data['payment']['name'])) {
            $fields[] = 'paymentMethod';
        }

        if (!empty($fields)) {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data',
                sprintf('Order-Entity could not converted cause of empty necessary field(s): %s.', implode(', ', $fields)),
                [
                    'id' => $this->oldId,
                    'entity' => 'Order',
                    'fields' => $fields,
                ],
                \count($fields)
            );

            return new ConvertStruct(null, $data);
        }

        $this->mainLocale = $data['_locale'];
        unset($data['_locale']);
        $this->context = $context;
        $this->connectionId = $migrationContext->getConnection()->getId();

        $converted = [];
        $converted['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            OrderDefinition::getEntityName(),
            $data['id'],
            $this->context
        );
        unset($data['id']);
        $this->uuid = $converted['id'];

        $this->convertValue($converted, 'orderNumber', $data, 'ordernumber');

        $customerId = $this->mappingService->getUuid(
            $this->connectionId,
            CustomerDefinition::getEntityName(),
            $data['customer']['email'],
            $this->context
        );

        if ($customerId === null) {
            $customerId = $this->mappingService->getUuid(
                $this->connectionId,
                CustomerDefinition::getEntityName(),
                $data['userID'],
                $this->context
            );
        }

        if ($customerId === null) {
            throw new AssociationEntityRequiredMissingException(
                OrderDefinition::getEntityName(),
                CustomerDefinition::getEntityName()
            );
        }

        $converted['orderCustomer'] = [
            'customerId' => $customerId,
        ];

        $salutationUuid = $this->getSalutation($data['customer']['salutation']);
        if ($salutationUuid === null) {
            return new ConvertStruct(null, $data);
        }
        $converted['orderCustomer']['salutationId'] = $salutationUuid;

        $this->convertValue($converted['orderCustomer'], 'email', $data['customer'], 'email');
        $this->convertValue($converted['orderCustomer'], 'firstName', $data['customer'], 'firstname');
        $this->convertValue($converted['orderCustomer'], 'lastName', $data['customer'], 'lastname');
        $this->convertValue($converted['orderCustomer'], 'customerNumber', $data['customer'], 'customernumber');
        unset($data['userID'], $data['customer']);

        $this->convertValue($converted, 'currencyFactor', $data, 'currencyFactor', self::TYPE_FLOAT);
        $converted['currency'] = $this->getCurrency($data['paymentcurrency']);
        unset($data['currency'], $data['currencyFactor'], $data['paymentcurrency']);

        $this->convertValue($converted, 'orderDate', $data, 'ordertime', self::TYPE_DATETIME);

        $converted['stateId'] = $this->mappingService->getUuid(
            $this->connectionId,
            OrderStateReader::getMappingName(),
            (string) $data['status'],
            $this->context
        );

        if (!isset($converted['stateId'])) {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::UNKNOWN_ORDER_STATE,
                'Cannot find order state',
                'Order-Entity could not converted cause of unknown order state',
                [
                    'id' => $this->oldId,
                    'orderState' => $data['status'],
                ]
            );

            return new ConvertStruct(null, $data);
        }
        unset($data['status'], $data['orderstatus']);

        $shippingCosts = new CalculatedPrice(
            (float) $data['invoice_shipping'],
            (float) $data['invoice_shipping'],
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        if (isset($data['details'])) {
            $taxRules = $this->getTaxRules($data);
            $taxStatus = $this->getTaxStatus($data);

            $converted['lineItems'] = $this->getLineItems($data['details'], $converted, $taxRules, $taxStatus, $context);

            $converted['price'] = new CartPrice(
                (float) $data['invoice_amount_net'],
                (float) $data['invoice_amount'],
                (float) $data['invoice_amount'] - (float) $data['invoice_shipping'],
                new CalculatedTaxCollection([]),
                $taxRules,
                $taxStatus
            );

            $converted['shippingCosts'] = $shippingCosts;
        }
        unset(
            $data['net'],
            $data['taxfree'],
            $data['invoice_amount_net'],
            $data['invoice_amount'],
            $data['invoice_shipping_net'],
            $data['invoice_shipping'],
            $data['details']
        );

        $converted['deliveries'] = $this->getDeliveries($data, $converted, $shippingCosts);
        unset($data['trackingcode'], $data['shippingMethod'], $data['dispatchID'], $data['shippingaddress']);

        $this->getTransactions($data, $converted);
        unset($data['cleared'], $data['paymentstatus']);

        $billingAddress = $this->getAddress($data['billingaddress']);
        if (empty($billingAddress)) {
            $fields = ['billingaddress'];
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data',
                sprintf('Order-Entity could not converted cause of empty necessary field(s): %s.', implode(', ', $fields)),
                [
                    'id' => $this->oldId,
                    'entity' => 'Order',
                    'fields' => $fields,
                ],
                \count($fields)
            );

            return new ConvertStruct(null, $data);
        }
        $converted['billingAddressId'] = $billingAddress['id'];
        $converted['addresses'][] = $billingAddress;
        unset($data['billingaddress']);

        $converted['salesChannelId'] = Defaults::SALES_CHANNEL;
        if (isset($data['subshopID'])) {
            $salesChannelId = $this->mappingService->getUuid(
                $this->connectionId,
                SalesChannelDefinition::getEntityName(),
                $data['subshopID'],
                $this->context
            );

            if ($salesChannelId !== null) {
                $converted['salesChannelId'] = $salesChannelId;
                unset($data['subshopID']);
            }
        }

        if (isset($data['attributes'])) {
            $converted['attributes'] = $this->getAttributes($data['attributes']);
        }
        unset($data['attributes']);

        // Legacy data which don't need a mapping or there is no equivalent field
        unset(
            $data['invoice_shipping_tax_rate'],
            $data['transactionID'],
            $data['comment'],
            $data['customercomment'],
            $data['internalcomment'],
            $data['partnerID'],
            $data['temporaryID'],
            $data['referer'],
            $data['cleareddate'],
            $data['remote_addr'],
            $data['deviceType'],
            $data['is_proportional_calculation'],
            $data['changed'],
            $data['payment'],
            $data['paymentID'],

            // TODO check how to handle these
            $data['language'], // TODO use for sales channel information?
            $data['documents']
        );

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    private function getCurrency(array $originalData): array
    {
        $currency = [];
        $currency['id'] = $this->mappingService->getCurrencyUuid($this->connectionId, $originalData['currency'], $this->context);

        if (!isset($currency['id'])) {
            $currency['id'] = $this->mappingService->createNewUuid(
                $this->connectionId,
                CurrencyDefinition::getEntityName(),
                $originalData['id'],
                $this->context
            );
        }

        $this->convertValue($currency, 'isDefault', $originalData, 'standard', self::TYPE_BOOLEAN);
        $this->convertValue($currency, 'factor', $originalData, 'factor', self::TYPE_FLOAT);
        $this->convertValue($currency, 'position', $originalData, 'position', self::TYPE_INTEGER);

        $currency['symbol'] = html_entity_decode($originalData['templatechar']);
        $currency['placedInFront'] = ((int) $originalData['symbol_position']) > 16;
        $currency['decimalPrecision'] = $this->context->getCurrencyPrecision();

        $this->getCurrencyTranslation($currency, $originalData);
        $this->convertValue($currency, 'shortName', $originalData, 'currency');
        $this->convertValue($currency, 'name', $originalData, 'name');

        return $currency;
    }

    private function getCurrencyTranslation(array &$currency, array $data): void
    {
        $languageData = $this->mappingService->getDefaultLanguageUuid($this->context);
        if ($languageData['createData']['localeCode'] === $this->mainLocale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'shortName', $data, 'currency');
        $this->convertValue($localeTranslation, 'name', $data, 'name');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            CurrencyTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->mainLocale,
            $this->context
        );
        $languageData = $this->mappingService->getLanguageUuid($this->connectionId, $this->mainLocale, $this->context);

        if (isset($languageData['createData']) && !empty($languageData['createData'])) {
            $localeTranslation['language']['id'] = $languageData['uuid'];
            $localeTranslation['language']['localeId'] = $languageData['createData']['localeId'];
            $localeTranslation['language']['name'] = $languageData['createData']['localeCode'];
        } else {
            $localeTranslation['languageId'] = $languageData['uuid'];
        }

        $currency['translations'][$languageData['uuid']] = $localeTranslation;
    }

    private function getTransactions(array $data, array &$converted): void
    {
        $converted['transactions'] = [];
        if (!isset($converted['lineItems'])) {
            return;
        }

        /** @var CartPrice $cartPrice */
        $cartPrice = $converted['price'];
        $stateId = $this->mappingService->getUuid(
            $this->connectionId,
            TransactionStateReader::getMappingName(),
            $data['cleared'],
            $this->context
        );

        if ($stateId === null) {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::UNKNOWN_TRANSACTION_STATE,
                'Cannot find transaction state',
                'Transaction-Order-Entity could not converted cause of unknown transaction state',
                [
                    'id' => $this->oldId,
                    'transactionState' => $data['cleared'],
                ]
            );

            return;
        }

        $id = $this->mappingService->createNewUuid(
            $this->connectionId,
            OrderTransactionDefinition::getEntityName(),
            $this->oldId,
            $this->context
        );

        $paymentMethodUuid = $this->getPaymentMethod($data);

        if ($paymentMethodUuid === null) {
            return;
        }

        $transactions = [
            [
                'id' => $id,
                'paymentMethodId' => $paymentMethodUuid,
                'stateId' => $stateId,
                'amount' => new CalculatedPrice(
                    $cartPrice->getTotalPrice(),
                    $cartPrice->getTotalPrice(),
                    $cartPrice->getCalculatedTaxes(),
                    $cartPrice->getTaxRules()
                ),
            ],
        ];

        $converted['transactions'] = $transactions;
    }

    private function getPaymentMethod(array $originalData): ?string
    {
        $paymentMethodUuid = $this->mappingService->getUuid(
            $this->connectionId,
            PaymentMethodReader::getMappingName(),
            $originalData['payment']['id'],
            $this->context
        );

        if ($paymentMethodUuid === null) {
            $this->loggingService->addInfo(
                $this->runId,
                Shopware55LogTypes::UNKNOWN_PAYMENT_METHOD,
                'Cannot find payment method',
                'Order-Transaction-Entity could not converted cause of unknown payment method',
                [
                    'id' => $this->oldId,
                    'entity' => OrderDefinition::getEntityName(),
                    'paymentMethod' => $originalData['payment']['id'],
                ]
            );
        }

        return $paymentMethodUuid;
    }

    private function getAddress(array $originalData): array
    {
        $fields = $this->checkForEmptyRequiredDataFields($originalData, $this->requiredAddressDataFieldKeys);
        if (!empty($fields)) {
            $this->loggingService->addInfo(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data fields for address',
                sprintf('Address-Entity could not converted cause of empty necessary field(s): %s.', implode(', ', $fields)),
                [
                    'id' => $this->oldId,
                    'uuid' => $this->uuid,
                    'entity' => 'Address',
                    'fields' => $fields,
                ],
                \count($fields)
            );

            return [];
        }

        $address = [];
        $address['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            OrderAddressDefinition::getEntityName(),
            $originalData['id'],
            $this->context
        );

        $address['countryId'] = $this->mappingService->getUuid(
            $this->connectionId,
            CountryDefinition::getEntityName(),
            $originalData['countryID'],
            $this->context
        );

        if (isset($originalData['country']) && $address['countryId'] === null) {
            $address['country'] = $this->getCountry($originalData['country']);
        }

        if (isset($originalData['stateID'])) {
            $address['countryStateId'] = $this->mappingService->getUuid(
                $this->connectionId,
                CountryStateDefinition::getEntityName(),
                $originalData['stateID'],
                $this->context
            );

            if (isset($address['countryStateId'], $originalData['state']) && ($address['countryId'] !== null || isset($address['country']['id']))) {
                $address['countryState'] = $this->getCountryState($originalData['state'], $address['countryId'] ?? $address['country']['id']);
            }
        }

        $salutationUuid = $this->getSalutation($originalData['salutation']);
        if ($salutationUuid === null) {
            return [];
        }
        $address['salutationId'] = $salutationUuid;

        $this->convertValue($address, 'firstName', $originalData, 'firstname');
        $this->convertValue($address, 'lastName', $originalData, 'lastname');
        $this->convertValue($address, 'zipcode', $originalData, 'zipcode');
        $this->convertValue($address, 'city', $originalData, 'city');
        $this->convertValue($address, 'company', $originalData, 'company');
        $this->convertValue($address, 'street', $originalData, 'street');
        $this->convertValue($address, 'department', $originalData, 'department');
        $this->convertValue($address, 'title', $originalData, 'title');
        if (isset($originalData['ustid'])) {
            $this->convertValue($address, 'vatId', $originalData, 'ustid');
        }
        $this->convertValue($address, 'phoneNumber', $originalData, 'phone');
        $this->convertValue($address, 'additionalAddressLine1', $originalData, 'additional_address_line1');
        $this->convertValue($address, 'additionalAddressLine2', $originalData, 'additional_address_line2');

        return $address;
    }

    private function getCountry(array $oldCountryData): array
    {
        $country = [];
        if (isset($oldCountryData['countryiso'], $oldCountryData['iso3'])) {
            $country['id'] = $this->mappingService->getCountryUuid(
                $oldCountryData['id'],
                $oldCountryData['countryiso'],
                $oldCountryData['iso3'],
                $this->connectionId,
                $this->context
            );
        }

        if (!isset($country['id'])) {
            $country['id'] = $this->mappingService->createNewUuid(
                $this->connectionId,
                CountryDefinition::getEntityName(),
                $oldCountryData['id'],
                $this->context
            );
        }

        $this->getCountryTranslation($country, $oldCountryData);
        $this->convertValue($country, 'iso', $oldCountryData, 'countryiso');
        $this->convertValue($country, 'position', $oldCountryData, 'position', self::TYPE_INTEGER);
        $this->convertValue($country, 'taxFree', $oldCountryData, 'taxfree', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'taxfreeForVatId', $oldCountryData, 'taxfree_ustid', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'taxfreeVatidChecked', $oldCountryData, 'taxfree_ustid_checked', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'active', $oldCountryData, 'active', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'iso3', $oldCountryData, 'iso3');
        $this->convertValue($country, 'displayStateInRegistration', $oldCountryData, 'display_state_in_registration', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'forceStateInRegistration', $oldCountryData, 'force_state_in_registration', self::TYPE_BOOLEAN);
        $this->convertValue($country, 'name', $oldCountryData, 'countryname');

        return $country;
    }

    private function getCountryTranslation(array &$country, array $data): void
    {
        $languageData = $this->mappingService->getDefaultLanguageUuid($this->context);
        if ($languageData['createData']['localeCode'] === $this->mainLocale) {
            return;
        }

        $localeTranslation = [];
        $localeTranslation['countryId'] = $country['id'];

        $this->convertValue($localeTranslation, 'name', $data, 'countryname');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            CountryTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->mainLocale,
            $this->context
        );

        $languageData = $this->mappingService->getLanguageUuid($this->connectionId, $this->mainLocale, $this->context);
        if (isset($languageData['createData']) && !empty($languageData['createData'])) {
            $localeTranslation['language']['id'] = $languageData['uuid'];
            $localeTranslation['language']['localeId'] = $languageData['createData']['localeId'];
            $localeTranslation['language']['name'] = $languageData['createData']['localeCode'];
        } else {
            $localeTranslation['languageId'] = $languageData['uuid'];
        }

        $country['translations'][$languageData['uuid']] = $localeTranslation;
    }

    private function getCountryState(array $oldStateData, string $newCountryId): array
    {
        $state = [];
        $state['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            CountryStateDefinition::getEntityName(),
            $oldStateData['id'],
            $this->context
        );
        $state['countryId'] = $newCountryId;

        $this->getCountryStateTranslation($state, $oldStateData);
        $this->convertValue($state, 'shortCode', $oldStateData, 'shortcode');
        $this->convertValue($state, 'position', $oldStateData, 'position', self::TYPE_INTEGER);
        $this->convertValue($state, 'active', $oldStateData, 'active', self::TYPE_BOOLEAN);
        $this->convertValue($state, 'name', $oldStateData, 'name');

        return $state;
    }

    private function getCountryStateTranslation(array &$state, array $data): void
    {
        $languageData = $this->mappingService->getDefaultLanguageUuid($this->context);
        if ($languageData['createData']['localeCode'] === $this->mainLocale) {
            return;
        }

        $localeTranslation = [];
        $translation['countryStateId'] = $state['id'];

        $this->convertValue($translation, 'name', $data, 'name');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            CountryStateTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->mainLocale,
            $this->context
        );

        $languageData = $this->mappingService->getLanguageUuid($this->connectionId, $this->mainLocale, $this->context);
        if (isset($languageData['createData']) && !empty($languageData['createData'])) {
            $localeTranslation['language']['id'] = $languageData['uuid'];
            $localeTranslation['language']['localeId'] = $languageData['createData']['localeId'];
            $localeTranslation['language']['name'] = $languageData['createData']['localeCode'];
        } else {
            $localeTranslation['languageId'] = $languageData['uuid'];
        }

        $state['translations'][$languageData['uuid']] = $localeTranslation;
    }

    private function getDeliveries(array $data, array $converted, CalculatedPrice $shippingCosts): array
    {
        $deliveries = [];

        $delivery = [
            'id' => $this->mappingService->createNewUuid(
                $this->connectionId,
                OrderDeliveryDefinition::getEntityName(),
                $this->oldId,
                $this->context
            ),
            'stateId' => $converted['stateId'],
            'shippingDateEarliest' => $converted['orderDate'],
            'shippingDateLatest' => $converted['orderDate'],
        ];

        if (isset($data['shippingMethod']['id'])) {
            $delivery['shippingMethod'] = $this->getShippingMethod($data['shippingMethod']);
        } else {
            return [];
        }

        if (isset($data['shippingaddress']['id'])) {
            $delivery['shippingOrderAddress'] = $this->getAddress($data['shippingaddress']);
        }

        if (!isset($delivery['shippingOrderAddress'])) {
            $delivery['shippingOrderAddress'] = $this->getAddress($data['billingaddress']);
        }

        if (isset($data['trackingcode']) && $data['trackingcode'] !== '') {
            $delivery['trackingCode'] = $data['trackingcode'];
        }

        if (isset($converted['lineItems'])) {
            $positions = [];
            foreach ($converted['lineItems'] as $lineItem) {
                $positions[] = [
                    'id' => $this->mappingService->createNewUuid(
                        $this->connectionId,
                        OrderDeliveryPositionDefinition::getEntityName(),
                        $lineItem['id'],
                        $this->context
                    ),
                    'orderLineItemId' => $lineItem['id'],
                    'price' => $lineItem['price'],
                ];
            }

            $delivery['positions'] = $positions;
        }
        $delivery['shippingCosts'] = $shippingCosts;

        $deliveries[] = $delivery;

        return $deliveries;
    }

    private function getShippingMethod(array $originalData): array
    {
        $shippingMethod = [];
        $shippingMethod['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ShippingMethodDefinition::getEntityName(),
            $originalData['id'],
            $this->context
        );

        $this->getShippingMethodTranslation($shippingMethod, $originalData);
        $this->convertValue($shippingMethod, 'bindShippingfree', $originalData, 'bind_shippingfree', self::TYPE_BOOLEAN);
        $this->convertValue($shippingMethod, 'active', $originalData, 'active', self::TYPE_BOOLEAN);
        $this->convertValue($shippingMethod, 'shippingFree', $originalData, 'shippingfree', self::TYPE_FLOAT);
        $this->convertValue($shippingMethod, 'name', $originalData, 'name');
        $this->convertValue($shippingMethod, 'description', $originalData, 'description');
        $this->convertValue($shippingMethod, 'comment', $originalData, 'comment');

        $defaultDeliveryTimeUuid = $this->mappingService->getUuid(
            $this->connectionId,
            DeliveryTimeDefinition::getEntityName(),
            'default_delivery_time',
            $this->context
        );

        if ($defaultDeliveryTimeUuid !== null) {
            $shippingMethod['deliveryTimeId'] = $defaultDeliveryTimeUuid;
        } else {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data fields',
                'Order-Entity could not converted cause of empty necessary field(s): delivery_time.',
                [
                    'id' => $this->oldId,
                    'entity' => OrderDefinition::getEntityName(),
                    'fields' => ['delivery_time'],
                ],
                1
            );
        }

        $defaultAvailabilityRuleUuid = $this->mappingService->getDefaultAvailabilityRule($this->context);
        if ($defaultAvailabilityRuleUuid !== null) {
            $shippingMethod['availabilityRuleId'] = $defaultAvailabilityRuleUuid;
        } else {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data fields',
                'Order-Entity could not converted cause of empty necessary field(s): availability_rule_id.',
                [
                    'id' => $this->oldId,
                    'entity' => OrderDefinition::getEntityName(),
                    'fields' => ['availability_rule_id'],
                ],
                1
            );
        }

        return $shippingMethod;
    }

    private function getShippingMethodTranslation(array &$shippingMethod, array $data): void
    {
        $languageData = $this->mappingService->getDefaultLanguageUuid($this->context);
        if ($languageData['createData']['localeCode'] === $this->mainLocale) {
            return;
        }

        $localeTranslation = [];
        $localeTranslation['shippingMethodId'] = $shippingMethod['id'];

        $this->convertValue($localeTranslation, 'name', $data, 'name');
        $this->convertValue($localeTranslation, 'description', $data, 'description');
        $this->convertValue($localeTranslation, 'comment', $data, 'comment');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ShippingMethodTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->mainLocale,
            $this->context
        );

        $languageData = $this->mappingService->getLanguageUuid($this->connectionId, $this->mainLocale, $this->context);
        if (isset($languageData['createData']) && !empty($languageData['createData'])) {
            $localeTranslation['language']['id'] = $languageData['uuid'];
            $localeTranslation['language']['localeId'] = $languageData['createData']['localeId'];
            $localeTranslation['language']['name'] = $languageData['createData']['localeCode'];
        } else {
            $localeTranslation['languageId'] = $languageData['uuid'];
        }

        $shippingMethod['translations'][$languageData['uuid']] = $localeTranslation;
    }

    private function getLineItems(array $originalData, array &$converted, TaxRuleCollection $taxRules, string $taxStatus, Context $context): array
    {
        $lineItems = [];

        foreach ($originalData as $originalLineItem) {
            $isProduct = (int) $originalLineItem['modus'] === 0 && (int) $originalLineItem['articleID'] !== 0;

            $lineItem = [
                'id' => $this->mappingService->createNewUuid(
                    $this->connectionId,
                    OrderLineItemDefinition::getEntityName(),
                    $originalLineItem['id'],
                    $this->context
                ),
            ];

            if ($isProduct) {
                if ($originalLineItem['articleordernumber'] !== null) {
                    $lineItem['identifier'] = $this->mappingService->getUuid(
                        $this->connectionId,
                        ProductDefinition::getEntityName(),
                        $originalLineItem['articleordernumber'],
                        $this->context
                    );
                }

                if (!isset($lineItem['identifier'])) {
                    $lineItem['identifier'] = 'unmapped-product-' . $originalLineItem['articleordernumber'] . '-' . $originalLineItem['articleID'];
                }

                $lineItem['type'] = LineItem::PRODUCT_LINE_ITEM_TYPE;
            } else {
                $this->convertValue($lineItem, 'identifier', $originalLineItem, 'articleordernumber');

                $lineItem['type'] = DiscountSurchargeCollector::DATA_KEY;
            }

            $this->convertValue($lineItem, 'quantity', $originalLineItem, 'quantity', self::TYPE_INTEGER);
            $this->convertValue($lineItem, 'label', $originalLineItem, 'name');

            $calculatedTax = null;
            $totalPrice = $lineItem['quantity'] * $originalLineItem['price'];
            if ($taxStatus === CartPrice::TAX_STATE_NET) {
                $calculatedTax = $this->taxCalculator->calculateNetTaxes($totalPrice, $context->getCurrencyPrecision(), $taxRules);
            }

            if ($taxStatus === CartPrice::TAX_STATE_GROSS) {
                $calculatedTax = $this->taxCalculator->calculateGrossTaxes($totalPrice, $context->getCurrencyPrecision(), $taxRules);
            }

            if ($calculatedTax !== null) {
                $lineItem['price'] = new CalculatedPrice(
                    (float) $originalLineItem['price'],
                    (float) $totalPrice,
                    $calculatedTax,
                    $taxRules,
                    (int) $lineItem['quantity']
                );

                $lineItem['priceDefinition'] = new QuantityPriceDefinition(
                    (float) $originalLineItem['price'],
                    $taxRules,
                    $context->getCurrencyPrecision()
                );
            }

            if (!isset($lineItem['identifier'])) {
                $this->loggingService->addInfo(
                    $this->runId,
                    Shopware55LogTypes::EMPTY_LINE_ITEM_IDENTIFIER,
                    'Line item could not converted',
                    'Order-Line-Item-Entity could not converted cause of empty identifier',
                    [
                        'orderId' => $this->oldId,
                        'lineItemId' => $originalLineItem['id'],
                    ]
                );

                continue;
            }

            $lineItems[] = $lineItem;
        }

        return $lineItems;
    }

    private function getTaxRules(array $originalData): TaxRuleCollection
    {
        $taxRates = array_unique(array_column($originalData['details'], 'tax_rate'));

        $taxRules = [];
        foreach ($taxRates as $taxRate) {
            $taxRules[] = new TaxRule((float) $taxRate);
        }

        return new TaxRuleCollection($taxRules);
    }

    private function getTaxStatus(array $originalData): string
    {
        $taxStatus = CartPrice::TAX_STATE_GROSS;
        if (isset($originalData['net']) && (bool) $originalData['net']) {
            $taxStatus = CartPrice::TAX_STATE_NET;
        }
        if (isset($originalData['isTaxFree']) && (bool) $originalData['isTaxFree']) {
            $taxStatus = CartPrice::TAX_STATE_FREE;
        }

        return $taxStatus;
    }

    private function getSalutation(string $salutation): ?string
    {
        $salutationUuid = $this->mappingService->getUuid(
            $this->connectionId,
            SalutationReader::getMappingName(),
            $salutation,
            $this->context
        );

        if ($salutationUuid === null) {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::UNKNOWN_CUSTOMER_SALUTATION,
                'Cannot find customer salutation for order',
                'Order-Entity could not converted cause of unknown customer salutation',
                [
                    'id' => $this->oldId,
                    'entity' => OrderDefinition::getEntityName(),
                    'salutation' => $salutation,
                ]
            );
        }

        return $salutationUuid;
    }

    private function getAttributes(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute => $value) {
            if ($attribute === 'id' || $attribute === 'orderID') {
                continue;
            }
            $result[OrderDefinition::getEntityName() . '_' . $attribute] = $value;
        }

        return $result;
    }
}
