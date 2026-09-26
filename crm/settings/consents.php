<?php
/**
 * crm/settings/consents.php — katalog celów zgód na komunikację.
 *
 * Cel to jednostka, na którą odbiorca wyraża zgodę („newsletter", „zbiórki").
 * Kampania i wysyłka masowa mogą wskazać cel — wtedy trafiają wyłącznie do
 * kontaktów z aktualną zgodą. Zob. includes/crm_consent.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_consent.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_migrate();
crm_require('settings', 'write');
crm_consent_migrate();

$PAGE_TITLE = 'CRM — Cele zgód';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $nazwa    = trim($_POST['nazwa'] ?? '');
        $kod      = trim($_POST['kod'] ?? '');
        $klauzula = trim($_POST['klauzula'] ?? '');
        $channel  = array_key_exists($_POST['channel'] ?? '', CRM_CONSENT_CHANNELS) ? $_POST['channel'] : 'email';
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $valid_m  = max(0, min(240, (int)($_POST['valid_months'] ?? 0)));
        $gslug    = preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $_POST['gdpr_clause_slug'] ?? '') ? $_POST['gdpr_clause_slug'] : null;

        // Kod służy do wskazywania celu z formularzy i kodu — stąd wąski zestaw znaków.
        if ($kod === '' && $nazwa !== '') {
            $kod = strtolower(preg_replace('/[^a-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nazwa)) ?? '');
        }
        $kod = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($kod)) ?? '', '_');

        if ($nazwa === '' || $kod === '') { flash_set('danger', 'Nazwa celu jest wymagana.'); goto redirect; }

        try {
            if ($id) {
                crm_db()->prepare(
                    "UPDATE crm_consent_purposes SET kod=?, nazwa=?, klauzula=?, channel=?, sort_order=?, valid_months=?, is_active=?, gdpr_clause_slug=? WHERE id=?"
                )->execute([$kod, $nazwa, $klauzula ?: null, $channel, $sort, $valid_m, isset($_POST['is_active']) ? 1 : 0, $gslug, $id]);
                flash_set('success', 'Cel zgody zaktualizowany.');
            } else {
                crm_insert('crm_consent_purposes', [
                    'kod'        => $kod,
                    'nazwa'      => $nazwa,
                    'klauzula'   => $klauzula ?: null,
                    'channel'    => $channel,
                    'is_active'  => 1,
                    'sort_order' => $sort,
                    'valid_months' => $valid_m,
                    'gdpr_clause_slug' => $gslug,
                    'created_by' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                flash_set('success', "Dodano cel zgody: „{$nazwa}\".");
            }
        } catch (\Throwable $e) {
            flash_set('danger', "Cel o kodzie „{$kod}\" już istnieje.");
        }
    } elseif ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = crm_one("SELECT is_active FROM crm_consent_purposes WHERE id=?", [$id]);
        if ($cur) {
            crm_db()->prepare("UPDATE crm_consent_purposes SET is_active=? WHERE id=?")
                ->execute([$cur['is_active'] ? 0 : 1, $id]);
        }
    } elseif ($op === 'delete') {
        // Usunięcie celu skasowałoby kaskadą całą historię zgód na ten cel —
        // czyli dowód, że zgoda kiedykolwiek była. Dopuszczamy je tylko dla
        // celów bez ani jednego zdarzenia; resztę się wyłącza.
        $id   = (int)($_POST['id'] ?? 0);
        $used = crm_consent_purpose_usage($id);
        if ($used > 0) {
            flash_set('danger', "Nie można usunąć — w rejestrze jest {$used} zdarzeń dla tego celu. "
                . 'Wyłącz cel: zniknie z list wyboru, a historia zgód zostanie.');
            goto redirect;
        }
        crm_db()->prepare("DELETE FROM crm_consent_purposes WHERE id=?")->execute([$id]);
        flash_set('success', 'Cel zgody usunięty.');
    }
    redirect:
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$purposes = crm_consent_purposes(false);
$counts   = crm_consent_counts();
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? crm_one("SELECT * FROM crm_consent_purposes WHERE id=?", [$edit_id]) : null;

$total_events = (int)(crm_one("SELECT COUNT(*) AS c FROM crm_consents")['c'] ?? 0);
$opt_out_cnt  = (int)(crm_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE COALESCE(email_opt_out,0)=1")['c'] ?? 0);

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Cele zgód</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-shield-check"></i></div>
  <div>
    <h1 class="crm-object-title">Cele zgód na komunikację</h1>
    <div class="crm-object-count">
      <?= count($purposes) ?> cel(ów) · <?= $total_events ?> zdarzeń w rejestrze ·
      <?= $opt_out_cnt ?> kontakt(ów) wypisanych globalnie
    </div>
  </div>
  <div class="crm-object-actions">
    <button class="btn btn-crm-primary btn-sm" onclick="document.getElementById('add-form').classList.toggle('d-none')">
      <i class="bi bi-plus-lg me-1"></i>Dodaj cel
    </button>
  </div>
</div>

<?= flash_get() ?>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Zgoda jest zapisywana <strong>na cel</strong>, z datą i sposobem pozyskania — w kartotece
  kontaktu, w panelu „Zgody na komunikację". Rejestr jest dopisywany, nigdy nadpisywany:
  wycofanie zgody nie usuwa śladu, że wcześniej jej udzielono.
  <br>
  <strong>Zgoda nie jest jedyną podstawą kontaktu.</strong> Do stron umów, partnerów
  i uczestników prowadzonych działań pisze się w oparciu o umowę albo uzasadniony interes —
  wysyłka bez zadeklarowanego celu nie jest filtrowana zgodami.
</div>

<div class="card mb-4">
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Cel</th>
          <th>Kod</th>
          <th>Kanał</th>
          <th>Ze zgodą</th>
          <th>Ważność</th>
          <th>Stan</th>
          <th><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$purposes): ?>
        <tr><td colspan="7" class="text-muted small py-3">Katalog jest pusty — dodaj pierwszy cel.</td></tr>
        <?php endif; ?>
        <?php foreach ($purposes as $p): $cnt = (int)($counts[(int)$p['id']] ?? 0); $used = crm_consent_purpose_usage((int)$p['id']); ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($p['nazwa']) ?></div>
            <?php if (!empty($p['klauzula'])): ?>
            <div class="small text-muted" style="max-width:46ch"><?= h($p['klauzula']) ?></div>
            <?php endif; ?>
          </td>
          <td><code class="small"><?= h($p['kod']) ?></code></td>
          <td class="small"><?= h(CRM_CONSENT_CHANNELS[$p['channel']] ?? $p['channel']) ?></td>
          <td class="small">
            <?php $vm = (int)($p['valid_months'] ?? 0); ?>
            <?= $vm > 0 ? h($vm) . ' mies.' : '<span class="text-muted">bezterminowo</span>' ?>
          </td>
          <td><span class="badge bg-light text-dark border"><?= $cnt ?></span></td>
          <td>
            <?php if ($p['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">aktywny</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">wyłączony</span>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-1">
            <a href="?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"
               aria-label="Edytuj <?= h($p['nazwa']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm <?= $p['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2"
                      aria-label="<?= $p['is_active'] ? 'Wyłącz' : 'Włącz' ?> <?= h($p['nazwa']) ?>">
                <i class="bi <?= $p['is_active'] ? 'bi-pause' : 'bi-play' ?>" aria-hidden="true"></i>
              </button>
            </form>
            <?php if ($used === 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć cel <?= h(addslashes($p['nazwa'])) ?>?')">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" aria-label="Usuń <?= h($p['nazwa']) ?>">
                <i class="bi bi-trash3" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="add-form" class="card mb-3 <?= $edit_row ? '' : 'd-none' ?>">
  <div class="card-header bg-white py-2 fw-semibold"><?= $edit_row ? 'Edytuj cel zgody' : 'Nowy cel zgody' ?></div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>
      <div class="col-md-5">
        <label class="form-label small mb-1" for="cp_nazwa">Nazwa celu <span class="text-danger">*</span></label>
        <input type="text" name="nazwa" id="cp_nazwa" class="form-control form-control-sm" required maxlength="160"
               value="<?= h($edit_row['nazwa'] ?? '') ?>" placeholder="np. Newsletter i informacje o działalności">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1" for="cp_kod">Kod</label>
        <input type="text" name="kod" id="cp_kod" class="form-control form-control-sm" maxlength="40"
               value="<?= h($edit_row['kod'] ?? '') ?>" placeholder="wyliczy się z nazwy">
        <div class="form-text" style="font-size:.72rem">Do wskazywania celu w formularzach.</div>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1" for="cp_channel">Kanał</label>
        <select name="channel" id="cp_channel" class="form-select form-select-sm">
          <?php foreach (CRM_CONSENT_CHANNELS as $ck => $cl): ?>
          <option value="<?= h($ck) ?>" <?= (($edit_row['channel'] ?? 'email') === $ck) ? 'selected' : '' ?>><?= h($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1" for="cp_valid">Ważność (mies.)</label>
        <input type="number" name="valid_months" id="cp_valid" class="form-control form-control-sm"
               min="0" max="240" value="<?= (int)($edit_row['valid_months'] ?? 0) ?>">
        <div class="form-text" style="font-size:.72rem">0 = bezterminowo. Po tym czasie zgoda przestaje uprawniać do wysyłki.</div>
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1" for="cp_sort">Kolejność</label>
        <input type="number" name="sort_order" id="cp_sort" class="form-control form-control-sm"
               value="<?= (int)($edit_row['sort_order'] ?? count($purposes) + 1) ?>">
      </div>
      <div class="col-12">
        <label class="form-label small mb-1" for="cp_klauzula">Treść klauzuli</label>
        <textarea name="klauzula" id="cp_klauzula" class="form-control form-control-sm" rows="2"
                  placeholder="Treść, którą odbiorca akceptuje — zapisujemy jej kopię przy każdej zgodzie"><?= h($edit_row['klauzula'] ?? '') ?></textarea>
        <div class="form-text" style="font-size:.72rem">
          Kopia tej treści trafia do każdego zapisu zgody. Zmiana klauzuli nie zmienia
          brzmienia zgód udzielonych wcześniej — i o to chodzi.
        </div>
      </div>
      <div class="col-md-6">
        <?php require_once dirname(__DIR__, 2) . '/modules/gdpr_clauses/logic/gdpr_clauses.php'; $gdef = gdpr_clauses_default_slug('crm_form'); ?>
        <label class="form-label small mb-1" for="cp_gdpr">Klauzula informacyjna z rejestru (art. 13)</label>
        <?= str_replace('class="form-select"', 'class="form-select form-select-sm"', gdpr_clauses_select('gdpr_clause_slug', (string)($edit_row['gdpr_clause_slug'] ?? ''),
                $gdef !== '' ? '— domyślna dla formularzy CRM (' . (gdpr_clauses_options()[$gdef] ?? $gdef) . ') —' : '— brak —', 'cp_gdpr')) ?>
        <div class="form-text" style="font-size:.72rem">
          Link do pełnej klauzuli przy zgodzie w formularzu; wersja, którą zobaczyła osoba, trafia do
          <a href="<?= APP_URL ?>/modules/gdpr_clauses/acceptances.php">rejestru akceptacji</a>.
        </div>
      </div>
      <?php if ($edit_row): ?>
      <div class="col-auto d-flex align-items-center">
        <div class="form-check mb-0">
          <input type="checkbox" name="is_active" class="form-check-input" id="cpActive"
                 value="1" <?= $edit_row['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="cpActive">Aktywny</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <?= $edit_row ? '<i class="bi bi-save me-1"></i>Zapisz' : '<i class="bi bi-plus-lg me-1"></i>Dodaj' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/consents.php" class="btn btn-outline-secondary btn-sm ms-1">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
