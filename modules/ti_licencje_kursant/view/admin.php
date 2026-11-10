<?php
if (!isset($adm, $org)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/admin.php — pulpit Administratora Licencji w Panelu Licencji: wyszukiwanie uczestnika, dane do panelu,
 * konto Office (tenant edukacyjny), osoby z licencjami bez danych do panelu. Zmienne: $adm, $q, $found, $cand, $org, $flash.
 */
$PAGE_TITLE = 'Administrator Licencji';
require_once dirname(__DIR__) . '/logic/LicencjeAdminNav.php';
$nav = array_map(fn($x) => [$x[0], $x[1], '', false, $x[2] === 'admin'], LicencjeAdminNav::items());   // pełne menu Administratora Licencji
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
$qf = '<input type="hidden" name="q" value="' . h($q) . '">';
?>
<h1 class="m-title">Administrator Licencji</h1>
<p class="m-explain">Znajdź uczestnika, nadaj mu dostęp do Panelu Licencji albo załóż konto Office. Dane do panelu dostają osoby z licencjami albo produktami; płatny produkt liczy się dopiero z fakturą VAT.</p>
<div class="tw-flex tw-flex-wrap tw-gap-2 tw-mb-4">
  <a class="m-btn" href="<?= h(route('lk.admin.prices')) ?>">Office: ceny planów A3 / A5</a>
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
    <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6 tw-mt-2">
      <div><dt>Panel Licencji</dt><dd><?= $r['login'] ? '<span class="m-mono">' . h($r['login']) . '</span>' : 'bez loginu' ?>, hasło <?= $r['hasPass'] ? 'ustawione' : 'brak' ?><?= $r['last'] !== '' ? '<span class="m-note tw-block">ostatnio ' . h(date('d.m.Y H:i', strtotime($r['last']))) . '</span>' : '' ?></dd></div>
      <div><dt>Konto Office</dt><dd><?= $o['upn'] !== '' ? '<span class="m-mono">' . h($o['upn']) . '</span> ' : 'brak imienia lub nazwiska ' ?><?= $tag($o['ok'] ? 'ok' : ($o['state'] === 'error' ? 'bad' : 'off'), $o['ok'] ? 'aktywne' : $o['label']) ?></dd></div>
      <div><dt>Karta</dt><dd><?= $r['card'] ? h(LicenceCard::label($r['card'])) : '<span class="m-muted">brak</span>' ?></dd></div>
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
    </div>
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
