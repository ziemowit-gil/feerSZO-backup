# ShipmentPriceStatus

Shipment processing status.


## Fields

| Field                                                         | Type                                                          | Required                                                      | Description                                                   | Example                                                       |
| ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- |
| `error`                                                       | *?bool*                                                       | :heavy_minus_sign:                                            | Indicates whether an error occurred during processing.        | false                                                         |
| `code`                                                        | *?string*                                                     | :heavy_minus_sign:                                            | Status code.                                                  | SENT                                                          |
| `description`                                                 | *?string*                                                     | :heavy_minus_sign:                                            | Status description.                                           | Przekazano do wysyłki                                         |
| `date`                                                        | [\DateTime](https://www.php.net/manual/en/class.datetime.php) | :heavy_minus_sign:                                            | Status timestamp (UTC).                                       | 2024-06-01T16:22:07Z                                          |