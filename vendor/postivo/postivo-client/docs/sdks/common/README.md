# Common
(*common*)

## Overview

Common

### Available Operations

* [ping](#ping) - Check API availability and version

## ping

Check the API connection and current availability status. The response also includes the current API version.

### Example Usage

<!-- UsageSnippet language="php" operationID="ping" method="get" path="/ping" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()->build();



$response = $sdk->common->ping(

);

if ($response->pingResponse !== null) {
    // handle response
}
```

### Response

**[?Operations\PingResponse](../../Models/Operations/PingResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 4XX                 | application/problem+json |
| Errors\ErrorResponse     | 503, 5XX                 | application/problem+json |