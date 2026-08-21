# ByExtId
(*addressBook->contacts->byExtId*)

## Overview

### Available Operations

* [get](#get) - Retrieve contact details by EXT_ID
* [update](#update) - Update a contact by EXT_ID
* [delete](#delete) - Delete a contact by EXT_ID
* [removeFromGroup](#removefromgroup) - Remove a contact from a group by EXT_ID
* [addToGroup](#addtogroup) - Add a contact to a group by EXT_ID

## get

Get the details of a contact from your Address Book using your external (custom) ID (the value you defined when creating the contact).

### Example Usage

<!-- UsageSnippet language="php" operationID="getContactByExternalId" method="get" path="/contacts/external/{ext_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->byExtId->get(
    extId: 'my-ext-id-44'
);

if ($response->contactResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                     | Type                                          | Required                                      | Description                                   | Example                                       |
| --------------------------------------------- | --------------------------------------------- | --------------------------------------------- | --------------------------------------------- | --------------------------------------------- |
| `extId`                                       | *string*                                      | :heavy_check_mark:                            | External (custom) ID of the contact to fetch. | my-ext-id-44                                  |

### Response

**[?Operations\GetContactByExternalIdResponse](../../Models/Operations/GetContactByExternalIdResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 404, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## update

Update a contact by its external (custom) ID - the value you defined when creating the contact.

### Example Usage

<!-- UsageSnippet language="php" operationID="updateContactByExternalId" method="put" path="/contacts/external/{ext_id}" -->
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

$contact = new Components\Contact(
    name: 'Jan Nowak',
    name2: 'Firma Testowa Sp. z o.o.',
    address: 'ul. Aleje Jerozolimskie',
    homeNumber: '31',
    flatNumber: '2',
    postCode: '00-999',
    city: 'Warszawa',
    phoneNumber: '+48999999999',
    extId: 'my-contact-1',
    groupIds: [
        13,
        534,
    ],
);

$response = $sdk->addressBook->contacts->byExtId->update(
    extId: 'my-id-2',
    contact: $contact

);

if ($response->contactResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                | Type                                                     | Required                                                 | Description                                              | Example                                                  |
| -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| `extId`                                                  | *string*                                                 | :heavy_check_mark:                                       | External (custom) ID of the contact to update.           | my-id-2                                                  |
| `contact`                                                | [Components\Contact](../../Models/Components/Contact.md) | :heavy_check_mark:                                       | A `Contact` object with the updated fields.              |                                                          |

### Response

**[?Operations\UpdateContactByExternalIdResponse](../../Models/Operations/UpdateContactByExternalIdResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## delete

Remove a contact from your account by its external (custom) ID - the value defined when the contact was created.

### Example Usage

<!-- UsageSnippet language="php" operationID="deleteContactByExternalId" method="delete" path="/contacts/external/{ext_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->byExtId->delete(
    extId: 'my-id-2'
);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                      | Type                                           | Required                                       | Description                                    | Example                                        |
| ---------------------------------------------- | ---------------------------------------------- | ---------------------------------------------- | ---------------------------------------------- | ---------------------------------------------- |
| `extId`                                        | *string*                                       | :heavy_check_mark:                             | External (custom) ID of the contact to remove. | my-id-2                                        |

### Response

**[?Operations\DeleteContactByExternalIdResponse](../../Models/Operations/DeleteContactByExternalIdResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## removeFromGroup

Remove a contact from a group in your Address Book using the contact’s external (custom) ID. This operation does not delete the contact; it only detaches the contact from the group. Provide the contact’s `ext_id` and the group’s `group_id` parameters to perform the detachment.

### Example Usage

<!-- UsageSnippet language="php" operationID="removeContactByExtIdToGroup" method="delete" path="/contacts/external/{ext_id}/group/{group_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->byExtId->removeFromGroup(
    extId: 'my-id-1',
    groupId: 656

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                     | Type                                                          | Required                                                      | Description                                                   | Example                                                       |
| ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- |
| `extId`                                                       | *string*                                                      | :heavy_check_mark:                                            | External (custom) ID of the contact to detach from the group. | my-id-1                                                       |
| `groupId`                                                     | *int*                                                         | :heavy_check_mark:                                            | Group `id` to detach from the contact.                        | 656                                                           |

### Response

**[?Operations\RemoveContactByExtIdToGroupResponse](../../Models/Operations/RemoveContactByExtIdToGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 404                      | application/json         |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## addToGroup

Assign a contact to a group using the contact’s external (custom) ID (assigned when creating the contact). If a contact and a group exist in your account, you can add the contact to that group.

Provide the contact’s `ext_id` and the group’s `group_id` parameters to perform the assignment.

### Example Usage

<!-- UsageSnippet language="php" operationID="addContactByExtIdToGroup" method="patch" path="/contacts/external/{ext_id}/group/{group_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->byExtId->addToGroup(
    extId: 'my-id-1',
    groupId: 656

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                | Type                                                     | Required                                                 | Description                                              | Example                                                  |
| -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| `extId`                                                  | *string*                                                 | :heavy_check_mark:                                       | External (custom) ID of the contact to add to the group. | my-id-1                                                  |
| `groupId`                                                | *int*                                                    | :heavy_check_mark:                                       | Group `id` to associate with the contact.                | 656                                                      |

### Response

**[?Operations\AddContactByExtIdToGroupResponse](../../Models/Operations/AddContactByExtIdToGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 404                      | application/json         |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |