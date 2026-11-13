<?php
/**
 * modules/dhosting_migrator/view/_mailConfig.php — dane do programu pocztowego (telefon, Outlook, Thunderbird) na /migracjapoczty.
 * Treść według instrukcji dostawcy podanej przez użytkownika (polecenie 2026-11-13): nazwa użytkownika, hasło, POP3 / IMAP / SMTP
 * na *.dpoczta.pl — połączenie szyfrowane (zalecane: IMAP 993 SSL, POP3 995 SSL, SMTP 587 STARTTLS) i bez szyfrowania
 * (IMAP 143, POP3 110, SMTP 587), wymagane uwierzytelnienie przy wysyłce, różnica POP3 i IMAP.
 * Zmienne: $cfgBox (adres skrzynki albo ''), $web (poczta w przeglądarce), $imapHost (z DhostingConfig::imapForUsers()).
 */
$cfgUser = ($cfgBox ?? '') !== '' ? (string)$cfgBox : '';
$cfgImap = (string)($imapHost ?? DhostingConfig::DEFAULT_IMAP);
$cfgRows = static function (bool $ssl) use ($cfgImap): array {
    return [
        ['Poczta przychodząca — IMAP (polecamy)', $cfgImap, $ssl ? 993 : 143, $ssl ? 'SSL/TLS' : 'brak'],
        ['Poczta przychodząca — POP3', DhostingConfig::POP3_HOST, $ssl ? 995 : 110, $ssl ? 'SSL/TLS' : 'brak'],
        ['Poczta wychodząca — SMTP', DhostingConfig::SMTP_HOST, DhostingConfig::SMTP_PORT, $ssl ? 'STARTTLS' : 'brak'],
    ];
};
?>
<table class="dm-cfg">
  <caption class="dm-cfg__cap">Dane do logowania</caption>
  <tr><th scope="row">Nazwa użytkownika</th><td><?= $cfgUser !== '' ? '<code>' . h($cfgUser) . '</code>' : 'Twój pełny adres e-mail' ?></td></tr>
  <tr><th scope="row">Hasło</th><td>hasło do skrzynki pocztowej (z SMS-a albo ustawione przez Ciebie) — nie hasło do Microsoft 365</td></tr>
  <tr><th scope="row">Serwer poczty wychodzącej wymaga uwierzytelnienia</th><td><strong>Tak</strong> — ten sam login i hasło</td></tr>
</table>

<table class="dm-cfg dm-cfg--srv">
  <caption class="dm-cfg__cap">Połączenie szyfrowane <span class="mt-badge mt-badge--ok">zalecane</span></caption>
  <thead><tr><th scope="col">Serwer</th><th scope="col">Adres</th><th scope="col">Port</th><th scope="col">Szyfrowanie</th></tr></thead>
  <tbody>
  <?php foreach ($cfgRows(true) as [$lbl, $host, $port, $sec]): ?>
    <tr><th scope="row"><?= h($lbl) ?></th><td><code><?= h($host) ?></code></td><td><?= (int)$port ?></td><td><?= h($sec) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<details class="dm-more">
  <summary>Połączenie bez szyfrowania (tylko gdy program nie obsługuje SSL)</summary>
  <table class="dm-cfg dm-cfg--srv">
    <caption class="visually-hidden">Połączenie bez szyfrowania</caption>
    <thead><tr><th scope="col">Serwer</th><th scope="col">Adres</th><th scope="col">Port</th><th scope="col">Szyfrowanie</th></tr></thead>
    <tbody>
    <?php foreach ($cfgRows(false) as [$lbl, $host, $port, $sec]): ?>
      <tr><th scope="row"><?= h($lbl) ?></th><td><code><?= h($host) ?></code></td><td><?= (int)$port ?></td><td><?= h($sec) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</details>

<details class="dm-more">
  <summary>Czym różni się POP3 od IMAP?</summary>
  <p><strong>IMAP (polecamy)</strong> — wiadomości zostają na serwerze. Widzisz je tak samo w telefonie, na komputerze i w przeglądarce,
    a awaria jednego urządzenia nic Ci nie zabiera.</p>
  <p><strong>POP3</strong> — program (Outlook, Thunderbird, Apple Mail i inne) pobiera wiadomości na urządzenie i zwykle usuwa je z serwera,
    od razu albo po pewnym czasie. Przydaje się, gdy często pracujesz bez internetu. Pobrane wiadomości są jednak tylko na tym jednym
    urządzeniu — awaria komputera, telefonu czy tabletu może oznaczać, że stracisz je bezpowrotnie.</p>
</details>
<?php if (($web ?? '') !== ''): ?>
  <p class="dm-help" style="margin-top:.8rem">Poczta w przeglądarce, bez programu: <a href="<?= h($web) ?>" target="_blank" rel="noopener"><?= h((string)(parse_url($web, PHP_URL_HOST) ?: $web)) ?><span class="visually-hidden"> (nowa karta)</span></a></p>
<?php endif; ?>
