<?php
/**
 * auth/report_login_issue.php — Zgłoszenie problemu z logowaniem (mikropanel).
 *
 * Dostępny z ekranu logowania, BEZ logowania. Aby ograniczyć nadużycia, przed
 * przyjęciem zgłoszenia weryfikuje tożsamość, prosząc o LOSOWO wybrane dane
 * z kartoteki (umowa wolontariatu / zlecenia / dzieło / praca).
 *
 * Kroki (state machine via session):
 *   1. Identyfikacja: numer umowy + imię i nazwisko + e-mail + opis problemu
 *   2. Weryfikacja: 1–2 losowe pola z kartoteki (PESEL/telefon/data ur./dokument/…)
 * Po pozytywnej weryfikacji powstaje zgłoszenie helpdesk z prefiksem LOG.
 *
 * Strona STANDALONE — nie includuje header.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
auth_start();

const LI_TABLES = [
    'umowy_wolontariat' => 'wolontariat',
    'umowy_zlecenie'    => 'zlecenie',
    'umowy_dzielo'      => 'dzieło',
    'umowy_praca'       => 'praca',
];
const LI_ERR_VERIFY = 'Nie udało się zweryfikować danych. Sprawdź wprowadzone informacje i spróbuj ponownie.';
const LI_ERR_RATE   = 'Zbyt wiele prób. Spróbuj ponownie za godzinę.';

// ── Rate limiting (max 8 prób / IP / godzina) — współdzieli login_attempts ─────
function _li_rate_ok(): bool {
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL,
            ip TEXT NOT NULL DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } catch (\Throwable $e) {}
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $since = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at>?");
    $stmt->execute(['LOGISSUE:' . $ip, $since]);
    return (int)($stmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0) < 8;
}
function _li_rate_record(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try { db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?,?)")->execute(['LOGISSUE:' . $ip, $ip]); }
    catch (\Throwable $e) {}
}

// ── Pomocnicze ─────────────────────────────────────────────────────────────────
function _li_digits($s): string { return preg_replace('/\D/', '', (string)$s); }
function _li_date($s): string { $t = strtotime((string)$s); return $t ? date('Y-m-d', $t) : ''; }

/** Metadane pytania weryfikacyjnego (etykieta + podpowiedź). */
function _li_meta(string $key): array {
    return [
        'pesel3'   => ['Ostatnie 3 cyfry numeru PESEL', '3 cyfry', 'numeric'],
        'telefon'  => ['Numer telefonu podany w umowie', 'np. 600100200', 'tel'],
        'data_ur'  => ['Data urodzenia', 'RRRR-MM-DD', 'date'],
        'dok'      => ['Numer dokumentu tożsamości', 'seria i numer', 'text'],
        'data_zaw' => ['Data zawarcia umowy', 'RRRR-MM-DD', 'date'],
        'konto4'   => ['Ostatnie 4 cyfry numeru rachunku', '4 cyfry', 'numeric'],
    ][$key] ?? [$key, '', 'text'];
}

/** Lista pytań możliwych do zadania dla danego rekordu (tylko niepuste pola). */
function _li_available(array $row): array {
    $a = [];
    if (!empty($row['pesel']))            $a[] = 'pesel3';
    if (!empty($row['telefon']))          $a[] = 'telefon';
    if (!empty($row['data_urodzenia']))   $a[] = 'data_ur';
    if (!empty($row['seria_nr_dowodu']) || !empty($row['id_document_number'])) $a[] = 'dok';
    if (!empty($row['data_zawarcia']))    $a[] = 'data_zaw';
    if (!empty($row['rachunek_bankowy'])) $a[] = 'konto4';
    return $a;
}

