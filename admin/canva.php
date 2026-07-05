<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/canva.php';

require_role('admin');
cpc_migrate();
$PAGE_TITLE = 'Zarządzanie Canva Pro';

// ── Wyłącznik funkcjonalności Canva (globalny, dla całego systemu) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_toggle_canva_enabled'])) {
    csrf_check();
    $on = isset($_POST['enabled']) ? '1' : '0';
    org_setting_set('canva_enabled', $on);
    flash_set('success', $on === '1' ? 'Funkcjonalności Canva włączone.' : 'Funkcjonalności Canva wyłączone w całym systemie.');
    header('Location: canva.php'); exit;
}
$canva_enabled = canva_module_enabled();

// ── Oznacz jako zaproszony (zbiorczo) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_mark_invited'])) {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['contract_ids'] ?? []));
    if (empty($ids)) {
        $ids = array_column(db_all(
            "SELECT id FROM umowy_wolontariat WHERE canva_access=1 AND canva_invited_at IS NULL"
        ), 'id');
    }
    $now = date('Y-m-d H:i:s');
    $org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $count = 0;
    foreach ($ids as $cid) {
        $r = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$cid]);
        if (!$r) continue;
        db_update('umowy_wolontariat', ['canva_invited_at' => $now], $cid);
        log_contract_action('wolontariat', $cid, (int)current_user()['id'], 'note', 'Admin: Canva — oznaczono jako zaproszony');
        // Mail do wolontariusza
        $email_vol = trim($r['email'] ?? '');
        if ($email_vol && filter_var($email_vol, FILTER_VALIDATE_EMAIL)) {
            $name = h($r['imie_nazwisko'] ?? $email_vol);
            $m365 = trim($r['m365_login'] ?? '');
            $lm   = $m365
                ? "kontem Microsoft 365 (<strong>{$m365}</strong>)"
                : "adresem e-mail (<strong>{$email_vol}</strong>)";
            $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🎨 Twój dostęp do Canva jest aktywny — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>Zaproszenie do przestrzeni <strong>Canva Pro</strong> organizacji <strong>{$org}</strong> zostało wysłane.
  Sprawdź skrzynkę e-mail i kliknij <strong>„Dołącz do zespołu"</strong> w wiadomości od Canva.</p>
  <div style="background:#fdf4ff;border-left:4px solid #a855f7;border-radius:4px;padding:14px;margin:14px 0;font-size:.9em">
    Loguj się do Canva przez {$lm}.<br>
    Wybierz opcję <em>„Continue with Microsoft"</em> na stronie logowania Canva.
  </div>
  <div style="text-align:center;margin:20px 0">
    <a href="https://www.canva.com" style="background:#7c3aed;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600">Otwórz Canva →</a>
  </div>
</div></body></html>
HTML;
            try { approval_send_email($email_vol, "Twój dostęp do Canva jest gotowy — {$org}", $body); } catch (\Throwable $e) {}
        }
        $count++;
    }
    flash_set('success', "Oznaczono {$count} zaproszeń jako wysłane. E-maile do wolontariuszy wysłane.");
    header('Location: canva.php'); exit;
}

// ── Ręczne dane konta Canva (login/hasło) — wpisuje admin, widzi wolontariusz ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_canva_acc'])) {
    csrf_check();
    $cid = (int)($_POST['cid'] ?? 0);
    if ($cid && db_one("SELECT id FROM umowy_wolontariat WHERE id=?", [$cid])) {
        $login = trim($_POST['canva_login'] ?? '');
        $haslo = (string)($_POST['canva_haslo'] ?? '');
        db()->prepare("UPDATE umowy_wolontariat SET canva_login=?, canva_haslo=?, canva_konto_zrodlo='admin', canva_konto_at=datetime('now','localtime') WHERE id=?")
            ->execute([$login, $haslo, $cid]);
        log_contract_action('wolontariat', $cid, (int)current_user()['id'], 'note', 'Admin: Canva — zapisano dane konta (login/hasło)');
        flash_set('success', 'Zapisano dane konta Canva — wolontariusz zobaczy je w panelu.');
    }
    header('Location: canva.php'); exit;
}

// ── Dostęp Canva dla użytkownika BEZ umowy (poziom konta) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_grant_canva_user'])) {
    csrf_check();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid && db_one("SELECT id FROM users WHERE id=?", [$uid])) {
        canva_user_grant($uid, (int)current_user()['id']);
        flash_set('success', 'Włączono dostęp do Canva dla wybranego użytkownika.');
    } else { flash_set('error', 'Wybierz użytkownika.'); }
    header('Location: canva.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_revoke_canva_user'])) {
    csrf_check();
    canva_user_revoke((int)($_POST['user_id'] ?? 0));
    flash_set('success', 'Wyłączono dostęp do Canva.');
    header('Location: canva.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_canva_apikey'])) {
    csrf_check();
    org_setting_set('canva_button_api_key', trim($_POST['canva_button_api_key'] ?? ''));
    flash_set('success', 'Zapisano klucz API kreatora Canva.');
    header('Location: canva.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_canva_user_acc'])) {
    csrf_check();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid && db_one("SELECT id FROM users WHERE id=?", [$uid])) {
        canva_user_set_account($uid, trim($_POST['canva_login'] ?? ''), (string)($_POST['canva_haslo'] ?? ''), 'admin');
        flash_set('success', 'Zapisano dane konta Canva — użytkownik zobaczy je w panelu.');
    }
    header('Location: canva.php'); exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$canva_user_grants = canva_users_with_access();
// Użytkownicy do nadania dostępu (bez aktywnego dostępu na poziomie konta)
$_granted_ids = array_map(fn($r) => (int)$r['user_id'], array_filter($canva_user_grants, fn($r) => (int)$r['access'] === 1));
$canva_users_pick = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name");
$canva_users_pick = array_filter($canva_users_pick, fn($u) => !in_array((int)$u['id'], $_granted_ids, true));

$pending = db_all(
    "SELECT id, imie_nazwisko, email, m365_login, m365_user_id, numer_umowy, canva_invited_at, canva_email_sent_at,
            canva_login, canva_haslo, canva_konto_zrodlo
     FROM umowy_wolontariat
     WHERE canva_access=1
     ORDER BY canva_invited_at IS NOT NULL, imie_nazwisko"
);

foreach ($pending as &$p) {
    $p['_has_m365'] = !empty($p['m365_login']) || !empty($p['m365_user_id']);
    $p['_invited']  = !empty($p['canva_invited_at']);
}
unset($p);

$cnt_waiting  = count(array_filter($pending, fn($r) => !$r['_invited']));
$cnt_done     = count(array_filter($pending, fn($r) =>  $r['_invited']));
$cnt_no_m365  = count(array_filter($pending, fn($r) => !$r['_has_m365']));

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0" style="color:#7c3aed">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" class="me-2"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2z"/></svg>
      Zarządzanie Canva Pro
    </h4>
    <div class="text-muted small mt-1">
      Lista wolontariuszy z dostępem do Canva. Zaproszenia wysyłane ręcznie przez panel Canva.
    </div>
  </div>
  <a href="https://www.canva.com/brand/invite" target="_blank"
     class="btn btn-sm" style="background:#7c3aed;border-color:#7c3aed;color:#fff">
    <i class="bi bi-box-arrow-up-right me-1"></i>Panel zaproszeń Canva
  </a>
</div>

<?= flash_html() ?>

<!-- Wyłącznik funkcjonalności Canva -->
<div class="card shadow-sm mb-3 <?= $canva_enabled ? '' : 'border-danger' ?>">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <div class="fw-semibold"><i class="bi bi-power me-2 <?= $canva_enabled ? 'text-success' : 'text-danger' ?>"></i>Funkcjonalności Canva w systemie</div>
      <div class="text-muted" style="font-size:.82rem">
        <?php if ($canva_enabled): ?>
        Włączone — wolontariusze widzą w panelu prośbę o dostęp / kartę Canva, dostępny jest też Canva Creator.
        <?php else: ?>
        Wyłączone — moduł Canva jest ukryty w panelu wolontariusza i w kreatorze projektów. Nowe wnioski nie są przyjmowane.
        <?php endif; ?>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_toggle_canva_enabled" value="1">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="canvaEnabledSwitch" name="enabled"
               onchange="this.form.submit()" <?= $canva_enabled ? 'checked' : '' ?>>
        <label class="form-check-label" for="canvaEnabledSwitch"><?= $canva_enabled ? 'Włączone' : 'Wyłączone' ?></label>
      </div>
    </form>
  </div>
</div>

<?php if (!$canva_enabled): ?>
<div class="alert alert-warning d-flex gap-2 mb-3" style="font-size:.85rem">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <div>Moduł Canva jest wyłączony — poniższe ustawienia i lista dostępów pozostają widoczne tylko dla administratora, ale wolontariusze nie zobaczą Canvy w swoim panelu.</div>
</div>
<?php endif; ?>

<!-- Canva Creator (Design Button) -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-palette-fill me-2" style="color:#7c3aed"></i>Canva Creator — tworzenie projektów</div>
  <div class="card-body">
    <p class="text-muted mb-2" style="font-size:.84rem">Pozwala adminom i wolontariuszom (z dostępem) tworzyć projekty w Canva bez wychodzenia z systemu (Canva „Design Button"). Wymaga klucza API z <a href="https://www.canva.com/developers/" target="_blank" rel="noopener">Canva Developers</a> → Design Button.</p>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end" style="max-width:640px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_save_canva_apikey" value="1">
      <div class="flex-grow-1">
        <label class="form-label mb-1" style="font-size:.74rem">Klucz API „Canva Button"</label>
        <input type="text" name="canva_button_api_key" class="form-control form-control-sm font-monospace"
               value="<?= h(canva_button_api_key()) ?>" placeholder="np. AB1cd...">
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
      <a href="<?= APP_URL ?>/canva/creator.php" class="btn btn-sm <?= ($canva_enabled && canva_creator_enabled())?'btn-outline-primary':'btn-outline-secondary disabled' ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Otwórz kreator</a>
    </form>
    <?php if ($canva_enabled && canva_creator_enabled()): ?><div class="text-success mt-2" style="font-size:.78rem"><i class="bi bi-check-circle me-1"></i>Kreator aktywny — dostępny w panelu wolontariusza z dostępem do Canva.</div><?php endif; ?>
  </div>
</div>

<!-- Statystyki -->
<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3 px-4 d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:10px;background:#fdf4ff;display:flex;align-items:center;justify-content:center;color:#7c3aed;font-size:1.2rem">⏳</div>
        <div>
          <div style="font-size:1.6rem;font-weight:800;color:#7c3aed"><?= $cnt_waiting ?></div>
          <div style="font-size:.75rem;color:#64748B;font-weight:600;text-transform:uppercase">Oczekuje na zaproszenie</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3 px-4 d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:1.2rem">✓</div>
        <div>
          <div style="font-size:1.6rem;font-weight:800;color:#16a34a"><?= $cnt_done ?></div>
          <div style="font-size:.75rem;color:#64748B;font-weight:600;text-transform:uppercase">Zaproszonych</div>
        </div>
      </div>
    </div>
  </div>
  <?php if ($cnt_no_m365): ?>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3 px-4 d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:1.2rem">⚠</div>
        <div>
          <div style="font-size:1.6rem;font-weight:800;color:#dc2626"><?= $cnt_no_m365 ?></div>
          <div style="font-size:.75rem;color:#64748B;font-weight:600;text-transform:uppercase">Brak konta M365</div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Jak to działa -->
<div class="alert alert-info d-flex gap-2 mb-3" style="font-size:.85rem">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Jak to działa:</strong>
    Canva nie obsługuje logowania jednokrotnego (SAML) — dostęp nadawany jest wyłącznie na podstawie
    <strong>wniosku</strong>. Wolontariusz prosi o dostęp w panelu, prośba trafia do
    <a href="<?= APP_URL ?>/contracts/approvals/index.php">Zatwierdzeń</a>, a po akceptacji administrator
    zaprasza konto ręcznie w panelu Canva (plan Pro nie ma API do automatycznych zaproszeń) i oznacza je
    poniżej jako zaproszone. Wolontariusz loguje się na canva.com przez <em>„Continue with Microsoft"</em>.
  </div>
</div>

<?php if ($cnt_waiting > 0): ?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_mark_invited" value="1">
    <button type="submit" class="btn btn-success"
            onclick="return confirm('Oznaczyć wszystkie <?= $cnt_waiting ?> oczekujące jako zaproszone i wysłać e-maile?')">
      <i class="bi bi-check-all me-1"></i>Oznacz wszystkie oczekujące jako zaproszone (<?= $cnt_waiting ?>)
    </button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($pending)): ?>
<div class="alert alert-secondary">
  <i class="bi bi-info-circle me-1"></i>
  Brak wolontariuszy z zaznaczonym dostępem do Canva.
  Dostęp nadajesz przy <a href="<?= APP_URL ?>/contracts/wolontariat/add.php">dodawaniu umowy</a> lub w widoku umowy (zakładka M365).
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr>
          <th>Wolontariusz</th>
          <th>E-mail</th>
          <th>Login M365</th>
          <th>Numer umowy</th>
          <th>Konto Canva (login/hasło)</th>
          <th>Status Canva</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
      <tr class="<?= !$p['_has_m365'] ? 'table-warning' : '' ?>">
        <td>
          <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $p['id'] ?>#tab-m365-anchor"
             class="fw-semibold text-decoration-none">
            <?= h($p['imie_nazwisko'] ?: '—') ?>
          </a>
        </td>
        <td class="text-muted"><?= h($p['email'] ?: '—') ?></td>
        <td>
          <?php if ($p['m365_login']): ?>
          <span class="font-monospace small text-primary"><?= h($p['m365_login']) ?></span>
          <?php elseif ($p['_has_m365']): ?>
          <span class="text-muted small">ID tylko</span>
          <?php else: ?>
          <span class="badge bg-danger" style="font-size:.7rem">Brak M365</span>
          <?php endif; ?>
        </td>
        <td class="font-monospace small"><?= h($p['numer_umowy'] ?: '—') ?></td>
        <td style="min-width:230px">
          <form method="post" class="d-flex flex-column gap-1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_save_canva_acc" value="1">
            <input type="hidden" name="cid" value="<?= (int)$p['id'] ?>">
            <input type="text" name="canva_login" value="<?= h($p['canva_login'] ?? '') ?>" placeholder="login / e-mail" class="form-control form-control-sm" style="font-size:.74rem">
            <div class="d-flex gap-1">
              <input type="text" name="canva_haslo" value="<?= h($p['canva_haslo'] ?? '') ?>" placeholder="hasło (opcjonalne)" class="form-control form-control-sm" style="font-size:.74rem">
              <button class="btn btn-sm btn-outline-primary" style="white-space:nowrap" title="Zapisz dane konta"><i class="bi bi-save"></i></button>
            </div>
            <?php if (($p['canva_konto_zrodlo'] ?? '')==='wolontariusz'): ?>
            <span class="text-muted" style="font-size:.66rem"><i class="bi bi-person-badge me-1"></i>login podany przez wolontariusza</span>
            <?php elseif (($p['canva_konto_zrodlo'] ?? '')==='admin' && !empty($p['canva_login'])): ?>
            <span class="text-success" style="font-size:.66rem"><i class="bi bi-eye me-1"></i>widoczne dla wolontariusza w panelu</span>
            <?php endif; ?>
          </form>
        </td>
        <td>
          <?php if ($p['_invited']): ?>
          <span class="badge bg-success" style="font-size:.72rem">
            <i class="bi bi-check me-1"></i>Zaproszony <?= date_pl($p['canva_invited_at']) ?>
          </span>
          <?php else: ?>
          <span class="badge bg-warning text-dark" style="font-size:.72rem">
            <i class="bi bi-hourglass-split me-1"></i>Oczekuje
          </span>
          <?php if ($p['canva_email_sent_at']): ?>
          <div class="text-muted" style="font-size:.7rem">Powiad. admina: <?= date_pl($p['canva_email_sent_at']) ?></div>
          <?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <?php if (!$p['_invited']): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_mark_invited" value="1">
            <input type="hidden" name="contract_ids[]" value="<?= intval($p['id']) ?>">
            <button type="submit" class="btn btn-sm btn-success" style="font-size:.74rem;padding:.2rem .6rem">
              <i class="bi bi-check-lg"></i> Oznacz
            </button>
          </form>
          <?php else: ?>
          <span class="text-muted small">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Dostęp Canva dla wolontariuszy BEZ umowy (poziom konta) ──────────────── -->
<div class="card shadow-sm mt-4">
  <div class="card-header fw-semibold"><i class="bi bi-person-gear me-2 text-primary"></i>Dostęp do Canva bez umowy</div>
  <div class="card-body">
    <p class="text-muted" style="font-size:.84rem">Włącz dostęp do Canva dla dowolnego konta użytkownika — także osoby <strong>bez umowy wolontariackiej</strong>. Użytkownik zobaczy w panelu przycisk logowania i dane konta (jeśli je wpiszesz).</p>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end mb-3" style="max-width:560px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_grant_canva_user" value="1">
      <div class="flex-grow-1">
        <label class="form-label mb-1" style="font-size:.74rem">Użytkownik</label>
        <select name="user_id" class="form-select form-select-sm" required>
          <option value="">— wybierz konto —</option>
          <?php foreach ($canva_users_pick as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?><?= $u['email'] ? ' · '.h($u['email']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Włącz dostęp</button>
    </form>

    <?php $active_grants = array_filter($canva_user_grants, fn($g) => (int)$g['access'] === 1); ?>
    <?php if ($active_grants): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light"><tr><th>Użytkownik</th><th>E-mail</th><th>Konto Canva (login/hasło)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($active_grants as $g): ?>
        <tr>
          <td class="fw-semibold"><?= h($g['user_name']) ?> <span class="badge bg-light text-muted border" style="font-size:.6rem"><?= h($g['user_role']) ?></span></td>
          <td class="text-muted"><?= h($g['user_email'] ?: '—') ?></td>
          <td style="min-width:230px">
            <form method="post" class="d-flex flex-column gap-1">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_save_canva_user_acc" value="1">
              <input type="hidden" name="user_id" value="<?= (int)$g['user_id'] ?>">
              <input type="text" name="canva_login" value="<?= h($g['login'] ?? '') ?>" placeholder="login / e-mail" class="form-control form-control-sm" style="font-size:.74rem">
              <div class="d-flex gap-1">
                <input type="text" name="canva_haslo" value="<?= h($g['haslo'] ?? '') ?>" placeholder="hasło (opcjonalne)" class="form-control form-control-sm" style="font-size:.74rem">
                <button class="btn btn-sm btn-outline-primary" title="Zapisz dane konta"><i class="bi bi-save"></i></button>
              </div>
              <?php if (($g['konto_zrodlo'] ?? '')==='wolontariusz'): ?>
              <span class="text-muted" style="font-size:.66rem"><i class="bi bi-person-badge me-1"></i>login podany przez użytkownika</span>
              <?php elseif (($g['konto_zrodlo'] ?? '')==='admin' && !empty($g['login'])): ?>
              <span class="text-success" style="font-size:.66rem"><i class="bi bi-eye me-1"></i>widoczne dla użytkownika w panelu</span>
              <?php endif; ?>
            </form>
          </td>
          <td class="text-end">
            <form method="post" onsubmit="return confirm('Wyłączyć dostęp do Canva dla tego użytkownika?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_revoke_canva_user" value="1">
              <input type="hidden" name="user_id" value="<?= (int)$g['user_id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Wyłącz dostęp"><i class="bi bi-x-lg"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-muted" style="font-size:.82rem">Brak dostępów przyznanych poza umowami.</div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
