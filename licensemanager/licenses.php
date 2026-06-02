<?php
/**
 * licensemanager/licenses.php — Lista i edycja licencji.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
lm_require_login();

$error = '';
$id    = (int)($_GET['id'] ?? 0);

// ── Akcje POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lm_csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'update' && $id) {
        $status   = in_array($_POST['status'] ?? '', ['active','trial','revoked','pending','expired'], true) ? $_POST['status'] : 'trial';
        $expires  = $_POST['expires_at'] ?? date('Y-m-d', time() + 365*86400);
        $org_name = trim($_POST['org_name'] ?? '');
        $org_krs  = preg_replace('/\D/', '', $_POST['org_krs'] ?? '');
        $app_key  = trim($_POST['app_key'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        lm_exec("UPDATE licenses SET status=?,expires_at=?,org_name=?,org_krs=?,app_key=?,note=?,updated_at=datetime('now') WHERE id=?",
            [$status,$expires,$org_name,$org_krs,$app_key,$note,$id]);
        lm_log($id, 'updated', "Status: {$status}");
        lm_flash_set('success', 'Licencja zaktualizowana.');
        header('Location: licenses.php?id=' . $id); exit;
    }

    if ($act === 'generate_cert' && $id) {
        $lic = lm_one("SELECT * FROM licenses WHERE id=?", [$id]);
        if (!$lic || !$lic['org_name'] || !$lic['app_key']) {
            lm_flash_set('danger', 'Brak APP_KEY lub nazwy org — uzupełnij dane licencji.');
        } elseif (!extension_loaded('openssl')) {
            lm_flash_set('danger', 'Brak rozszerzenia OpenSSL na tym serwerze.');
        } else {
            $result = _lm_generate_cert($lic);
            if ($result['ok']) {
                lm_exec("UPDATE licenses SET cert_pem=?,cert_key=?,cert_sig=?,updated_at=datetime('now') WHERE id=?",
                    [$result['cert_pem'], $result['cert_key'], $result['cert_sig'], $id]);
                lm_log($id, 'cert_generated', "Ważny do: " . date('Y-m-d', $result['valid_to']));
                lm_flash_set('success', 'Certyfikat wygenerowany. Pobierz go i zainstaluj na serwerze instalacji.');
            } else {
                lm_flash_set('danger', 'Błąd generowania: ' . $result['error']);
            }
        }
        header('Location: licenses.php?id=' . $id); exit;
    }

    if ($act === 'renew' && $id) {
        $days    = max(1, (int)($_POST['days'] ?? 365));
        $expires = date('Y-m-d', time() + $days * 86400);
        lm_exec("UPDATE licenses SET expires_at=?,status='active',updated_at=datetime('now') WHERE id=?", [$expires, $id]);
        lm_log($id, 'renewed', "Nowa data: {$expires}");
        lm_flash_set('success', "Licencja odnowiona do {$expires}.");
        header('Location: licenses.php?id=' . $id); exit;
    }

    if ($act === 'revoke' && $id) {
        lm_exec("UPDATE licenses SET status='revoked',updated_at=datetime('now') WHERE id=?", [$id]);
        lm_log($id, 'revoked');
        lm_flash_set('success', 'Licencja odwołana.');
        header('Location: licenses.php?id=' . $id); exit;
    }

    if ($act === 'delete' && $id) {
        lm_exec("DELETE FROM license_log WHERE license_id=?", [$id]);
        lm_exec("DELETE FROM licenses WHERE id=?", [$id]);
        lm_flash_set('success', 'Licencja usunięta.');
        header('Location: licenses.php'); exit;
    }
}

// ── Generator certyfikatu ────────────────────────────────────────────────────
function _lm_generate_cert(array $lic): array {
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$pkey) return ['ok' => false, 'error' => openssl_error_string()];

    $dn = [
        'C' => 'PL', 'ST' => 'Polska',
        'O' => $lic['org_name'], 'CN' => $lic['org_name'],
        'OU' => 'Platforma NGO',
        'serialNumber' => 'KRS:' . $lic['org_krs'],
    ];
    $csr  = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) return ['ok' => false, 'error' => 'CSR failed'];
    $cert = openssl_csr_sign($csr, null, $pkey, 730, ['digest_alg' => 'sha256'], (int)(microtime(true)*1000)&0x7FFFFFFF);
    if (!$cert) return ['ok' => false, 'error' => 'Sign failed'];

    $cert_pem = $key_pem = '';
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($pkey, $key_pem);
    $sig      = hash_hmac('sha256', $cert_pem, $lic['app_key']);
    $parsed   = openssl_x509_parse($cert_pem);

    return ['ok' => true, 'cert_pem' => $cert_pem, 'cert_key' => $key_pem,
            'cert_sig' => $sig, 'valid_to' => $parsed['validTo_time_t']];
}

// ── Widok szczegółów licencji ────────────────────────────────────────────────
if ($id) {
    $lic = lm_one("SELECT * FROM licenses WHERE id=?", [$id]);
    if (!$lic) { lm_flash_set('danger', 'Nie znaleziono licencji.'); header('Location: licenses.php'); exit; }

    lm_head('Licencja #' . $id, 'licenses.php');
    $days     = lm_days_left($lic['expires_at']);
    $bar_pct  = max(0, min(100, round($days / 365 * 100)));
    $bar_color= $days <= 0 ? '#ef4444' : ($days <= 14 ? '#f59e0b' : '#22c55e');
    $log      = lm_all("SELECT * FROM license_log WHERE license_id=? ORDER BY created_at DESC LIMIT 20", [$id]);
    ?>

    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
      <h2 style="font-size:1.3rem;font-weight:700;color:#f8fafc">🔑 Licencja #<?= $id ?></h2>
      <?= lm_status_badge($lic['status']) ?>
      <a href="licenses.php" style="margin-left:auto;color:#64748b;text-decoration:none;font-size:.82rem">← Powrót</a>
    </div>

    <?= lm_flash_html() ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">

      <!-- Edycja danych -->
      <div class="lm-card" style="grid-column:1/-1">
        <div class="lm-card-header">Dane licencji</div>
        <div class="lm-card-body">
          <form method="post">
            <input type="hidden" name="_csrf"    value="<?= lm_h(lm_csrf()) ?>">
            <input type="hidden" name="_action" value="update">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

              <div class="lm-form-group" style="grid-column:1/-1">
                <label class="lm-label">URL instalacji</label>
                <div class="lm-code"><?= lm_h($lic['install_url']) ?></div>
              </div>

              <div class="lm-form-group">
                <label class="lm-label">Nazwa organizacji</label>
                <input type="text" name="org_name" class="lm-input" value="<?= lm_h($lic['org_name']) ?>" required>
              </div>

              <div class="lm-form-group">
                <label class="lm-label">KRS</label>
                <input type="text" name="org_krs" class="lm-input" value="<?= lm_h($lic['org_krs']) ?>" placeholder="0000000000" maxlength="10">
              </div>

              <div class="lm-form-group">
                <label class="lm-label">APP_KEY instalacji</label>
                <input type="text" name="app_key" class="lm-input" style="font-family:monospace;font-size:.78rem"
                       value="<?= lm_h($lic['app_key']) ?>" placeholder="klucz z config.php">
              </div>

              <div class="lm-form-group">
                <label class="lm-label">Status</label>
                <select name="status" class="lm-select">
                  <?php foreach (['active'=>'Aktywna','trial'=>'Trial','pending'=>'Oczekuje','revoked'=>'Odwołana','expired'=>'Wygasła'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $lic['status']===$k?'selected':'' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="lm-form-group">
                <label class="lm-label">Data wygaśnięcia</label>
                <input type="date" name="expires_at" class="lm-input" value="<?= lm_h($lic['expires_at']) ?>">
                <div style="font-size:.75rem;color:<?= $days <= 0 ? '#ef4444' : ($days <= 14 ? '#f59e0b' : '#64748b') ?>;margin-top:.3rem">
                  <?= $days <= 0 ? 'Wygasła ' . abs($days) . ' dni temu' : "Pozostało $days dni" ?>
                </div>
              </div>

              <div class="lm-form-group" style="grid-column:1/-1">
                <label class="lm-label">Notatka</label>
                <textarea name="note" class="lm-textarea" rows="2"><?= lm_h($lic['note'] ?? '') ?></textarea>
              </div>
            </div>
            <div style="display:flex;gap:.75rem">
              <button type="submit" class="btn-lm btn-primary">💾 Zapisz zmiany</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Odnów -->
      <div class="lm-card">
        <div class="lm-card-header">🔄 Odnów licencję</div>
        <div class="lm-card-body">
          <form method="post" style="display:flex;gap:.75rem;align-items:flex-end">
            <input type="hidden" name="_csrf"   value="<?= lm_h(lm_csrf()) ?>">
            <input type="hidden" name="_action" value="renew">
            <div class="lm-form-group" style="margin:0;flex:1">
              <label class="lm-label">Ile dni od dziś</label>
              <input type="number" name="days" class="lm-input" value="365" min="1" max="3650">
            </div>
            <button type="submit" class="btn-lm btn-success">Odnów</button>
          </form>
        </div>
      </div>

      <!-- Certyfikat -->
      <div class="lm-card">
        <div class="lm-card-header">🔐 Certyfikat instalacyjny</div>
        <div class="lm-card-body">
          <?php if ($lic['cert_pem']): ?>
          <div style="font-size:.78rem;color:#86efac;margin-bottom:.75rem">✓ Certyfikat wygenerowany</div>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <button class="btn-lm btn-ghost btn-sm"
                    onclick="copyText(<?= json_encode($lic['cert_pem']) ?>, this)">📋 Kopiuj app.crt</button>
            <button class="btn-lm btn-ghost btn-sm"
                    onclick="copyText(<?= json_encode($lic['cert_sig']) ?>, this)">📋 Kopiuj app.sig</button>
            <a href="download.php?id=<?= $id ?>&file=crt" class="btn-lm btn-ghost btn-sm">⬇ Pobierz .crt</a>
            <a href="download.php?id=<?= $id ?>&file=sig" class="btn-lm btn-ghost btn-sm">⬇ Pobierz .sig</a>
          </div>
          <div style="margin-top:.75rem;font-size:.75rem;color:#64748b">
            Skopiuj <code>app.crt</code> i <code>app.sig</code> do katalogu <code>certs/</code> na serwerze instalacji.
          </div>
          <?php else: ?>
          <div style="font-size:.78rem;color:#64748b;margin-bottom:.75rem">Brak certyfikatu. Podaj APP_KEY i wygeneruj.</div>
          <?php endif; ?>
          <form method="post" style="margin-top:.75rem">
            <input type="hidden" name="_csrf"   value="<?= lm_h(lm_csrf()) ?>">
            <input type="hidden" name="_action" value="generate_cert">
            <button type="submit" class="btn-lm btn-warning"
                    onclick="return confirm('Wygenerować nowy certyfikat? Stary zostanie nadpisany.')">
              ⚙️ <?= $lic['cert_pem'] ? 'Regeneruj cert' : 'Generuj certyfikat' ?>
            </button>
          </form>
        </div>
      </div>

      <!-- Odwołaj / Usuń -->
      <div class="lm-card" style="grid-column:1/-1">
        <div class="lm-card-header" style="color:#fca5a5">⚠️ Akcje nieodwracalne</div>
        <div class="lm-card-body" style="display:flex;gap:1rem">
          <?php if ($lic['status'] !== 'revoked'): ?>
          <form method="post">
            <input type="hidden" name="_csrf"   value="<?= lm_h(lm_csrf()) ?>">
            <input type="hidden" name="_action" value="revoke">
            <button type="submit" class="btn-lm btn-danger"
                    onclick="return confirm('Odwołać licencję? Instalacja przestanie działać.')">🚫 Odwołaj</button>
          </form>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf"   value="<?= lm_h(lm_csrf()) ?>">
            <input type="hidden" name="_action" value="delete">
            <button type="submit" class="btn-lm btn-danger"
                    onclick="return confirm('Trwale usunąć licencję i jej historię?')">🗑 Usuń licencję</button>
          </form>
        </div>
      </div>

      <!-- Log -->
      <?php if ($log): ?>
      <div class="lm-card" style="grid-column:1/-1">
        <div class="lm-card-header">📋 Historia zdarzeń</div>
        <table class="lm-table">
          <thead><tr><th>Data</th><th>Zdarzenie</th><th>Szczegóły</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($log as $l): ?>
            <tr>
              <td style="font-size:.78rem;color:#64748b"><?= lm_h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></td>
              <td><code style="font-size:.8rem"><?= lm_h($l['event']) ?></code></td>
              <td style="font-size:.78rem;color:#94a3b8"><?= lm_h($l['detail'] ?? '') ?></td>
              <td style="font-size:.75rem;color:#64748b"><?= lm_h($l['ip'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

    </div><!-- /grid -->

    <?php lm_foot(); exit;
}

// ── Lista wszystkich licencji ─────────────────────────────────────────────────
$filter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($filter) { $where .= ' AND status=?'; $params[] = $filter; }
if ($search) { $where .= ' AND (install_url LIKE ? OR org_name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$licenses = lm_all("SELECT * FROM licenses $where ORDER BY created_at DESC", $params);

lm_head('Licencje', 'licenses.php');
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <h2 style="font-size:1.3rem;font-weight:700;color:#f8fafc">🔑 Licencje</h2>
  <form style="display:flex;gap:.5rem;margin-left:auto;flex-wrap:wrap">
    <input type="text" name="q" class="lm-input" style="width:200px" placeholder="Szukaj…" value="<?= lm_h($search) ?>">
    <select name="status" class="lm-select" style="width:130px" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <?php foreach (['active','trial','pending','revoked','expired'] as $s): ?>
      <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-lm btn-ghost">🔍</button>
  </form>
  <a href="new.php" class="btn-lm btn-primary">➕ Nowa</a>
</div>

<?= lm_flash_html() ?>

<div class="lm-card">
  <table class="lm-table">
    <thead>
      <tr>
        <th>#</th>
        <th>URL instalacji</th>
        <th>Organizacja</th>
        <th>Status</th>
        <th>Wygasa</th>
        <th>Cert</th>
        <th>Ping</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$licenses): ?>
      <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem">Brak licencji.</td></tr>
      <?php endif; ?>
      <?php foreach ($licenses as $lic):
          $days = lm_days_left($lic['expires_at']);
          $bar  = max(0, min(100, round($days / 365 * 100)));
          $bc   = $days <= 0 ? '#ef4444' : ($days <= 14 ? '#f59e0b' : '#22c55e');
      ?>
      <tr>
        <td style="color:#64748b"><?= $lic['id'] ?></td>
        <td>
          <a href="<?= lm_h($lic['install_url']) ?>" target="_blank"
             style="color:#38bdf8;text-decoration:none;font-size:.8rem">
            <?= lm_h($lic['install_url']) ?>
          </a>
        </td>
        <td style="font-size:.85rem"><?= lm_h($lic['org_name'] ?: '—') ?></td>
        <td><?= lm_status_badge($lic['status']) ?></td>
        <td>
          <div style="font-size:.78rem"><?= date('d.m.Y', strtotime($lic['expires_at'])) ?></div>
          <div class="lm-progress" style="width:60px;margin-top:.3rem">
            <div class="lm-progress-bar" style="width:<?= $bar ?>%;background:<?= $bc ?>"></div>
          </div>
        </td>
        <td style="text-align:center"><?= $lic['cert_pem'] ? '✅' : '—' ?></td>
        <td style="font-size:.75rem;color:#64748b"><?= $lic['last_ping_at'] ? date('d.m.Y', strtotime($lic['last_ping_at'])) : '—' ?></td>
        <td><a href="?id=<?= $lic['id'] ?>" class="btn-lm btn-ghost btn-sm">Edytuj</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php lm_foot(); ?>