/** Weryfikuje pojedynczą odpowiedź względem rekordu kartoteki. */
function _li_match(string $key, string $input, array $row): bool {
    $input = trim($input);
    if ($input === '') return false;
    switch ($key) {
        case 'pesel3':
            $p = _li_digits($row['pesel'] ?? ''); $i = _li_digits($input);
            return strlen($p) >= 3 && strlen($i) >= 3 && substr($p, -3) === substr($i, -3);
        case 'telefon':
            $a = _li_digits($row['telefon'] ?? ''); $b = _li_digits($input);
            return strlen($a) >= 9 && strlen($b) >= 9 && substr($a, -9) === substr($b, -9);
        case 'data_ur':
            return _li_date($input) !== '' && _li_date($input) === _li_date($row['data_urodzenia'] ?? '');
        case 'dok':
            $d1 = trim((string)($row['seria_nr_dowodu'] ?? '')); $d2 = trim((string)($row['id_document_number'] ?? ''));
            return ($d1 !== '' && strcasecmp($d1, $input) === 0) || ($d2 !== '' && strcasecmp($d2, $input) === 0);
        case 'data_zaw':
            return _li_date($input) !== '' && _li_date($input) === _li_date($row['data_zawarcia'] ?? '');
        case 'konto4':
            $a = _li_digits($row['rachunek_bankowy'] ?? ''); $b = _li_digits($input);
            return strlen($a) >= 4 && strlen($b) >= 4 && substr($a, -4) === substr($b, -4);
    }
    return false;
}

/** Znajduje rekord kartoteki po imieniu i nazwisku (opcjonalnie zawężając e-mailem). */
function _li_find(string $name, string $email): ?array {
    $pdo = db();
    $nn = mb_strtolower(trim($name));
    if ($nn === '') return null;
    $em = mb_strtolower(trim($email));
    foreach (array_keys(LI_TABLES) as $tbl) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$tbl} WHERE imie_nazwisko LIKE ? LIMIT 20");
            $stmt->execute([$name]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (mb_strtolower(trim((string)($r['imie_nazwisko'] ?? ''))) !== $nn) continue;
                // jeśli rekord ma e-mail i podano e-mail — musi się zgadzać (zawęża duplikaty imion)
                if (!empty($r['email']) && $em !== '' && mb_strtolower(trim((string)$r['email'])) !== $em) continue;
                return ['table' => $tbl, 'row' => $r];
            }
        } catch (\Throwable $e) {}
    }
    return null;
}

