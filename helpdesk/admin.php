<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
require_once dirname(__DIR__) . '/modules/helpdeskMail/lib/helpdeskMail.php';
require_once dirname(__DIR__) . '/modules/helpdeskTime/lib/helpdeskTime.php';
require_once dirname(__DIR__) . '/modules/helpdeskCsat/lib/helpdeskCsat.php';
require_once dirname(__DIR__) . '/modules/helpdeskCategories/lib/helpdeskCategories.php';
require_once dirname(__DIR__) . '/modules/helpdeskMigrate/lib/helpdeskMigrate.php';
require_once dirname(__DIR__) . '/modules/helpdeskEscalation/lib/helpdeskEscalation.php';
helpdesk_migrate();
require_login();
require_role('admin');

// ── Akcje ─────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Toggle helpdesk_operator
    if ($op === 'toggle_operator') {
        $target = (int)($_POST['user_id'] ?? 0);
        if ($target) {
            $cur = db_one("SELECT helpdesk_operator FROM users WHERE id=?", [$target]);
            if ($cur !== null) {
                $new = $cur['helpdesk_operator'] ? 0 : 1;
                db()->prepare("UPDATE users SET helpdesk_operator=? WHERE id=?")->execute([$new, $target]);
                flash_set('success', $new ? 'Uprawnienia operatora nadane.' : 'Uprawnienia operatora cofnięte.');
            }
        }
    }

    // Włącz/wyłącz moduł
    if ($op === 'toggle_module') {
        $cur = db_one("SELECT value FROM settings WHERE key_='helpdesk_enabled'");
        $new = ($cur['value'] ?? '1') === '1' ? '0' : '1';
        if ($cur) {
            db()->prepare("UPDATE settings SET value=? WHERE key_='helpdesk_enabled'")->execute([$new]);
        } else {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['helpdesk_enabled', $new]);
        }
        flash_set('success', $new === '1' ? 'Helpdesk włączony.' : 'Helpdesk wyłączony.');
    }

    if ($op === 'autoesc_save') {
        $mails = array_filter(preg_split('/[\s,;]+/', trim((string)($_POST['hd_autoesc_notify'] ?? ''))));
        $bad = array_filter($mails, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad) flash_set('danger', 'Niepoprawny adres e-mail: ' . implode(', ', $bad));
        else {
            org_setting_set('hd_autoesc_enabled', !empty($_POST['hd_autoesc_enabled']) ? '1' : '0');
            org_setting_set('hd_autoesc_l2_hours', (string)max(1, min(720, (int)($_POST['hd_autoesc_l2_hours'] ?? 24))));
            org_setting_set('hd_autoesc_raise', !empty($_POST['hd_autoesc_raise']) ? '1' : '0');
            org_setting_set('hd_autoesc_notify', implode(', ', $mails));
            flash_set('success', 'Ustawienia automatycznego podbijania zapisane.');
        }
    }
    if ($op === 'autoesc_run') {
        $r = hdAutoEscRun();
        flash_set(!empty($r['disabled']) ? 'warning' : 'success', !empty($r['disabled']) ? 'Automatyczne podbijanie jest wyłączone — włącz je i zapisz ustawienia.'
            : 'Podbito: poziom 1 — ' . (int)$r['l1'] . ', poziom 2 — ' . (int)$r['l2'] . '.');
    }
    if ($op === 'migrate_numbers') {
        $r = hdMigrateRun((int)(current_user()['id'] ?? 0));
        flash_set(isset($r['error']) ? 'danger' : 'success', $r['error'] ?? ('Zmigrowano numery zgłoszeń: ' . (int)$r['migrated'] . '. Stare numery zostają w polu „dawny numer” i nadal działają w wyszukiwarce oraz w odpowiedziach na stare e-maile.'));
    }
    if ($op === 'numbering_save') {
        $pat = trim((string)($_POST['hd_number_pattern'] ?? ''));
        if ($pat === '' || hdNumberPatternValid($pat)) { org_setting_set('hd_number_pattern', $pat); flash_set('success', 'Wzorzec numeru zapisany.'); }
        else flash_set('danger', 'Nieprawidłowy wzorzec: licznik {NNNNN} (3–8 liter N) musi być na końcu; dozwolone znaczniki {PREFIX} {YYYY} {YY} {MM}.');
    }
    if ($op === 'csat_toggle') {
        $new = hdCsatEnabled() ? '0' : '1';
        org_setting_set('hd_csat_enabled', $new);
        flash_set('success', $new === '1' ? 'Ankieta zadowolenia włączona — zgłaszający dostaną link po rozwiązaniu zgłoszenia.' : 'Ankieta zadowolenia wyłączona.');
    }
    if ($op === 'hours_save') {
        $err = hdWorkConfigSave($_POST);
        flash_set($err ? 'danger' : 'success', $err ? implode(' ', $err) : 'Ustawienia SLA zapisane.');
    }

    // Skrzynki Helpdesku (e-mail → zgłoszenia)
    if ($op === 'mail_save') {
        try { hdMailIntakeSave($_POST, (int)(current_user()['id'] ?? 0)); flash_set('success', 'Skrzynka zapisana.'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
    }
    if ($op === 'mail_run_now') {
        try { $r = hdMailIntakeRun(); flash_set('success', 'Sprawdzono skrzynki: nowe zgłoszenia ' . (int)$r['tickets'] . ', odpowiedzi w wątkach ' . (int)$r['replies'] . ', pominięte ' . (int)$r['skipped'] . '.'); }
        catch (\Throwable $e) { flash_set('danger', 'Błąd przetwarzania skrzynek: ' . $e->getMessage()); }
    }
    if ($op === 'mail_reply_save') {
        $addr = trim((string)($_POST['hd_mail_reply_to'] ?? ''));
        if ($addr === '' || filter_var($addr, FILTER_VALIDATE_EMAIL)) { org_setting_set('hd_mail_reply_to', $addr); flash_set('success', 'Adres odpowiedzi zapisany.'); }
        else flash_set('danger', 'Podaj poprawny adres e-mail albo zostaw puste (użyty zostanie adres podpiętej skrzynki).');
    }
    if ($op === 'mail_delete') {
        hdMailIntakeDelete((int)($_POST['mailbox_id'] ?? 0));
        flash_set('success', 'Skrzynka odpięta od Helpdesku.');
    }

    // Włącz/wyłącz formularz zgłoszenia błędu
    if ($op === 'toggle_bug_report') {
        $cur = db_one("SELECT value FROM settings WHERE key_='bug_report_enabled'");
        $new = ($cur['value'] ?? '1') === '1' ? '0' : '1';
        if ($cur) {
            db()->prepare("UPDATE settings SET value=? WHERE key_='bug_report_enabled'")->execute([$new]);
        } else {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['bug_report_enabled', $new]);
        }
        flash_set('success', $new === '1' ? 'Formularz zgłoszeń błędów włączony.' : 'Formularz zgłoszeń błędów wyłączony.');
    }

    header('Location: admin.php'); exit;
}

