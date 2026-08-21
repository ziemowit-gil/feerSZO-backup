# ShipmentDocuments

Document payload to print and enclose into shipment. For a single document, provide `DocumentPdf`, `DocumentLibrary`, or `DocumentMock` (for checking the price only). For multiple documents, provide an array of `DocumentPdf`, `DocumentLibrary`, or `DocumentMock` objects (1–20).


## Supported Types

### `Components\DocumentPdf|Components\DocumentLibrary|Components\DocumentMock`

```php
/**
* @var Components\DocumentPdf|Components\DocumentLibrary|Components\DocumentMock
*/
Components\DocumentPdf|Components\DocumentLibrary|Components\DocumentMock $value = /* values here */
```

### `array`

```php
/**
* @var array<Components\DocumentPdf|Components\DocumentLibrary|Components\DocumentMock>
*/
array $value = /* values here */
```

