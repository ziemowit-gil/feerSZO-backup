<?php
/**
 * events/public/preview.php — Podgląd formularza rejestracji (tylko dla organizatorów)
 * Renderuje formularz identycznie jak register.php, ale:
 *  - wymaga zalogowania z rolą admin/volunteer dla danego wydarzenia
 *  - nie przetwarza rejestracji
 *  - wyświetla baner PODGLĄD
 */
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

require_login();

$event_id = (int)($_GET['id'] ?? 0);
if (!$event_id) {
    flash_set('error', 'Brak ID wydarzenia.');
    header('Location: ' . APP_URL . '/events/index.php'); exit;
}

$event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
if (!$event) {
    flash_set('error', 'Wydarzenie nie istnieje.');
    header('Location: ' . APP_URL . '/events/index.php'); exit;
}

ev_require_role($event_id, ['admin', 'volunteer']);

// Pola niestandardowe
$form_fields = [];
try {
    $form_fields = db_all("SELECT * FROM ev_form_fields WHERE event_id=? ORDER BY position ASC", [$event_id]);
} catch (\Throwable $e) {}

// Klauzula RODO
$rodo = '';
try {
    $rodo = trim($event['rodo_clause'] ?? '') ?: org_setting('ev_rodo_clause');
    $org  = defined('ORG_NAME') ? ORG_NAME : '';
    $rodo = str_replace(['{org_name}', '{event_title}'], [$org, $event['title']], $rodo);
} catch (\Throwable $e) {}

$confirmed_count = 0;
try {
    $confirmed_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'",
        [$event_id]
    )['n'] ?? 0);
} catch (\Throwable $e) {}

