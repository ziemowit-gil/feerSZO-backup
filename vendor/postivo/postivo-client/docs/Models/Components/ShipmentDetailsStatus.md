# ShipmentDetailsStatus

Shipment processing status.


## Fields

| Field                                                           | Type                                                            | Required                                                        | Description                                                     | Example                                                         |
| --------------------------------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------- |
| `error`                                                         | *?bool*                                                         | :heavy_minus_sign:                                              | Indicates whether an error occurred during shipment processing. | false                                                           |
| `code`                                                          | *?string*                                                       | :heavy_minus_sign:                                              | Shipment status code.                                           | SENT                                                            |
| `name`                                                          | *?string*                                                       | :heavy_minus_sign:                                              | Shipment status description.                                    | Przekazano do wysyłki                                           |
| `date`                                                          | [\DateTime](https://www.php.net/manual/en/class.datetime.php)   | :heavy_minus_sign:                                              | Date and time of the shipment status change (UTC).              | 2024-06-01T16:22:07Z                                            |