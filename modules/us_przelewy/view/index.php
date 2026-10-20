<?php
/**
 * modules/us_przelewy/view/index.php — rejestr zobowiązań podatkowych, plik przelewów do US i ustawienia.
 * Zmienne: $rows, $f (filtry), $forms, $konta (rachunki organizacji), $rachunek (rachunek US), $nip. Kontroler: index.
 */
$PAGE_TITLE = 'Przelewy do Urzędu Skarbowego';
include dirname(__DIR__, 3) . '/includes/header.php';
$money = fn($v) => number_format(_edok_kwota_float((string)$v), 2, ',', ' ');
$today = date('Y-m-d');
$domyslneKonto = '';
foreach ($konta as $k) if (substr(preg_replace('/\D/', '', (string)$k['nrb']), 2, 4) === '1020') { $domyslneKonto = preg_replace('/\D/', '', (string)$k['nrb']); break; }
if ($domyslneKonto === '' && $konta) $domyslneKonto = preg_replace('/\D/', '', (string)$konta[0]['nrb']);
$otwarte = array_filter($rows, fn($z) => in_array($z['status'], ['do_zaplaty', 'zlecony'], true));
$poTerminie = array_filter($otwarte, fn($z) => $z['termin'] < $today);
$mikro = uspMikrorachunek($nip);
$uwagiRach = $rachunek !== '' ? uspRachunekUwagi($rachunek) : [];
$fmtLabels = EDOK_PRZELEWY_FORMATY;
?>
<style type="text/tailwindcss">
  .usp-card  { @apply tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-4 tw-shadow-sm sm:tw-p-5; }
  .usp-input { @apply tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-outline-none focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100; }
  .usp-label { @apply tw-mb-1 tw-block tw-text-sm tw-font-medium tw-text-slate-700; }
  .usp-table th { @apply tw-bg-slate-50 tw-px-3 tw-py-2 tw-text-left tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500; }
  .usp-table td { @apply tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-px-3 tw-py-2 tw-align-top tw-text-sm; }
</style>

