# RefreshToken

Token umożliwiający odświeżenie tokenu dostępu.
> Więcej informacji:
> - [Odświeżanie tokena](https://github.com/CIRFMF/ksef-docs/blob/main/uwierzytelnianie.md#5-od%C5%9Bwie%C5%BCenie-tokena-dost%C4%99powego-accesstoken)


## Fields

| Field                                                         | Type                                                          | Required                                                      | Description                                                   |
| ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------- |
| `token`                                                       | *string*                                                      | :heavy_check_mark:                                            | Token w formacie JWT.                                         |
| `validUntil`                                                  | [\DateTime](https://www.php.net/manual/en/class.datetime.php) | :heavy_check_mark:                                            | Data ważności tokena.                                         |