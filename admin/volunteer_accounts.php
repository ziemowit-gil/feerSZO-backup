<?php
/**
 * admin/volunteer_accounts.php — Konta wolontariuszy bez umowy.
 *
 * Dostępne tylko gdy org_setting('allow_standalone_vol_accounts') === '1'.
 * Tworzy konta z rolą viewer + flagą is_standalone_volunteer=1.
 * Uprawnienia identyczne jak konto z porozumienia wolontariackiego (pełny portal).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/sms.php';

require_role('admin');
cpc_migrate();

$sms_available = sms_is_enabled();

// ── Guard: funkcja musi być włączona przez admina ─────────────────────────────
if (org_setting('allow_standalone_vol_accounts') !== '1') {
    flash_set('danger', 'Funkcja kont wolontariuszy bez umowy jest wyłączona. '
        . 'Włącz ją w Ustawienia organizacji → Portal.');
    header('Location: ' . APP_URL . '/admin/org_settings.php?tab=portal');
    exit;
}

$PAGE_TITLE = 'Konta wolontariuszy bez umowy';
$me  = current_user();
$org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');

$errors    = [];
$new_pass  = null;
$new_user  = null;

// ── Pomocnik: wyświetlana nazwa ───────────────────────────────────────────────
function _sva_name(array $u): string {
    $fn = trim($u['first_name'] ?? '');
    $ln = trim($u['last_name']  ?? '');
    if ($fn !== '' || $ln !== '') return trim($fn . ' ' . $ln);
    return $u['name'] ?? '';
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // ── Utwórz nowe konto ──────────────────────────────────────────────────────
    if ($action === 'create') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $phone      = trim($_POST['phone'] ?? '');
        $notify_ch       = $_POST['notify_channel'] ?? 'email'; // email | sms | both | none
        $send_email      = in_array($notify_ch, ['email', 'both'], true);
        $send_sms        = in_array($notify_ch, ['sms', 'both'], true) && $sms_available && $phone;
        $m365_mode       = $_POST['m365_create_mode'] ?? 'none'; // auto | manual | none
        $m365_sg_id      = trim($_POST['m365_security_group_id']   ?? '');
        $m365_sg_name    = trim($_POST['m365_security_group_name']  ?? '');
        $m365_login_man  = strtolower(trim($_POST['m365_login_manual']   ?? ''));
        $m365_uid_man    = trim($_POST['m365_user_id_manual'] ?? '');
        $org_unit_id     = (int)($_POST['org_unit_id'] ?? 0) ?: null;

        if (!$first_name && !$last_name) $errors[] = 'Podaj imie i nazwisko.';
        if (!$email)                      $errors[] = 'Adres e-mail jest wymagany.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidlowy adres e-mail.';

        if (!$errors) {
            $exists = db_one("SELECT id FROM users WHERE LOWER(email)=?", [$email]);
            if ($exists) $errors[] = 'Uzytkownik z tym adresem e-mail juz istnieje.';
        }

        if (!$errors) {
            // Generuj haslo
            $chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $new_pass = '';
            for ($i = 0; $i < 10; $i++) $new_pass .= $chars[random_int(0, strlen($chars) - 1)];

            $full_name = trim($first_name . ' ' . $last_name);

            db_insert('users', [
                'name'                   => $full_name ?: $email,
                'first_name'             => $first_name,
                'last_name'              => $last_name,
                'email'                  => $email,
                'password'               => password_hash($new_pass, PASSWORD_BCRYPT),
                'role'                   => 'viewer',
                'is_active'              => 1,
                'is_standalone_volunteer'=> 1,
                'must_change_password'   => 1,
                'created_at'             => date('Y-m-d H:i:s'),
            ]);
            $new_uid = (int)db()->lastInsertId();

            if ($phone) {
                try {
                    db()->prepare("UPDATE users SET phone_number=? WHERE id=?")->execute([$phone, $new_uid]);
                } catch (\Throwable $e) {}
            }

            log_user_action($new_uid, (int)$me['id'], 'user_create',
                'Utworzono konto wolontariusza bez umowy: ' . $full_name . ' (' . $email . ')');

            // Jednostka organizacyjna
            if ($org_unit_id) {
                try { db()->prepare("UPDATE users SET org_unit_id=? WHERE id=?")->execute([$org_unit_id, $new_uid]); } catch (\Throwable $e) {}
            }

            // ── Microsoft 365 ──────────────────────────────────────────────────
            $m365_login_created = null;
            $m365_pass_created  = null;
            if ($m365_mode !== 'none') {
                try {
                    require_once dirname(__DIR__) . '/includes/m365.php';
                    $graph = new M365Graph();
                    if (!$graph->is_configured()) throw new \RuntimeException('M365 nie jest skonfigurowane.');

                    if ($m365_mode === 'auto') {
                        // Utwórz konto automatycznie
                        $m365_login_created = $graph->unique_login($full_name);
                        $m365_pass_created  = M365Graph::generate_password();
                        $m365user           = $graph->create_user(
                            $m365_login_created,
                            $full_name,
                            $m365_pass_created,
                            true // aktywne od razu
                        );
                        $m365_azure_id = $m365user['id'];

                        // Przypisz licencję
                        $sku = m365_setting('m365_license_sku_id');
                        if ($sku) {
                            try { $graph->assign_license($m365_azure_id, $sku); } catch (\Throwable $eL) {
                                flash_set('warning', 'Konto M365 utworzone, ale licencja nie zostala przypisana: ' . $eL->getMessage());
                            }
                        }
                        log_user_action($new_uid, (int)$me['id'], 'note', 'Utworzono konto M365: ' . $m365_login_created);

                    } else {
                        // Ręczne — user podał dane
                        $m365_login_created = $m365_login_man;
                        $m365_azure_id      = $m365_uid_man;
                    }

                    // Zapisz M365 do users table
                    $m365_update = ['microsoft_id' => $m365_azure_id];
                    if ($m365_login_created) $m365_update['m365_login'] = $m365_login_created;
                    if ($m365_sg_id) {
                        $m365_update['m365_security_group_id']   = $m365_sg_id;
                        $m365_update['m365_security_group_name'] = $m365_sg_name;
                    }
                    $sets = implode(', ', array_map(fn($k) => "{$k}=?", array_keys($m365_update)));
                    db()->prepare("UPDATE users SET {$sets} WHERE id=?")
                        ->execute(array_merge(array_values($m365_update), [$new_uid]));

                    // Dodaj do Security Group (od razu przez Graph API)
                    if ($m365_sg_id && $m365_azure_id) {
                        try {
                            $graph->add_to_group($m365_azure_id, $m365_sg_id);
                            log_user_action($new_uid, (int)$me['id'], 'note',
                                'Dodano do Security Group M365: ' . ($m365_sg_name ?: $m365_sg_id));
                        } catch (\Throwable $eSg) {
                            flash_set('warning', 'Konto M365 OK, ale Security Group nie zostala przypisana: ' . $eSg->getMessage());
                        }
                    }

                } catch (\Throwable $e) {
                    flash_set('warning', 'Blad M365: ' . $e->getMessage());
                }
            }

            // Wyslij e-mail z danymi logowania
            if ($send_email && $email) {
                $login_url = APP_URL . '/auth/login.php';
                $panel_url = APP_URL . '/panel/index.php';
                $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">Konto w portalu wolontariusza &mdash; {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Czesc, <strong>{$full_name}</strong>!</p>
  <p>Administrator organizacji <strong>{$org}</strong> utworzyl dla Ciebie konto w portalu wolontariusza.</p>
  <table style="background:#f8f9fa;border-radius:8px;padding:16px;width:100%;margin:16px 0;border-collapse:collapse">
    <tr>
      <td style="padding:5px 14px;color:#6c757d;width:130px;font-size:.9em">Adres e-mail</td>
      <td style="padding:5px 14px"><strong>{$email}</strong></td>
    </tr>
    <tr>
      <td style="padding:5px 14px;color:#6c757d;font-size:.9em">Haslo startowe</td>
      <td style="padding:5px 14px"><strong style="font-family:monospace;font-size:1.15em;letter-spacing:.05em">{$new_pass}</strong></td>
    </tr>
  </table>
  <div style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:4px;padding:12px 16px;margin:16px 0;font-size:.88em">
    Po zalogowaniu zostaniesz poproszony/a o zmiane hasla.
  </div>
  <div style="margin:22px 0;text-align:center">
    <a href="{$login_url}" style="background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj sie do portalu &rarr;
    </a>
  </div>
  <p style="font-size:.9em">W portalu mozesz:</p>
  <ul style="font-size:.9em;padding-left:18px">
    <li>Przegladac przypisane do Ciebie zadania i projekty</li>
    <li>Komentowac i aktualizowac statusy zadan</li>
    <li>Komunikowac sie z zespolem organizacji</li>
  </ul>
  <p style="color:#6c757d;font-size:.82em;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Jesli nie spodziewal/as sie tego e-maila, zignoruj go.<br>
    <a href="{$panel_url}" style="color:#2563eb">{$panel_url}</a>
  </p>
</div>
</body></html>
HTML;
                try {
                    if (!email_rate_limit_ok($email, 3)) {
                        flash_set('warning', 'Konto utworzone, ale e-mail nie zostal wyslany (limit dzienny).');
                    } else {
                        mail_queue_add($email, $full_name, "Twoje konto w portalu wolontariusza — {$org}",
                            $body, '', 'standalone_vol', $new_uid, '', true);
                        email_log($email, "Konto wolontariusza bez umowy — {$org}", 'standalone_vol', $new_uid);
                    }
                } catch (\Throwable $e) {}
            }

            // Wyslij SMS z danymi logowania
            if ($send_sms) {
                try {
                    $login_url_short = parse_url(APP_URL, PHP_URL_HOST) ?: APP_URL;
                    $sms_text = "Konto {$org}: login: {$email} / haslo: {$new_pass}\nZaloguj: {$login_url_short}";
                    // Sms max 160 znaków — przytnij URL jesli potrzeba
                    if (mb_strlen($sms_text) > 160) {
                        $sms_text = "Konto {$org}: {$email} / {$new_pass}\n{$login_url_short}";
                    }
                    sms_send($phone, $sms_text);
                } catch (\Throwable $e) {
                    flash_set('warning', 'Konto utworzone, ale SMS nie zostal wyslany: ' . $e->getMessage());
                }
            }

            // Dodaj kontakt CRM i umieść w grupie Wolontariusze (jeśli CRM włączony)
            try {
                if (module_enabled('crm_enabled')) {
                    require_once dirname(__DIR__) . '/includes/crm.php';
                    crm_migrate();
                    CrmManager::autoAddVolunteer([
                        'imie_nazwisko'   => $full_name,
                        'imie'            => $first_name,
                        'nazwisko'        => $last_name,
                        'email'           => $email,
                        'telefon'         => $phone ?: null,
                        'action_id'       => 0,
                        'projekt_program' => '',
                    ], $new_uid);
                }
            } catch (\Throwable $e) {}

            $new_user = ['id' => $new_uid, 'name' => $full_name, 'email' => $email];
            flash_set('success', 'Konto dla ' . h($full_name) . ' zostalo utworzone.');
            // Stay on page to show password
        }
    }

    // ── Migruj na umowę wolontariacką ─────────────────────────────────────────
    if ($action === 'migrate_to_contract') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $u = db_one(
                "SELECT id, name, first_name, last_name, email, phone_number
                 FROM users WHERE id=? AND is_standalone_volunteer=1 AND is_active=1",
                [$uid]
            );
            if ($u) {
                $fn = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['name'] ?? '');
                auth_start();
                // Prefill identyczny jak z kwestionariusza onboardingu
                $_SESSION['ob_prefill'] = [
                    'imie_nazwisko' => $fn,
                    'email'         => $u['email'] ?? '',
                    'telefon'       => $u['phone_number'] ?? '',
                ];
                // UID konta standalone — po zapisaniu umowy czyścimy flagę
                $_SESSION['standalone_migration_uid'] = $uid;
                header('Location: ' . APP_URL . '/contracts/wolontariat/add.php');
                exit;
            }
        }
        flash_set('danger', 'Nie znaleziono konta do migracji.');
        header('Location: ' . APP_URL . '/admin/volunteer_accounts.php'); exit;
    }

    // ── Dezaktywuj konto ───────────────────────────────────────────────────────
    if ($action === 'toggle_active') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid && $uid !== (int)$me['id']) {
            $u = db_one("SELECT is_active, name FROM users WHERE id=? AND is_standalone_volunteer=1", [$uid]);
            if ($u) {
                $new_state = $u['is_active'] ? 0 : 1;
                db()->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new_state, $uid]);
                log_user_action($uid, (int)$me['id'], 'user_toggle',
                    $new_state ? 'Aktywowano konto bez umowy' : 'Dezaktywowano konto bez umowy');
                flash_set('success', $new_state ? 'Konto aktywowane.' : 'Konto dezaktywowane.');
            }
        }
        header('Location: ' . APP_URL . '/admin/volunteer_accounts.php'); exit;
    }

    // ── Reset hasla ────────────────────────────────────────────────────────────
    if ($action === 'reset_pass') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_standalone_volunteer=1", [$uid]);
            if ($u) {
                $chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $new_pass = '';
                for ($i = 0; $i < 10; $i++) $new_pass .= $chars[random_int(0, strlen($chars) - 1)];
                db()->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?")
                    ->execute([password_hash($new_pass, PASSWORD_BCRYPT), $uid]);
                log_user_action($uid, (int)$me['id'], 'user_password_reset', 'Reset hasla konta bez umowy');
                auth_start();
                $_SESSION['sva_reset'] = ['uid' => $uid, 'name' => $u['name'], 'pass' => $new_pass];
            }
        }
        header('Location: ' . APP_URL . '/admin/volunteer_accounts.php'); exit;
    }
}

// ── Wyswietl info o resecie hasla (jednorazowe) ───────────────────────────────
auth_start();
$reset_info = $_SESSION['sva_reset'] ?? null;
unset($_SESSION['sva_reset']);

// ── Lista kont bez umowy ──────────────────────────────────────────────────────
$accounts = db_all(
    "SELECT u.*,
            COALESCE(NULLIF(TRIM(u.first_name || ' ' || u.last_name),''), u.name) AS display_name,
            ou.name AS org_unit_name
     FROM users u
     LEFT JOIN org_units ou ON ou.id = u.org_unit_id
     WHERE u.is_standalone_volunteer = 1
     ORDER BY u.created_at DESC"
);

// Sprawdz ktore konta maja przypisane zadania
$task_counts = [];
try {
    $rows = db_all(
        "SELECT ta.user_id, COUNT(*) AS cnt
         FROM task_assignments ta
         JOIN tasks t ON t.id = ta.task_id AND t.status NOT IN ('done','cancelled')
         WHERE ta.user_id IN (SELECT id FROM users WHERE is_standalone_volunteer=1)
         GROUP BY ta.user_id"
    );
    foreach ($rows as $r) $task_counts[(int)$r['user_id']] = (int)$r['cnt'];
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.sva-table td, .sva-table th { vertical-align: middle; font-size: .87rem; }
.sva-badge-active   { background: #dcfce7; color: #166534; }
.sva-badge-inactive { background: #f1f5f9; color: #64748b; }
</style>

<div class="d-flex align-items-center gap-2 mb-4">
  <div style="width:42px;height:42px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0">
    <i class="bi bi-person-plus-fill text-primary"></i>
  </div>
  <div>
    <h4 class="mb-0">Konta wolontariuszy bez umowy</h4>
    <div class="text-muted" style="font-size:.8rem">Konta z uprawnieniami wolontariusza — bez powiazanego porozumienia</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= APP_URL ?>/admin/org_settings.php?tab=portal" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-gear me-1"></i>Ustawienia
    </a>
    <button class="btn btn-sm btn-primary" data-bs-toggle="offcanvas" data-bs-target="#createPanel">
      <i class="bi bi-person-plus me-1"></i>Nowe konto
    </button>
  </div>
</div>

<?= flash_html() ?>

<?php if ($reset_info): ?>
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
  <i class="bi bi-key-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Nowe haslo dla <?= h($reset_info['name']) ?>:</strong>
    <code class="ms-2 fs-6"><?= h($reset_info['pass']) ?></code>
    <div class="small mt-1 text-muted">Przekaz haslo uzytkownikowi. Zostanie ono ukryte po odswiezeniu strony.</div>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Komunikat po utworzeniu konta (z haslem) -->
<?php if ($new_user && $new_pass): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Konto utworzone dla <?= h($new_user['name']) ?></strong> (<?= h($new_user['email']) ?>)<br>
    Haslo startowe: <code class="fs-6"><?= h($new_pass) ?></code>
    <div class="small mt-1 text-muted">Zapamietaj haslo — nie zostanie ono wyswietlone ponownie.</div>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif (!$errors): ?>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- Opis uprawnien -->
<div class="alert alert-info d-flex gap-2 mb-4" style="font-size:.83rem">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    Konta otrzymuja role <strong>viewer</strong> — taki sam dostep jak wolontariusz z porozumieniem.
    Moga logowac sie do portalu, byc przypisywane do zadan, odbierac wiadomosci od organizacji i korzystac z poczty wewnetrznej.
    Panel wolontariusza nie bedzie pokazywal umow (bo ich nie ma), ale wszystkie inne funkcje dzialaja normalnie.
  </div>
</div>

<!-- Tabela kont -->
<?php if ($accounts): ?>
<div class="card shadow-sm">
  <div class="card-header bg-white d-flex align-items-center justify-content-between py-2 px-3">
    <span class="fw-semibold" style="font-size:.88rem">
      <i class="bi bi-people me-1 text-muted"></i>
      Konta bez umowy
      <span class="badge bg-light text-dark border ms-1"><?= count($accounts) ?></span>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 sva-table">
      <thead class="table-light">
        <tr>
          <th>Uzytkownik</th>
          <th>E-mail</th>
          <th class="d-none d-lg-table-cell">M365</th>
          <th class="d-none d-lg-table-cell">Jednostka</th>
          <th>Status</th>
          <th>Zadania</th>
          <th>Utworzono</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $u):
          $active = (bool)$u['is_active'];
          $tasks  = $task_counts[(int)$u['id']] ?? 0;
          $dn     = $u['display_name'] ?? $u['name'];
          $ini    = '';
          foreach (preg_split('/\s+/', trim($dn)) as $w) $ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
          $ini = mb_substr($ini, 0, 2, 'UTF-8') ?: '?';
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div style="width:32px;height:32px;border-radius:50%;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0">
                <?= h($ini) ?>
              </div>
              <div>
                <div class="fw-semibold" style="font-size:.88rem"><?= h($dn) ?></div>
                <?php if ($u['phone_number'] ?? ''): ?>
                <div class="text-muted" style="font-size:.75rem"><?= h($u['phone_number']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= h($u['email'] ?? '—') ?></td>
          <td class="d-none d-lg-table-cell">
            <?php if (!empty($u['m365_login'])): ?>
            <div style="font-size:.78rem;font-family:monospace;color:#1e40af"><?= h($u['m365_login']) ?></div>
            <?php if (!empty($u['m365_security_group_name'])): ?>
            <div style="font-size:.72rem;color:#64748b"><i class="bi bi-shield-check me-1 text-success"></i><?= h($u['m365_security_group_name']) ?></div>
            <?php endif; ?>
            <?php else: ?>
            <span class="text-muted" style="font-size:.78rem">—</span>
            <?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell" style="font-size:.8rem;color:#475569">
            <?= h($u['org_unit_name'] ?? '—') ?>
          </td>
          <td>
            <span class="badge <?= $active ? 'sva-badge-active' : 'sva-badge-inactive' ?>">
              <?= $active ? 'Aktywne' : 'Nieaktywne' ?>
            </span>
          </td>
          <td>
            <?php if ($tasks): ?>
            <a href="<?= APP_URL ?>/tasks/index.php" class="badge bg-primary text-white text-decoration-none"><?= $tasks ?> aktywnych</a>
            <?php else: ?>
            <span class="text-muted" style="font-size:.8rem">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;color:#64748b"><?= date_pl($u['created_at'] ?? '') ?></td>
          <td>
            <div class="d-flex gap-1 justify-content-end flex-wrap">
              <!-- Migruj na umowę -->
              <?php if ($active): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="migrate_to_contract">
                <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary"
                        title="Migruj na umowe wolontariacka"
                        onclick="return confirm('Przejdz do formularza umowy wolontariackiej dla <?= h(addslashes($dn)) ?>? Dane zostana wstepnie uzupelnione.')">
                  <i class="bi bi-file-earmark-text"></i>
                  <span class="d-none d-md-inline ms-1" style="font-size:.78rem">Migruj na umowe</span>
                </button>
              </form>
              <?php endif; ?>
              <!-- Reset hasla -->
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="reset_pass">
                <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary"
                        title="Resetuj haslo"
                        onclick="return confirm('Zresetowac haslo dla <?= h(addslashes($dn)) ?>?')">
                  <i class="bi bi-key"></i>
                </button>
              </form>
              <!-- Aktywuj/dezaktywuj -->
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="toggle_active">
                <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                <button type="submit"
                        class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                        title="<?= $active ? 'Dezaktywuj' : 'Aktywuj' ?>"
                        onclick="return confirm('<?= $active ? 'Dezaktywowac' : 'Aktywowac' ?> konto <?= h(addslashes($dn)) ?>?')">
                  <i class="bi bi-<?= $active ? 'person-dash' : 'person-check' ?>"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-person-plus fs-2 d-block mb-2 opacity-40"></i>
    <div class="fw-semibold mb-1">Brak kont bez umowy</div>
    <div class="small">Kliknij <strong>Nowe konto</strong>, aby dodac pierwszego wolontariusza bez porozumienia.</div>
  </div>
</div>
<?php endif; ?>

<!-- ══ Offcanvas: tworzenie konta ══════════════════════════════════════════════ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="createPanel" style="width:min(420px,100vw)">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title fw-bold">
      <i class="bi bi-person-plus-fill text-primary me-2"></i>Nowe konto wolontariusza
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">

    <div class="alert alert-light border d-flex gap-2 mb-4" style="font-size:.8rem">
      <i class="bi bi-info-circle flex-shrink-0 mt-1 text-primary"></i>
      <div>
        Konto bez umowy. Uzytkownik bedzie mogl logowac sie do portalu,
        przeglądac przypisane zadania i korespondowac z organizacja.
        Nie bedzie widal panelu umow (bo nie ma umowy).
      </div>
    </div>

    <form method="post" id="createForm">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="create">

      <div class="row g-3 mb-3">
        <div class="col-6">
          <label class="form-label fw-semibold" for="cf_first">Imie <span class="text-danger">*</span></label>
          <input type="text" name="first_name" id="cf_first" class="form-control"
                 value="<?= h($_POST['first_name'] ?? '') ?>"
                 placeholder="np. Anna" autocomplete="given-name" required>
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold" for="cf_last">Nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="last_name" id="cf_last" class="form-control"
                 value="<?= h($_POST['last_name'] ?? '') ?>"
                 placeholder="np. Kowalska" autocomplete="family-name" required>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="cf_email">Adres e-mail <span class="text-danger">*</span></label>
        <input type="email" name="email" id="cf_email" class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>"
               placeholder="anna.kowalska@email.pl"
               autocomplete="email" required>
        <div class="form-text">Sluzy jako login do systemu.</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="cf_phone">Telefon <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="tel" name="phone" id="cf_phone" class="form-control"
               value="<?= h($_POST['phone'] ?? '') ?>"
               placeholder="123 456 789" autocomplete="tel">
      </div>

      <!-- ── Microsoft 365 ───────────────────────────────────────────────────── -->
      <?php
      $m365_enabled_sva = ms_login_available();
      $org_units_sva = [];
      try { $org_units_sva = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name"); } catch (\Throwable $e) {}
      ?>
      <?php if ($m365_enabled_sva): ?>
      <div class="mb-3 border rounded p-3" style="background:#f8fafc">
        <div class="fw-semibold mb-2" style="font-size:.88rem">
          <img src="https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/microsoft.svg"
               style="width:13px;height:13px;margin-right:5px;vertical-align:middle;opacity:.7" alt="">
          Microsoft 365
        </div>

        <!-- Tryb tworzenia konta -->
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1">Konto M365</label>
          <div class="d-flex flex-column gap-1">
            <?php
            $cur_m365 = $_POST['m365_create_mode'] ?? 'none';
            $m365_modes = ['auto'=>'Utwórz automatycznie','manual'=>'Podaj istniejące','none'=>'Bez konta M365'];
            foreach ($m365_modes as $mv => $ml):
            ?>
            <label class="d-flex align-items-center gap-2 p-2 border rounded m365-mode-opt"
                   style="cursor:pointer;font-size:.82rem;<?= $cur_m365===$mv ? 'background:#eff6ff;border-color:#2563eb;font-weight:600' : 'background:#fff' ?>">
              <input type="radio" name="m365_create_mode" value="<?= $mv ?>"
                     class="form-check-input mt-0" <?= $cur_m365===$mv ? 'checked' : '' ?>
                     onchange="toggleM365Fields()">
              <?= h($ml) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Ręczne podanie danych (widoczne gdy manual) -->
        <div id="m365ManualFields" style="<?= $cur_m365==='manual' ? '' : 'display:none' ?>">
          <div class="mb-2">
            <label class="form-label small" for="cf_m365_login">Login M365 (UPN)</label>
            <input type="email" name="m365_login_manual" id="cf_m365_login" class="form-control form-control-sm"
                   value="<?= h($_POST['m365_login_manual'] ?? '') ?>"
                   placeholder="imie.nazwisko@domena.pl">
          </div>
          <div class="mb-2">
            <label class="form-label small" for="cf_m365_uid">Azure AD User ID</label>
            <input type="text" name="m365_user_id_manual" id="cf_m365_uid" class="form-control form-control-sm font-monospace"
                   value="<?= h($_POST['m365_user_id_manual'] ?? '') ?>"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          </div>
        </div>

        <!-- Security Group (widoczne gdy auto lub manual) -->
        <div id="m365GroupField" style="<?= $cur_m365==='none' ? 'display:none' : '' ?>">
          <label class="form-label small fw-semibold">Security Group</label>
          <div class="d-flex gap-1 align-items-center mb-1">
            <select name="m365_security_group_id" id="cf_m365_sg" class="form-select form-select-sm" style="flex:1">
              <option value="">— brak / załaduj —</option>
            </select>
            <input type="hidden" name="m365_security_group_name" id="cf_m365_sg_name">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadSgGroups()"
                    title="Załaduj grupy z M365">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>
          <div id="sgLoadStatus" class="form-text"></div>
        </div>

      </div>
      <?php endif; ?>

      <!-- Jednostka organizacyjna -->
      <?php if ($org_units_sva): ?>
      <div class="mb-3">
        <label class="form-label" for="cf_org_unit" style="font-size:.88rem;font-weight:600">
          <i class="bi bi-diagram-3 me-1 text-muted"></i>Jednostka organizacyjna
          <span class="text-muted fw-normal">(opcjonalnie)</span>
        </label>
        <select name="org_unit_id" id="cf_org_unit" class="form-select form-select-sm">
          <option value="">— wybierz —</option>
          <?php foreach ($org_units_sva as $ou): ?>
          <option value="<?= (int)$ou['id'] ?>"
                  <?= ((int)($_POST['org_unit_id'] ?? 0)) === (int)$ou['id'] ? 'selected' : '' ?>>
            <?= h($ou['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- Kanał wysyłki danych logowania -->
      <div class="mb-4">
        <div class="fw-semibold mb-2" style="font-size:.88rem">
          <i class="bi bi-send me-1 text-primary"></i>Wyślij dane logowania przez:
        </div>
        <?php
        $cur_ch = $_POST['notify_channel'] ?? 'email';
        $ch_options = [
            'email' => ['E-mail',         'bi-envelope-fill',   'text-primary',   true],
            'none'  => ['Nie wysyłaj',     'bi-x-circle',        'text-secondary', true],
        ];
        if ($sms_available) {
            $ch_options = [
                'email' => ['E-mail',         'bi-envelope-fill',   'text-primary',   true],
                'sms'   => ['SMS',             'bi-phone-fill',      'text-success',   true],
                'both'  => ['E-mail + SMS',    'bi-send-check-fill', 'text-info',      true],
                'none'  => ['Nie wysyłaj',     'bi-x-circle',        'text-secondary', true],
            ];
        }
        ?>
        <div class="d-flex flex-wrap gap-2" id="notifyChannelGroup">
          <?php foreach ($ch_options as $val => [$lbl, $ico, $col, $avail]):
            if (!$avail) continue;
            $checked = $cur_ch === $val;
          ?>
          <label class="d-flex align-items-center gap-2 border rounded px-3 py-2 notify-ch-pill"
                 style="cursor:pointer;font-size:.83rem;<?= $checked ? 'border-color:#2563eb;background:#eff6ff;font-weight:600' : '' ?>">
            <input type="radio" name="notify_channel" value="<?= h($val) ?>"
                   class="form-check-input mt-0" <?= $checked ? 'checked' : '' ?>
                   onchange="updateChPills()">
            <i class="bi <?= $ico ?> <?= $col ?>"></i>
            <?= h($lbl) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php if ($sms_available): ?>
        <div class="form-text mt-1" id="smsPhoneNote" style="display:none">
          <i class="bi bi-info-circle me-1"></i>SMS zostanie wysłany na numer podany w polu Telefon.
        </div>
        <?php endif; ?>
      </div>

      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-person-check-fill me-2"></i>Utworz konto
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anuluj</button>
      </div>
    </form>

  </div>
</div>

<script>
// ── M365 ──────────────────────────────────────────────────────────────────────
function toggleM365Fields() {
  var mode = '';
  document.querySelectorAll('[name="m365_create_mode"]').forEach(function(r) {
    if (r.checked) mode = r.value;
    r.closest('.m365-mode-opt').style.background    = r.checked ? '#eff6ff' : '#fff';
    r.closest('.m365-mode-opt').style.borderColor   = r.checked ? '#2563eb' : '';
    r.closest('.m365-mode-opt').style.fontWeight    = r.checked ? '600' : '';
  });
  var mf = document.getElementById('m365ManualFields');
  var gf = document.getElementById('m365GroupField');
  if (mf) mf.style.display = (mode === 'manual') ? '' : 'none';
  if (gf) gf.style.display = (mode === 'none')   ? 'none' : '';
}

var _sgLoaded = false;
function loadSgGroups() {
  var sel    = document.getElementById('cf_m365_sg');
  var status = document.getElementById('sgLoadStatus');
  if (!sel) return;
  if (status) status.textContent = 'Ładowanie grup…';
  fetch('<?= APP_URL ?>/contracts/wolontariat/api_groups.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) { if (status) status.textContent = 'Błąd: ' + data.error; return; }
      sel.innerHTML = '<option value="">— wybierz grupę —</option>';
      (data || []).forEach(function(g) {
        var opt = document.createElement('option');
        opt.value = g.id;
        opt.textContent = g.displayName;
        sel.appendChild(opt);
      });
      _sgLoaded = true;
      if (status) status.textContent = 'Załadowano ' + (data.length||0) + ' grup.';
    })
    .catch(function(e) { if (status) status.textContent = 'Błąd połączenia.'; });
}
document.addEventListener('DOMContentLoaded', function() {
  var sgSel = document.getElementById('cf_m365_sg');
  var sgName = document.getElementById('cf_m365_sg_name');
  if (sgSel && sgName) {
    sgSel.addEventListener('change', function() {
      var opt = this.options[this.selectedIndex];
      sgName.value = opt ? opt.textContent : '';
    });
  }
  // Auto-załaduj przy pierwszym otwarciu offcanvas
  var oc = document.getElementById('createPanel');
  if (oc) {
    oc.addEventListener('show.bs.offcanvas', function() {
      if (!_sgLoaded) loadSgGroups();
    });
  }
  toggleM365Fields();
});

function updateChPills() {
  var radios  = document.querySelectorAll('[name="notify_channel"]');
  var pills   = document.querySelectorAll('.notify-ch-pill');
  var smsNote = document.getElementById('smsPhoneNote');
  var selected = '';
  radios.forEach(function(r) { if (r.checked) selected = r.value; });
  pills.forEach(function(p) {
    var inp = p.querySelector('input');
    var on  = inp && inp.checked;
    p.style.borderColor  = on ? '#2563eb' : '';
    p.style.background   = on ? '#eff6ff' : '';
    p.style.fontWeight   = on ? '600'     : '';
  });
  if (smsNote) {
    smsNote.style.display = (selected === 'sms' || selected === 'both') ? '' : 'none';
  }
}
document.addEventListener('DOMContentLoaded', updateChPills);
</script>

<?php
// Otworz offcanvas automatycznie jesli sa bledy (wroc do formularza)
if ($errors):
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var oc = new bootstrap.Offcanvas(document.getElementById('createPanel'));
  oc.show();
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
