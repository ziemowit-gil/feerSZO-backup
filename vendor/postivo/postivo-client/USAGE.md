<!-- Start SDK Example Usage [usage] -->
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