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

// Registration window — time in Polish timezone (Europe/Warsaw)
$now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s');
$reg_closed  = false;
$reg_not_yet = false;
if (!($event['reg_always_open'] ?? 0)) {
    if ($event['reg_open_at']  && $now < $event['reg_open_at'])  $reg_not_yet = true;
    if ($event['reg_close_at'] && $now > $event['reg_close_at']) $reg_closed  = true;
}

// Capacity check
$confirmed_count = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'", [$event['id']])['n'] ?? 0);
$capacity_full   = $event['capacity'] && $confirmed_count >= (int)$event['capacity'];
$waitlist_enabled = org_setting('ev_waitlist_enabled') !== '0';

// Custom form fields
$form_fields = db_all("SELECT * FROM ev_form_fields WHERE event_id=? ORDER BY position ASC", [$event['id']]);

// RODO clause — event-specific or global fallback
$rodo_text = trim($event['rodo_clause'] ?? '');
if (!$rodo_text) {
    try { $rodo_text = trim(org_setting('ev_rodo_clause') ?? ''); } catch (\Throwable $_e) {}
}
$rodo_text = str_replace(
    ['{event_title}', '{org_name}'],
    [$event['title'], defined('ORG_NAME') ? ORG_NAME : ''],
    $rodo_text
);
// Klauzula z rejestru (modules/gdpr_clauses) zastępuje tekst powyżej.
$gdpr = ev_gdpr_clause($event);
if ($gdpr) $rodo_text = strip_tags($gdpr['html']);

$errors  = [];
$success = false;
$ticket_code = '';

