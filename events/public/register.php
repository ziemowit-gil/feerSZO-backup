<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

// Module check (soft — public page)
$ev_enabled = org_setting('events_enabled');
if ($ev_enabled === '0') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Moduł wydarzeń jest wyłączony.</h1></body></html>';
    exit;
}

$slug = trim($_GET['slug'] ?? '');
if (!$slug) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Brak parametru slug.</h1></body></html>';
    exit;
}

$event = db_one("SELECT * FROM ev_events WHERE slug=?", [$slug]);
if (!$event || $event['status'] !== 'published') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Wydarzenie nie istnieje lub jest niedostępne.</h1></body></html>';
    exit;
}

// Public check
if (!$event['is_public']) {
    // Check if logged in
    auth_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

// Registration window
$now = date('Y-m-d H:i:s');
$reg_closed  = false;
$reg_not_yet = false;
if ($event['reg_open_at']  && $now < $event['reg_open_at'])  $reg_not_yet = true;
if ($event['reg_close_at'] && $now > $event['reg_close_at']) $reg_closed  = true;

// Capacity check
$confirmed_count = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'", [$event['id']])['n'] ?? 0);
$capacity_full   = $event['capacity'] && $confirmed_count >= (int)$event['capacity'];
$waitlist_enabled = org_setting('ev_waitlist_enabled') !== '0';

// Custom form fields
$form_fields = db_all("SELECT * FROM ev_form_fields WHERE event_id=? ORDER BY position ASC", [$event['id']]);

$errors  = [];
$success = false;
$ticket_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $phone      = trim($_POST['phone']      ?? '');

    if (!$first_name) $errors[] = 'Imię jest wymagane.';
    if (!$last_name)  $errors[] = 'Nazwisko jest wymagane.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj poprawny adres email.';

    // Custom fields validation
    $extra_data = [];
    foreach ($form_fields as $ff) {
        $val = trim($_POST['field_' . $ff['field_key']] ?? '');
        if ($ff['is_required'] && $val === '') {
            $errors[] = 'Pole "' . $ff['label'] . '" jest wymagane.';
        }
        $extra_data[$ff['field_key']] = $val;
    }

    if ($reg_not_yet) $errors[] = 'Rejestracja jeszcze nie jest otwarta.';
    if ($reg_closed)  $errors[] = 'Rejestracja jest już zamknięta.';

    if (!$errors) {
        // Check for duplicate email
        $dup = db_one("SELECT id FROM ev_registrations WHERE event_id=? AND email=? AND status!='cancelled'", [$event['id'], $email]);
        if ($dup) $errors[] = 'Ten adres email jest już zarejestrowany na to wydarzenie.';
    }

    if (!$errors) {
        $status = 'confirmed';
        if ($capacity_full) {
            if ($waitlist_enabled) {
                $status = 'waitlist';
            } else {
                $errors[] = 'Brak wolnych miejsc na to wydarzenie.';
            }
        }
    }

    if (!$errors) {
        $ticket_code = ev_ticket_code();
        $reg_id = db_insert('ev_registrations', [
            'event_id'    => $event['id'],
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'email'       => $email,
            'phone'       => $phone,
            'ticket_code' => $ticket_code,
            'status'      => $status,
            'reg_data'    => json_encode($extra_data),
            'source'      => 'form',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // CRM sync
        try {
            ev_crm_sync([
                'first_name' => $first_name, 'last_name' => $last_name,
                'email' => $email, 'phone' => $phone,
            ], (int)$event['id']);
        } catch (\Throwable $_e) {}

        // Webhook PA
        try {
            ev_pa_notify((int)$event['id'], [
                'first_name' => $first_name, 'last_name' => $last_name,
                'email' => $email, 'phone' => $phone, 'ticket_code' => $ticket_code,
            ]);
        } catch (\Throwable $_e) {}

        // E-mail potwierdzający dla uczestnika
        try {
            require_once dirname(dirname(dirname(__FILE__))) . '/includes/mail_queue.php';
            $org    = defined('ORG_NAME') ? ORG_NAME : '';
            $subj   = 'Potwierdzenie rejestracji — ' . $event['title'];
            $start  = $event['start_at'] ? date('d.m.Y H:i', strtotime($event['start_at'])) : '';
            $html   = '
<p>Cześć <strong>' . htmlspecialchars($first_name . ' ' . $last_name) . '</strong>,</p>
<p>Twoja rejestracja na <strong>' . htmlspecialchars($event['title']) . '</strong> została potwierdzona.</p>
' . ($start ? '<p><i>Data:</i> ' . htmlspecialchars($start) . '</p>' : '') . '
<div style="text-align:center;margin:24px 0">
  <span style="font-size:1.6rem;font-weight:900;letter-spacing:.2em;font-family:monospace;
               background:#f5f3ff;border:2px dashed #7c3aed;border-radius:8px;
               padding:10px 24px;display:inline-block;color:#7c3aed">'
               . htmlspecialchars($ticket_code) . '</span>
  <div style="margin-top:8px;font-size:.82rem;color:#64748b">Kod biletu — zachowaj go</div>
</div>
' . ($status === 'waitlist' ? '<p style="color:#d97706">⚠️ Zostałeś/aś zapisany/a na listę oczekujących.</p>' : '') . '
<p style="color:#64748b;font-size:.85rem">Organizator: ' . htmlspecialchars($org) . '</p>';
            mail_queue_add($email, $first_name . ' ' . $last_name, $subj, $html, '', 'event', (int)$event['id']);
        } catch (\Throwable $_e) {}

        // Powiadomienie dla organizatora (jeśli skonfigurowane)
        try {
            $notify = trim($event['notify_email'] ?? '');
            if ($notify && $event['notify_new_reg']) {
                require_once dirname(dirname(dirname(__FILE__))) . '/includes/mail_queue.php';
                $org_subj = 'Nowa rejestracja: ' . $event['title'];
                $org_html = '<p>Nowa rejestracja na <strong>' . htmlspecialchars($event['title']) . '</strong>:</p>'
                          . '<ul><li>' . htmlspecialchars($first_name . ' ' . $last_name) . '</li>'
                          . '<li>' . htmlspecialchars($email) . '</li>'
                          . '<li>Status: ' . htmlspecialchars($status) . '</li></ul>';
                mail_queue_add($notify, '', $org_subj, $org_html, '', 'event', (int)$event['id']);
            }
        } catch (\Throwable $_e) {}

        $success = true;
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rejestracja — <?= h($event['title']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--ev-purple:#7c3aed;--ev-purple-bg:#f5f3ff;}
body{background:var(--ev-purple-bg);min-height:100vh}
.reg-card{max-width:560px;margin:2.5rem auto;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1)}
.reg-header{background:var(--ev-purple);color:#fff;border-radius:16px 16px 0 0;padding:1.75rem}
.ticket-box{background:#f0fdf4;border:2px dashed #22c55e;border-radius:12px;padding:1.5rem;text-align:center}
.ticket-code{font-size:2.2rem;font-weight:800;letter-spacing:.2em;color:var(--ev-purple)}
</style>
</head>
<body>
<div class="reg-card bg-white">
    <div class="reg-header">
        <h4 class="mb-1 fw-bold"><?= h($event['title']) ?></h4>
        <div class="small opacity-75">
            <?php if ($event['start_at']): ?>
            <i class="bi bi-clock me-1"></i><?= date('d.m.Y H:i', strtotime($event['start_at'])) ?>
            <?php endif; ?>
            <?php if ($event['type'] === 'stationary' && $event['venue']): ?>
            · <i class="bi bi-geo-alt me-1"></i><?= h($event['venue']) ?>
            <?php elseif ($event['type'] === 'webinar'): ?>
            · <i class="bi bi-camera-video me-1"></i>Wydarzenie online
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-4">
        <?php if ($success): ?>
        <?php
            $s_label = $status === 'waitlist' ? 'Lista oczekujących' : 'Rejestracja potwierdzona';
            $s_class = $status === 'waitlist' ? 'warning' : 'success';
        ?>
        <div class="ticket-box mb-3">
            <div class="text-success mb-2"><i class="bi bi-check-circle-fill" style="font-size:2rem"></i></div>
            <h5 class="fw-bold"><?= $status === 'waitlist' ? 'Trafiłeś na listę oczekujących' : 'Rejestracja zakończona pomyślnie!' ?></h5>
            <?php if ($status === 'confirmed'): ?>
            <p class="text-muted small mb-2">Twój kod biletu:</p>
            <div class="ticket-code"><?= h($ticket_code) ?></div>
            <p class="text-muted small mt-2">Zachowaj ten kod — będzie potrzebny przy wejściu.</p>
            <?php else: ?>
            <p class="text-muted small">Zostaniesz powiadomiony, jeśli zwolni się miejsce.</p>
            <?php endif; ?>
        </div>
        <div class="text-center">
            <a href="<?= APP_URL ?>/events/public/register.php?slug=<?= urlencode($slug) ?>" class="btn btn-outline-secondary btn-sm">
                Powrót do formularza
            </a>
        </div>

        <?php elseif ($reg_not_yet): ?>
        <div class="alert alert-info">
            <i class="bi bi-clock me-2"></i>Rejestracja otworzy się
            <?= date('d.m.Y o H:i', strtotime($event['reg_open_at'])) ?>.
        </div>

        <?php elseif ($reg_closed): ?>
        <div class="alert alert-warning">
            <i class="bi bi-lock me-2"></i>Rejestracja na to wydarzenie jest zamknięta.
        </div>

        <?php elseif ($capacity_full && !$waitlist_enabled): ?>
        <div class="alert alert-warning">
            <i class="bi bi-people me-2"></i>Brak wolnych miejsc na to wydarzenie.
        </div>

        <?php else: ?>
        <?php if ($capacity_full && $waitlist_enabled): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Brak wolnych miejsc — Twoja rejestracja trafi na listę oczekujących.
        </div>
        <?php endif; ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post">
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label for="first_name" class="form-label fw-semibold">Imię <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="first_name" name="first_name"
                           value="<?= h($_POST['first_name'] ?? '') ?>" required>
                </div>
                <div class="col-6">
                    <label for="last_name" class="form-label fw-semibold">Nazwisko <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="last_name" name="last_name"
                           value="<?= h($_POST['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label for="phone" class="form-label fw-semibold">Telefon</label>
                <input type="tel" class="form-control" id="phone" name="phone"
                       value="<?= h($_POST['phone'] ?? '') ?>">
            </div>

            <?php foreach ($form_fields as $ff):
                $fval = $_POST['field_' . $ff['field_key']] ?? '';
                $fid  = 'field_' . h($ff['field_key']);
            ?>
            <div class="mb-3">
                <label for="<?= $fid ?>" class="form-label fw-semibold">
                    <?= h($ff['label']) ?>
                    <?php if ($ff['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
                </label>
                <?php if ($ff['type'] === 'textarea'): ?>
                <textarea class="form-control" id="<?= $fid ?>" name="<?= $fid ?>"
                          <?= $ff['is_required']?'required':'' ?>
                          placeholder="<?= h($ff['placeholder']) ?>"><?= h($fval) ?></textarea>
                <?php elseif ($ff['type'] === 'select'):
                    $opts = $ff['options'] ? (is_string($ff['options']) ? json_decode($ff['options'],true) : $ff['options']) : [];
                ?>
                <select class="form-select" id="<?= $fid ?>" name="<?= $fid ?>" <?= $ff['is_required']?'required':'' ?>>
                    <option value="">Wybierz…</option>
                    <?php foreach ($opts as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $fval === $opt ? 'selected':'' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($ff['type'] === 'checkbox'): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="<?= $fid ?>" name="<?= $fid ?>"
                           value="1" <?= $fval ? 'checked':'' ?> <?= $ff['is_required']?'required':'' ?>>
                    <label class="form-check-label" for="<?= $fid ?>"><?= h($ff['placeholder'] ?: $ff['label']) ?></label>
                </div>
                <?php else: ?>
                <input type="<?= h($ff['type']) ?>" class="form-control" id="<?= $fid ?>" name="<?= $fid ?>"
                       value="<?= h($fval) ?>"
                       placeholder="<?= h($ff['placeholder']) ?>"
                       <?= $ff['is_required']?'required':'' ?>>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-lg btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-circle me-2"></i>Zarejestruj się
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="text-center text-muted small pb-4" style="opacity:.6">
    <?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
