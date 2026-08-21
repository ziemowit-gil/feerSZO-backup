[![Packagist Version](https://img.shields.io/packagist/v/postivo/postivo-client)](https://packagist.org/packages/postivo/postivo-client)
[![GitHub License](https://img.shields.io/github/license/postivo/php-client)](https://github.com/postivo/php-client/blob/main/LICENSE)
[![Static Badge](https://img.shields.io/badge/built_by-Speakeasy-yellow)](https://www.speakeasy.com/?utm_source=postivo&utm_campaign=php)
# POSTIVO.PL REST API Client SDK for PHP ≥ 8.2 (postivo/postivo-client)

This package provides the **POSTIVO.PL Hybrid Mail Services SDK** for PHP (≥ 8.2), allowing you to dispatch shipments directly from your application via the [POSTIVO.PL](https://postivo.pl) platform.

## Additional documentation:

Comprehensive documentation of all methods and types is available below in [Available Resources and Operations](#available-resources-and-operations).

You can also refer to the [REST API v1 documentation](https://api.postivo.pl/rest/v1/) for additional details about this SDK.
<!-- No Summary [summary] -->
<!-- Start Table of Contents [toc] -->
## Table of Contents
<!-- $toc-max-depth=2 -->
* [POSTIVO.PL REST API Client SDK for PHP ≥ 8.2 (postivo/postivo-client)](#postivopl-rest-api-client-sdk-for-php-82-postivopostivo-client)
  * [Additional documentation:](#additional-documentation)
  * [SDK Installation](#sdk-installation)
  * [Requirements:](#requirements)
  * [SDK Example Usage](#sdk-example-usage)
  * [Authentication](#authentication)
  * [Available Resources and Operations](#available-resources-and-operations)
  * [Retries](#retries)
  * [Error Handling](#error-handling)
  * [Server Selection](#server-selection)
* [Development](#development)
  * [Maturity](#maturity)
  * [Contributions](#contributions)

<!-- End Table of Contents [toc] -->

<!-- Start SDK Installation [installation] -->
## SDK Installation

The SDK relies on [Composer](https://getcomposer.org/) to manage its dependencies.

To install the SDK and add it as a dependency to an existing `composer.json` file:
```bash
composer require "postivo/postivo-client"
```
<!-- End SDK Installation [installation] -->
## Requirements:
- Minimal required PHP version: 8.2
<!-- Start SDK Example Usage [usage] -->
## SDK Example Usage

### Sending Shipment to single recipient

This example demonstrates simple sending Shipment to a single recipient:

```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Brick\DateTime\LocalDate;
use Postivo;
use Postivo\Models\Components;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();

$request = new Components\Shipment(
    recipients: new Components\RecipientInline(
        name: 'Jan Nowak',
        name2: 'Firma testowa Sp. z o.o.',
        address: 'ul. Testowa',
        homeNumber: '23',
        flatNumber: '2',
        postCode: '00-999',
        city: 'Warszawa',
        phoneNumber: '+48666666666',
        postscript: 'Komunikat',
        customId: '1234567890',
    ),
    documents: [
        new Components\DocumentPdf(
            fileStream: '<document_1 content encoded to base64>',
            fileName: 'document1.pdf',
        ),
        new Components\DocumentPdf(
            fileStream: '<document_2 content encoded to base64>',
            fileName: 'document2.pdf',
        ),
    ],
    options: new Components\ShipmentOptions(
        predefinedConfigId: 2670,
    ),
);

$response = $sdk->shipments->dispatch(
    request: $request
);

if ($response->shipmentDetails !== null) {
    // handle response
}
```

### Checking the price of a shipment for single recipient

This example demonstrates simple checking the price of a Shipment to a single recipient:

```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Brick\DateTime\LocalDate;
use Postivo;
use Postivo\Models\Components;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();

$request = new Components\Shipment(
    recipients: new Components\RecipientInline(
        name: 'Jan Nowak',
        name2: 'Firma testowa Sp. z o.o.',
        address: 'ul. Testowa',
        homeNumber: '23',
        flatNumber: '2',
        postCode: '00-999',
        city: 'Warszawa',
        phoneNumber: '+48666666666',
        postscript: 'Komunikat',
        customId: '1234567890',
    ),
    documents: [
        new Components\DocumentPdf(
            fileStream: '<document_1 content encoded to base64>',
            fileName: 'document1.pdf',
        ),
        new Components\DocumentPdf(
            fileStream: '<document_2 content encoded to base64>',
            fileName: 'document2.pdf',
        ),
    ],
    options: new Components\ShipmentOptions(
        predefinedConfigId: 2670,
    ),
);

$response = $sdk->shipments->price(
    request: $request
);

if ($response->shipmentPrices !== null) {
    // handle response
}
```
<!-- End SDK Example Usage [usage] -->

<!-- Start Authentication [security] -->
## Authentication

### Per-Client Security Schemes

This SDK supports the following security scheme globally:

| Name     | Type | Scheme      |
| -------- | ---- | ----------- |
| `bearer` | http | HTTP Bearer |

To authenticate with the API the `bearer` parameter must be set when initializing the SDK. For example:
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->get(

);

if ($response->accountResponse !== null) {
    // handle response
}
```
<!-- End Authentication [security] -->

<!-- Start Available Resources and Operations [operations] -->
## Available Resources and Operations

<details open>
<summary>Available methods</summary>

### [accounts](docs/sdks/accounts/README.md)

* [get](docs/sdks/accounts/README.md#get) - Retrieve account details
* [getSubaccount](docs/sdks/accounts/README.md#getsubaccount) - Get subaccount details

#### [addressBook->contacts](docs/sdks/contacts/README.md)

* [list](docs/sdks/contacts/README.md#list) - List contacts
* [add](docs/sdks/contacts/README.md#add) - Add a new contact
* [get](docs/sdks/contacts/README.md#get) - Retrieve contact details
* [update](docs/sdks/contacts/README.md#update) - Update a contact
* [delete](docs/sdks/contacts/README.md#delete) - Delete a contact
* [removeFromGroup](docs/sdks/contacts/README.md#removefromgroup) - Remove a contact from a group
* [addToGroup](docs/sdks/contacts/README.md#addtogroup) - Add a contact to a group

#### [addressBook->contacts->byExtId](docs/sdks/byextid/README.md)

* [get](docs/sdks/byextid/README.md#get) - Retrieve contact details by EXT_ID
* [update](docs/sdks/byextid/README.md#update) - Update a contact by EXT_ID
* [delete](docs/sdks/byextid/README.md#delete) - Delete a contact by EXT_ID
* [removeFromGroup](docs/sdks/byextid/README.md#removefromgroup) - Remove a contact from a group by EXT_ID
* [addToGroup](docs/sdks/byextid/README.md#addtogroup) - Add a contact to a group by EXT_ID

#### [addressBook->groups](docs/sdks/groups/README.md)

* [list](docs/sdks/groups/README.md#list) - List groups
* [add](docs/sdks/groups/README.md#add) - Add a new group
* [get](docs/sdks/groups/README.md#get) - Retrieve group details
* [update](docs/sdks/groups/README.md#update) - Update a group
* [delete](docs/sdks/groups/README.md#delete) - Delete a group

### [common](docs/sdks/common/README.md)

* [ping](docs/sdks/common/README.md#ping) - Check API availability and version

### [metadata](docs/sdks/metadata/README.md)

* [list](docs/sdks/metadata/README.md#list) - List metadata
* [getPredefinedConfigs](docs/sdks/metadata/README.md#getpredefinedconfigs) - List predefined configs

### [senders](docs/sdks/senders/README.md)

* [list](docs/sdks/senders/README.md#list) - List senders
* [add](docs/sdks/senders/README.md#add) - Add a new sender
* [delete](docs/sdks/senders/README.md#delete) - Delete a sender
* [verify](docs/sdks/senders/README.md#verify) - Verify sender

### [shipments](docs/sdks/shipments/README.md)

* [status](docs/sdks/shipments/README.md#status) - Retrieve shipment details with status events
* [cancel](docs/sdks/shipments/README.md#cancel) - Cancel shipments
* [dispatch](docs/sdks/shipments/README.md#dispatch) - Dispatch a new shipment
* [documents](docs/sdks/shipments/README.md#documents) - Retrieve documents related to a shipment
* [price](docs/sdks/shipments/README.md#price) - Check the shipment price

</details>
<!-- End Available Resources and Operations [operations] -->

<!-- Start Retries [retries] -->
## Retries

Some of the endpoints in this SDK support retries. If you use the SDK without any configuration, it will fall back to the default retry strategy provided by the API. However, the default retry strategy can be overridden on a per-operation basis, or across the entire SDK.

To change the default retry strategy for a single API call, simply provide an `Options` object built with a `RetryConfig` object to the call:
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;
use Postivo\Utils\Retry;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->get(
    options: Utils\Options->builder()->setRetryConfig(
        new Retry\RetryConfigBackoff(
            initialInterval: 1,
            maxInterval:     50,
            exponent:        1.1,
            maxElapsedTime:  100,
            retryConnectionErrors: false,
        ))->build()
);

if ($response->accountResponse !== null) {
    // handle response
}
```

If you'd like to override the default retry strategy for all operations that support retries, you can pass a `RetryConfig` object to the `SDKBuilder->setRetryConfig` function when initializing the SDK:
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;
use Postivo\Utils\Retry;

$sdk = Postivo\Client::builder()
    ->setRetryConfig(
        new Retry\RetryConfigBackoff(
            initialInterval: 1,
            maxInterval:     50,
            exponent:        1.1,
            maxElapsedTime:  100,
            retryConnectionErrors: false,
        )
  )
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->get(

);

if ($response->accountResponse !== null) {
    // handle response
}
```
<!-- End Retries [retries] -->

<!-- Start Error Handling [errors] -->
## Error Handling

Handling errors in this SDK should largely match your expectations. All operations return a response object or throw an exception.

By default an API error will raise a `Errors\APIException` exception, which has the following properties:

| Property       | Type                                    | Description           |
|----------------|-----------------------------------------|-----------------------|
| `$message`     | *string*                                | The error message     |
| `$statusCode`  | *int*                                   | The HTTP status code  |
| `$rawResponse` | *?\Psr\Http\Message\ResponseInterface*  | The raw HTTP response |
| `$body`        | *string*                                | The response content  |

When custom error responses are specified for an operation, the SDK may also throw their associated exception. You can refer to respective *Errors* tables in SDK docs for more details on possible exception types for each operation. For example, the `get` method throws the following exceptions:

| Error Type           | Status Code   | Content Type             |
| -------------------- | ------------- | ------------------------ |
| Errors\ErrorResponse | 401, 403, 4XX | application/problem+json |
| Errors\ErrorResponse | 5XX           | application/problem+json |

### Example

```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;
use Postivo\Models\Errors;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();

try {
    $response = $sdk->accounts->get(

    );

    if ($response->accountResponse !== null) {
        // handle response
    }
} catch (Errors\ErrorResponseThrowable $e) {
    // handle $e->$container data
    throw $e;
} catch (Errors\ErrorResponseThrowable $e) {
    // handle $e->$container data
    throw $e;
} catch (Errors\APIException $e) {
    // handle default exception
    throw $e;
}
```
<!-- End Error Handling [errors] -->

<!-- Start Server Selection [server] -->
## Server Selection

### Select Server by Name

You can override the default server globally using the `setServer(string $serverName)` builder method when initializing the SDK client instance. The selected server will then be used as the default on the operations that use it. This table lists the names associated with the available servers:

| Name      | Server                                   | Description           |
| --------- | ---------------------------------------- | --------------------- |
| `prod`    | `https://api.postivo.pl/rest/v1`         | Production system     |
| `sandbox` | `https://api.postivo.pl/rest-sandbox/v1` | Test system (SANDBOX) |

#### Example

```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setServer('sandbox')
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->get(

);

if ($response->accountResponse !== null) {
    // handle response
}
```

### Override Server URL Per-Client

The default server can also be overridden globally using the `setServerUrl(string $serverUrl)` builder method when initializing the SDK client instance. For example:
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setServerURL('https://api.postivo.pl/rest/v1')
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->get(

);

if ($response->accountResponse !== null) {
    // handle response
}
```
<!-- End Server Selection [server] -->

<!-- Placeholder for Future Speakeasy SDK Sections -->

# Development

## Maturity

This SDK is in beta, and there may be breaking changes between versions without a major version update. Therefore, we recommend pinning usage
to a specific package version. This way, you can install the same version each time without breaking changes unless you are intentionally
looking for the latest version.

## Contributions

While we value open-source contributions to this SDK, this library is generated programmatically. Any manual changes added to internal files will be overwritten on the next generation. 
We look forward to hearing your feedback. Feel free to open a PR or an issue with a proof of concept and we'll do our best to include it in a future release.