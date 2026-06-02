<?php
/**
 * licensemanager/new.php — Dodaj nową licencję.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
lm_require_login();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lm_csrf_check();
    $url      = rtrim(trim($_POST['install_url'] ?? ''), '/');
    $org_name = trim($_POST['org_name'] ?? '');
    $org_krs  = preg_replace('/\D/', '', $_POST['org_krs'] ?? '');
    $app_key  = trim($_POST['app_key'] ?? '');
    $status   = in_array($_POST['status'] ?? '', ['active','trial','revoked','pending'], true)
                ? $_POST['status'] : 'trial';
    $days     = max(1, (int)($_POST['days'] ?? 365));
    $note     = trim($_POST['note'] ?? '');

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        $error = 'Podaj prawidłowy URL instalacji (np. https://domena.pl).';
    } elseif (!$org_name) {
        $error = 'Nazwa organizacji jest wymagana.';
    } else {
        $exists = lm_one("SELECT id FROM licenses WHERE install_url=?", [$url]);
        if ($exists) {
            $error = 'Licencja dla tego URL już istnieje. <a href="licenses.php?id=' . $exists['id'] . '" style="color:#7dd3fc">Przejdź do edycji</a>.';
        } else {
            $expires = date('Y-m-d', time() + $days * 86400);
            $id = lm_insert(
                "INSERT INTO licenses (install_url,org_name,org_krs,app_key,status,expires_at,note) VALUES (?,?,?,?,?,?,?)",
                [$url, $org_name, $org_krs, $app_key, $status, $expires, $note]
            );
            lm_log($id, 'created', "Status: {$status}, wygasa: {$expires}");
            lm_flash_set('success', "Licencja dla {$url} została utworzona.");

            // Powiadomienie e-mail do twórcy
            $notify_to = 'ziemowit.gil@gmail.com';
            $subject   = "[License Manager] Nowa instalacja: {$org_name}";
            $body      = implode("\n", [
                "Nowa licencja została utworzona w License Manager.",
                "",
                "Organizacja : {$org_name}",
                "KRS         : " . ($org_krs ?: '—'),
                "URL         : {$url}",
                "Status      : {$status}",
                "Wygasa      : {$expires}",
                "APP_KEY     : " . ($app_key ? substr($app_key, 0, 8) . '…' : '—'),
                "",
                "Panel: " . lm_base_url() . "/licenses.php?id={$id}",
                "",
                "-- License Manager · Platforma NGO",
            ]);
            @mail($notify_to, $subject, $body,
                "From: noreply@feer.me\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: LicenseManager/1.0");

            header('Location: licenses.php?id=' . $id); exit;
        }
    }
}

lm_head('Nowa licencja', 'new.php');
?>

<h2 style="font-size:1.4rem;font-weight:700;margin-bottom:1.25rem;color:#f8fafc">➕ Nowa licencja</h2>

<?php if ($error): ?>
<div class="lm-alert lm-alert-danger"><?= $error ?></div>
<?php endif; ?>

<div class="lm-card">
  <div class="lm-card-header">Dane instalacji</div>
  <div class="lm-card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= lm_h(lm_csrf()) ?>">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="lm-form-group" style="grid-column:1/-1">
          <label class="lm-label">URL instalacji <span style="color:#ef4444">*</span></label>
          <input type="url" name="install_url" class="lm-input"
                 value="<?= lm_h($_POST['install_url'] ?? '') ?>"
                 placeholder="https://domena.pl/app" required>
          <div style="font-size:.75rem;color:#64748b;margin-top:.3rem">Pełny URL instalacji aplikacji (bez trailing slash).</div>
        </div>

        <div class="lm-form-group">
          <label class="lm-label">Nazwa organizacji <span style="color:#ef4444">*</span></label>
          <input type="text" name="org_name" class="lm-input"
                 value="<?= lm_h($_POST['org_name'] ?? '') ?>"
                 placeholder="Fundacja XYZ" required maxlength="200">
        </div>

        <div class="lm-form-group">
          <label class="lm-label">KRS</label>
          <input type="text" name="org_krs" class="lm-input"
                 value="<?= lm_h($_POST['org_krs'] ?? '') ?>"
                 placeholder="0000000000" maxlength="10" pattern="\d{0,10}">
        </div>

        <div class="lm-form-group">
          <label class="lm-label">APP_KEY instalacji</label>
          <input type="text" name="app_key" class="lm-input" style="font-family:monospace;font-size:.8rem"
                 value="<?= lm_h($_POST['app_key'] ?? '') ?>"
                 placeholder="0d74d40a… (z config.php)">
          <div style="font-size:.75rem;color:#64748b;margin-top:.3rem">Potrzebny do generowania certyfikatu HMAC.</div>
        </div>

        <div class="lm-form-group">
          <label class="lm-label">Status</label>
          <select name="status" class="lm-select">
            <?php foreach (['trial'=>'Trial (30 dni)', 'active'=>'Aktywna', 'pending'=>'Oczekuje', 'revoked'=>'Odwołana'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= (($_POST['status'] ?? 'trial') === $k) ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="lm-form-group">
          <label class="lm-label">Ważność (dni)</label>
          <input type="number" name="days" class="lm-input" min="1" max="3650"
                 value="<?= lm_h($_POST['days'] ?? '365') ?>">
        </div>

        <div class="lm-form-group" style="grid-column:1/-1">
          <label class="lm-label">Notatka (opcjonalna)</label>
          <textarea name="note" class="lm-textarea" rows="2"
                    placeholder="np. Wdrożenie pilotażowe, kontakt: jan@feer.me"><?= lm_h($_POST['note'] ?? '') ?></textarea>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;margin-top:.5rem">
        <button type="submit" class="btn-lm btn-primary">✅ Utwórz licencję</button>
        <a href="licenses.php" class="btn-lm btn-ghost">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php lm_foot(); ?>
