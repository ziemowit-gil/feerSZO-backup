<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia modułu Wydarzeń';

// Automatyczna migracja przy pierwszym wejściu w ustawienia
try {
    $already = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ev_events'")->fetchColumn();
    if (!$already) {
        $mig = dirname(__DIR__) . '/cli/migrations/migrate_events.php';
        if (file_exists($mig)) {
            ob_start();
            require $mig;
            $mig_out = ob_get_clean();
            try {
                db()->prepare(
                    "INSERT INTO _migration_log (filename, status, output) VALUES ('migrate_events.php','ok',?)
                     ON CONFLICT(filename) DO UPDATE SET run_at=CURRENT_TIMESTAMP, status='ok', output=excluded.output"
                )->execute([$mig_out]);
            } catch (\Throwable $e) {}
        }
    }
} catch (\Throwable $e) {}

$settings_keys = [
    'ev_mail_confirm_enabled',
    'ev_mail_from',
    'ev_mail_subject_tpl',
    'ev_mail_body_tpl',
    'ev_notify_admin_enabled',
    'ev_notify_admin_email',
    'ev_public_reg_enabled',
    'ev_waitlist_enabled',
    'ev_default_capacity',
    'ev_show_in_nav',
    'ev_rodo_clause',
    'ev_crm_auto_sync_default',
];

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values = [
        'ev_mail_confirm_enabled'   => isset($_POST['ev_mail_confirm_enabled'])   ? '1' : '0',
        'ev_notify_admin_enabled'   => isset($_POST['ev_notify_admin_enabled'])   ? '1' : '0',
        'ev_public_reg_enabled'     => isset($_POST['ev_public_reg_enabled'])     ? '1' : '0',
        'ev_waitlist_enabled'       => isset($_POST['ev_waitlist_enabled'])        ? '1' : '0',
        'ev_show_in_nav'            => isset($_POST['ev_show_in_nav'])             ? '1' : '0',
        'ev_crm_auto_sync_default'  => isset($_POST['ev_crm_auto_sync_default'])  ? '1' : '0',
        'ev_mail_from'              => trim($_POST['ev_mail_from']           ?? ''),
        'ev_mail_subject_tpl'       => trim($_POST['ev_mail_subject_tpl']    ?? ''),
        'ev_mail_body_tpl'          => trim($_POST['ev_mail_body_tpl']       ?? ''),
        'ev_notify_admin_email'     => trim($_POST['ev_notify_admin_email']  ?? ''),
        'ev_default_capacity'       => (int)($_POST['ev_default_capacity']   ?? 0) ?: '',
        'ev_rodo_clause'            => trim($_POST['ev_rodo_clause']         ?? ''),
    ];

    foreach ($values as $key => $val) {
        try {
            db()->prepare(
                "INSERT INTO settings (key_, value) VALUES (?, ?)
                 ON CONFLICT(key_) DO UPDATE SET value=excluded.value"
            )->execute([$key, $val]);
        } catch (\Throwable $e) {
            try {
                $exists = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
                if ($exists) {
                    db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
                } else {
                    db_insert('settings', ['key_' => $key, 'value' => $val]);
                }
            } catch (\Throwable $e2) {}
        }
    }

    flash_set('success', 'Ustawienia modułu Wydarzeń zostały zapisane.');
    header('Location: events_settings.php');
    exit;
}

// ── Read current settings ─────────────────────────────────────────────────────
$cfg = [];
foreach ($settings_keys as $k) {
    $cfg[$k] = org_setting($k);
}

// Defaults
if ($cfg['ev_mail_confirm_enabled']  === '') $cfg['ev_mail_confirm_enabled']  = '1';
if ($cfg['ev_notify_admin_enabled']  === '') $cfg['ev_notify_admin_enabled']  = '0';
if ($cfg['ev_public_reg_enabled']    === '') $cfg['ev_public_reg_enabled']    = '1';
if ($cfg['ev_waitlist_enabled']      === '') $cfg['ev_waitlist_enabled']      = '1';
if ($cfg['ev_show_in_nav']           === '') $cfg['ev_show_in_nav']           = '1';
if ($cfg['ev_crm_auto_sync_default'] === '') $cfg['ev_crm_auto_sync_default'] = '1';
if ($cfg['ev_mail_subject_tpl']      === '') $cfg['ev_mail_subject_tpl']      = 'Potwierdzenie rejestracji — {event_title}';
if ($cfg['ev_mail_body_tpl']         === '') $cfg['ev_mail_body_tpl']         =
    "Witaj {first_name},\n\nDziękujemy za rejestrację na wydarzenie \"{event_title}\".\n\nTwój kod biletu: {ticket_code}\n\nDo zobaczenia!\n";
if ($cfg['ev_rodo_clause']           === '') $cfg['ev_rodo_clause']           =
    "Administratorem Pani/Pana danych osobowych jest {org_name}.\n"
    . "Dane będą przetwarzane w celu organizacji wydarzenia \"{event_title}\" na podstawie art. 6 ust. 1 lit. a RODO (zgoda).\n"
    . "Podanie danych jest dobrowolne. Przysługuje Pani/Panu prawo dostępu, sprostowania, usunięcia danych oraz wniesienia skargi do UODO.";

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="mb-0"><i class="bi bi-calendar-event text-primary me-2"></i>Ustawienia modułu Wydarzeń</h4>
    <a href="<?= APP_URL ?>/events/dashboard.php" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-calendar-event me-1"></i>Przejdź do wydarzeń
    </a>
</div>

<?= flash_html() ?>

<div class="row g-4" style="max-width:900px">
    <div class="col-12">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

            <!-- General settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-gear me-2 text-primary"></i>Ogólne
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="ev_public_reg_enabled" name="ev_public_reg_enabled"
                                       <?= $cfg['ev_public_reg_enabled'] !== '0' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="ev_public_reg_enabled">
                                    Publiczne rejestracje
                                </label>
                                <div class="form-text">Zezwalaj na rejestrację bez logowania (dla wydarzeń oznaczonych jako publiczne).</div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="ev_waitlist_enabled" name="ev_waitlist_enabled"
                                       <?= $cfg['ev_waitlist_enabled'] !== '0' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="ev_waitlist_enabled">
                                    Lista oczekujących
                                </label>
                                <div class="form-text">Gdy zabraknie miejsc, uczestnicy trafiają na listę oczekujących.</div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="ev_show_in_nav" name="ev_show_in_nav"
                                       <?= $cfg['ev_show_in_nav'] !== '0' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="ev_show_in_nav">
                                    Pokazuj w nawigacji głównej
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="ev_default_capacity" class="form-label fw-semibold">Domyślny limit miejsc</label>
                            <input type="number" class="form-control" id="ev_default_capacity" name="ev_default_capacity"
                                   min="0" value="<?= h($cfg['ev_default_capacity']) ?>" placeholder="bez limitu">
                            <div class="form-text">Wpisz 0 lub zostaw puste — bez limitu.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email do uczestnika -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-envelope me-2 text-primary"></i>Email potwierdzający rejestrację (do uczestnika)
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="ev_mail_confirm_enabled" name="ev_mail_confirm_enabled"
                               <?= $cfg['ev_mail_confirm_enabled'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="ev_mail_confirm_enabled">
                            Wysyłaj email potwierdzający rejestrację uczestnikowi
                        </label>
                    </div>
                    <div class="mb-3">
                        <label for="ev_mail_from" class="form-label fw-semibold">Adres nadawcy (From)</label>
                        <input type="email" class="form-control" id="ev_mail_from" name="ev_mail_from"
                               value="<?= h($cfg['ev_mail_from']) ?>" placeholder="np. noreply@example.org">
                    </div>
                    <div class="mb-3">
                        <label for="ev_mail_subject_tpl" class="form-label fw-semibold">Szablon tematu</label>
                        <input type="text" class="form-control" id="ev_mail_subject_tpl" name="ev_mail_subject_tpl"
                               value="<?= h($cfg['ev_mail_subject_tpl']) ?>">
                        <div class="form-text">
                            Zmienne: <code>{event_title}</code> <code>{ticket_code}</code>
                            <code>{first_name}</code> <code>{last_name}</code>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label for="ev_mail_body_tpl" class="form-label fw-semibold">Treść emaila</label>
                        <textarea class="form-control font-monospace" id="ev_mail_body_tpl" name="ev_mail_body_tpl"
                                  rows="8"><?= h($cfg['ev_mail_body_tpl']) ?></textarea>
                        <div class="form-text">
                            Zmienne: <code>{event_title}</code> <code>{ticket_code}</code>
                            <code>{first_name}</code> <code>{last_name}</code>
                            <code>{event_date}</code> <code>{event_url}</code>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Powiadomienia dla admina/organizatora -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-bell-fill me-2 text-warning"></i>Powiadomienia dla organizatora
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="ev_notify_admin_enabled" name="ev_notify_admin_enabled"
                               <?= $cfg['ev_notify_admin_enabled'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="ev_notify_admin_enabled">
                            Wysyłaj powiadomienie przy każdej nowej rejestracji
                        </label>
                        <div class="form-text">Organizator lub podany adres email otrzyma wiadomość gdy ktoś się zapisze.</div>
                    </div>
                    <div class="mb-0">
                        <label for="ev_notify_admin_email" class="form-label fw-semibold">Domyślny adres email powiadomień</label>
                        <input type="email" class="form-control" id="ev_notify_admin_email" name="ev_notify_admin_email"
                               value="<?= h($cfg['ev_notify_admin_email']) ?>"
                               placeholder="np. organizator@example.org">
                        <div class="form-text">Każde wydarzenie może nadpisać ten adres w zakładce Powiadomienia &amp; CRM.</div>
                    </div>
                </div>
            </div>

            <!-- CRM -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-diagram-2-fill me-2" style="color:#7c3aed"></i>Integracja z CRM
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="ev_crm_auto_sync_default" name="ev_crm_auto_sync_default"
                               <?= $cfg['ev_crm_auto_sync_default'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="ev_crm_auto_sync_default">
                            Domyślnie synchronizuj uczestników z CRM
                        </label>
                        <div class="form-text">
                            Nowe wydarzenia będą mieć domyślnie włączoną synchronizację z CRM.
                            Każde wydarzenie może nadpisać to ustawienie w zakładce Powiadomienia &amp; CRM.
                        </div>
                    </div>
                </div>
            </div>

            <!-- RODO -->
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-shield-check me-2 text-success"></i>Globalna klauzula informacyjna RODO
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Treść wyświetlana przy formularzu rejestracji (nad checkboxem zgody).
                        Każde wydarzenie może mieć własną klauzulę ustawioną w zakładce RODO — jeśli tam pusta, używana jest ta globalna.
                    </p>
                    <div class="mb-0">
                        <label for="ev_rodo_clause" class="form-label fw-semibold">Treść klauzuli</label>
                        <textarea class="form-control" id="ev_rodo_clause" name="ev_rodo_clause"
                                  rows="7"><?= h($cfg['ev_rodo_clause']) ?></textarea>
                        <div class="form-text">
                            Zmienne: <code>{org_name}</code> <code>{event_title}</code>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Zapisz ustawienia
                </button>
                <a href="modules_settings.php" class="btn btn-outline-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
