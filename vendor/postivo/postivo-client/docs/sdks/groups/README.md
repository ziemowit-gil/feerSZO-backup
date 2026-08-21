# Groups
(*addressBook->groups*)

## Overview

### Available Operations

* [list](#list) - List groups
* [add](#add) - Add a new group
* [get](#get) - Retrieve group details
* [update](#update) - Update a group
* [delete](#delete) - Delete a group

## list

Retrieve the full list of groups defined in your account’s Address Book.

### Example Usage

<!-- UsageSnippet language="php" operationID="listGroups" method="get" path="/groups" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->groups->list(

);

if ($response->groupResponses !== null) {
    // handle response
}
```

### Response

**[?Operations\ListGroupsResponse](../../Models/Operations/ListGroupsResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## add

Create a new contact group in your account’s Address Book.

### Example Usage

<!-- UsageSnippet language="php" operationID="addGroup" method="post" path="/groups" -->
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

$request = new Components\Group(
    name: 'my-group',
    description: 'This is a group for school contacts',
);

$response = $sdk->addressBook->groups->add(
    request: $request
);

if ($response->groupResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                            | Type                                                 | Required                                             | Description                                          |
| ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- |
| `$request`                                           | [Components\Group](../../Models/Components/Group.md) | :heavy_check_mark:                                   | The request object to use for the request.           |

### Response

**[?Operations\AddGroupResponse](../../Models/Operations/AddGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## get

Get the details of a single group from your Address Book by its `id` (returned when the group was created).

### Example Usage

<!-- UsageSnippet language="php" operationID="getGroupById" method="get" path="/groups/{id}" -->
```php
declare(strict_types=1);

require 'vendor/autoload.php';

use Postivo;

$sdk = Postivo\Client::builder()
    ->setSecurity(
        '<YOUR API ACCESS TOKEN>'
    )
    ->build();



$response = $sdk->addressBook->groups->get(
    id: 194
);

if ($response->groupResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter          | Type               | Required           | Description        | Example            |
| ------------------ | ------------------ | ------------------ | ------------------ | ------------------ |
| `id`               | *int*              | :heavy_check_mark: | Group id to fetch  | 194                |

### Response

**[?Operations\GetGroupByIdResponse](../../Models/Operations/GetGroupByIdResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 404, 4XX       | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## update

Update a group’s details by ID.

### Example Usage

<!-- UsageSnippet language="php" operationID="updateGroup" method="put" path="/groups/{id}" -->
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

$group = new Components\Group(
    name: 'my-group',
    description: 'This is a group for school contacts',
);

$response = $sdk->addressBook->groups->update(
    id: 14,
    group: $group

);

if ($response->groupResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                            | Type                                                 | Required                                             | Description                                          | Example                                              |
| ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- |
| `id`                                                 | *int*                                                | :heavy_check_mark:                                   | Group `id` to update.                                | 14                                                   |
| `group`                                              | [Components\Group](../../Models/Components/Group.md) | :heavy_check_mark:                                   | A `Group` object with the updated fields.            |                                                      |

### Response

**[?Operations\UpdateGroupResponse](../../Models/Operations/UpdateGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |

## delete

Remove a group from your account’s Address Book by `ID`. Pass the group’s `id` to remove it. The group is deleted immediately from the Address Book.

If you also want to remove contacts that belong to this group, set the parameter `contacts` to `delete`. The default is `contacts: detach`, which detaches contacts from the removed group but leaves them in the Address Book.

### Example Usage

<!-- UsageSnippet language="php" operationID="deleteGroup" method="delete" path="/groups/{id}" -->
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



$response = $sdk->addressBook->groups->delete(
    id: 876,
    contacts: Operations\ContactHandling::Detach

);

if ($response->errorResponse !== null) {
    // handle response
}
```

### Parameters

| Parameter                                                                 | Type                                                                      | Required                                                                  | Description                                                               | Example                                                                   |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `id`                                                                      | *int*                                                                     | :heavy_check_mark:                                                        | Group `id` to remove.                                                     | 876                                                                       |
| `contacts`                                                                | [?Operations\ContactHandling](../../Models/Operations/ContactHandling.md) | :heavy_minus_sign:                                                        | How to handle contacts that belong to the group.                          |                                                                           |

### Response

**[?Operations\DeleteGroupResponse](../../Models/Operations/DeleteGroupResponse.md)**

### Errors

| Error Type               | Status Code              | Content Type             |
| ------------------------ | ------------------------ | ------------------------ |
| Errors\ErrorResponse     | 400, 401, 403, 404, 4XX  | application/problem+json |
| Errors\ErrorResponse     | 5XX                      | application/problem+json |