// ── Stan ────────────────────────────────────────────────────────────────────────
$step    = (int)($_SESSION['li_step'] ?? 1);
$fails   = (int)($_SESSION['li_fails'] ?? 0);
$error   = '';
$info    = '';
$done    = null;   // dane zgłoszenia po sukcesie
$org     = defined('ORG_NAME') ? ORG_NAME : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'restart') {
        foreach (['li_step','li_fails','li_found','li_table','li_id','li_keys','li_email','li_name','li_numer','li_opis'] as $k) unset($_SESSION[$k]);
        header('Location: ' . APP_URL . '/auth/report_login_issue.php'); exit;
    }

    // ── KROK 1: identyfikacja ────────────────────────────────────────────────
    if ($action === 'identify' && $step === 1) {
        if (!_li_rate_ok()) { $error = LI_ERR_RATE; }
        else {
            _li_rate_record();
            $email = trim($_POST['email'] ?? '');
            $name  = trim($_POST['imie_nazwisko'] ?? '');
            $opis  = trim($_POST['opis'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Podaj poprawny adres e-mail.';
            elseif ($name === '')                           $error = 'Podaj imię i nazwisko.';
            elseif (mb_strlen($opis) < 5)                   $error = 'Opisz krótko, na czym polega problem z logowaniem.';
            else {
                $rec   = _li_find($name, $email);
                $found = $rec !== null;
                $keys  = $found ? _li_available($rec['row']) : [];
                shuffle($keys);
                $keys  = array_slice($keys, 0, 2);
                if ($found && !$keys) $keys = ['_none']; // brak danych do challenge — sama identyfikacja wystarczy
                if (!$found)          $keys = ['pesel3']; // neutralny ekran kroku 2 (i tak nie przejdzie)

                $_SESSION['li_step']  = 2;
                $_SESSION['li_fails'] = 0;
                $_SESSION['li_found'] = $found;
                $_SESSION['li_table'] = $found ? $rec['table'] : '';
                $_SESSION['li_id']    = $found ? (int)$rec['row']['id'] : 0;
                $_SESSION['li_keys']  = $keys;
                $_SESSION['li_email'] = $email;
                $_SESSION['li_name']  = $found ? (string)($rec['row']['imie_nazwisko'] ?? $name) : $name;
                $_SESSION['li_numer'] = $found ? (string)($rec['row']['numer_umowy'] ?? '') : '';
                $_SESSION['li_opis']  = $opis;
                $step = 2;
                $info = 'Potwierdź tożsamość, podając poniższe dane z Twojej umowy.';
            }
        }
    }

    // ── KROK 2: weryfikacja losowych danych ───────────────────────────────────
    elseif ($action === 'verify' && $step === 2) {
        $keys  = (array)($_SESSION['li_keys'] ?? []);
        $found = !empty($_SESSION['li_found']);
        $tbl   = (string)($_SESSION['li_table'] ?? '');
        $ok    = false;

        if ($found && isset(LI_TABLES[$tbl])) {
            $row = db_one("SELECT * FROM {$tbl} WHERE id = ?", [(int)($_SESSION['li_id'] ?? 0)]);
            if ($row) {
                $ok = true;
                foreach ($keys as $k) {
                    if ($k === '_none') continue;
                    if (!_li_match($k, (string)($_POST['ans'][$k] ?? ''), $row)) { $ok = false; break; }
                }
            }
        }

        if ($ok) {
            // ── Utwórz zgłoszenie helpdesk z prefiksem LOG ────────────────────
            $email = (string)($_SESSION['li_email'] ?? '');
            $name  = (string)($_SESSION['li_name'] ?? '');
            $numer = (string)($_SESSION['li_numer'] ?? '');
            $opis  = (string)($_SESSION['li_opis'] ?? '');
            $row   = $row ?? [];
            $typ   = LI_TABLES[$tbl] ?? '';

            $number = hd_next_number('LOG');
            $ticket_id = db_insert('helpdesk_tickets', [
                'number'          => $number,
                'title'           => 'Problem z logowaniem — ' . ($name ?: $email),
                'description'     => $opis,
                'category'        => 'it_konto',
                'priority'        => 'wysoki',
                'status'          => 'nowe',
                'requester_id'    => null,
                'requester_name'  => $name ?: 'Zgłaszający',
                'requester_email' => $email ?: ($row['email'] ?? null),
                'requester_phone' => $row['telefon'] ?? null,
                'source'          => 'login_issue',
            ]);
            // Publiczna treść = opis problemu
            db_insert('helpdesk_messages', [
                'ticket_id' => $ticket_id, 'user_id' => null,
                'user_name' => $name ?: 'Zgłaszający', 'body' => $opis, 'is_internal' => 0,
            ]);
            // Notatka wewnętrzna z danymi identyfikacyjnymi (dane wrażliwe — tylko operatorzy)
            db_insert('helpdesk_messages', [
                'ticket_id' => $ticket_id, 'user_id' => null, 'user_name' => 'System',
                'body' => "Zgłoszenie z ekranu logowania — tożsamość zweryfikowana danymi z kartoteki.\n"
                        . ($numer !== '' ? "Numer umowy: {$numer} " : '') . "(typ: {$typ}). E-mail kontaktowy: {$email}.",
                'is_internal' => 1,
            ]);

            $track = hd_track_url(['id' => $ticket_id, 'access_token' => '']);

            // Potwierdzenie e-mail do zgłaszającego (z linkiem do mikropanelu)
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    require_once dirname(__DIR__) . '/includes/mail_queue.php';
                    $num_h = h($number); $name_h = h($name ?: ''); $track_h = h($track); $org_h = h($org);
                    mail_queue_add($email, $name ?: '', "[{$number}] Zgłoszenie problemu z logowaniem przyjęte",
                        <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#b45309;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🔐 Zgłoszenie przyjęte — {$org_h} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name_h}</strong>!</p>
  <p>Przyjęliśmy Twoje zgłoszenie problemu z logowaniem o numerze <strong>{$num_h}</strong>.
     Zespół wsparcia skontaktuje się z Tobą najszybciej, jak to możliwe.</p>
  <div style="margin:20px 0;text-align:center">
    <a href="{$track_h}" style="background:#b45309;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Podgląd i odpowiedź →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Link umożliwia podgląd i odpowiedź bez logowania. {$org_h} · Helpdesk IT
  </p>
</div></body></html>
HTML
                    );
                } catch (\Throwable $e) {}
            }
            // Powiadom operatorów helpdesku
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL");
                $url_op = APP_URL . '/helpdesk/view.php?id=' . $ticket_id;
                foreach ($ops as $opx) {
                    if (empty($opx['email'])) continue;
                    mail_queue_add($opx['email'], $opx['name'] ?? '', "[{$number}] Problem z logowaniem: " . ($name ?: $email),
                        '<p><strong style="font-family:monospace">' . h($number) . '</strong> — problem z logowaniem (priorytet: wysoki)</p>'
                        . '<p>' . nl2br(h($opis)) . '</p>'
                        . '<p><a href="' . h($url_op) . '">Otwórz zgłoszenie →</a></p>');
                }
            } catch (\Throwable $e) {}

            foreach (['li_step','li_fails','li_found','li_table','li_id','li_keys','li_email','li_name','li_numer','li_opis'] as $k) unset($_SESSION[$k]);
            $done = ['number' => $number, 'track' => $track, 'email' => $email];
            $step = 3;
        } else {
            $fails++;
            $_SESSION['li_fails'] = $fails;
            if ($fails >= 3) {
                foreach (['li_step','li_fails','li_found','li_table','li_id','li_keys','li_email','li_name','li_numer','li_opis'] as $k) unset($_SESSION[$k]);
                $step = 1; $error = 'Zbyt wiele błędnych prób. Zacznij od początku.';
            } else { $error = LI_ERR_VERIFY; }
        }
    }
}

