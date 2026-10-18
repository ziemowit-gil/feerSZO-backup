<?php
/**
 * panel/rozliczenia_zlecenie.php
 * Widok zleceniobiorcy: ewidencja godzin i saldo rozliczeń własnych umów zlecenie (tylko odczyt).
 * Dane liczy includes/zlecenie_rachunki.php (sekcja ewidencji / salda). Rachunki wystawia księgowy.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/zlecenie_rachunki.php';

require_login();

$PAGE_TITLE = 'Godziny i rozliczenia — umowy zlecenie';
$user  = current_user();
$email = (string)($user['email'] ?? '');
$ms_id = (string)($user['microsoft_id'] ?? '');
$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową

// ── Umowy zlecenie użytkownika (te same kryteria co viewer_owns_contract + e-mail z umowy) ──
$conds = []; $params = [];
if ($ms_id !== '') { $conds[] = 'm365_user_id = ?'; $params[] = $ms_id; }
if ($email !== '') { $conds[] = 'm365_login = ?'; $params[] = $email; $conds[] = 'email = ?'; $params[] = $email; }
$contracts = $conds
    ? db_all("SELECT * FROM umowy_zlecenie WHERE (" . implode(' OR ', $conds) . ") AND status NOT IN ('anulowana','rozwiązana') ORDER BY data_zawarcia DESC, id DESC", $params)
    : [];

$sel_id = (int)($_GET['id'] ?? 0);
$sel    = null;
foreach ($contracts as $c) { if ($sel_id ? (int)$c['id'] === $sel_id : $sel === null) $sel = $c; }
$ledger = $sel ? zl_rozl_ledger($sel) : null;
$months = $sel ? zl_ew_months($sel) : [];
$ym     = (isset($_GET['ym']) && zl_ew_ym_valid((string)$_GET['ym'])) ? (string)$_GET['ym'] : ($months[0] ?? date('Y-m'));
$wpisy  = $sel ? zl_ew_wpisy((int)$sel['id'], $ym) : [];
$grupy  = $sel ? zl_ew_month_groups((int)$sel['id'], $ym) : [];
$h_zr   = null; $h_ewid = $sel ? zl_ew_month_hours((int)$sel['id'], $ym, $h_zr) : 0.0;
$h_rozl = $sel ? zl_rozl_month_hours((int)$sel['id'], $ym) : 0.0;
$przes  = $sel ? array_filter(zl_przes_list((int)$sel['id']), fn($p) => $p['z_ym'] === $ym || $p['do_ym'] === $ym) : [];
$row_m  = null;
if ($ledger) foreach ($ledger['miesiace'] as $m) if ($m['ym'] === $ym) { $row_m = $m; break; }

$fmt_h = fn($v) => number_format((float)$v, 2, ',', ' ');
// Sierpień 2026: znany problem z przeliczaniem godzin — ostrzeżenie dla zleceniobiorcy, gdy ma ten miesiąc w rozliczeniu
$_zl_aug_issue = $ledger && in_array(ZL_ROZL_AUG_ISSUE_YM, array_column($ledger['miesiace'], 'ym'), true);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles(); // style panelu (.tz-*, .pv-*) także pod powłoką SZO
}
?>
<div class="container-xl py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-calculator text-primary"></i> Godziny i rozliczenia</h1>
    <?php if (count($contracts) > 1): ?>
    <form method="get" class="d-flex align-items-center gap-2">
      <label for="rz-umowa" class="small text-muted mb-0">Umowa</label>
      <select id="rz-umowa" name="id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($contracts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $sel && (int)$c['id'] === (int)$sel['id'] ? 'selected' : '' ?>><?= h($c['numer_umowy']) ?> · <?= h($c['status']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$sel): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-file-earmark-text" style="font-size:2.4rem;opacity:.3"></i>
    <div class="mt-2">Nie znaleziono umowy zlecenie przypisanej do Twojego konta.</div>
  </div>
  <?php else: $S = $ledger['suma']; $typ = (string)($sel['typ_stawki'] ?? ''); ?>

  <?php if ($_zl_aug_issue): ?>
  <div class="alert alert-warning d-flex gap-2 align-items-start mb-3" role="note">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <div class="fw-bold">Rozliczenie za sierpień 2026 — prosimy się nim nie sugerować</div>
      Kwoty i saldo za sierpień (a przez przeniesienie także w kolejnych miesiącach) mogą być nieprawidłowe.
      Wynika to ze zidentyfikowanego w systemie problemu z przeliczaniem godzin, który poprawiamy.
      Rachunek wystawia księgowość na podstawie zweryfikowanej ewidencji — nie musisz nic robić.
    </div>
  </div>
  <?php endif; ?>

  <!-- Umowa + podsumowanie -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <div class="flex-grow-1">
          <div class="fw-bold fs-5"><?= h($sel['numer_umowy']) ?></div>
          <div class="text-muted small">
            <?= h($sel['przedmiot_zlecenia'] ? mb_strimwidth($sel['przedmiot_zlecenia'], 0, 120, '…') : 'Umowa zlecenie') ?><br>
            <?= date_pl($sel['data_rozpoczecia'] ?: $sel['data_zawarcia']) ?> – <?= !empty($sel['bezterminowa']) ? 'bezterminowo' : date_pl($sel['data_zakonczenia']) ?>
            · status: <b><?= h($sel['status']) ?></b>
            <?php if ($typ === 'godzinowo'): ?> · stawka <b><?= money((float)$sel['stawka_kwota']) ?>/h</b>
            <?php elseif ($typ === 'ryczalt'): ?> · ryczałt <b><?= money((float)$sel['wynagrodzenie_brutto']) ?></b>
            <?php elseif ($typ === 'miesiecznie'): ?> · <b><?= money((float)$sel['stawka_kwota']) ?>/mies.</b><?php endif; ?>
            · źródło godzin: <b><?= h(ZL_EW_ZRODLA[zl_ew_zrodlo($sel)]['label']) ?></b>
          </div>
        </div>
        <a href="<?= APP_URL ?>/contracts/zlecenie/view.php?id=<?= (int)$sel['id'] ?>&tab=rachunki" class="btn btn-sm btn-outline-primary"><i class="bi bi-receipt me-1"></i>Rachunki</a>
      </div>
      <div class="row g-2 text-center mt-2">
        <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-5 fw-bold"><?= $fmt_h($S['godziny_rozl']) ?> h</div><div class="text-muted small">godzin rozliczanych</div></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-5 fw-bold"><?= money($S['naleznosc']) ?></div><div class="text-muted small">należność razem (brutto)</div></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-5 fw-bold text-success"><?= money($S['wyplata']) ?></div><div class="text-muted small">rachunki w rejestrze</div></div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 <?= abs($S['pozostalo']) > 0.004 ? 'border-warning' : 'border-success' ?>">
          <div class="fs-5 fw-bold <?= $S['pozostalo'] > 0.004 ? 'text-warning-emphasis' : ($S['pozostalo'] < -0.004 ? 'text-danger' : 'text-success') ?>"><?= money($S['pozostalo']) ?></div>
          <div class="text-muted small"><?= $S['pozostalo'] < -0.004 ? 'nadpłata' : 'pozostało do rozliczenia' ?></div></div></div>
      </div>
      <div class="small text-muted mt-2">Kwoty są wstępne (brutto, przed składkami i podatkiem). Rachunek wystawia księgowość na podstawie tego zestawienia.</div>
    </div>
  </div>

  <!-- Saldo miesięczne -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-table"></i> Rozliczenie miesiąc po miesiącu</div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.88rem">
      <thead class="table-light"><tr><th>Miesiąc</th><th class="text-end">Godziny</th><th class="text-end">Należność</th><th class="text-end">Z poprz.</th><th class="text-end">Rachunki</th><th class="text-end">Pozostało</th><th>Stan</th></tr></thead>
      <tbody>
      <?php foreach ($ledger['miesiace'] as $m): ?>
        <tr class="<?= $m['ym'] === $ym ? 'table-primary bg-opacity-10' : '' ?>">
          <td class="text-nowrap"><a href="?id=<?= (int)$sel['id'] ?>&ym=<?= h($m['ym']) ?>" class="text-decoration-none fw-semibold"><?= h($m['label']) ?></a><?php if ($m['ym'] === ZL_ROZL_AUG_ISSUE_YM): ?> <span class="badge text-bg-warning" title="Znany problem z przeliczaniem godzin — kwoty mogą być nieprawidłowe">do weryfikacji</span><?php endif; ?></td>
          <td class="text-end"><?= $fmt_h($m['godziny_rozl']) ?><?php if (abs($m['godziny_rozl'] - $m['godziny_ewid']) > 0.004): ?> <span class="text-muted small" title="po przesunięciach i korektach">(ewid. <?= $fmt_h($m['godziny_ewid']) ?>)</span><?php endif; ?></td>
          <td class="text-end text-nowrap"><?= money($m['naleznosc']) ?></td>
          <td class="text-end text-nowrap text-muted"><?= abs($m['przeniesione_in']) > 0.004 ? money($m['przeniesione_in']) : '—' ?></td>
          <td class="text-end text-nowrap"><?= money($m['wyplata']) ?>
            <?php foreach ($m['rachunki'] as $r): ?><div class="small text-muted">#<?= (int)$r['id'] ?><?= $r['numer'] ? ' ' . h($r['numer']) : '' ?> · <?= money($r['kwota']) ?></div><?php endforeach; ?></td>
          <td class="text-end text-nowrap fw-semibold <?= $m['pozostalo'] > 0.004 ? 'text-warning-emphasis' : ($m['pozostalo'] < -0.004 ? 'text-danger' : 'text-success') ?>"><?= money($m['pozostalo']) ?></td>
          <td><?= $m['status'] === 'zamkniety' ? '<span class="badge text-bg-secondary"><i class="bi bi-lock"></i> zamknięty</span>' : '<span class="badge text-bg-success-subtle text-success-emphasis border">otwarty</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ledger['miesiace']): ?><tr><td colspan="7" class="text-center text-muted py-3">Brak miesięcy do rozliczenia.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Ewidencja wybranego miesiąca -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2">
      <span><i class="bi bi-calendar-week"></i> Ewidencja godzin — <?= h(zl_ew_ym_label($ym)) ?></span>
      <span class="ms-auto small fw-normal text-muted">
        ewidencja <b><?= $fmt_h($h_ewid) ?> h</b><?= $h_zr === 'karta' ? ' (karta miesięczna)' : '' ?>
        <?php if (abs($h_rozl - $h_ewid) > 0.004): ?> · rozliczane <b><?= $fmt_h($h_rozl) ?> h</b><?php endif; ?>
      </span>
    </div>
    <div class="card-body">
      <?php if ($grupy): ?>
      <table class="table table-sm mb-3" style="font-size:.88rem">
        <thead class="table-light"><tr><th>Podział</th><th>Kod</th><th class="text-end">Wpisy</th><th class="text-end">Godziny</th></tr></thead>
        <tbody>
        <?php foreach ($grupy as $g): ?>
          <tr><td><?= $g['zrodlo'] === 'karty30' ? '<i class="bi bi-journal-bookmark text-info"></i> ' : '<i class="bi bi-pencil text-secondary"></i> ' ?><?= h($g['nazwa']) ?></td><td class="text-muted small"><?= h($g['kod'] ?: '—') ?></td><td class="text-end"><?= (int)$g['liczba'] ?></td><td class="text-end fw-semibold"><?= $fmt_h($g['godziny']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <?php if ($wpisy): ?>
      <table class="table table-sm mb-0" style="font-size:.88rem">
        <thead class="table-light"><tr><th style="width:7rem">Data</th><th>Opis</th><th class="text-end" style="width:6rem">Godziny</th></tr></thead>
        <tbody>
        <?php foreach ($wpisy as $w): ?>
          <tr><td class="text-nowrap"><?= date_pl($w['data']) ?></td><td><?= h($w['opis'] ?: '—') ?></td><td class="text-end fw-semibold"><?= $fmt_h($w['godziny']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="text-muted small">Brak wpisów dziennych w tym miesiącu<?= $h_zr === 'karta' ? ' — godziny z karty miesięcznej' : '' ?>.</div>
      <?php endif; ?>
      <?php if ($przes): ?>
      <div class="mt-3 small">
        <div class="fw-semibold mb-1"><i class="bi bi-arrow-left-right"></i> Przesunięcia godzin</div>
        <?php foreach ($przes as $p): ?>
        <div><?= $p['z_ym'] === $ym ? '−' : '+' ?><?= $fmt_h($p['godziny']) ?> h <?= $p['z_ym'] === $ym ? 'przeniesione do ' . h(zl_ew_ym_label($p['do_ym'])) : 'przeniesione z ' . h(zl_ew_ym_label($p['z_ym'])) ?> — <span class="text-muted"><?= h($p['powod']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($row_m && $row_m['korekty']): ?>
      <div class="mt-3 small">
        <div class="fw-semibold mb-1"><i class="bi bi-pencil-square"></i> Korekty zaksięgowane w tym miesiącu</div>
        <?php foreach ($row_m['korekty'] as $k): ?>
        <div><?= ((float)$k['kwota'] >= 0 ? '+' : '') . money((float)$k['kwota']) ?><?= abs((float)$k['godziny']) > 0.004 ? ', ' . ((float)$k['godziny'] >= 0 ? '+' : '') . $fmt_h($k['godziny']) . ' h' : '' ?><?= $k['dotyczy_ym'] ? ' (dotyczy ' . h(zl_ew_ym_label($k['dotyczy_ym'])) . ')' : '' ?> — <span class="text-muted"><?= h($k['powod']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="text-muted small mt-3">Widzisz błąd w ewidencji? Zgłoś go opiekunowi umowy — wpisy i korekty wprowadza organizacja.</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
