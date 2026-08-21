# Contacts
(*addressBook->contacts*)

## Overview

### Available Operations

* [list](#list) - List contacts
* [add](#add) - Add a new contact
* [get](#get) - Retrieve contact details
* [update](#update) - Update a contact
* [delete](#delete) - Delete a contact
* [removeFromGroup](#removefromgroup) - Remove a contact from a group
* [addToGroup](#addtogroup) - Add a contact to a group

## list

Retrieve a paginated list of all contacts defined in your account’s **Address Book**.

### Example Usage

<!-- UsageSnippet language="php" operationID="listContacts" method="get" path="/contacts" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->list(
    page: 1,
    limit: 10

);

if ($response->contactResponses !== null) {
    // handle response
}
```

### Parameters

| Parameter               | Type                    | Required                | Description             | Example                 |
| ----------------------- | ----------------------- | ----------------------- | ----------------------- | ----------------------- |
| `page`                  | *?int*                  | :heavy_minus_sign:      | Page number of results  | 1                       |
| `limit`                 | *?int*                  | :heavy_minus_sign:      | Results limit per page. | 10                      |

### Response

**[?Operations\ListContactsResponse](../../Models/Operations/ListContactsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## add

Create a new contact in your account’s **Address Book**.

### Example Usage

<!-- UsageSnippet language="php" operationID="addContact" method="post" path="/contacts" -->
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

$request = new Components\Contact(
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

$response = $sdk->addressBook->contacts->add(
    request: $request
);

if ($response->contactResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                | Type                                                     | Required                                                 | Description                                              |
| -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| `$request`                                               | [Components\Contact](../../Models/Components/Contact.md) | :heavy_check_mark:                                       | The request object to use for the request.               |

### Response

**[?Operations\AddContactResponse](../../Models/Operations/AddContactResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## get

Get the details of a contact from your Address Book using its global `id`.

### Example Usage

<!-- UsageSnippet language="php" operationID="getContactById" method="get" path="/contacts/{id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->get(
    id: 14
);

if ($response->contactResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                     | Type                          | Required                      | Description                   | Example                       |
| ----------------------------- | ----------------------------- | ----------------------------- | ----------------------------- | ----------------------------- |
| `id`                          | *int*                         | :heavy_check_mark:            | Global contact `id` to fetch. | 14                            |

### Response

**[?Operations\GetContactByIdResponse](../../Models/Operations/GetContactByIdResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 404, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## update

Update a contact by its global ID.

### Example Usage

<!-- UsageSnippet language="php" operationID="updateContact" method="put" path="/contacts/{id}" -->
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

$response = $sdk->addressBook->contacts->update(
    id: 14,
    contact: $contact

);

if ($response->contactResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                | Type                                                     | Required                                                 | Description                                              | Example                                                  |
| -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| `id`                                                     | *int*                                                    | :heavy_check_mark:                                       | ID of the contact to update.                             | 14                                                       |
| `contact`                                                | [Components\Contact](../../Models/Components/Contact.md) | :heavy_check_mark:                                       | A `Contact` object with the updated fields.              |                                                          |

### Response

**[?Operations\UpdateContactResponse](../../Models/Operations/UpdateContactResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## delete

Remove a contact from your account by system ID.

### Example Usage

<!-- UsageSnippet language="php" operationID="deleteContact" method="delete" path="/contacts/{id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->delete(
    id: 14
);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                      | Type                           | Required                       | Description                    | Example                        |
| ------------------------------ | ------------------------------ | ------------------------------ | ------------------------------ | ------------------------------ |
| `id`                           | *int*                          | :heavy_check_mark:             | Global contact `id` to remove. | 14                             |

### Response

**[?Operations\DeleteContactResponse](../../Models/Operations/DeleteContactResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## removeFromGroup

Remove a contact from a group in your Address Book. This does not delete the contact; it only detaches the contact from the group.

Provide the contact’s `id` and the group’s `group_id` parameters to perform the detachment.

### Example Usage

<!-- UsageSnippet language="php" operationID="removeContactFromGroup" method="delete" path="/contacts/{id}/group/{group_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->removeFromGroup(
    id: 35,
    groupId: 656

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                     | Type                                          | Required                                      | Description                                   | Example                                       |
| --------------------------------------------- | --------------------------------------------- | --------------------------------------------- | --------------------------------------------- | --------------------------------------------- |
| `id`                                          | *int*                                         | :heavy_check_mark:                            | Global contact `id` to detach from the group. | 35                                            |
| `groupId`                                     | *int*                                         | :heavy_check_mark:                            | Group `id` to detach from the contact.        | 656                                           |

### Response

**[?Operations\RemoveContactFromGroupResponse](../../Models/Operations/RemoveContactFromGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 404                      | application/json         |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## addToGroup

Assign a contact to a group. If a contact and a group exist in your account, you can add the contact to that group.

Provide the contact’s `id` and the group’s `group_id` parameters to perform the assignment.

### Example Usage

<!-- UsageSnippet language="php" operationID="addContactToGroup" method="patch" path="/contacts/{id}/group/{group_id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->contacts->addToGroup(
    id: 35,
    groupId: 656

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                 | Type                                      | Required                                  | Description                               | Example                                   |
| ----------------------------------------- | ----------------------------------------- | ----------------------------------------- | ----------------------------------------- | ----------------------------------------- |
| `id`                                      | *int*                                     | :heavy_check_mark:                        | Global contact `id` to add to the group.  | 35                                        |
| `groupId`                                 | *int*                                     | :heavy_check_mark:                        | Group `id` to associate with the contact. | 656                                       |

### Response

**[?Operations\AddContactToGroupResponse](../../Models/Operations/AddContactToGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 404                      | application/json         |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |