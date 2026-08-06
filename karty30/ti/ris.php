<?php
/**
 * karty30/ti/ris.php — Dane do RIS (Rejestr Instytucji Szkoleniowych) + dane kierownika.
 *
 * Ustawienia wykorzystywane w sprawozdaniu do WUP (nagłówek instytucji, nr/data wpisu
 * do RIS, województwo) oraz w bloku podpisu (kierownik: imię, nazwisko, stanowisko).
 * Dane tożsamości organizacji (nazwa, NIP, REGON, adres) pochodzą z ustawień ogólnych.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();

// Klucze ustawień RIS / kierownika (przechowywane w tabeli settings)
$RIS_KEYS = [
    'ti_ris_number'      => ['Numer wpisu do RIS',        'np. 2.12/00123/2023'],
    'ti_ris_date'        => ['Data wpisu do RIS',         'RRRR-MM-DD'],
    'ti_ris_voivodeship' => ['Województwo',               'np. małopolskie'],
    'ti_wup_name'        => ['Właściwy WUP',              'np. Wojewódzki Urząd Pracy w Krakowie'],
    'ti_teryt'           => ['Kod terytorialny (TERYT) jednostki', 'np. 1261011'],
    'ti_manager_name'    => ['Kierownik — imię i nazwisko','np. Jan Kowalski'],
    'ti_manager_title'   => ['Kierownik — stanowisko',    'np. Prezes Zarządu'],
];

$S = function (string $k): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    return trim((string)($r['value'] ?? ''));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'save_ris') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }
    foreach (array_keys($RIS_KEYS) as $k) {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$k, trim((string)($_POST[$k] ?? ''))]);
    }
    flash_set('success', 'Dane do RIS zapisane.');
    header('Location: ris.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'save_ezd_teczka') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
    $val = (int)($_POST['ti_report_ezd_teczka_id'] ?? 0);
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES ('ti_report_ezd_teczka_id', ?)")->execute([$val ?: '']);
    flash_set('success', 'Teczka EZD zapisana.');
    header('Location: ris.php'); exit;
}

$PAGE_TITLE = 'Dane do RIS';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.85rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Dane do RIS</li>
</ol></nav>

<?= flash_html() ?>

<h4 class="fw-bold mb-1"><i class="bi bi-bank me-2 text-primary"></i>Dane do RIS</h4>
<p class="text-body-secondary small mb-4">Rejestr Instytucji Szkoleniowych — dane instytucji i kierownika używane w sprawozdaniu do Wojewódzkiego Urzędu Pracy.</p>

<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil-square me-2"></i>Dane RIS i kierownika</div>
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save_ris">
        <?php foreach ($RIS_KEYS as $k => [$label, $ph]): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="f_<?= $k ?>"><?= h($label) ?></label>
          <input type="<?= $k === 'ti_ris_date' ? 'date' : 'text' ?>" class="form-control" id="f_<?= $k ?>"
                 name="<?= $k ?>" value="<?= h($S($k)) ?>" placeholder="<?= h($ph) ?>" <?= $can_write ? '' : 'disabled' ?>>
        </div>
        <?php endforeach; ?>
        <?php if ($can_write): ?>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-2"></i>Tożsamość organizacji</div>
      <div class="card-body small">
        <p class="text-body-secondary mb-3">Poniższe dane pochodzą z ustawień ogólnych organizacji i również trafiają do nagłówka sprawozdania.</p>
        <dl class="row mb-0">
          <dt class="col-5 text-body-secondary fw-normal">Nazwa</dt><dd class="col-7"><?= h($S('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '—')) ?></dd>
          <dt class="col-5 text-body-secondary fw-normal">NIP</dt><dd class="col-7"><?= h($S('org_nip') ?: '—') ?></dd>
          <dt class="col-5 text-body-secondary fw-normal">REGON</dt><dd class="col-7"><?= h($S('org_regon') ?: '—') ?></dd>
          <dt class="col-5 text-body-secondary fw-normal">Adres</dt><dd class="col-7"><?= h(trim($S('org_adres') . ' ' . $S('org_miejscowosc')) ?: '—') ?></dd>
        </dl>
        <?php if (is_admin()): ?>
        <a href="<?= APP_URL ?>/admin/org_settings.php" class="btn btn-outline-secondary btn-sm mt-3"><i class="bi bi-gear me-1"></i>Edytuj dane organizacji</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (is_admin() && module_enabled('ezd_enabled')): ?>
<?php
$ezd_teczka_id = (int)($S('ti_report_ezd_teczka_id') ?: 0);
$ezd_teczki = [];
try { $ezd_teczki = db_all("SELECT id, symbol, title FROM ezd_teczki ORDER BY symbol, title"); } catch (\Throwable $e) {}
?>
<div class="row g-4 mt-2">
  <div class="col-lg-7">
    <form method="post" class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-folder2-open me-2 text-teal"></i>EZD — teczka dla raportów TI</div>
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save_ezd_teczka">
        <p class="text-body-secondary small mb-3">Wygenerowane raporty (per prowadzący / grupę / uczestnika / WUP) oraz wgrane podpisane sprawozdania zostaną automatycznie zapisane jako pisma EZD w wybranej teczce pod sprawą <em>Raporty TI RRRR</em>.</p>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="f_ezd_teczka">Teczka (segregator EZD)</label>
          <select name="ti_report_ezd_teczka_id" id="f_ezd_teczka" class="form-select">
            <option value="0">— wyłączone (nie wysyłaj do EZD) —</option>
            <?php foreach ($ezd_teczki as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $ezd_teczka_id===(int)$t['id']?'selected':'' ?>><?= h(trim($t['symbol'] . ' ' . $t['title'])) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$ezd_teczki): ?><div class="form-text text-warning">Brak teczek EZD — utwórz teczki w module EZD.</div><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz teczke EZD</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
