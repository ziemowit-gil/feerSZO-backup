<?php
/**
 * outlook-addin/manifest.php — Manifest add-inu Outlook (Office.js), generowany dynamicznie.
 *
 * Wstrzykuje bieżący APP_URL, więc działa w każdym wdrożeniu (single- i multi-tenant).
 * Pobierz ten URL i wgraj w Outlook: Pobierz dodatki → Moje dodatki → Dodaj dodatek niestandardowy → Z adresu URL.
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/xml; charset=utf-8');

$APP  = rtrim(APP_URL, '/');
$host = parse_url($APP, PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'] ?? 'localhost';
$icon = $APP . '/assets/logo/logo_1780615370.png';

// Stały GUID add-inu (nie zmieniać — identyfikuje add-in w Outlooku).
$ID = '7b3e9f10-5a2c-4d8e-bb44-9f1c2a6d8e30';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
           xmlns:mailappor="http://schemas.microsoft.com/office/mailappversionoverrides/1.0"
           xsi:type="MailApp">
  <Id><?= $ID ?></Id>
  <Version>1.0.0.0</Version>
  <ProviderName>FEER</ProviderName>
  <DefaultLocale>pl-PL</DefaultLocale>
  <DisplayName DefaultValue="feerSZO CRM"/>
  <Description DefaultValue="Podgląd powiązanego kontaktu CRM i przypinanie maili do kartoteki feerSZO."/>
  <IconUrl DefaultValue="<?= htmlspecialchars($icon) ?>"/>
  <HighResolutionIconUrl DefaultValue="<?= htmlspecialchars($icon) ?>"/>
  <SupportUrl DefaultValue="<?= htmlspecialchars($APP . '/crm/') ?>"/>
  <AppDomains>
    <AppDomain><?= htmlspecialchars($APP) ?></AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.5"/>
    </Sets>
  </Requirements>
  <FormSettings>
    <Form xsi:type="ItemRead">
      <DesktopSettings>
        <SourceLocation DefaultValue="<?= htmlspecialchars($APP . '/outlook-addin/taskpane.html') ?>"/>
        <RequestedHeight>450</RequestedHeight>
      </DesktopSettings>
    </Form>
  </FormSettings>
  <Permissions>ReadItem</Permissions>
  <Rule xsi:type="RuleCollection" Mode="Or">
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Read"/>
  </Rule>
  <DisableEntityHighlighting>false</DisableEntityHighlighting>
  <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides" xsi:type="VersionOverridesV1_0">
    <Requirements>
      <bt:Sets DefaultMinVersion="1.5">
        <bt:Set Name="Mailbox"/>
      </bt:Sets>
    </Requirements>
    <Hosts>
      <Host xsi:type="MailHost">
        <DesktopFormFactor>
          <FunctionFile resid="functionFile"/>
          <ExtensionPoint xsi:type="MessageReadCommandSurface">
            <OfficeTab id="TabDefault">
              <Group id="feerMsgReadGroup">
                <Label resid="groupLabel"/>
                <Control xsi:type="Button" id="openTaskpaneButton">
                  <Label resid="taskpaneButtonLabel"/>
                  <Supertip>
                    <Title resid="taskpaneButtonLabel"/>
                    <Description resid="taskpaneButtonTip"/>
                  </Supertip>
                  <Icon>
                    <bt:Image size="16" resid="iconImg"/>
                    <bt:Image size="32" resid="iconImg"/>
                    <bt:Image size="80" resid="iconImg"/>
                  </Icon>
                  <Action xsi:type="ShowTaskpane">
                    <SourceLocation resid="taskpaneUrl"/>
                  </Action>
                </Control>
              </Group>
            </OfficeTab>
          </ExtensionPoint>
        </DesktopFormFactor>
      </Host>
    </Hosts>
    <Resources>
      <bt:Images>
        <bt:Image id="iconImg" DefaultValue="<?= htmlspecialchars($icon) ?>"/>
      </bt:Images>
      <bt:Urls>
        <bt:Url id="functionFile" DefaultValue="<?= htmlspecialchars($APP . '/outlook-addin/commands.html') ?>"/>
        <bt:Url id="taskpaneUrl" DefaultValue="<?= htmlspecialchars($APP . '/outlook-addin/taskpane.html') ?>"/>
      </bt:Urls>
      <bt:ShortStrings>
        <bt:String id="groupLabel" DefaultValue="feerSZO CRM"/>
        <bt:String id="taskpaneButtonLabel" DefaultValue="Kontakt CRM"/>
      </bt:ShortStrings>
      <bt:LongStrings>
        <bt:String id="taskpaneButtonTip" DefaultValue="Pokaż powiązany kontakt CRM i przypnij ten mail do kartoteki."/>
      </bt:LongStrings>
    </Resources>
  </VersionOverrides>
</OfficeApp>
