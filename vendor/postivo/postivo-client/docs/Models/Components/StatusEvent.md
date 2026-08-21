# StatusEvent

Single shipment status event.


## Fields

| Field                                                         | Type                                                          | Required                                                      | Description                                                   | Example                                                       |
| ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- |
| `uniqueId`                                                    | *?int*                                                        | :heavy_minus_sign:                                            | Unique status event ID.                                       | 778656                                                        |
| `type`                                                        | *?string*                                                     | :heavy_minus_sign:                                            | Event type: `OK` (regular) or `EX` (exception/irregular).     | OK                                                            |
| `code`                                                        | *?string*                                                     | :heavy_minus_sign:                                            | Status event code.                                            | ACCEPTED                                                      |
| `name`                                                        | *?string*                                                     | :heavy_minus_sign:                                            | Status event description.                                     | Przesyłka przyjęta do realizacji                              |
| `details`                                                     | *?string*                                                     | :heavy_minus_sign:                                            | Status event details (for EXTERNAL status codes).             | Przyjęcie do Punktu Dystrybucyjnego                           |
| `date`                                                        | [\DateTime](https://www.php.net/manual/en/class.datetime.php) | :heavy_minus_sign:                                            | Status event timestamp (UTC).                                 | 2024-06-01T12:00:00Z                                          |