<div class="tw-mx-auto tw-max-w-6xl">
  <div class="tw-mb-4 tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3">
    <div>
      <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500">EODoK · Płatności</p>
      <h1 class="tw-m-0 tw-mt-1 tw-text-2xl tw-font-semibold tw-text-slate-900"><i class="bi bi-bank2 tw-mr-1 tw-text-blue-600" aria-hidden="true"></i> Przelewy do Urzędu Skarbowego</h1>
      <p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-sm tw-text-slate-500">Zobowiązania podatkowe (PIT-4R, PIT-8AR, CIT, VAT) z okresem, kwotą i terminem. Plik dla banku zawiera przelew podatkowy: tytuł <span class="tw-font-mono">/TI/N…/OKR/…/SFP/…</span> i kod 71. Bez obiegu akceptacji — kwota wynika z deklaracji.</p>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= h(route('usp.create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nowe zobowiązanie</a>
  </div>
  <?= flash_html() ?>

  <?php if ($rachunek === ''): ?>
  <div class="alert alert-warning"><strong>Brak rachunku urzędu skarbowego.</strong> Zapisz go w <a href="#ustawienia">ustawieniach poniżej</a> — bez niego nie da się dodać zobowiązania.</div>
  <?php endif; ?>
  <?php if ($poTerminie): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i> Po terminie: <strong><?= count($poTerminie) ?></strong> — sprawdź, czy przelew wyszedł, i oznacz zapłatę albo zapłać z odsetkami.</div>
  <?php endif; ?>

  <form method="get" action="<?= h(route('usp.index')) ?>" class="tw-mb-3 tw-flex tw-flex-wrap tw-items-end tw-gap-2">
    <div><label class="usp-label" for="f-st">Status</label>
      <select id="f-st" name="status" class="usp-input">
        <option value="">otwarte (do zapłaty i zlecone)</option>
        <?php foreach (USP_STATUSY as $k => $s): ?><option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?>
        <option value="wszystkie" <?= $f['status'] === 'wszystkie' ? 'selected' : '' ?>>wszystkie</option>
      </select></div>
    <div><label class="usp-label" for="f-fo">Rodzaj</label>
      <select id="f-fo" name="formularz" class="usp-input"><option value="">wszystkie</option>
        <?php foreach ($forms as $k => $fo): ?><option value="<?= h($k) ?>" <?= $f['formularz'] === $k ? 'selected' : '' ?>><?= h($fo['sfp'] . ['M' => '', 'K' => ' (kwartalny)', 'R' => ' (roczny)'][$fo['okres']]) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="usp-label" for="f-rok">Rok okresu</label>
      <input id="f-rok" name="rok" type="number" min="2000" max="2100" class="usp-input tw-w-28" value="<?= $f['rok'] ?: '' ?>"></div>
    <button class="btn btn-outline-secondary btn-sm" type="submit">Pokaż</button>
  </form>

  <form method="post" action="<?= h(route('usp.generuj')) ?>" class="usp-card">
    <?= Router::csrfField() ?>
    <?php if (!$rows): ?>
      <p class="tw-m-0 tw-text-sm tw-text-slate-600">Brak zobowiązań<?= $f['status'] === '' ? ' do zapłaty' : '' ?>.</p>
    <?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="usp-table tw-w-full">
        <caption class="visually-hidden">Zobowiązania podatkowe</caption>
        <thead><tr>
          <th scope="col"><input type="checkbox" class="form-check-input" id="uspAll" aria-label="Zaznacz wszystkie do zapłaty"></th>
          <th scope="col">Rodzaj</th>
          <th scope="col">Okres</th>
          <th scope="col" class="tw-text-right">Kwota</th>
          <th scope="col">Termin</th>
          <th scope="col">Tytuł przelewu</th>
          <th scope="col">Status</th>
          <th scope="col">Akceptacja</th>
          <th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $z):
          $open = in_array($z['status'], ['do_zaplaty', 'zlecony'], true);
          $late = $open && $z['termin'] < $today;
          $okres = uspOkresLabel($z['okres_typ'], (int)$z['okres_rok'], (int)$z['okres_nr']);
          $blokada = $open ? uspBlokadaEksportu($z) : null;
          $zd = uspZatwDoc($z); ?>
          <tr class="<?= $open ? '' : 'tw-text-slate-500' ?>">
            <td><?php if ($open && !$blokada): ?><input type="checkbox" class="form-check-input usp-cb" name="zid[]" value="<?= (int)$z['id'] ?>" data-kwota="<?= h((string)_edok_kwota_float($z['kwota'])) ?>"
                   <?= $z['status'] === 'do_zaplaty' ? 'checked' : '' ?> aria-label="<?= h($z['sfp'] . ' ' . $okres) ?>"><?php elseif ($blokada): ?><i class="bi bi-lock tw-text-slate-400" title="<?= h($blokada) ?>" aria-label="<?= h($blokada) ?>"></i><?php endif; ?></td>
            <td><a class="tw-font-medium" href="<?= h(route('usp.show', ['id' => $z['id']])) ?>"><?= h($z['sfp']) ?></a>
              <?php if ($z['zalacznik_path']): ?><i class="bi bi-paperclip tw-text-slate-400" title="deklaracja" aria-label="ma deklarację"></i><?php endif; ?>
              <?php if ($z['wyliczenie_path'] ?? ''): ?><i class="bi bi-calculator tw-text-slate-400" title="wyliczenie" aria-label="ma wyliczenie"></i><?php endif; ?></td>
            <td class="tw-whitespace-nowrap"><?= h($okres) ?></td>
            <td class="tw-whitespace-nowrap tw-text-right tw-font-semibold"><?= $money($z['kwota']) ?></td>
            <td class="tw-whitespace-nowrap <?= $late ? 'tw-font-semibold tw-text-red-700' : '' ?>"><?= h(date('d.m.Y', strtotime($z['termin']))) ?><?= $late ? ' <span class="badge text-bg-danger">po terminie</span>' : '' ?></td>
            <td class="tw-font-mono tw-text-xs"><?= h(uspTytul($z)) ?></td>
            <td class="tw-text-xs"><span class="badge <?= h(USP_STATUSY[$z['status']]['badge'] ?? 'text-bg-light') ?>"><?= h(USP_STATUSY[$z['status']]['label'] ?? $z['status']) ?></span>
              <?php if ($z['status'] === 'zaplacony' && $z['data_zaplaty']): ?><div class="tw-mt-1"><?= h(date('d.m.Y', strtotime($z['data_zaplaty']))) ?></div><?php endif; ?>
              <?php if ($z['status'] === 'zlecony' && $z['zlecono_at']): ?><div class="tw-mt-1">plik <?= h(date('d.m.Y', strtotime($z['zlecono_at']))) ?></div><?php endif; ?></td>
            <td class="tw-text-xs"><?php if ($zd): $eds = EDOK_STATUSES[$zd['status']] ?? ['label' => $zd['status'], 'class' => 'secondary']; ?>
              <a href="<?= h(APP_URL) ?>/edok/view.php?id=<?= (int)$zd['id'] ?>"><?= h($zd['number']) ?></a><div class="tw-mt-1"><span class="badge text-bg-<?= h($eds['class']) ?>"><?= h($eds['label']) ?></span></div>
              <?php elseif ($open): ?><span class="tw-text-slate-500">niezaakceptowane</span><?php else: ?>—<?php endif; ?></td>
            <td class="tw-whitespace-nowrap"><?php if ($open): ?>
              <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#uspPaid" data-id="<?= (int)$z['id'] ?>" data-opis="<?= h($z['sfp'] . ' ' . $okres . ' — ' . $money($z['kwota']) . ' zł') ?>">Zapłacono</button>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($otwarte): ?>
    <div class="tw-mt-4 tw-grid tw-gap-4 tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-pt-4 md:tw-grid-cols-3">
      <div>
        <label for="rachunek_z" class="usp-label">Z rachunku organizacji</label>
        <select id="rachunek_z" name="rachunek_z" class="usp-input tw-w-full" required>
          <?php if (!$konta): ?><option value="">— brak rachunków w Ustawieniach organizacji —</option><?php endif; ?>
          <?php foreach ($konta as $k): $n = preg_replace('/\D/', '', (string)$k['nrb']); ?>
          <option value="<?= h($n) ?>" <?= $n === $domyslneKonto ? 'selected' : '' ?>><?= h(trim(($k['nazwa'] ?? '') . ' ' . ($k['bank'] ?? ''))) ?> · …<?= h(substr($n, -4)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="format" class="usp-label">Format pliku</label>
        <select id="format" name="format" class="usp-input tw-w-full" aria-describedby="formatHelp">
          <?php foreach (USP_FORMATY as $fk): ?><option value="<?= h($fk) ?>"><?= h($fmtLabels[$fk] ?? $fk) ?></option><?php endforeach; ?>
        </select>
        <p id="formatHelp" class="tw-m-0 tw-mt-1 tw-text-xs tw-text-slate-500">Tylko formaty ELIXIR-O — oznaczają przelew podatkowy kodem 71. Po imporcie sprawdź w bankowości, że przelew jest typu „do US”.</p>
      </div>
      <div class="tw-pt-1">
        <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="potwierdz_ponowne" value="1"> Potwierdzam ponowne ujęcie zobowiązań, które były już w pliku (status „Zlecony”)</label>
      </div>
    </div>
    <div class="tw-mt-4 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
      <p class="tw-m-0 tw-text-sm" aria-live="polite">Zaznaczone: <strong id="uspIle">0</strong> · suma <strong id="uspSuma">0,00</strong> zł</p>
      <button type="submit" class="btn btn-primary" <?= $konta ? '' : 'disabled' ?>><i class="bi bi-download me-1" aria-hidden="true"></i> Generuj plik przelewów</button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </form>

  <section id="ustawienia" class="usp-card tw-mt-5" aria-labelledby="uspSetH">
    <h2 id="uspSetH" class="tw-m-0 tw-mb-3 tw-text-base tw-font-semibold">Ustawienia</h2>
    <form method="post" action="<?= h(route('usp.settings')) ?>" class="tw-grid tw-gap-4 md:tw-grid-cols-2">
      <?= Router::csrfField() ?>
      <div>
        <label for="s-rach" class="usp-label">Rachunek urzędu skarbowego (mikrorachunek podatkowy)</label>
        <input id="s-rach" name="rachunek" class="usp-input tw-w-full tw-font-mono" required aria-describedby="s-rach-h"
               value="<?= h($rachunek !== '' ? edok_nrb_format($rachunek) : '') ?>" placeholder="00 1010 0071 2222 …">
        <p id="s-rach-h" class="tw-m-0 tw-mt-1 tw-text-xs tw-text-slate-500">Sprawdzana suma kontrolna IBAN. PIT, CIT i VAT płaci się na mikrorachunek
          <?php if ($mikro !== ''): ?>— dla NIP <?= h($nip) ?> to <button type="button" class="btn btn-link btn-sm tw-p-0 tw-align-baseline tw-font-mono" onclick="document.getElementById('s-rach').value=this.textContent"><?= h(edok_nrb_format($mikro)) ?></button><?php else: ?>(brak NIP w Ustawieniach organizacji)<?php endif; ?>.</p>
        <?php foreach ($uwagiRach as $u): ?><p class="tw-m-0 tw-mt-1 tw-text-xs tw-font-semibold tw-text-amber-700"><?= h($u) ?></p><?php endforeach; ?>
      </div>
      <div>
        <label for="s-odb" class="usp-label">Nazwa odbiorcy w przelewie</label>
        <input id="s-odb" name="odbiorca" class="usp-input tw-w-full" maxlength="70" value="<?= h((string)org_setting('usp_odbiorca')) ?>" placeholder="<?= h(USP_ODBIORCA_DOMYSLNY) ?>">
      </div>
      <div>
        <label for="s-mail" class="usp-label">Przypomnienia o terminach — adresy e-mail</label>
        <input id="s-mail" name="przypomnienia" class="usp-input tw-w-full" value="<?= h((string)org_setting('usp_przypomnienia')) ?>" placeholder="ksiegowosc@…, skarbnik@…" aria-describedby="s-mail-h">
        <p id="s-mail-h" class="tw-m-0 tw-mt-1 tw-text-xs tw-text-slate-500">Puste = przypomnienia wyłączone. Agent CRON wysyła je rano: przed terminem i w dniu po terminie.</p>
      </div>
      <div>
        <label for="s-dni" class="usp-label">Ile dni przed terminem przypominać</label>
        <input id="s-dni" name="przypomnienia_dni" type="number" min="0" max="30" class="usp-input tw-w-28" value="<?= h((string)(org_setting('usp_przypomnienia_dni') !== '' ? org_setting('usp_przypomnienia_dni') : '3')) ?>">
      </div>
      <div class="md:tw-col-span-2">
        <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="wymaga_zatwierdzenia" value="1" <?= uspWymagaZatwierdzenia() ? 'checked' : '' ?>>
          Plik przelewu tylko dla zaakceptowanych zobowiązań (przycisk „Akceptuję” na karcie zobowiązania)</label>
      </div>
      <div class="md:tw-col-span-2 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2">
        <p class="tw-m-0 tw-text-xs tw-text-slate-500">Rodzaje zobowiązań, symbole formularzy i ustawowe terminy: <a href="<?= h(APP_URL) ?>/modules/dictionary/router.php">Słownik Centralny</a> → „Księgowość — formularze przelewów do US”.</p>
        <button type="submit" class="btn btn-outline-primary btn-sm">Zapisz ustawienia</button>
      </div>
    </form>
  </section>
</div>

<div class="modal fade" id="uspPaid" tabindex="-1" aria-labelledby="uspPaidH" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content" id="uspPaidForm" action="">
    <?= Router::csrfField() ?>
    <input type="hidden" name="status" value="zaplacony"><input type="hidden" name="back" value="lista">
    <div class="modal-header"><h2 class="modal-title fs-5" id="uspPaidH">Oznacz jako zapłacone</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <p id="uspPaidOpis" class="small"></p>
      <label for="uspPaidData" class="form-label">Data zapłaty</label>
      <input type="date" class="form-control" id="uspPaidData" name="data_zaplaty" value="<?= h($today) ?>" max="<?= h($today) ?>" required>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button type="submit" class="btn btn-success">Zapisz</button></div>
  </form></div>
</div>

<script>
(function () {
  var base = <?= json_encode(route('usp.show', ['id' => 0])) ?>.replace(/\/0$/, '/');
  var modal = document.getElementById('uspPaid');
  modal.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('uspPaidForm').action = base + b.dataset.id + '/status';
    document.getElementById('uspPaidOpis').textContent = b.dataset.opis;
  });
  var cbs = Array.prototype.slice.call(document.querySelectorAll('.usp-cb'));
  var all = document.getElementById('uspAll');
  function upd() {
    var n = 0, s = 0;
    cbs.forEach(function (c) { if (c.checked) { n++; s += parseFloat(c.dataset.kwota || '0'); } });
    var ile = document.getElementById('uspIle'); if (!ile) return;
    ile.textContent = n;
    document.getElementById('uspSuma').textContent = s.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  cbs.forEach(function (c) { c.addEventListener('change', upd); });
  if (all) all.addEventListener('change', function () { cbs.forEach(function (c) { c.checked = all.checked; }); upd(); });
  upd();
})();
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
