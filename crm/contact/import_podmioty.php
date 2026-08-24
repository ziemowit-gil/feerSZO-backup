<?php
/**
 * crm/contact/import_podmioty.php — masowy import podmiotów po NIP / REGON.
 *
 * Krok 1: wklejona lista numerów → sprawdzenie w rejestrach (podgląd),
 * Krok 2: zaznaczone pozycje trafiają do kartotek CRM.
 *
 * Podgląd trzymamy w sesji, żeby zatwierdzenie nie odpytywało API drugi raz —
 * publiczne rejestry mają limity, a przy 200 numerach to realna różnica.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/podmioty_import.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

if (!can_write('crm_import') && !can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do importu kontaktów.');
    header('Location: ' . APP_URL . '/crm/index.php'); exit;
}

$PAGE_TITLE = 'Import podmiotów (NIP / REGON)';
$uid = (int)(current_user()['id'] ?? 0);

$preview = null;
$result  = null;
$raw     = '';

try { $groups = db_all("SELECT id, name FROM crm_groups ORDER BY name"); } catch (\Throwable $e) { $groups = []; }
try { $users  = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) { $users = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'preview') {
        $raw  = (string)($_POST['numbers'] ?? '');
        $rows = podmiot_parse_input($raw);
        if (!$rows) {
            flash_set('warning', 'Nie znalazłem w tym tekście żadnego numeru.');
        } else {
            $preview = podmiot_preview($rows);
            $_SESSION['podmiot_preview'] = $preview;
        }
    }

    if ($op === 'commit') {
        $preview_all = $_SESSION['podmiot_preview'] ?? [];
        $pick        = array_flip(array_map('strval', (array)($_POST['pick'] ?? [])));
        $selected    = array_values(array_filter($preview_all,
            static fn($r) => isset($pick[(string)$r['id']])));

        $result = podmiot_import_commit($selected, [
            'update_existing' => !empty($_POST['update_existing']),
            'status'          => (string)($_POST['status'] ?? 'nowy'),
            'owner_id'        => (int)($_POST['owner_id'] ?? 0),
            'group_id'        => (int)($_POST['group_id'] ?? 0),
        ]);
        unset($_SESSION['podmiot_preview']);

        flash_set('success', 'Import zakończony: założono ' . $result['created']
                . ', uzupełniono ' . $result['updated'] . ', pominięto ' . $result['skipped'] . '.');
    }
}

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.pi-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:1.15rem 1.25rem; margin-bottom:.9rem }
.pi-h { font-size:.95rem; font-weight:700; margin:0 0 .2rem }
.pi-sub { font-size:.8rem; color:#9CA3AF; margin:0 0 1rem }
.pi-ta { width:100%; min-height:190px; font-family:ui-monospace,Menlo,monospace; font-size:.82rem;
  border:1px solid #E5E7EB; border-radius:10px; padding:.7rem .8rem; resize:vertical }
.pi-ta:focus { border-color:var(--crm-primary); box-shadow:0 0 0 3px rgba(1,118,211,.12); outline:none }
.pi-tbl { width:100%; font-size:.82rem; border-collapse:collapse }
.pi-tbl th { font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; color:#9CA3AF;
  text-align:left; padding:.4rem .5rem; border-bottom:1px solid #E5E7EB; font-weight:700 }
.pi-tbl td { padding:.45rem .5rem; border-bottom:1px solid #F3F4F6; vertical-align:top }
.pi-tag { font-size:.68rem; font-weight:700; padding:.1rem .45rem; border-radius:2rem; white-space:nowrap }
.pi-tag--new { background:#EFF7ED; color:#2E844A }
.pi-tag--upd { background:#EFF6FF; color:#1D4ED8 }
.pi-tag--err { background:#FEF2F2; color:#B91C1C }
.pi-tag--inv { background:#FFF7ED; color:#92400E }
.pi-num { font-family:ui-monospace,Menlo,monospace; font-size:.76rem; color:#374151 }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-building-add me-2" style="color:#0176D3"></i>Import podmiotów</h1>
  <span class="text-muted small">po NIP albo REGON — dane z rejestrów, bez przepisywania ręcznie</span>
  <a class="btn btn-crm-ghost btn-sm ms-auto" href="<?= APP_URL ?>/crm/contact/import.php">
    <i class="bi bi-file-earmark-arrow-up me-1"></i>Import z pliku CSV/XLSX
  </a>
</div>

<?php if ($result): ?>
<div class="pi-card">
  <p class="pi-h">Wynik importu</p>
  <p class="mb-2" style="font-size:.86rem">
    Założono kartotek: <strong><?= (int)$result['created'] ?></strong> ·
    uzupełniono istniejących: <strong><?= (int)$result['updated'] ?></strong> ·
    pominięto: <strong><?= (int)$result['skipped'] ?></strong>
  </p>
  <?php if ($result['ids']): ?>
  <a class="btn btn-crm-primary btn-sm" href="<?= APP_URL ?>/crm/index.php?type=organizacja">
    <i class="bi bi-list-ul me-1"></i>Pokaż organizacje w kartotece
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($preview === null): ?>
<!-- ── Krok 1: lista numerów ─────────────────────────────────────────── -->
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="preview">

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="pi-card">
        <p class="pi-h">1. Wklej numery</p>
        <p class="pi-sub">
          Jeden podmiot w wierszu. NIP (10 cyfr) albo REGON (9 lub 14 cyfr) — myślniki i spacje nie przeszkadzają.
          Możesz dopisać po średniku e-mail i telefon: <span class="pi-num">7343570539; biuro@firma.pl; 123456789</span>
        </p>
        <label class="visually-hidden" for="numbers">Lista numerów NIP lub REGON</label>
        <textarea class="pi-ta" id="numbers" name="numbers" required
                  placeholder="7343570539&#10;123-456-32-18&#10;012345678"><?= h($raw) ?></textarea>
        <div class="form-text" style="font-size:.74rem">
          Maksymalnie <?= PODMIOT_IMPORT_MAX ?> numerów naraz. Powtórzone numery liczą się raz.
        </div>
        <button class="btn btn-crm-primary btn-sm mt-2">
          <i class="bi bi-search me-1"></i>Sprawdź w rejestrach
        </button>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="pi-card">
        <p class="pi-h">2. Co zrobić ze znalezionymi</p>
        <p class="pi-sub">Ustawienia zadziałają przy zatwierdzaniu podglądu.</p>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="status">Status nowych kartotek</label>
          <select class="form-select form-select-sm" id="status" name="status">
            <?php foreach (crm_statuses() as $sk => $sv): ?>
            <option value="<?= h($sk) ?>"><?= h($sv['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" style="font-size:.72rem">Lista pochodzi z katalogu statusów CRM.</div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="owner_id">Opiekun</label>
          <select class="form-select form-select-sm" id="owner_id" name="owner_id">
            <option value="">— bez opiekuna —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $uid ? 'selected' : '' ?>><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($groups): ?>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="group_id">Dopisz do grupy</label>
          <select class="form-select form-select-sm" id="group_id" name="group_id">
            <option value="">— bez grupy —</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing" value="1" checked>
          <label class="form-check-label small" for="update_existing">
            Uzupełniaj istniejące kartoteki (tylko puste pola — nic nie nadpisujemy)
          </label>
        </div>

        <hr>
        <p class="pi-sub mb-0">
          Skąd dane: NIP — CEIDG i Biała Lista VAT, REGON — Biała Lista VAT.
          Rejestry są publiczne i bezpłatne, więc odpytujemy je wolno; przy stu numerach
          sprawdzenie trwa kilkadziesiąt sekund.
        </p>
      </div>
    </div>
  </div>
</form>

<?php else: ?>
<!-- ── Krok 2: podgląd i zatwierdzenie ───────────────────────────────── -->
<?php
  $cnt = ['new' => 0, 'update' => 0, 'error' => 0, 'invalid' => 0];
  foreach ($preview as $r) $cnt[$r['status']] = ($cnt[$r['status']] ?? 0) + 1;
?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="commit">
  <input type="hidden" name="status" value="<?= h($_POST['status'] ?? crm_status_default()) ?>">
  <input type="hidden" name="owner_id" value="<?= (int)($_POST['owner_id'] ?? 0) ?>">
  <input type="hidden" name="group_id" value="<?= (int)($_POST['group_id'] ?? 0) ?>">
  <?php if (!empty($_POST['update_existing'])): ?>
  <input type="hidden" name="update_existing" value="1">
  <?php endif; ?>

  <div class="pi-card">
    <p class="pi-h">Podgląd — sprawdź, zanim zapiszesz</p>
    <p class="pi-sub">
      Nowe: <strong><?= (int)$cnt['new'] ?></strong> ·
      do uzupełnienia: <strong><?= (int)($cnt['update'] ?? 0) ?></strong> ·
      nieznalezione: <strong><?= (int)($cnt['error'] ?? 0) ?></strong> ·
      błędny numer: <strong><?= (int)($cnt['invalid'] ?? 0) ?></strong>
    </p>

    <div class="table-responsive">
      <table class="pi-tbl">
        <thead>
          <tr>
            <th style="width:28px"><input type="checkbox" id="pickAll" checked aria-label="Zaznacz wszystkie"></th>
            <th>Numer</th><th>Podmiot</th><th>Adres</th><th>Stan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $r): $ok = in_array($r['status'], ['new', 'update'], true); ?>
          <tr>
            <td>
              <?php if ($ok): ?>
              <input type="checkbox" class="pick" name="pick[]" value="<?= h($r['id']) ?>" checked
                     aria-label="Importuj <?= h($r['id']) ?>">
              <?php endif; ?>
            </td>
            <td class="pi-num">
              <?= h($r['id']) ?>
              <div class="text-muted" style="font-size:.68rem"><?= strtoupper(h($r['kind'] ?: '?')) ?></div>
            </td>
            <td>
              <?php if ($r['data']): ?>
              <div class="fw-semibold"><?= h($r['data']['nazwa']) ?></div>
              <div class="text-muted pi-num">
                NIP <?= h($r['data']['nip'] ?: '—') ?> · REGON <?= h($r['data']['regon'] ?: '—') ?>
                <?= $r['data']['krs'] ? ' · KRS ' . h($r['data']['krs']) : '' ?>
              </div>
              <?php else: ?>
              <span class="text-muted"><?= h($r['error'] ?: 'brak danych') ?></span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= h($r['data']['adres'] ?? '') ?></td>
            <td>
              <?php if ($r['status'] === 'new'): ?>
              <span class="pi-tag pi-tag--new">nowa kartoteka</span>
              <?php elseif ($r['status'] === 'update'): ?>
              <span class="pi-tag pi-tag--upd">jest w CRM</span>
              <div class="text-muted" style="font-size:.7rem"><?= h($r['existing']['imie_nazwisko'] ?? '') ?></div>
              <?php elseif ($r['status'] === 'invalid'): ?>
              <span class="pi-tag pi-tag--inv">błędny numer</span>
              <?php else: ?>
              <span class="pi-tag pi-tag--err">nie znaleziono</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-crm-primary btn-sm">
        <i class="bi bi-download me-1"></i>Importuj zaznaczone
      </button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/crm/contact/import_podmioty.php">
        Zacznij od nowa
      </a>
    </div>
  </div>
</form>

<script>
document.getElementById('pickAll').addEventListener('change', function () {
  document.querySelectorAll('.pick').forEach(function (c) { c.checked = this.checked; }, this);
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
