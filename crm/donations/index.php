<?php
/**
 * crm/donations/index.php — rejestr darowizn.
 *
 * Rejestr istnieje przede wszystkim po to, żeby w lutym dało się jednym
 * kliknięciem wydać darczyńcy potwierdzenie za poprzedni rok. Dlatego filtr roku
 * jest pierwszym elementem, a nie schowanym parametrem.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';
require_once dirname(dirname(__DIR__)) . '/includes/donations.php';

require_login();
crm_require('donations', 'read');
require_module_enabled('donations_enabled', 'Moduł Darowizny');
crm_migrate();
donations_migrate();

$PAGE_TITLE = 'Darowizny';
$can_write  = is_admin() || can_write('crm');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    if (($_POST['_op'] ?? '') === 'delete') {
        donation_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Darowizna usunięta z rejestru.');
    }
    header('Location: ' . APP_URL . '/crm/donations/index.php?' . http_build_query(['year' => (int)($_POST['year'] ?? date('Y'))]));
    exit;
}

$years = donation_years();
$year  = (int)($_GET['year'] ?? ($years[0] ?? date('Y')));
$f = [
    'year'    => $year,
    'kind'    => (string)($_GET['kind'] ?? ''),
    'channel' => (string)($_GET['channel'] ?? ''),
    'q'       => trim((string)($_GET['q'] ?? '')),
];
$rows  = donations_list($f);
$stats = donations_stats($year);
$m     = fn(float $v) => number_format($v, 2, ',', ' ');

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-gift me-2"></i>Darowizny
      <span class="badge bg-secondary"><?= count($rows) ?></span>
    </h1>
    <?php if ($can_write): ?>
    <a href="<?= APP_URL ?>/crm/donations/form.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Dopisz darowiznę
    </a>
    <?php endif; ?>
  </div>

  <?= flash_get() ?>

  <div class="row g-2 mb-3">
    <?php foreach ([
      ['Wpłat w roku ' . $year, (string)$stats['count'],                  'bi-list-ol',      '#0176D3'],
      ['Suma',                  $m($stats['total']) . ' zł',              'bi-cash-coin',    '#16A34A'],
      ['Darczyńców',            (string)$stats['donors'],                 'bi-people',       '#7C3AED'],
      ['W tym rzeczowe',        $m($stats['total_rzeczowa']) . ' zł',     'bi-box-seam',     '#D97706'],
    ] as [$lbl, $val, $ico, $col]): ?>
    <div class="col-6 col-lg-3">
      <div class="card h-100">
        <div class="card-body py-2 px-3">
          <div class="text-muted" style="font-size:.72rem"><i class="bi <?= $ico ?> me-1" style="color:<?= $col ?>"></i><?= h($lbl) ?></div>
          <div class="fw-bold" style="font-size:1.05rem"><?= h($val) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($stats['total_gotowka'] > 0): ?>
  <div class="alert alert-warning py-2 px-3 small d-flex gap-2">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
    <div>
      W <?= (int)$year ?> r. przyjęto <strong><?= h($m($stats['total_gotowka'])) ?> zł</strong> darowizn
      pieniężnych w gotówce. Gotówka nie jest wpłatą na rachunek płatniczy, więc darczyńca
      nie ma dowodu wymaganego przez art. 26 ust. 7 pkt 1 ustawy o PIT — potwierdzenie
      wystawimy, ale z jawnym zastrzeżeniem.
    </div>
  </div>
  <?php endif; ?>

  <form method="get" class="card mb-3">
    <div class="card-body py-2 row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label small mb-1" for="dn_year">Rok</label>
        <select name="year" id="dn_year" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach (array_unique(array_merge($years, [(int)date('Y')])) as $y): ?>
          <option value="<?= (int)$y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1" for="dn_kind">Rodzaj</label>
        <select name="kind" id="dn_kind" class="form-select form-select-sm">
          <option value="">wszystkie</option>
          <?php foreach (DONATION_KINDS as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1" for="dn_ch">Sposób</label>
        <select name="channel" id="dn_ch" class="form-select form-select-sm">
          <option value="">wszystkie</option>
          <?php foreach (DONATION_CHANNELS as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= $f['channel'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="form-label small mb-1" for="dn_q">Szukaj</label>
        <input type="search" name="q" id="dn_q" class="form-control form-control-sm"
               value="<?= h($f['q']) ?>" placeholder="darczyńca, cel, opis, tytuł przelewu">
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-secondary btn-sm">Filtruj</button>
        <a href="?" class="btn btn-link btn-sm">Wyczyść</a>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:6.5rem">Data</th>
            <th>Darczyńca</th>
            <th>Cel / przedmiot</th>
            <th style="width:9rem">Sposób</th>
            <th class="text-end" style="width:8rem">Kwota</th>
            <th style="width:11rem"><span class="visually-hidden">Dokumenty</span></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-muted small py-4 text-center">
            Brak darowizn dla wybranych kryteriów.
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $d): $rzecz = $d['kind'] === 'rzeczowa'; ?>
          <tr>
            <td class="small"><?= h(date('d.m.Y', strtotime((string)$d['donation_date']))) ?></td>
            <td>
              <?php if (!empty($d['contact_id'])): ?>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$d['contact_id'] ?>" class="text-decoration-none">
                <?= h((string)($d['contact_name'] ?? $d['donor_name'])) ?>
              </a>
              <?php else: ?>
              <?= h((string)$d['donor_name']) ?>
              <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem"
                    title="Bez kartoteki nie da się wystawić potwierdzenia rocznego">bez kartoteki</span>
              <?php endif; ?>
              <?php if (!empty($d['is_anonymous'])): ?>
              <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size:.62rem">anonimowa</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= h((string)($rzecz ? ($d['description'] ?: '—') : ($d['purpose'] ?: '—'))) ?>
              <?php if ($rzecz): ?>
              <span class="badge bg-warning-subtle text-warning-emphasis border" style="font-size:.62rem">rzeczowa</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= h($rzecz ? 'przekazanie rzeczy' : donation_channel_label((string)$d['channel'])) ?></td>
            <td class="text-end"><?= h($m((float)$d['amount'])) ?> <?= h((string)$d['currency']) ?></td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <?php if ($rzecz): ?>
                <a href="<?= APP_URL ?>/crm/donations/pdf.php?id=<?= (int)$d['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.72rem"
                   title="Oświadczenie o przyjęciu darowizny (art. 26 ust. 7 pkt 2)">Oświadczenie</a>
                <?php elseif (!empty($d['contact_id'])): ?>
                <a href="<?= APP_URL ?>/crm/donations/pdf.php?contact=<?= (int)$d['contact_id'] ?>&year=<?= (int)$year ?>"
                   target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.72rem"
                   title="Potwierdzenie wszystkich darowizn pieniężnych tego darczyńcy za <?= (int)$year ?> r.">Do PIT</a>
                <?php endif; ?>
                <?php if ($can_write): ?>
                <a href="<?= APP_URL ?>/crm/donations/form.php?id=<?= (int)$d['id'] ?>"
                   class="btn btn-sm btn-outline-secondary py-0 px-2" aria-label="Edytuj">
                  <i class="bi bi-pencil" aria-hidden="true"></i></a>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Usunąć darowiznę z rejestru? Wystawione potwierdzenia przestaną się zgadzać.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <input type="hidden" name="year" value="<?= (int)$year ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" aria-label="Usuń">
                    <i class="bi bi-trash3" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Darowizna <strong>pieniężna</strong>: podstawą odliczenia jest dowód wpłaty na rachunek
    (art. 26 ust. 7 pkt 1 ustawy o PIT), a nasze potwierdzenie roczne jest dokumentem
    pomocniczym. Darowizna <strong>rzeczowa</strong>: wymaganym dokumentem jest oświadczenie
    obdarowanego o przyjęciu (pkt 2) — i to je generujemy.
  </p>
</div>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
