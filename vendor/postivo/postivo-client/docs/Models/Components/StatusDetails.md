# StatusDetails

Details of a single shipment and its status events


## Fields

| Field                                                                     | Type                                                                      | Required                                                                  | Description                                                               |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `shipmentDetails`                                                         | [?Components\ShipmentDetails](../../Models/Components/ShipmentDetails.md) | :heavy_minus_sign:                                                        | Single shipment details                                                   |
| `statusEvents`                                                            | array<[Components\StatusEvent](../../Models/Components/StatusEvent.md)>   | :heavy_minus_sign:                                                        | List of status events for the shipment.                                   |