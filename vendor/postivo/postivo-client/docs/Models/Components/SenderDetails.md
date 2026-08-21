# SenderDetails

Extended sender details.


## Fields

| Field                                                  | Type                                                   | Required                                               | Description                                            | Example                                                |
| ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ |
| `id`                                                   | *?int*                                                 | :heavy_minus_sign:                                     | Unique sender ID.                                      | 665                                                    |
| `sender`                                               | [Components\Sender](../../Models/Components/Sender.md) | :heavy_check_mark:                                     | Sender data for the shipment.                          |                                                        |
| `active`                                               | *bool*                                                 | :heavy_check_mark:                                     | Indicates whether the sender is active.                | true                                                   |
| `default`                                              | *bool*                                                 | :heavy_check_mark:                                     | Indicates whether this is the default sender.          | true                                                   |