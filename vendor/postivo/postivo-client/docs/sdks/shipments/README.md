# Shipments
(*shipments*)

## Overview

### Available Operations

* [status](#status) - Retrieve shipment details with status events
* [cancel](#cancel) - Cancel shipments
* [dispatch](#dispatch) - Dispatch a new shipment
* [documents](#documents) - Retrieve documents related to a shipment
* [price](#price) - Check the shipment price

## status

Retrieve the current status and details for one or more shipments by their `ids`. Pass the unique shipment IDs (returned when the shipments were created) as a path parameter. To query provide a list (up to **50** IDs per call). For larger batches, split the requests.

### Example Usage

<!-- UsageSnippet language="php" operationID="getStatus" method="get" path="/shipment/{ids}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->shipments->status(
    ids: [
        'A0043456',
    ]
);

if ($response->statusDetails !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                                                                           | Type                                                                                                                | Required                                                                                                            | Description                                                                                                         |
| ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `ids`                                                                                                               | array<*string*>                                                                                                     | :heavy_check_mark:                                                                                                  | Shipment IDs assigned by the system (comma-separated). The system accepts a maximum of **50** identifiers per call. |

### Response

**[?Operations\GetStatusResponse](../../Models/Operations/GetStatusResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## cancel

Cancel shipments that have not yet been processed by their `ids`. Pass the unique shipment IDs (returned when the shipment was created) as a parameter. To cancel multiple shipments at once, provide a list of IDs (up to **50** per call). For larger volumes, split the operation into multiple requests. Only shipments with status `ACCEPTED` can be cancelled.

If duplicate shipment IDs are provided in a single call, the API processes each unique ID only once.

### Example Usage

<!-- UsageSnippet language="php" operationID="cancelShipment" method="delete" path="/shipment/{ids}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->shipments->cancel(
    ids: [
        'A0043456',
    ]
);

if ($response->shipmentCancellations !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                                                                           | Type                                                                                                                | Required                                                                                                            | Description                                                                                                         |
| ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `ids`                                                                                                               | array<*string*>                                                                                                     | :heavy_check_mark:                                                                                                  | Shipment IDs assigned by the system (comma-separated). The system accepts a maximum of **50** identifiers per call. |

### Response

**[?Operations\CancelShipmentResponse](../../Models/Operations/CancelShipmentResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## dispatch

Send a shipment to one or multiple recipients in a single request. Provide a `Shipment` object. The object includes properties that define the shipment (recipient details, included documents, and optional settings). Some fields are required.

The system accepts up to **50** recipients per call. For larger volumes, split the operation into multiple requests.

### Example Usage

<!-- UsageSnippet language="php" operationID="shipmentDispatch" method="post" path="/shipment" -->
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

### Parameters

| Parameter                                                  | Type                                                       | Required                                                   | Description                                                |
| ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- |
| `$request`                                                 | [Components\Shipment](../../Models/Components/Shipment.md) | :heavy_check_mark:                                         | The request object to use for the request.                 |

### Response

**[?Operations\ShipmentDispatchResponse](../../Models/Operations/ShipmentDispatchResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## documents

Download documents related to a shipment by its `id`. Pass the unique shipment `id` (returned when the shipment was created) as a parameter. The second parameter is the document type to download. Supported document types include: dispatch certificate, envelope template, and EPO (in PDF or XML formats).

### Example Usage

<!-- UsageSnippet language="php" operationID="getDocuments" method="get" path="/shipment/{id}/document/{type}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;
use Postivo\Models\Operations;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->shipments->documents(
    id: 'A0043456',
    type: Operations\DocumentType::DispatchCert

);

if ($response->documentResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                                | Type                                                                     | Required                                                                 | Description                                                              | Example                                                                  |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| `id`                                                                     | *string*                                                                 | :heavy_check_mark:                                                       | Single shipment ID assigned by the system when the shipment was created. | A0043456                                                                 |
| `type`                                                                   | [Operations\DocumentType](../../Models/Operations/DocumentType.md)       | :heavy_check_mark:                                                       | Type of document/certificate to generate.                                | dispatch_cert                                                            |

### Response

**[?Operations\GetDocumentsResponse](../../Models/Operations/GetDocumentsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## price

Check the price of a shipment for one or multiple recipients. Provide a `Shipment` object in the request. Each object includes properties such as recipient details, included documents, and optional settings. Some fields are required.

The system accepts up to **50** recipients per call. For larger volumes, split the operation into multiple requests.

### Example Usage

<!-- UsageSnippet language="php" operationID="shipmentPrice" method="post" path="/shipment/price" -->
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

### Parameters

| Parameter                                                  | Type                                                       | Required                                                   | Description                                                |
| ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- |
| `$request`                                                 | [Components\Shipment](../../Models/Components/Shipment.md) | :heavy_check_mark:                                         | The request object to use for the request.                 |

### Response

**[?Operations\ShipmentPriceResponse](../../Models/Operations/ShipmentPriceResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |