<?php
if (!isset($adm, $org)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/admin.php — pulpit Administratora Licencji w Panelu Licencji: wyszukiwanie uczestnika, dane do panelu,
 * konto Office (tenant edukacyjny), osoby z licencjami bez danych do panelu. Zmienne: $adm, $q, $found, $cand, $org, $flash.
 */
$PAGE_TITLE = 'Administrator Licencji';
require_once dirname(__DIR__) . '/logic/LicencjeAdminNav.php';
$nav = LicencjeAdminNav::sideNav('admin');   // pełne menu Administratora Licencji
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
$qf = '<input type="hidden" name="q" value="' . h($q) . '">';
?>
<h1 class="m-title">Administrator Licencji</h1>
<p class="m-explain">Znajdź uczestnika, nadaj mu dostęp do Panelu Licencji albo załóż konto Office. Dane do panelu dostają osoby z licencjami albo produktami; płatny produkt liczy się dopiero z fakturą VAT.</p>
<div class="tw-flex tw-flex-wrap tw-gap-2 tw-mb-4">
  <a class="m-btn" href="<?= h(route('lk.admin.prices')) ?>">Office: ceny A3 / A5 i poczta</a>
  <a class="m-btn-ghost" href="<?= h(route('lk.admin.drive')) ?>">Dysk Online: pakiety i ceny</a>
  <a class="m-btn-ghost" href="/licencje/claudeAbonament.php?tab=ustawienia">Ceny i ustawienia płatnych licencji</a>
  <a class="m-btn-ghost" href="/licencje/licencjeDydaktyczne.php">Wnioski AM5 / AM7 i operacje</a>
  <a class="m-btn-ghost" href="/licencje/licencjeKursantow.php">Programy i klucze</a>
</div>

<section class="m-tile" aria-labelledby="a-szukaj">
  <h2 class="m-sect" id="a-szukaj">Szukaj uczestnika</h2>
  <form method="get" action="<?= h(LicencjePanelAuth::PATH) ?>" role="search" class="tw-flex tw-flex-wrap tw-gap-2 tw-items-end">
    <input type="hidden" name="_route" value="/admin">
    <div style="flex:1;min-width:240px"><label class="m-label" for="a-q">Imię i nazwisko, login l…, e-mail albo nr beneficjenta</label>
      <input class="m-input" id="a-q" name="q" value="<?= h($q) ?>" autocomplete="off"></div>
    <button class="m-btn" type="submit">Szukaj</button>
  </form>
</section>

<?php if ($q !== ''): ?>
<section class="m-tile tw-mt-4" aria-labelledby="a-wyniki">
  <h2 class="m-sect" id="a-wyniki">Wyniki (<?= count($found) ?>)</h2>
  <?php if (!$found): ?><p class="tw-text-sm">Nikogo nie znaleziono. Wpisz co najmniej 2 znaki.</p><?php endif; ?>
  <?php foreach ($found as $r): $o = $r['office']; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($r['name']) ?></span>
      <?= $r['virtual'] ? $tag('due', 'konto testowe') : '' ?>
      <span class="m-note"><?= h(trim((string)$r['email'] . ($r['phone'] ? ' · ' . $r['phone'] : ''), ' ·')) ?></span>
    </div>
    <dl class="m-kv tw-grid sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-x-6 tw-mt-2">
      <div><dt>Panel Licencji</dt><dd><?= $r['login'] ? '<span class="m-mono">' . h($r['login']) . '</span>' : 'bez loginu' ?>, hasło <?= $r['hasPass'] ? 'ustawione' : 'brak' ?><?= $r['last'] !== '' ? '<span class="m-note tw-block">ostatnio ' . h(date('d.m.Y H:i', strtotime($r['last']))) . '</span>' : '' ?></dd></div>
      <div><dt>Konto Office</dt><dd><?= $o['upn'] !== '' ? '<span class="m-mono">' . h($o['upn']) . '</span> ' : 'brak imienia lub nazwiska ' ?><?= $tag($o['ok'] ? 'ok' : ($o['state'] === 'error' ? 'bad' : 'off'), $o['ok'] ? 'aktywne' : $o['label']) ?></dd></div>
      <div><dt>Karta</dt><dd><?= $r['card'] ? h(LicenceCard::label($r['card'])) : '<span class="m-muted">brak</span>' ?></dd></div>
      <div><dt>Poczta kursanta</dt><dd><?= $r['mail'] ? '<span class="m-mono">' . h($r['mail']['login']) . '</span>, hasło ' . ($r['mail']['hasPassword'] ? 'wpisane' : 'brak') : '<span class="m-muted">brak</span>' ?></dd></div>
    </dl>
    <p class="tw-text-sm"><?= $r['why'] ? 'Ma: ' . h(implode(', ', array_slice($r['why'], 0, 5))) . (count($r['why']) > 5 ? ' i ' . (count($r['why']) - 5) . ' inne' : '') : '<span class="m-muted">Brak licencji i produktów' . ($r['unpaid'] ? '. Płatne bez faktury VAT: ' . h(implode(', ', $r['unpaid'])) : '') . '.</span>' ?></p>
    <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2">
      <?php if ($r['why'] || $r['virtual']): ?>
      <form method="post" action="<?= h(route('lk.admin.access')) ?>"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <button class="m-btn" type="submit"><?= $r['hasPass'] ? 'Wyślij nowe hasło' : 'Nadaj dostęp do Panelu Licencji' ?><span class="tw-sr-only"> (<?= h($r['name']) ?>)</span></button></form>
      <?php endif; ?>
      <?php if (!$o['ok'] && $o['upn'] !== ''): ?>
      <form method="post" action="<?= h(route('lk.admin.office')) ?>"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <button class="m-btn-ghost" type="submit">Sprawdź / załóż konto Office<span class="tw-sr-only"> (<?= h($r['name']) ?>)</span></button></form>
      <?php endif; ?>
      <form method="post" action="<?= h(route('lk.admin.as')) ?>"><?= Router::csrfField() ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <button class="m-btn-ghost" type="submit">Zaloguj jako uczestnik<span class="tw-sr-only"> (<?= h($r['name']) ?>, podgląd)</span></button></form>
    </div>
    <?php $mk = 'pm' . (int)$r['id']; $ml = $r['mail']; ?>
    <details class="tw-mt-3"><summary>Poczta kursanta (@<?= h(PocztaStud::DOMAIN) ?>)<span class="tw-sr-only"> (<?= h($r['name']) ?>)</span></summary>
      <form method="post" action="<?= h(route('lk.admin.studmail')) ?>" class="tw-mt-3" style="max-width:560px" autocomplete="off"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <p class="tw-text-sm tw-mb-3">Skrzynkę prowadzi <?= h(PocztaStud::PROVIDER) ?>. Wpisz login i hasło z panelu dostawcy. Uczestnik zobaczy login i link do poczty <?= h(PocztaStud::WEBMAIL) ?>, a hasło odsłoni kodem SMS w panelu kursanta albo w Panelu Licencji.</p>
        <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
          <div><label class="m-label" for="<?= $mk ?>-l">Login (adres)</label>
            <input class="m-input" id="<?= $mk ?>-l" name="mail_login" value="<?= h($ml['login'] ?? '') ?>" placeholder="jan.kowalski@<?= h(PocztaStud::DOMAIN) ?>" required aria-describedby="<?= $mk ?>-lh">
            <p class="m-note" id="<?= $mk ?>-lh">Sama nazwa też wystarczy, domenę @<?= h(PocztaStud::DOMAIN) ?> dopiszemy.</p></div>
          <div><label class="m-label" for="<?= $mk ?>-p">Hasło</label>
            <input class="m-input" id="<?= $mk ?>-p" name="mail_pass" type="password" autocomplete="new-password" aria-describedby="<?= $mk ?>-ph">
            <p class="m-note" id="<?= $mk ?>-ph"><?= $ml && $ml['hasPassword'] ? 'Puste pole: hasło bez zmian (wpisane ' . h(date('d.m.Y', strtotime((string)$ml['password_set_at']))) . ').' : 'Puste pole: bez hasła, uczestnik zobaczy, że przekaże je biuro.' ?></p></div>
        </div>
        <label class="tw-flex tw-items-center tw-gap-2 tw-mt-3 tw-text-sm"><input type="checkbox" name="send_data" value="1" checked> Wyślij uczestnikowi dane (e-mail z loginem i adresem logowania, SMS z hasłem)</label>
        <button class="m-btn tw-mt-3" type="submit">Zapisz pocztę</button>
      </form>
      <?php if ($ml): $mlTicket = PocztaStud::ticketNumber($ml); ?>
      <p class="m-note tw-mt-2">Zmienione <?= h(date('d.m.Y H:i', strtotime((string)$ml['updated_at']))) ?> przez <?= h($ml['updated_by']) ?>. <?= h(PocztaStud::staffStatus($ml)) ?><?= $mlTicket !== '' ? ' Zgłoszenie w Helpdesku: ' . h($mlTicket) . '.' : '' ?></p>
      <form method="post" action="<?= h(route('lk.admin.studmail.send')) ?>" class="tw-mt-2"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <button class="m-btn-ghost" type="submit">Wyślij dane ponownie<span class="tw-sr-only"> (<?= h($r['name']) ?>: e-mail z loginem<?= $ml['hasPassword'] ? ', SMS z hasłem' : '' ?>)</span></button></form>
      <form method="post" action="<?= h(route('lk.admin.studmail.del')) ?>" class="tw-mt-2"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <button class="m-btn-ghost" type="submit">Usuń wpis poczty<span class="tw-sr-only"> (<?= h($r['name']) ?>)</span></button>
        <span class="m-note">Skrzynka w <?= h(PocztaStud::PROVIDER) ?> zostaje.</span></form>
      <?php endif; ?>
    </details>
    <?php require_once dirname(__DIR__) . '/logic/LicencjeDostep.php'; $accSent = LicencjeDostep::forClient((int)$r['id']); ?>
    <details class="tw-mt-3"><summary>Wyślij dane dostępu<span class="tw-sr-only"> (<?= h($r['name']) ?>)</span></summary>
      <form method="post" action="<?= h(route('lk.admin.accessdata')) ?>" class="tw-mt-3" style="max-width:560px"><?= Router::csrfField() ?><?= $qf ?><input type="hidden" name="client_id" value="<?= (int)$r['id'] ?>">
        <p class="tw-text-sm tw-mb-3">Uczestnik dostanie e-mail z loginem, adresem i instrukcją. Hasło albo klucz wyślemy osobno SMS-em, nigdy e-mailem.</p>
        <?= LicencjeDostep::fieldsHtml('ad' . (int)$r['id'] . '-', 'office_a3', (int)$r['id'], false, true) ?>
        <button class="m-btn" type="submit">Wyślij dane dostępu</button>
      </form>
      <?php if ($accSent): ?><p class="tw-text-sm tw-mt-3 tw-font-semibold">Wysłane wcześniej</p><ul class="tw-text-sm tw-list-disc tw-pl-5">
        <?php foreach (array_slice($accSent, 0, 5) as $as): ?><li><?= h($as['service']) ?>, <?= h(date('d.m.Y H:i', strtotime((string)$as['created_at']))) ?><?= $as['login'] !== '' ? ', login ' . h($as['login']) : '' ?> (e-mail: <?= $as['email_sent'] ? 'tak' : 'nie' ?><?= $as['had_secret'] ? ', SMS: ' . ($as['sms_sent'] ? 'tak' : 'nie') : '' ?>)<?= $as['created_by'] !== '' ? ', ' . h($as['created_by']) : '' ?></li><?php endforeach; ?>
      </ul><?php endif; ?>
    </details>
  </div>
  <?php endforeach; ?>
</section>
<?php else: ?>
<section class="m-tile tw-mt-4" aria-labelledby="a-bez">
  <h2 class="m-sect" id="a-bez">Bez danych do Panelu Licencji (<?= count($cand) ?>)</h2>
  <p class="m-explain">Osoby z licencjami albo produktami, które nie mają jeszcze hasła do panelu.</p>
  <?php if (!$cand): ?><p class="tw-text-sm">Wszyscy z licencjami albo produktami mają już dane.</p><?php endif; ?>
  <?php foreach ($cand as $c): ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-center tw-gap-3">
    <span class="tw-font-semibold" style="min-width:12rem"><?= h($c['name']) ?></span>
    <span class="tw-text-sm m-muted" style="flex:1"><?= h(implode(', ', array_slice($c['reasons'], 0, 3))) ?></span>
    <form method="post" action="<?= h(route('lk.admin.access')) ?>"><?= Router::csrfField() ?><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
      <button class="m-btn-ghost" type="submit">Nadaj dostęp<span class="tw-sr-only"> (<?= h($c['name']) ?>)</span></button></form>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