$keys = (array)($_SESSION['li_keys'] ?? []);
$step_labels = [1 => 'Identyfikacja', 2 => 'Weryfikacja', 3 => 'Gotowe'];
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Problem z logowaniem — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body{background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .li-wrap{max-width:520px;width:100%}
  .step-bar{display:flex;align-items:center;justify-content:center;margin-bottom:1.8rem}
  .step-item{display:flex;flex-direction:column;align-items:center;gap:4px}
  .step-circle{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;border:2px solid}
  .step-circle.done{background:#b45309;border-color:#b45309;color:#fff}
  .step-circle.active{background:#fff;border-color:#b45309;color:#b45309}
  .step-circle.pending{background:#fff;border-color:#dee2e6;color:#adb5bd}
  .step-label{font-size:.72rem;text-align:center;color:#6c757d;max-width:90px}
  .step-label.active{color:#b45309;font-weight:600}
  .step-connector{flex:1;height:2px;background:#dee2e6;margin:0 8px 20px}
  .step-connector.done{background:#b45309}
</style>
</head><body>
<div class="li-wrap">

  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
         style="width:60px;height:60px;background:rgba(180,83,9,.12)">
      <i class="bi bi-shield-lock-fill" style="font-size:1.8rem;color:#b45309"></i>
    </div>
    <h5 class="fw-bold mb-1">Problem z logowaniem</h5>
    <p class="text-muted small mb-0"><?= h($org) ?></p>
  </div>

  <div class="step-bar">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <?php if ($i > 1): ?><div class="step-connector<?= $step > $i - 1 ? ' done' : '' ?>"></div><?php endif; ?>
      <div class="step-item">
        <div class="step-circle <?= $step > $i ? 'done' : ($step == $i ? 'active' : 'pending') ?>">
          <?= $step > $i ? '<i class="bi bi-check-lg"></i>' : $i ?>
        </div>
        <span class="step-label<?= $step == $i ? ' active' : '' ?>"><?= h($step_labels[$i]) ?></span>
      </div>
    <?php endfor; ?>
  </div>

  <?php if ($error !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2"><i class="bi bi-exclamation-triangle-fill mt-1"></i><div><?= h($error) ?></div></div>
  <?php endif; ?>
  <?php if ($info !== ''): ?>
  <div class="alert alert-info d-flex align-items-start gap-2"><i class="bi bi-info-circle-fill mt-1"></i><div><?= h($info) ?></div></div>
  <?php endif; ?>

  <?php if ($step === 3 && $done): ?>
  <!-- ── Sukces ─────────────────────────────────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-body p-4 text-center">
      <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
      <h6 class="fw-bold mt-3 mb-1">Zgłoszenie przyjęte</h6>
      <p class="text-muted small mb-3">
        Numer zgłoszenia: <strong class="font-monospace"><?= h($done['number']) ?></strong>.
        <?php if ($done['email']): ?>Potwierdzenie wysłaliśmy na <strong><?= h($done['email']) ?></strong>.<?php endif; ?>
      </p>
      <a href="<?= h($done['track']) ?>" class="btn btn-warning text-white">
        <i class="bi bi-eye me-1"></i>Podgląd i odpowiedź
      </a>
      <div class="mt-3">
        <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Wróć do logowania
        </a>
      </div>
    </div>
  </div>

  <?php elseif ($step === 2): ?>
  <!-- ── Krok 2: weryfikacja losowych danych ────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Weryfikacja tożsamości</h6>
      <p class="text-muted small mb-3">Aby potwierdzić, że to Ty, podaj poniższe dane z Twojej umowy.</p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="verify">
        <?php foreach ($keys as $k): if ($k === '_none') continue; [$lbl, $hint, $mode] = _li_meta($k); ?>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="ans_<?= h($k) ?>"><?= h($lbl) ?></label>
          <input type="text" class="form-control" id="ans_<?= h($k) ?>" name="ans[<?= h($k) ?>]"
                 placeholder="<?= h($hint) ?>"
                 <?= $mode === 'numeric' ? 'inputmode="numeric"' : ($mode === 'tel' ? 'inputmode="tel"' : '') ?>
                 autocomplete="off" required>
        </div>
        <?php endforeach; ?>
        <div class="d-grid mb-2">
          <button type="submit" class="btn btn-warning text-white"><i class="bi bi-check-circle me-1"></i>Zweryfikuj i wyślij zgłoszenie</button>
        </div>
        <?php if ($fails > 0): ?>
        <p class="text-danger small text-center mb-0">Błędna próba <?= (int)$fails ?>/3.</p>
        <?php endif; ?>
      </form>
      <div class="text-center mt-2">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="restart">
          <button type="submit" class="btn btn-link btn-sm text-muted p-0"><i class="bi bi-arrow-left me-1"></i>Wróć i popraw dane</button>
        </form>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Krok 1: identyfikacja ──────────────────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Zgłoś problem z logowaniem</h6>
      <p class="text-muted small mb-3">Podaj dane ze swojej umowy — potwierdzimy tożsamość, zanim przyjmiemy zgłoszenie.</p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="identify">

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="email">Adres e-mail (kontaktowy)</label>
          <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email" required autofocus
                   value="<?= h($_POST['email'] ?? '') ?>" placeholder="na ten adres wyślemy potwierdzenie"></div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="imie_nazwisko">Imię i nazwisko</label>
          <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control" id="imie_nazwisko" name="imie_nazwisko" required
                   value="<?= h($_POST['imie_nazwisko'] ?? '') ?>" placeholder="dokładnie jak w umowie"></div>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold small" for="opis">Opis problemu</label>
          <textarea class="form-control" id="opis" name="opis" rows="3" required
                    placeholder="Co się dzieje przy logowaniu? np. „nie przychodzi kod SMS", „błędne hasło mimo resetu"…"><?= h($_POST['opis'] ?? '') ?></textarea>
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-warning text-white"><i class="bi bi-arrow-right-circle me-1"></i>Dalej</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($step !== 3): ?>
  <div class="text-center mt-3">
    <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="text-muted small text-decoration-none">
      <i class="bi bi-arrow-left me-1"></i>Wróć do strony logowania
    </a>
  </div>
  <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