$capacity_full    = $event['capacity'] && $confirmed_count >= (int)$event['capacity'];
$waitlist_enabled = org_setting('ev_waitlist_enabled') !== '0';
$pub_url          = APP_URL . '/events/public/register.php?slug=' . urlencode($event['slug']);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Podgląd formularza — <?= h($event['title']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--ev-purple:#7c3aed;--ev-purple-bg:#f5f3ff;}
body{background:#f1f5f9;min-height:100vh;padding-bottom:3rem}
.preview-bar{background:var(--ev-purple);color:#fff;text-align:center;padding:.55rem 1rem;
  font-size:.82rem;font-weight:700;letter-spacing:.06em;position:sticky;top:0;z-index:100;
  box-shadow:0 2px 8px rgba(0,0,0,.18)}
.preview-bar a{color:#e9d5ff;text-decoration:underline}
.reg-card{max-width:580px;margin:1.5rem auto;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);pointer-events:auto}
.reg-header{background:var(--ev-purple);color:#fff;border-radius:16px 16px 0 0;padding:1.75rem}
.preview-overlay{position:absolute;inset:0;border-radius:16px;
  background:repeating-linear-gradient(45deg,rgba(124,58,237,.03) 0,rgba(124,58,237,.03) 10px,transparent 10px,transparent 20px);
  pointer-events:none;z-index:1}
.reg-wrap{position:relative}
</style>
</head>
<body>

<!-- Baner podglądu -->
<div class="preview-bar" role="banner" aria-label="Tryb podglądu">
    <i class="bi bi-eye-fill me-2"></i>PODGLĄD FORMULARZA — dane nie są zapisywane
    <span class="mx-3 opacity-50">|</span>
    <a href="<?= APP_URL ?>/events/edit.php?id=<?= $event_id ?>&tab=fields">
        <i class="bi bi-pencil me-1"></i>Edytuj pola
    </a>
    <span class="mx-2 opacity-50">·</span>
    <a href="<?= APP_URL ?>/events/manage.php?id=<?= $event_id ?>">
        <i class="bi bi-arrow-left me-1"></i>Wróć do zarządzania
    </a>
    <?php if ($event['status'] === 'published' && $event['is_public']): ?>
    <span class="mx-2 opacity-50">·</span>
    <a href="<?= h($pub_url) ?>" target="_blank">
        <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz publiczny formularz
    </a>
    <?php endif; ?>
</div>

<div class="reg-wrap">
<div class="reg-card bg-white">
    <!-- Nagłówek -->
    <div class="reg-header">
        <div class="d-flex align-items-start gap-3">
            <div class="flex-grow-1">
                <h4 class="mb-1 fw-bold"><?= h($event['title']) ?></h4>
                <div class="small opacity-80 d-flex flex-wrap gap-3">
                    <?php if ($event['start_at']): ?>
                    <span><i class="bi bi-clock me-1"></i><?= date('d.m.Y H:i', strtotime($event['start_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ($event['end_at']): ?>
                    <span>– <?= date('H:i', strtotime($event['end_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ($event['type'] === 'stationary' && $event['venue']): ?>
                    <span><i class="bi bi-geo-alt me-1"></i><?= h($event['venue']) ?></span>
                    <?php elseif ($event['type'] === 'webinar'): ?>
                    <span><i class="bi bi-camera-video me-1"></i>Wydarzenie online</span>
                    <?php endif; ?>
                </div>
                <?php if ($event['description']): ?>
                <div class="small mt-2 opacity-80"><?= nl2br(h($event['description'])) ?></div>
                <?php endif; ?>
            </div>
            <div class="flex-shrink-0 text-end">
                <?php if ($event['capacity']): ?>
                <div class="small opacity-75">
                    <i class="bi bi-people me-1"></i><?= $confirmed_count ?> / <?= $event['capacity'] ?>
                </div>
                <?php endif; ?>
                <span class="badge mt-1" style="background:rgba(255,255,255,.2);font-size:.7rem">
                    <?= $event['type'] === 'webinar' ? 'Online' : 'Stacjonarne' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card-body p-4">

        <!-- Info o statusie (podgląd) -->
        <div class="alert alert-light border d-flex align-items-center gap-2 mb-4 py-2" style="font-size:.82rem">
            <i class="bi bi-info-circle text-primary"></i>
            <div>
                <strong>Status:</strong>
                <?php
                $sl = ['draft'=>'Szkic','published'=>'Opublikowane','cancelled'=>'Odwołane','archived'=>'Archiwum'];
                echo h($sl[$event['status']] ?? $event['status']);
                ?>
                <?php if ($event['capacity']): ?>
                · <strong>Miejsca:</strong> <?= $confirmed_count ?> / <?= $event['capacity'] ?>
                <?php if ($capacity_full): ?> — <span class="text-danger fw-semibold">Brak miejsc</span><?php endif; ?>
                <?php endif; ?>
                <?php if ($event['reg_open_at'] || $event['reg_close_at']): ?>
                · <strong>Rejestracja:</strong>
                <?= $event['reg_open_at'] ? 'od ' . date('d.m.Y H:i', strtotime($event['reg_open_at'])) : '' ?>
                <?= $event['reg_close_at'] ? ' do ' . date('d.m.Y H:i', strtotime($event['reg_close_at'])) : '' ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($capacity_full && $waitlist_enabled): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Brak wolnych miejsc — rejestracja trafia na listę oczekujących.
        </div>
        <?php endif; ?>

        <!-- Formularz (niefunkcjonalny w podglądzie) -->
        <form onsubmit="return previewSubmit(event)">

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Imię <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" placeholder="np. Jan" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Nazwisko <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" placeholder="np. Kowalski" disabled>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" placeholder="jan.kowalski@example.com" disabled>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Telefon</label>
                <input type="tel" class="form-control" placeholder="+48 600 000 000" disabled>
            </div>

            <?php foreach ($form_fields as $ff):
                $fid  = 'field_' . h($ff['field_key']);
                $opts = [];
                if (in_array($ff['type'], ['select','checkbox']) && $ff['options']) {
                    $opts = is_string($ff['options']) ? (json_decode($ff['options'], true) ?: []) : $ff['options'];
                }
            ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    <?= h($ff['label']) ?>
                    <?php if ($ff['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
                    <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem;font-weight:400"><?= h($ff['type']) ?></span>
                </label>
                <?php if ($ff['type'] === 'textarea'): ?>
                <textarea class="form-control" rows="3" placeholder="<?= h($ff['placeholder']) ?>" disabled></textarea>
                <?php elseif ($ff['type'] === 'select'): ?>
                <select class="form-select" disabled>
                    <option>Wybierz…</option>
                    <?php foreach ($opts as $opt): ?>
                    <option><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($ff['type'] === 'checkbox'): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" disabled>
                    <label class="form-check-label"><?= h($ff['placeholder'] ?: $ff['label']) ?></label>
                </div>
                <?php else: ?>
                <input type="<?= h($ff['type']) ?>" class="form-control"
                       placeholder="<?= h($ff['placeholder']) ?>" disabled>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($rodo): ?>
            <div class="mb-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.82rem">
                <div class="fw-semibold text-success mb-1">
                    <i class="bi bi-shield-check me-1"></i>Klauzula informacyjna RODO
                </div>
                <div style="white-space:pre-wrap;color:#374151"><?= h($rodo) ?></div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" disabled>
                    <label class="form-check-label small fw-semibold">
                        Zapoznałem/am się z klauzulą informacyjną i wyrażam zgodę na przetwarzanie danych. <span class="text-danger">*</span>
                    </label>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-warning small mb-3 py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Brak klauzuli RODO. Dodaj ją w zakładce
                <a href="<?= APP_URL ?>/events/edit.php?id=<?= $event_id ?>&tab=rodo">RODO</a>
                lub w <a href="<?= APP_URL ?>/admin/events_settings.php">ustawieniach modułu</a>.
            </div>
            <?php endif; ?>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-lg btn-primary"
                        style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-circle me-2"></i>Zarejestruj się
                </button>
            </div>

            <p class="text-center text-muted small mt-3 mb-0">
                <i class="bi bi-lock me-1"></i>Dane są chronione zgodnie z RODO.
            </p>
        </form>

        <?php if (!$form_fields): ?>
        <div class="alert alert-info mt-3 small py-2">
            <i class="bi bi-info-circle me-1"></i>
            Brak dodatkowych pól. Możesz je dodać w zakładce
            <a href="<?= APP_URL ?>/events/edit.php?id=<?= $event_id ?>&tab=fields">Pola formularza</a>.
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="text-center text-muted small pb-3 mt-2" style="font-size:.75rem;opacity:.6">
    <?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?> — Podgląd formularza rejestracji
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previewSubmit(e) {
    e.preventDefault();
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);background:#7c3aed;color:#fff;padding:.75rem 1.5rem;border-radius:8px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2)';
    toast.innerHTML = '<i class="bi bi-info-circle me-2"></i>To jest podgląd — dane nie są zapisywane.';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>
</body>
</html>
