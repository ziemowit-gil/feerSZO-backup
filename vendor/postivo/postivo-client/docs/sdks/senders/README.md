# Senders
(*senders*)

## Overview

### Available Operations

* [list](#list) - List senders
* [add](#add) - Add a new sender
* [delete](#delete) - Delete a sender
* [verify](#verify) - Verify sender

## list

Retrieve the list of allowed senders defined in your account. Senders are registered in your account and must be verified and activated before use. Activated senders have `active: true` property and can be used to send shipments. Inactive senders are also returned (`active: false`), but cannot be used until activated.

### Example Usage

<!-- UsageSnippet language="php" operationID="listSenders" method="get" path="/senders" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->senders->list(

);

if ($response->senderDetails !== null) {
    // handle response
}
```

### Response

**[?Operations\ListSendersResponse](../../Models/Operations/ListSendersResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## add

Create a new sender on your account. The request must contain a `Sender` object. To prevent fraud, all additional senders are verified by mailing a verification code to the sender’s address. Complete the verification using the `verifySender` method. Verified senders have `active: true` and can be used to send shipments. Inactive senders are also returned (`active: false`), but cannot be used until verification is completed.

### Example Usage

<!-- UsageSnippet language="php" operationID="addSender" method="post" path="/senders" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;
use Postivo\Models\Components;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();

$request = new Components\Sender(
    name: 'Jan Nowak',
    name2: 'Firma Testowa Sp. z o.o.',
    address: 'ul. Aleje Jerozolimskie',
    homeNumber: '31',
    flatNumber: '2',
    postCode: '00-999',
    city: 'Warszawa',
    country: 'PL',
);

$response = $sdk->senders->add(
    request: $request
);

if ($response->senderDetails !== null) {
    // handle response
}
```

### Parameters

| Parameter                                              | Type                                                   | Required                                               | Description                                            |
| ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ |
| `$request`                                             | [Components\Sender](../../Models/Components/Sender.md) | :heavy_check_mark:                                     | The request object to use for the request.             |

### Response

**[?Operations\AddSenderResponse](../../Models/Operations/AddSenderResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## delete

Remove a sender from your account by `id`. Pass the sender’s `id` parameter to remove it. The sender is deleted immediately.

### Example Usage

<!-- UsageSnippet language="php" operationID="deleteSender" method="delete" path="/senders/{id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->senders->delete(
    id: 14
);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter              | Type                   | Required               | Description            | Example                |
| ---------------------- | ---------------------- | ---------------------- | ---------------------- | ---------------------- |
| `id`                   | *int*                  | :heavy_check_mark:     | Sender `id` to remove. | 14                     |

### Response

**[?Operations\DeleteSenderResponse](../../Models/Operations/DeleteSenderResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## verify

Verify a sender to activate it. After adding a new sender, a letter containing a verification code is mailed to the sender’s address. Provide this code to complete verification.

### Example Usage

<!-- UsageSnippet language="php" operationID="verifySender" method="patch" path="/senders/{id}" -->
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

$requestBody = new Operations\VerifySenderRequestBody(
    verificationCode: 'A345FP73',
);

$response = $sdk->senders->verify(
    id: 443,
    requestBody: $requestBody

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                                                | Type                                                                                     | Required                                                                                 | Description                                                                              | Example                                                                                  |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `id`                                                                                     | *int*                                                                                    | :heavy_check_mark:                                                                       | ID of the sender to verify.                                                              | 443                                                                                      |
| `requestBody`                                                                            | [Operations\VerifySenderRequestBody](../../Models/Operations/VerifySenderRequestBody.md) | :heavy_check_mark:                                                                       | Verification code received in the letter.                                                |                                                                                          |

### Response

**[?Operations\VerifySenderResponse](../../Models/Operations/VerifySenderResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 404                      | application/json         |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |