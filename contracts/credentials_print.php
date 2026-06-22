<?php
/**
 * contracts/credentials_print.php — Wydruk danych logowania (Office 365 / System) dla umowy.
 *
 * Hasła nie są nigdzie przechowywane jawnie, więc „wydruk" oznacza wygenerowanie NOWEGO
 * hasła (reset) i jego wydruk na kartce. Scenariusz: wolontariusz nie odbiera SMS-ów/e-maili.
 * Wymaga podania POWODU i PODSTAWY — zapisywane w dzienniku umowy (audyt).
 *
 * POST: type, id, scope[] (office|system), basis, reason
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin', 'editor');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL); exit; }
csrf_check();

$type   = preg_replace('/[^a-z]/', '', $_POST['type'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$back   = APP_URL . "/contracts/{$type}/view.php?id={$id}&tab=m365";

$allowed_types = ['zlecenie', 'dzielo', 'wolontariat'];
if (!in_array($type, $allowed_types, true) || !$id) {
    flash_set('danger', 'Nieprawidłowy typ umowy lub ID.'); header('Location: ' . APP_URL); exit;
}

$table = table_for_type($type);
$row   = db_one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$row) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: ' . $back); exit; }

// Walidacja powodu i podstawy
$scope  = array_values(array_intersect((array)($_POST['scope'] ?? []), ['office', 'system']));
$basis  = trim($_POST['basis'] ?? '');
$reason = trim($_POST['reason'] ?? '');
if (!$scope) {
    flash_set('danger', 'Zaznacz co najmniej jeden zestaw danych do wydruku (Office / System).');
    header('Location: ' . $back); exit;
}
if ($basis === '' || $reason === '') {
    flash_set('danger', 'Podaj podstawę oraz powód wydruku danych logowania.');
    header('Location: ' . $back); exit;
}

$person_name  = trim($row['imie_nazwisko'] ?? '');
$person_email = trim($row['email'] ?? '');
$numer_umowy  = trim($row['numer_umowy'] ?? '');
$org          = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$me           = current_user() ?: [];
$now          = date('Y-m-d H:i');

$creds  = [];   // [ ['service'=>..., 'login'=>..., 'password'=>..., 'note'=>...] ]
$errors = [];

// ── Office 365 ────────────────────────────────────────────────────────────────
if (in_array('office', $scope, true)) {
    try {
        if (empty($row['m365_konto']) || empty($row['m365_user_id'])) {
            throw new RuntimeException('Brak konta Microsoft 365 powiązanego z tą umową.');
        }
        require_once dirname(__DIR__) . '/includes/m365.php';
        $graph = new M365Graph();
        if (!$graph->is_configured()) throw new RuntimeException('Integracja M365 nie jest skonfigurowana.');

        $password = M365Graph::generate_password();
        $graph->set_password($row['m365_user_id'], $password); // wymusza zmianę przy 1. logowaniu
        $login = $row['m365_login'] ?: $person_email;

        // Zapisz zaszyfrowane hasło w rejestrze IT (audyt)
        require_once dirname(__DIR__) . '/includes/it_helpers.php';
        it_migrate();
        $svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
        if ($svc) {
            it_log_password([
                'service_id'    => (int)$svc['id'],
                'contract_type' => $type,
                'contract_id'   => $id,
                'login'         => $login,
                'plain'         => $password,
                'sent_to_email' => null,
                'notes'         => 'Wydruk danych logowania — podstawa: ' . $basis,
                'issued_by'     => $me['id'] ?? null,
            ]);
        }
        $creds[] = [
            'service'  => 'Microsoft 365 (Office)',
            'login'    => $login,
            'password' => $password,
            'note'     => 'Hasło tymczasowe — przy pierwszym logowaniu system poprosi o ustawienie własnego.',
        ];
    } catch (\Throwable $e) {
        $errors[] = 'Office 365: ' . $e->getMessage();
    }
}

// ── System (portal / konto lokalne) ────────────────────────────────────────────
if (in_array('system', $scope, true)) {
    try {
        if ($person_email === '' || !filter_var($person_email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Brak adresu e-mail — konto systemowe loguje się adresem e-mail.');
        }
        $u = db_one("SELECT id, email FROM users WHERE LOWER(email)=LOWER(?)", [$person_email]);
        if (!$u) throw new RuntimeException('Brak konta w systemie. Zapisz umowę ponownie, aby utworzyć konto.');

        $password = substr(str_replace(['+','/','-','='], '', base64_encode(random_bytes(18))), 0, 12);
        db()->prepare("UPDATE users SET password=?, login_code=NULL WHERE id=?")
           ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$u['id']]);

        $creds[] = [
            'service'  => 'System (portal)',
            'login'    => $u['email'],
            'password' => $password,
            'note'     => 'Logowanie: ' . APP_URL . '/auth/login.php — hasło można zmienić w „Mój panel".',
        ];
    } catch (\Throwable $e) {
        $errors[] = 'System: ' . $e->getMessage();
    }
}

// Audyt w dzienniku umowy
$scope_labels = ['office' => 'Office 365', 'system' => 'System'];
$scope_txt    = implode(', ', array_map(fn($s) => $scope_labels[$s] ?? $s, $scope));
log_contract_action($type, $id, (int)($me['id'] ?? 0), 'note',
    'Wydruk danych logowania (' . $scope_txt . ') — podstawa: ' . $basis . '; powód: ' . $reason
    . '. Hasła zostały zresetowane.');

// Gdy nic nie wygenerowano — wróć z błędem
if (!$creds) {
    flash_set('danger', 'Nie udało się przygotować danych logowania. ' . implode(' ', $errors));
    header('Location: ' . $back); exit;
}

// ── Wydruk ──────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Dane logowania — <?= h($person_name ?: $numer_umowy) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 28px 32px; font-size: 13px; }
  h1 { font-size: 18px; margin: 0 0 2px; }
  .muted { color: #666; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; margin-bottom: 18px; }
  .meta td { padding: 2px 10px 2px 0; vertical-align: top; }
  .meta th { text-align: left; padding: 2px 14px 2px 0; color: #555; font-weight: 600; white-space: nowrap; }
  table.creds { width: 100%; border-collapse: collapse; margin: 16px 0; }
  table.creds th, table.creds td { border: 1px solid #999; padding: 8px 10px; text-align: left; }
  table.creds th { background: #f0f0f0; }
  .mono { font-family: 'Consolas', 'Courier New', monospace; font-weight: 700; letter-spacing: .03em; }
  .pw { font-size: 15px; }
  .box { border: 1px solid #ccc; border-left: 4px solid #b45309; background: #fff8e1; padding: 10px 14px; margin: 14px 0; border-radius: 4px; }
  .reason { border: 1px solid #ccc; border-radius: 4px; padding: 10px 14px; margin: 14px 0; }
  .sign { display: flex; gap: 60px; margin-top: 48px; }
  .sign div { flex: 1; border-top: 1px solid #1a1a1a; padding-top: 6px; text-align: center; color: #555; font-size: 12px; }
  .noprint { margin: 18px 0; }
  .page-break { page-break-before: always; }
  .decl p { line-height: 1.6; margin: 10px 0; }
  .decl .lead { font-size: 14px; }
  .fill { display: inline-block; min-width: 220px; border-bottom: 1px solid #1a1a1a; }
  .sign2 { display: flex; gap: 60px; margin-top: 70px; align-items: flex-end; }
  .sign2 .col { flex: 1; }
  .sign2 .line { border-top: 1px solid #1a1a1a; padding-top: 6px; text-align: center; color: #555; font-size: 12px; }
  .stamp { width: 210px; height: 130px; border: 1px dashed #999; border-radius: 6px;
           display: flex; align-items: center; justify-content: center; color: #aaa; font-size: 12px; text-align: center; }
  @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>
  <div class="noprint">
    <button onclick="window.print()" style="padding:8px 16px;font-size:14px;cursor:pointer">🖨 Drukuj</button>
    <a href="<?= h($back) ?>" style="margin-left:10px">← Powrót do umowy</a>
  </div>

  <div class="head">
    <div>
      <h1>Dane logowania</h1>
      <div class="muted"><?= h($org) ?></div>
    </div>
    <div class="muted" style="text-align:right">Wygenerowano:<br><?= h($now) ?></div>
  </div>

  <table class="meta">
    <tr><th>Osoba</th><td><?= h($person_name ?: '—') ?></td>
        <th>Numer umowy</th><td><?= h($numer_umowy ?: '—') ?></td></tr>
    <tr><th>Wydał(a)</th><td><?= h($me['name'] ?? $me['email'] ?? '—') ?></td>
        <th>E-mail</th><td><?= h($person_email ?: '—') ?></td></tr>
  </table>

  <table class="creds">
    <thead><tr><th style="width:170px">Usługa</th><th>Login</th><th>Hasło</th></tr></thead>
    <tbody>
      <?php foreach ($creds as $c): ?>
      <tr>
        <td>
          <strong><?= h($c['service']) ?></strong>
          <?php if (!empty($c['note'])): ?><div class="muted" style="font-weight:400;font-size:11px;margin-top:3px"><?= h($c['note']) ?></div><?php endif; ?>
        </td>
        <td class="mono"><?= h($c['login']) ?></td>
        <td class="mono pw"><?= h($c['password']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="reason">
    <div><strong>Podstawa wydruku:</strong> <?= h($basis) ?></div>
    <div style="margin-top:4px"><strong>Powód / uzasadnienie:</strong> <?= nl2br(h($reason)) ?></div>
  </div>

  <?php if ($errors): ?>
  <div class="box"><strong>Uwaga — części danych nie udało się przygotować:</strong><br><?= implode('<br>', array_map('h', $errors)) ?></div>
  <?php endif; ?>

  <div class="box">
    Dokument zawiera dane wrażliwe. Hasła są <strong>tymczasowe</strong> i zostały właśnie zresetowane —
    poprzednie hasła przestały działać. Przekaż dokument osobiście i zniszcz po wykorzystaniu.
  </div>

  <div class="sign">
    <div>Wydał(a) — podpis</div>
    <div>Odebrał(a) — podpis i data</div>
  </div>

  <!-- ── Strona 2: oświadczenie o wydaniu danych dostępowych ──────────────── -->
  <?php $svc_list = implode(', ', array_map(fn($c) => $c['service'], $creds)); ?>
  <div class="page-break"></div>

  <div class="head">
    <div>
      <h1>Oświadczenie o wydaniu danych dostępowych</h1>
      <div class="muted"><?= h($org) ?></div>
    </div>
    <div class="muted" style="text-align:right">Data:<br><?= h($now) ?></div>
  </div>

  <div class="decl">
    <p class="lead">
      Niniejszym potwierdza się, że dla osoby <strong><?= h($person_name ?: '—') ?></strong>
      <?= $numer_umowy !== '' ? '(umowa nr <strong>' . h($numer_umowy) . '</strong>)' : '' ?>
      zostały <strong>wydane (zresetowane) dane dostępowe</strong> do: <strong><?= h($svc_list) ?></strong>.
    </p>
    <p>
      Dane wydano w formie wydruku przekazanego osobiście, ponieważ nie było możliwe
      przekazanie ich kanałem standardowym (SMS / e-mail).
    </p>
    <p><strong>Podstawa wydania:</strong> <?= h($basis) ?></p>
    <p><strong>Powód / uzasadnienie:</strong> <?= nl2br(h($reason)) ?></p>
    <p>
      Dotychczasowe hasła zostały unieważnione. Hasła wydane są tymczasowe — przy pierwszym
      logowaniu wymagana jest zmiana hasła. Odbiorca zobowiązuje się do zachowania danych
      w poufności i nieudostępniania ich osobom trzecim.
    </p>
    <p style="margin-top:18px">
      Dane wydał(a): <strong><?= h($me['name'] ?? $me['email'] ?? '—') ?></strong>,
      dnia <span class="fill">&nbsp;<?= h(date('Y-m-d')) ?>&nbsp;</span>
    </p>
  </div>

  <div class="sign2">
    <div class="col">
      <div class="stamp">pieczątka organizacji</div>
    </div>
    <div class="col">
      <div class="line">Podpis osoby wydającej</div>
    </div>
    <div class="col">
      <div class="line">Podpis odbierającego i data</div>
    </div>
  </div>

  <script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
</body>
</html>