// ── Dane ─────────────────────────────────────────────────────────────────────
$hd_enabled         = (db_one("SELECT value FROM settings WHERE key_='helpdesk_enabled'")['value'] ?? '1') === '1';
$bug_report_enabled = (db_one("SELECT value FROM settings WHERE key_='bug_report_enabled'")['value'] ?? '1') === '1';
$work_cfg = hdWorkConfig();
$mail_intakes = hdMailIntakes();
$mail_free    = hdMailAvailableMailboxes();
$operators   = db_all("SELECT id, name, email, role FROM users WHERE helpdesk_operator=1 AND is_active=1 ORDER BY name");
$all_users   = db_all("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY name");

// Statystyki
$stats = [];
foreach (HD_STATUSES as $k => $s) {
    $row = db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status=?", [$k]);
    $stats[$k] = (int)($row['c'] ?? 0);
}
$total = array_sum($stats);

$PAGE_TITLE = 'Helpdesk — Ustawienia';
include dirname(__DIR__) . '/includes/header.php';
?>
<style>
/* Szata Dynamics 365: białe karty z cienką ramką, nagłówek bez tła, akcent #0f6cbd */
.hd-metro-admin .card, .hd-metro-admin .btn, .hd-metro-admin .badge, .hd-metro-admin .form-control, .hd-metro-admin .form-select,
.hd-metro-admin .alert, .hd-metro-admin .list-group-item, .hd-metro-admin .input-group-text { border-radius:2px !important; box-shadow:none !important; }
.hd-metro-admin .card { border:1px solid #e1dfdd !important; background:#fff; }
.hd-metro-admin .card-header { background:#fff !important; color:#242424 !important; text-transform:none !important; letter-spacing:0 !important;
  font-size:.9rem !important; font-weight:600 !important; border-bottom:1px solid #edebe9 !important; }
.hd-metro-admin .card-header i, .hd-metro-admin .card-header .text-primary { color:#0f6cbd !important; }
.hd-metro-admin .form-control, .hd-metro-admin .form-select { border:1px solid #8a8886; }
.hd-metro-admin .form-control:focus, .hd-metro-admin .form-select:focus { border-color:#0f6cbd; box-shadow:none; }
.hd-metro-admin .btn-primary { background:#0f6cbd; border-color:#0f6cbd; }
.hd-metro-admin .btn-primary:hover { background:#115ea3; border-color:#115ea3; }
.hd-metro-admin h4 { font-weight:600; font-size:1.4rem; }
.hd-metro-admin h4 .text-primary { color:#0f6cbd !important; }
</style>
<?= hd_admin_nav('settings') ?>
<div class="hd-metro-admin">

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-headset text-primary me-2"></i>Helpdesk IT — Admin</h4>
  </div>
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Lista zgłoszeń
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">

<!-- Statystyki -->
<div class="col-12">
  <div class="row g-2">
    <?php foreach (HD_STATUSES as $k => $s): ?>
    <div class="col-sm-4 col-md-2">
      <div class="card border-0 shadow-sm text-center py-2">
        <div class="fw-bold fs-4"><?= $stats[$k] ?></div>
        <div class="small"><?= hd_status_badge($k) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="col-sm-4 col-md-2">
      <div class="card border-0 shadow-sm text-center py-2 bg-light">
        <div class="fw-bold fs-4"><?= $total ?></div>
        <div class="small text-muted">Łącznie</div>
      </div>
    </div>
  </div>
</div>

<!-- Włącz/wyłącz moduł -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-power me-1"></i>Moduł Helpdesk
    </div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3">
        <div>
          Status:
          <span class="badge bg-<?= $hd_enabled ? 'success' : 'secondary' ?>">
            <?= $hd_enabled ? 'Włączony' : 'Wyłączony' ?>
          </span>
        </div>
        <form method="post" class="ms-auto">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="toggle_module">
          <button class="btn btn-sm btn-<?= $hd_enabled ? 'outline-danger' : 'success' ?>">
            <?= $hd_enabled ? '<i class="bi bi-pause-circle me-1"></i>Wyłącz' : '<i class="bi bi-play-circle me-1"></i>Włącz' ?>
          </button>
        </form>
      </div>
      <p class="text-muted small mt-2 mb-0">
        Wyłączenie modułu ukrywa link w menu — istniejące zgłoszenia pozostają w bazie.
      </p>
    </div>
  </div>
</div>

<!-- Formularz zgłoszeń błędów -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-bug-fill text-danger me-1"></i>Formularz zgłoszeń błędów
    </div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3">
        <div>
          Status:
          <span class="badge bg-<?= $bug_report_enabled ? 'success' : 'secondary' ?>">
            <?= $bug_report_enabled ? 'Włączony' : 'Wyłączony' ?>
          </span>
        </div>
        <form method="post" class="ms-auto">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="toggle_bug_report">
          <button class="btn btn-sm btn-<?= $bug_report_enabled ? 'outline-danger' : 'success' ?>">
            <?= $bug_report_enabled ? '<i class="bi bi-pause-circle me-1"></i>Wyłącz' : '<i class="bi bi-play-circle me-1"></i>Włącz' ?>
          </button>
        </form>
      </div>
      <p class="text-muted small mt-2 mb-0">
        Przycisk <i class="bi bi-bug-fill text-danger"></i>&nbsp;<em>Zgłoś błąd</em> widoczny jest w nagłówku każdej strony.
        Wyłączenie usuwa go ze wszystkich widoków.
      </p>
    </div>
  </div>
</div>

<!-- Operatorzy Helpdesk -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold d-flex align-items-center" style="font-size:.85rem">
      <i class="bi bi-person-badge me-1"></i>Operatorzy Helpdesk
      <span class="badge bg-secondary ms-2"><?= count($operators) ?></span>
    </div>
    <div class="card-body" style="max-height:250px;overflow-y:auto">
      <?php if (!$operators): ?>
      <p class="text-muted small">Brak operatorów. Dodaj poniżej.</p>
      <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($operators as $op): ?>
        <li class="list-group-item d-flex align-items-center gap-2 py-2 px-0">
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= h($op['name']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= h($op['email']) ?> · <?= h($op['role']) ?></div>
          </div>
          <form method="post">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_op"      value="toggle_operator">
            <input type="hidden" name="user_id"  value="<?= $op['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Cofnij uprawnienia">
              <i class="bi bi-x"></i>
            </button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Ankieta CSAT -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-emoji-smile me-1"></i>Ankieta zadowolenia (CSAT)</div>
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <p class="text-muted small mb-0 flex-grow-1">Po rozwiązaniu lub zamknięciu zgłoszenia zgłaszający dostaje e-mail z linkiem do oceny 1–5 (raz na zgłoszenie). Wyniki: pulpit Helpdesku i karta zgłoszenia. Domyślnie wyłączona — włączenie powoduje wysyłkę e-maili.</p>
      <?php $cs = hdCsatSummary(); ?>
      <span class="small">Odpowiedzi: <strong><?= $cs['answered'] ?></strong> / <?= $cs['sent'] ?><?= $cs['avg'] !== null ? ' · średnia <strong>' . h(number_format($cs['avg'], 2, ',', '')) . '</strong>' : '' ?></span>
      <form method="post"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="csat_toggle">
        <button class="btn btn-sm <?= hdCsatEnabled() ? 'btn-outline-danger' : 'btn-success' ?>"><?= hdCsatEnabled() ? 'Wyłącz' : 'Włącz' ?></button></form>
    </div>
  </div>
</div>

<!-- Automatyczne podbijanie -->
<?php $ae = hdAutoEscSettings(); $ae_now = hdAutoEscCandidates(); ?>
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-megaphone me-1"></i>Automatyczne podbijanie po przekroczeniu SLA</div>
    <div class="card-body">
      <p class="text-muted small mb-2">Zgłaszający nie podbija zgłoszeń ręcznie — robi to system (sprawdzanie co godzinę). <strong>Poziom 1:</strong> po przekroczeniu terminu reakcji lub rozwiązania zgłoszenie dostaje numer podbicia (z PDF), operatorzy są powiadamiani priorytetowo, a zgłaszający dostaje informację. <strong>Poziom 2:</strong> jeśli po podanym czasie SLA nadal jest przekroczone, powiadamiani są administratorzy i wskazane adresy, a priorytet może wzrosnąć o stopień. Najwyżej 2 podbicia na zgłoszenie; wstrzymane, rozwiązane i zamknięte są pomijane. Domyślnie wyłączone — włączenie powoduje wysyłkę e-maili.</p>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="autoesc_save">
        <div class="col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="hd_autoesc_enabled" value="1" id="aeOn"<?= $ae['enabled'] ? ' checked' : '' ?>><label class="form-check-label small" for="aeOn">Włączone</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="hd_autoesc_raise" value="1" id="aeRaise"<?= $ae['raise'] ? ' checked' : '' ?>><label class="form-check-label small" for="aeRaise">Podnieś priorytet na poz. 2</label></div></div>
        <div class="col-md-2"><label class="form-label small mb-0">Poziom 2 po (godz.)</label><input type="number" min="1" max="720" name="hd_autoesc_l2_hours" class="form-control form-control-sm" value="<?= (int)$ae['l2_hours'] ?>"></div>
        <div class="col-md-5"><label class="form-label small mb-0">Dodatkowe adresy przełożonych (po przecinku)</label><input name="hd_autoesc_notify" class="form-control form-control-sm" value="<?= h($ae['notify']) ?>" placeholder="kierownik@feer.org.pl, zarzad@feer.org.pl"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary">Zapisz</button></div>
      </form>
      <div class="d-flex flex-wrap align-items-center gap-3 mt-3 small">
        <span>Do podbicia w tej chwili: <strong><?= count($ae_now) ?></strong><?php if ($ae_now): ?> (<?= h(implode(', ', array_map(fn($x) => $x['ticket']['number'] . ' · poz. ' . $x['level'], array_slice($ae_now, 0, 5)))) ?><?= count($ae_now) > 5 ? '…' : '' ?>)<?php endif; ?></span>
        <form method="post" onsubmit="return confirm('Podbić teraz <?= count($ae_now) ?> zgłoszeń? Zostaną wysłane powiadomienia.')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="autoesc_run">
          <button class="btn btn-sm btn-outline-secondary"<?= !$ae['enabled'] || !$ae_now ? ' disabled' : '' ?>>Uruchom teraz</button></form>
        <span class="text-muted">Z konsoli: <code>php cron/helpdesk_autoescalate.php --dry-run</code></span>
      </div>
    </div>
  </div>
</div>

<!-- Migracja numerów -->
<?php $mig_plan = hdMigratePlan(); ?>
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-arrow-left-right me-1"></i>Migracja numerów zgłoszeń</div>
    <div class="card-body">
      <?php if (!$mig_plan): ?>
      <p class="text-muted small mb-0">Wszystkie zgłoszenia mają już numery w nowym formacie.</p>
      <?php else: ?>
      <p class="text-muted small mb-2">Do przeniesienia na wzorzec numeracji: <strong><?= count($mig_plan) ?></strong> zgłoszeń (prefiks zostaje, rok z daty utworzenia, licznik wg kolejności). Stary numer zostaje zapamiętany i nadal działa w wyszukiwarce oraz w odpowiedziach na stare e-maile. Operacja jest zapisywana w historii.</p>
      <div class="small mb-2" style="font-family:ui-monospace,monospace">
        <?php foreach (array_slice($mig_plan, 0, 5) as $mp): ?><div><?= h($mp['old']) ?> → <?= h($mp['new']) ?></div><?php endforeach; ?>
        <?php if (count($mig_plan) > 5): ?><div class="text-muted">… i <?= count($mig_plan) - 5 ?> kolejnych</div><?php endif; ?>
      </div>
      <form method="post" onsubmit="return confirm('Zmienić numery <?= count($mig_plan) ?> zgłoszeń? Stare numery zostaną zapamiętane.')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="migrate_numbers">
        <button class="btn btn-sm btn-primary">Migruj numery</button>
        <span class="small text-muted ms-2">Z konsoli: <code>php cli/helpdesk_migrate_numbers.php</code> (podgląd) / <code>--apply</code></span>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Numeracja zgłoszeń -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-hash me-1"></i>Numeracja zgłoszeń</div>
    <div class="card-body">
      <p class="text-muted small mb-2">Domyślny wzorzec numeru: znaczniki <code>{PREFIX}</code> (z kategorii), <code>{YYYY}</code>, <code>{YY}</code>, <code>{MM}</code> i licznik <code>{NNNNN}</code> na końcu. Licznik jest osobny dla każdego prefiksu i okresu z wzorca (nowy rok = od 1). Prefiks i opcjonalny własny wzorzec ustawiasz per kategoria w <a href="<?= APP_URL ?>/modules/dictionary/router.php">Słowniku Centralnym</a> (słownik „Helpdesk — kategorie zgłoszeń”, atrybuty <code>prefix</code> i <code>pattern</code>; podkategorie dziedziczą od kategorii nadrzędnej).</p>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="numbering_save">
        <div class="col-md-4"><label class="form-label small mb-0">Wzorzec domyślny</label>
          <input name="hd_number_pattern" class="form-control form-control-sm" value="<?= h(org_setting('hd_number_pattern')) ?>" placeholder="<?= h(HD_NUMBER_DEFAULT_PATTERN) ?>"></div>
        <div class="col-md-4 small text-muted">Następny numer w tej chwili: <strong><?= h(hdNextNumber('HD', hdNumberPattern())) ?></strong></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary">Zapisz</button></div>
      </form>
    </div>
  </div>
</div>

<!-- SLA: godziny pracy -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-clock-history me-1"></i>SLA — godziny pracy</div>
    <div class="card-body">
      <p class="text-muted small mb-3">W trybie „godziny pracy” cele reakcji i rozwiązania liczą się tylko w godzinach i dniach pracy (zgłoszenie z piątku po godzinach nie przekracza celu w weekend). Wstrzymanie zegara w statusach oczekiwania działa jak dotąd. Święta nie są uwzględniane.</p>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="hours_save">
        <div class="col-md-3"><label class="form-label small mb-0">Tryb liczenia</label>
          <select name="hd_sla_mode" class="form-select form-select-sm">
            <option value="calendar"<?= $work_cfg['mode'] === 'calendar' ? ' selected' : '' ?>>Czas kalendarzowy</option>
            <option value="business"<?= $work_cfg['mode'] === 'business' ? ' selected' : '' ?>>Godziny pracy</option></select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Początek</label><input name="hd_work_start" class="form-control form-control-sm" value="<?= h($work_cfg['start']) ?>" pattern="\d{1,2}:\d{2}"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Koniec</label><input name="hd_work_end" class="form-control form-control-sm" value="<?= h($work_cfg['end']) ?>" pattern="\d{1,2}:\d{2}"></div>
        <div class="col-md-4"><div class="small mb-0">Dni pracy</div>
          <?php foreach ([1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'] as $dn => $dl): ?>
          <label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" name="hd_work_days[]" value="<?= $dn ?>"<?= in_array($dn, $work_cfg['days'], true) ? ' checked' : '' ?>><?= $dl ?></label>
          <?php endforeach; ?></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary">Zapisz</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Skrzynki e-mail → zgłoszenia -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-envelope-arrow-down me-1"></i>Zgłoszenia z e-maila
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Wiadomość przychodząca na podpiętą skrzynkę (z modułu Poczta) staje się zgłoszeniem; zgłaszający dostaje potwierdzenie z linkiem.
        Odpowiedź z numerem w temacie, np. <code>[HD00042]</code>, dopisuje się do wątku. Przetwarzane są tylko wiadomości od chwili podpięcia
        (sprawdzanie co 5 min). Autorespondery i adresy systemowe są pomijane.
      </p>
      <?php foreach ($mail_intakes as $mi): ?>
      <form method="post" class="row g-2 align-items-end mb-2 pb-2 border-bottom">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="mail_save">
        <input type="hidden" name="mailbox_id" value="<?= (int)$mi['mailbox_id'] ?>">
        <div class="col-md-3"><div class="small text-muted">Skrzynka</div><strong><?= h($mi['mailbox']) ?></strong>
          <div class="small text-muted">od <?= h(date('d.m.Y H:i', strtotime($mi['since_at']))) ?><?= $mi['last_run_at'] ? ', ostatnio ' . h(date('H:i', strtotime($mi['last_run_at']))) : '' ?></div></div>
        <div class="col-md-3"><label class="form-label small mb-0">Kategoria</label>
          <select name="category" class="form-select form-select-sm">
            <?php foreach (hd_categories() as $k => $v): ?><option value="<?= h($k) ?>"<?= $mi['category'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Priorytet</label>
          <select name="priority" class="form-select form-select-sm">
            <?php foreach (HD_PRIORITIES as $k => $p): ?><option value="<?= h($k) ?>"<?= $mi['priority'] === $k ? ' selected' : '' ?>><?= h($p['label']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-2">
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="ack_enabled" value="1" id="ack<?= (int)$mi['id'] ?>"<?= $mi['ack_enabled'] ? ' checked' : '' ?>><label class="form-check-label" for="ack<?= (int)$mi['id'] ?>">Potwierdzenie</label></div>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act<?= (int)$mi['id'] ?>"<?= $mi['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="act<?= (int)$mi['id'] ?>">Aktywna</label></div>
        </div>
        <div class="col-md-2 text-end">
          <button class="btn btn-sm btn-primary">Zapisz</button>
          <button class="btn btn-sm btn-outline-danger" name="_op" value="mail_delete" onclick="return confirm('Odpiąć skrzynkę od Helpdesku?')">Odepnij</button>
        </div>
      </form>
      <?php endforeach; ?>
      <?php if ($mail_free): ?>
      <form method="post" class="row g-2 align-items-end mt-1">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="mail_save">
        <input type="hidden" name="ack_enabled" value="1"><input type="hidden" name="is_active" value="1">
        <div class="col-md-3"><label class="form-label small mb-0">Podepnij skrzynkę</label>
          <select name="mailbox_id" class="form-select form-select-sm">
            <?php foreach ($mail_free as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['mailbox']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Kategoria</label>
          <select name="category" class="form-select form-select-sm"><?php foreach (hd_categories() as $k => $v): ?><option value="<?= h($k) ?>"<?= $k === 'it_inne' ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Priorytet</label>
          <select name="priority" class="form-select form-select-sm"><?php foreach (HD_PRIORITIES as $k => $p): ?><option value="<?= h($k) ?>"<?= $k === 'normalny' ? ' selected' : '' ?>><?= h($p['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-success">Podepnij</button></div>
      </form>
      <?php elseif (!$mail_intakes): ?>
      <p class="small text-muted mb-0">Brak skonfigurowanych skrzynek w module Poczta.</p>
      <?php endif; ?>
      <?php if ($mail_intakes): $mail_recent = hdMailRecent(12); ?>
      <hr>
      <div class="d-flex flex-wrap gap-3 align-items-end mb-2">
        <form method="post" class="d-flex gap-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="mail_reply_save">
          <div><label class="form-label small mb-0">Adres odpowiedzi w mailach Helpdesku (Reply-To)</label>
            <input name="hd_mail_reply_to" class="form-control form-control-sm" style="min-width:260px" value="<?= h(org_setting('hd_mail_reply_to')) ?>" placeholder="<?= h(hdMailReplyAddress()) ?>"></div>
          <button class="btn btn-sm btn-outline-secondary">Zapisz</button>
        </form>
        <form method="post"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="mail_run_now">
          <button class="btn btn-sm btn-outline-primary" title="Przetwarza wiadomości, które Poczta już pobrała — nie odpytuje Microsoft 365"><i class="bi bi-arrow-repeat me-1"></i>Przetwórz teraz</button></form>
      </div>
      <p class="small text-muted mb-2">Powiadomienia Helpdesku mają ustawiony Reply-To na ten adres, więc odpowiedź z numerem w temacie wraca do skrzynki i dopisuje się do wątku.</p>
      <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.8rem">
        <thead><tr><th>Kiedy</th><th>Co</th><th>Od</th><th>Temat</th><th>Zgłoszenie / powód</th></tr></thead><tbody>
        <?php foreach ($mail_recent as $mr): ?>
        <tr><td class="text-nowrap"><?= h(substr((string)$mr['at'], 0, 16)) ?></td><td><?= h($mr['what']) ?></td><td><?= h($mr['from']) ?></td><td class="text-truncate" style="max-width:260px"><?= h($mr['subject']) ?></td>
          <td><?= $mr['ticket_id'] ? '<a href="' . h(APP_URL . '/helpdesk/view.php?id=' . $mr['ticket_id']) . '">' . h($mr['number']) . '</a>' : '<span class="text-muted">' . h($mr['note']) . '</span>' ?></td></tr>
        <?php endforeach; if (!$mail_recent): ?><tr><td colspan="5" class="text-muted text-center py-3">Brak przetworzonych wiadomości.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Dodaj operatora -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-person-plus me-1"></i>Dodaj / Zarządzaj operatorami
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Operator Helpdesk to <strong>dodatkowe uprawnienie</strong> nałożone na istniejącą rolę użytkownika.
        Użytkownik zachowuje swoją rolę, a dodatkowo może obsługiwać zgłoszenia IT.
      </p>
      <div class="table-responsive">
        <table class="table table-sm align-middle" style="font-size:.85rem">
          <thead class="table-light">
            <tr><th>Użytkownik</th><th>E-mail</th><th>Rola</th><th>Operator HD</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($all_users as $u_row): ?>
            <tr>
              <td class="fw-semibold"><?= h($u_row['name']) ?></td>
              <td class="text-muted small"><?= h($u_row['email']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= h($u_row['role']) ?></span></td>
              <td>
                <?php $is_op_row = in_array($u_row['id'], array_column($operators, 'id')); ?>
                <?php if ($is_op_row): ?>
                <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Operator</span>
                <?php else: ?>
                <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op"     value="toggle_operator">
                  <input type="hidden" name="user_id" value="<?= $u_row['id'] ?>">
                  <button class="btn btn-sm <?= $is_op_row ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 px-2">
                    <?= $is_op_row ? '<i class="bi bi-person-dash"></i> Cofnij' : '<i class="bi bi-person-check"></i> Nadaj' ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div><!-- /row -->

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