$field_errors = []; // per-field error tracking for aria-invalid

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $phone      = trim($_POST['phone']      ?? '');

    if (!$first_name) { $errors[] = 'Imię jest wymagane.';                                   $field_errors['first_name'] = true; }
    if (!$last_name)  { $errors[] = 'Nazwisko jest wymagane.';                               $field_errors['last_name']  = true; }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Podaj poprawny adres email.'; $field_errors['email'] = true; }

    // Custom fields validation
    $extra_data = [];
    foreach ($form_fields as $ff) {
        $val = trim($_POST['field_' . $ff['field_key']] ?? '');
        if ($ff['is_required'] && $val === '') {
            $errors[] = 'Pole „' . $ff['label'] . '" jest wymagane.';
            $field_errors['field_' . $ff['field_key']] = true;
        }
        $extra_data[$ff['field_key']] = $val;
    }

    if ($rodo_text && !($_POST['rodo_consent'] ?? '')) {
        $errors[] = 'Zgoda na przetwarzanie danych osobowych jest wymagana.';
        $field_errors['rodo_consent'] = true;
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
        if ($gdpr) {
            gdpr_clause_accept_from_post($gdpr['clause']['slug'], 'event',
                ['name' => trim($first_name . ' ' . $last_name), 'email' => $email], ['ev_registration', (int)$reg_id]);
        }

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
            $is_webinar = ($event['type'] ?? '') === 'webinar';
            $html   = '
<p>Cześć <strong>' . htmlspecialchars($first_name . ' ' . $last_name) . '</strong>,</p>
<p>Twoja rejestracja na <strong>' . htmlspecialchars($event['title']) . '</strong> została potwierdzona.</p>
' . ($start ? '<p><i>Data:</i> ' . htmlspecialchars($start) . '</p>' : '') . '
' . (!$is_webinar ? '
<div style="text-align:center;margin:24px 0">
  <span style="font-size:1.6rem;font-weight:900;letter-spacing:.2em;font-family:monospace;
               background:#f5f3ff;border:2px dashed #7c3aed;border-radius:8px;
               padding:10px 24px;display:inline-block;color:#7c3aed">'
               . htmlspecialchars($ticket_code) . '</span>
  <div style="margin-top:8px;font-size:.82rem;color:#64748b">Kod biletu — zachowaj go</div>
</div>' : '') . '
' . ($status === 'confirmed' && $is_webinar && !empty($event['meeting_url']) ? '
<div style="text-align:center;margin:24px 0">
  <a href="' . htmlspecialchars($event['meeting_url']) . '"
     style="background:#7c3aed;color:#fff;text-decoration:none;font-weight:700;padding:12px 28px;border-radius:8px;display:inline-block">
     ▶ Dołącz do wydarzenia online</a>
  <div style="margin-top:10px;font-size:.82rem;color:#64748b;word-break:break-all">
    Link do spotkania: <a href="' . htmlspecialchars($event['meeting_url']) . '">' . htmlspecialchars($event['meeting_url']) . '</a>
  </div>
  <div style="margin-top:4px;font-size:.78rem;color:#94a3b8">Link będzie aktywny wyłącznie w dniu wydarzenia — zachowaj tę wiadomość.</div>
</div>' : '') . '
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
:root { --ev-purple:#7c3aed; --ev-purple-dark:#5b21b6; --ev-purple-bg:#f5f3ff; }
body { background:var(--ev-purple-bg); min-height:100vh; }

/* Skip link */
.skip-link {
    position:absolute; left:-999px; top:auto; width:1px; height:1px; overflow:hidden;
}
.skip-link:focus {
    position:fixed; left:1rem; top:1rem; width:auto; height:auto; overflow:visible;
    background:#fff; color:var(--ev-purple); font-weight:700; padding:.5rem 1rem;
    border:2px solid var(--ev-purple); border-radius:6px; z-index:9999;
    text-decoration:none;
}

/* Card */
.reg-card { max-width:560px; margin:2.5rem auto; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.1); }
.reg-header { background:var(--ev-purple); color:#fff; border-radius:16px 16px 0 0; padding:1.75rem; }
.reg-header .reg-meta { color:rgba(255,255,255,.85); font-size:.88rem; }

/* Ticket */
.ticket-box { background:#f0fdf4; border:2px dashed #22c55e; border-radius:12px; padding:1.5rem; text-align:center; }
.ticket-code { font-size:2.2rem; font-weight:800; letter-spacing:.2em; color:var(--ev-purple); font-family:monospace; }
.ticket-code-wrap { display:inline-block; }

/* Focus ring — wzmocniony dla lepszej widoczności (WCAG 2.4.11) */
:focus-visible { outline:3px solid var(--ev-purple); outline-offset:2px; }

/* Kontrast pomocniczych tekstów — co najmniej 4.5:1 */
.reg-hint { color:#4b5563; font-size:.85rem; }

/* Pola z błędem */
.form-control.is-invalid, .form-select.is-invalid { border-color:#dc2626; }
</style>
</head>
<body>

<!-- Link pominięcia nawigacji (WCAG 2.4.1) -->
<a class="skip-link" href="#reg-main">Przejdź do formularza rejestracji</a>

<main id="reg-main">
<div class="reg-card bg-white">
    <div class="reg-header">
        <h1 class="mb-1 fw-bold fs-4"><?= h($event['title']) ?></h1>
        <p class="reg-meta mb-0">
            <?php if ($event['start_at']): ?>
            <i class="bi bi-clock me-1" aria-hidden="true"></i>
            <time datetime="<?= h(substr($event['start_at'],0,16)) ?>"><?= date('d.m.Y H:i', strtotime($event['start_at'])) ?></time>
            <?php endif; ?>
            <?php if ($event['type'] === 'stationary' && $event['venue']): ?>
            <span aria-hidden="true"> · </span>
            <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($event['venue']) ?>
            <?php elseif ($event['type'] === 'webinar'): ?>
            <span aria-hidden="true"> · </span>
            <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Wydarzenie online
            <?php endif; ?>
        </p>
    </div>

    <div class="card-body p-4">

        <?php if ($success): ?>
        <!-- ── Sukces ── -->
        <div role="status" aria-live="polite" class="ticket-box mb-3" tabindex="-1" id="reg-result">
            <i class="bi bi-check-circle-fill text-success mb-2 d-block" style="font-size:2rem" aria-hidden="true"></i>
            <h2 class="fw-bold fs-5"><?= $status === 'waitlist' ? 'Trafiłeś/aś na listę oczekujących' : 'Rejestracja zakończona pomyślnie!' ?></h2>
            <?php if ($status === 'confirmed'): ?>
            <?php if ($event['type'] !== 'webinar'): ?>
            <p class="reg-hint mb-2">Twój kod biletu:</p>
            <div class="ticket-code-wrap" aria-label="Kod biletu: <?= h($ticket_code) ?>">
                <div class="ticket-code" aria-hidden="true"><?= h($ticket_code) ?></div>
            </div>
            <p class="reg-hint mt-2">Zachowaj ten kod — będzie potrzebny przy wejściu.</p>
            <?php endif; ?>
            <?php if ($event['type'] === 'webinar' && !empty($event['meeting_url'])): ?>
            <a href="<?= h($event['meeting_url']) ?>" target="_blank" rel="noopener noreferrer"
               class="btn btn-sm mt-3" style="background:var(--ev-purple);color:#fff">
                <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Dołącz do wydarzenia online
                <span class="visually-hidden">(otwiera się w nowej karcie)</span>
            </a>
            <p class="reg-hint mt-2 mb-1">Link będzie aktywny wyłącznie w dniu wydarzenia.</p>
            <p class="reg-hint mb-0">Wysłaliśmy go też na Twój e-mail.</p>
            <?php endif; ?>
            <?php else: ?>
            <p class="reg-hint">Zostaniesz powiadomiony/a, jeśli zwolni się miejsce.</p>
            <?php endif; ?>
        </div>
        <div class="text-center">
            <a href="<?= APP_URL ?>/events/public/register.php?slug=<?= urlencode($slug) ?>"
               class="btn btn-outline-secondary btn-sm">
                Powrót do formularza
            </a>
        </div>

        <?php elseif ($reg_not_yet): ?>
        <div role="status" class="alert alert-info d-flex gap-2 align-items-start">
            <i class="bi bi-clock-history flex-shrink-0 mt-1" aria-hidden="true"></i>
            <span>Rejestracja otworzy się
                <strong><?= date('d.m.Y o H:i', strtotime($event['reg_open_at'])) ?></strong>.
            </span>
        </div>

        <?php elseif ($reg_closed): ?>
        <div role="status" class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="bi bi-lock-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
            <span>Rejestracja na to wydarzenie jest zamknięta.</span>
        </div>

        <?php elseif ($capacity_full && !$waitlist_enabled): ?>
        <div role="status" class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="bi bi-people-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
            <span>Brak wolnych miejsc na to wydarzenie.</span>
        </div>

        <?php else: ?>

        <?php if ($capacity_full && $waitlist_enabled): ?>
        <div role="note" class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
            <span>Brak wolnych miejsc — Twoja rejestracja trafi na listę oczekujących.</span>
        </div>
        <?php endif; ?>

        <?php if ($errors): ?>
        <!-- Podsumowanie błędów (WCAG 3.3.1) — focus przenoszony przez JS -->
        <div id="reg-errors" class="alert alert-danger" role="alert" aria-live="assertive" tabindex="-1">
            <h2 class="fw-semibold fs-6 mb-2">
                <i class="bi bi-exclamation-circle-fill me-1" aria-hidden="true"></i>
                Formularz zawiera <?= count($errors) === 1 ? 'błąd' : 'błędy' ?> — popraw je i spróbuj ponownie:
            </h2>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post" novalidate aria-label="Formularz rejestracji na wydarzenie">
            <!-- Nota o polach wymaganych (WCAG 3.3.2) -->
            <p class="reg-hint mb-3">
                Pola oznaczone <abbr title="wymagane" aria-label="wymagane">*</abbr> są obowiązkowe.
            </p>

            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label for="first_name" class="form-label fw-semibold">
                        Imię <abbr title="wymagane" aria-label="wymagane" class="text-danger" style="text-decoration:none">*</abbr>
                    </label>
                    <input type="text" autocomplete="given-name"
                           class="form-control<?= isset($field_errors['first_name']) ? ' is-invalid' : '' ?>"
                           id="first_name" name="first_name"
                           value="<?= h($_POST['first_name'] ?? '') ?>"
                           required
                           aria-required="true"
                           <?= isset($field_errors['first_name']) ? 'aria-invalid="true" aria-describedby="err-first_name"' : '' ?>>
                    <?php if (isset($field_errors['first_name'])): ?>
                    <div id="err-first_name" class="invalid-feedback">Imię jest wymagane.</div>
                    <?php endif; ?>
                </div>
                <div class="col-sm-6">
                    <label for="last_name" class="form-label fw-semibold">
                        Nazwisko <abbr title="wymagane" aria-label="wymagane" class="text-danger" style="text-decoration:none">*</abbr>
                    </label>
                    <input type="text" autocomplete="family-name"
                           class="form-control<?= isset($field_errors['last_name']) ? ' is-invalid' : '' ?>"
                           id="last_name" name="last_name"
                           value="<?= h($_POST['last_name'] ?? '') ?>"
                           required
                           aria-required="true"
                           <?= isset($field_errors['last_name']) ? 'aria-invalid="true" aria-describedby="err-last_name"' : '' ?>>
                    <?php if (isset($field_errors['last_name'])): ?>
                    <div id="err-last_name" class="invalid-feedback">Nazwisko jest wymagane.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">
                    Adres e-mail <abbr title="wymagane" aria-label="wymagane" class="text-danger" style="text-decoration:none">*</abbr>
                </label>
                <input type="email" autocomplete="email"
                       class="form-control<?= isset($field_errors['email']) ? ' is-invalid' : '' ?>"
                       id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       required
                       aria-required="true"
                       <?= isset($field_errors['email']) ? 'aria-invalid="true" aria-describedby="err-email"' : '' ?>>
                <?php if (isset($field_errors['email'])): ?>
                <div id="err-email" class="invalid-feedback">Podaj poprawny adres e-mail.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="phone" class="form-label fw-semibold">Numer telefonu</label>
                <input type="tel" autocomplete="tel"
                       class="form-control"
                       id="phone" name="phone"
                       value="<?= h($_POST['phone'] ?? '') ?>">
                <div class="form-text reg-hint">Opcjonalnie — używany tylko w razie konieczności kontaktu.</div>
            </div>

            <?php foreach ($form_fields as $ff):
                $fval     = $_POST['field_' . $ff['field_key']] ?? '';
                $fid      = 'field_' . h($ff['field_key']);
                $ferr_id  = 'err-' . h($ff['field_key']);
                $has_err  = isset($field_errors['field_' . $ff['field_key']]);
                $invalid_attrs = $has_err
                    ? 'aria-invalid="true" aria-describedby="' . $ferr_id . '"'
                    : '';
            ?>
            <div class="mb-3">
                <label for="<?= $fid ?>" class="form-label fw-semibold">
                    <?= h($ff['label']) ?>
                    <?php if ($ff['is_required']): ?>
                    <abbr title="wymagane" aria-label="wymagane" class="text-danger" style="text-decoration:none">*</abbr>
                    <?php endif; ?>
                </label>
                <?php if ($ff['type'] === 'textarea'): ?>
                <textarea class="form-control<?= $has_err ? ' is-invalid' : '' ?>"
                          id="<?= $fid ?>" name="<?= $fid ?>"
                          <?= $ff['is_required'] ? 'required aria-required="true"' : '' ?>
                          placeholder="<?= h($ff['placeholder']) ?>"
                          <?= $invalid_attrs ?>><?= h($fval) ?></textarea>
                <?php elseif ($ff['type'] === 'select'):
                    $opts = $ff['options'] ? (is_string($ff['options']) ? json_decode($ff['options'],true) : $ff['options']) : [];
                ?>
                <select class="form-select<?= $has_err ? ' is-invalid' : '' ?>"
                        id="<?= $fid ?>" name="<?= $fid ?>"
                        <?= $ff['is_required'] ? 'required aria-required="true"' : '' ?>
                        <?= $invalid_attrs ?>>
                    <option value="">Wybierz…</option>
                    <?php foreach ($opts as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $fval === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($ff['type'] === 'checkbox'): ?>
                <div class="form-check">
                    <input class="form-check-input<?= $has_err ? ' is-invalid' : '' ?>"
                           type="checkbox" id="<?= $fid ?>" name="<?= $fid ?>"
                           value="1" <?= $fval ? 'checked' : '' ?>
                           <?= $ff['is_required'] ? 'required aria-required="true"' : '' ?>
                           <?= $invalid_attrs ?>>
                    <label class="form-check-label" for="<?= $fid ?>"><?= h($ff['placeholder'] ?: $ff['label']) ?></label>
                </div>
                <?php else: ?>
                <input type="<?= h($ff['type']) ?>"
                       class="form-control<?= $has_err ? ' is-invalid' : '' ?>"
                       id="<?= $fid ?>" name="<?= $fid ?>"
                       value="<?= h($fval) ?>"
                       placeholder="<?= h($ff['placeholder']) ?>"
                       <?= $ff['is_required'] ? 'required aria-required="true"' : '' ?>
                       <?= $invalid_attrs ?>>
                <?php endif; ?>
                <?php if ($has_err): ?>
                <div id="<?= $ferr_id ?>" class="invalid-feedback">
                    Pole „<?= h($ff['label']) ?>" jest wymagane.
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($rodo_text): ?>
            <div class="mt-4 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
                <p class="fw-semibold small mb-2">
                    <i class="bi bi-shield-check text-success me-1" aria-hidden="true"></i>Klauzula informacyjna RODO
                </p>
                <?php if ($gdpr): ?>
                <div id="rodo-text" class="small reg-hint mb-2 gdpr-rich" style="max-height:180px;overflow-y:auto" tabindex="0" aria-label="Treść klauzuli informacyjnej"><?= $gdpr['html'] /* escapowany render modułu */ ?></div>
                <p class="small mb-3"><a href="<?= h($gdpr['url']) ?>" target="_blank" rel="noopener">Pełna treść klauzuli <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a></p>
                <?= $gdpr['field'] ?>
                <style>.gdpr-rich h2{font-size:.85rem;font-weight:700;margin:.6rem 0 .2rem}.gdpr-rich p,.gdpr-rich ul{margin-bottom:.4rem}</style>
                <?php else: ?>
                <div id="rodo-text" class="small reg-hint mb-3" style="max-height:120px;overflow-y:auto;white-space:pre-wrap"><?= h($rodo_text) ?></div>
                <?php endif; ?>
                <div class="form-check">
                    <input class="form-check-input<?= isset($field_errors['rodo_consent']) ? ' is-invalid' : '' ?>"
                           type="checkbox" id="rodo_consent" name="rodo_consent" value="1"
                           required aria-required="true"
                           <?= isset($field_errors['rodo_consent']) ? 'aria-invalid="true" aria-describedby="err-rodo"' : '' ?>
                           <?= !empty($_POST['rodo_consent']) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="rodo_consent">
                        Zapoznałem/am się z powyższą klauzulą informacyjną i wyrażam zgodę na przetwarzanie moich danych osobowych w podanym zakresie.
                        <abbr title="wymagane" aria-label="wymagane" class="text-danger" style="text-decoration:none">*</abbr>
                    </label>
                    <?php if (isset($field_errors['rodo_consent'])): ?>
                    <div id="err-rodo" class="invalid-feedback">Zgoda jest wymagana.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-lg"
                        style="background:var(--ev-purple);border-color:var(--ev-purple-dark);color:#fff;font-weight:600">
                    <i class="bi bi-check-circle me-2" aria-hidden="true"></i>Zarejestruj się
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<p class="text-center reg-hint pb-4 mt-2">
    <?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?>
</p>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Przenieś fokus na blok błędów po reload (WCAG 2.4.3 / 3.3.1)
(function () {
    var errBox = document.getElementById('reg-errors');
    if (errBox) { errBox.focus(); return; }
    var result = document.getElementById('reg-result');
    if (result) result.focus();
})();
</script>
</body>
</html>
