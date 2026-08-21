# Accounts
(*accounts*)

## Overview

### Available Operations

* [get](#get) - Retrieve account details
* [getSubaccount](#getsubaccount) - Get subaccount details

## get

Retrieve the current account balance and other account details. You can also check the account limit and whether the account is a **main** account. Main accounts have unrestricted privileges and, via the [User Panel](https://panel.postivo.pl), you can create as many subaccounts as needed.

### Example Usage

<!-- UsageSnippet language="php" operationID="getAccountDetails" method="get" path="/account" -->
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

### Response

**[?Operations\GetAccountDetailsResponse](../../Models/Operations/GetAccountDetailsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 401, 403, 4XX            | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## getSubaccount

Check the account balance and other details, such as the subcredit balance of a subaccount. Subaccounts are additional users who can access your account’s services and data. You can restrict access levels and set privileges for subaccounts in the [User Panel](https://panel.postivo.pl).

Provide the full subaccount login to access its data.

### Example Usage

<!-- UsageSnippet language="php" operationID="getSubaccountDetails" method="get" path="/account/{user_login}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->accounts->getSubaccount(
    userLogin: 'some-login'
);

if ($response->accountResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                  | Type                                                       | Required                                                   | Description                                                | Example                                                    |
| ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------- |
| `userLogin`                                                | *string*                                                   | :heavy_check_mark:                                         | Login of the subaccount (user) for which to retrieve data. | some-login                                                 |

### Response

**[?Operations\GetSubaccountDetailsResponse](../../Models/Operations/GetSubaccountDetailsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 401, 403, 404, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |