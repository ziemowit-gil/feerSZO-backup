# Metadata
(*metadata*)

## Overview

### Available Operations

* [list](#list) - List metadata
* [getPredefinedConfigs](#getpredefinedconfigs) - List predefined configs

## list

Retrieve a complete list of all types for shipments and their possible values. The method has no body and takes no parameters. The response includes metadata such as carrier types, service types, and more.

### Example Usage

<!-- UsageSnippet language="php" operationID="listMetadata" method="get" path="/metadata" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->metadata->list(

);

if ($response->metadataResponse !== null) {
    // handle response
}
```

### Response

**[?Operations\ListMetadataResponse](../../Models/Operations/ListMetadataResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## getPredefinedConfigs

Retrieve a complete list of predefined shipment configurations. The method has no body and takes no parameters. The response includes all stored configuration options.

### Example Usage

<!-- UsageSnippet language="php" operationID="listPredefinedConfigs" method="get" path="/metadata/predefined-configs" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->metadata->getPredefinedConfigs(

);

if ($response->predefinedConfigs !== null) {
    // handle response
}
```

### Response

**[?Operations\ListPredefinedConfigsResponse](../../Models/Operations/ListPredefinedConfigsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |