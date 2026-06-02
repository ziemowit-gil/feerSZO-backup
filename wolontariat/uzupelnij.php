<?php
/**
 * Strona uzupełnienia danych wolontariusza (przed 01.06.2026)
 * Dostęp: tylko przez token (bez logowania)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';

// Uruchom migrację, żeby kolumny data_token i data_token_used_at istniały
cpc_migrate();

// ─── Walidacja tokenu ────────────────────────────────────────────────────────
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$error   = '';
$success = false;
$row     = null;

function token_error(): void {
    global $error;
    $error = 'Link jest nieprawidłowy lub wygasł. Skontaktuj się z administratorem.';
}

if ($token === '') {
    token_error();
} else {
    $row = db_one(
        "SELECT * FROM umowy_wolontariat WHERE data_token = ? AND data_token_used_at IS NULL",
        [$token]
    );
    if (!$row) {
        token_error();
    }
}

// ─── Obsługa POST ────────────────────────────────────────────────────────────
$errors = [];
$posted = [];

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = [
        'imie_nazwisko' => trim($_POST['imie_nazwisko'] ?? ''),
        'pesel'         => trim($_POST['pesel'] ?? ''),
        'data_urodzenia'=> trim($_POST['data_urodzenia'] ?? ''),
        'telefon'       => trim($_POST['telefon'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'addr_street'   => trim($_POST['addr_street'] ?? ''),
        'addr_house'    => trim($_POST['addr_house'] ?? ''),
        'addr_flat'     => trim($_POST['addr_flat'] ?? ''),
        'addr_postal'   => trim($_POST['addr_postal'] ?? ''),
        'addr_city'     => trim($_POST['addr_city'] ?? ''),
        'rodo_zgoda'    => isset($_POST['rodo_zgoda']) ? 1 : 0,
    ];

    // Walidacja
    if ($posted['imie_nazwisko'] === '') {
        $errors[] = 'Podaj imię i nazwisko.';
    }
    if ($posted['pesel'] === '') {
        $errors[] = 'Podaj PESEL.';
    } elseif (!preg_match('/^\d{11}$/', $posted['pesel'])) {
        $errors[] = 'PESEL musi składać się z 11 cyfr.';
    }
    if ($posted['rodo_zgoda'] !== 1) {
        $errors[] = 'Wymagana jest zgoda na przetwarzanie danych osobowych.';
    }

    // Jeśli data urodzenia pusta — spróbuj wyciągnąć z PESEL
    if ($posted['data_urodzenia'] === '' && strlen($posted['pesel']) === 11) {
        $y = (int)substr($posted['pesel'], 0, 2);
        $m = (int)substr($posted['pesel'], 2, 2);
        $d = (int)substr($posted['pesel'], 4, 2);
        // Kodowanie stulecia: miesiąc 81-92 → 1800, 1-12 → 1900, 21-32 → 2000
        if ($m >= 81 && $m <= 92) { $year = 1800 + $y; $month = $m - 80; }
        elseif ($m >= 21 && $m <= 32) { $year = 2000 + $y; $month = $m - 20; }
        else { $year = 1900 + $y; $month = $m; }
        $posted['data_urodzenia'] = sprintf('%04d-%02d-%02d', $year, $month, $d);
    }

    if (empty($errors)) {
        // Email tylko jeśli nie był ustawiony (read-only)
        $email_to_save = trim($row['email'] ?? '');
        if ($email_to_save === '') {
            $email_to_save = $posted['email'];
        }

        db()->prepare(
            "UPDATE umowy_wolontariat SET
                imie_nazwisko   = ?,
                pesel           = ?,
                data_urodzenia  = ?,
                telefon         = ?,
                email           = ?,
                addr_street     = ?,
                addr_house      = ?,
                addr_flat       = ?,
                addr_postal     = ?,
                addr_city       = ?,
                data_token      = NULL,
                data_token_used_at = datetime('now')
             WHERE id = ?"
        )->execute([
            $posted['imie_nazwisko'],
            $posted['pesel'],
            $posted['data_urodzenia'],
            $posted['telefon'],
            $email_to_save,
            $posted['addr_street'],
            $posted['addr_house'],
            $posted['addr_flat'],
            $posted['addr_postal'],
            $posted['addr_city'],
            (int)$row['id'],
        ]);

        $success = true;
    }
}

// ─── Dane do formularza (pre-fill) ────────────────────────────────────────────
$f = $posted ?: ($row ?? []);
$has_email = !empty($row['email']);

// ─── Ustawienia org ────────────────────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : (function_exists('org_setting') ? org_setting('org_name') : 'Organizacja');
$org_logo = function_exists('org_setting') ? org_setting('org_logo') : '';
$app_url  = defined('APP_URL') ? APP_URL : '';

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Uzupełnienie danych wolontariusza — <?= h($org_name) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body {
      background: #f8f5f0;
      font-family: 'Segoe UI', system-ui, sans-serif;
      min-height: 100vh;
    }
    .page-wrapper {
      max-width: 680px;
      margin: 0 auto;
      padding: 2rem 1rem 4rem;
    }
    .brand-header {
      text-align: center;
      padding: 2rem 1rem 1.5rem;
    }
    .brand-header img {
      max-height: 64px;
      margin-bottom: .75rem;
    }
    .brand-header h1 {
      font-size: 1.3rem;
      font-weight: 700;
      color: #1a1a2e;
      margin-bottom: .25rem;
    }
    .brand-header .subtitle {
      color: #6c757d;
      font-size: .95rem;
    }
    .welcome-card {
      background: linear-gradient(135deg, #fff9f0, #fff3e0);
      border: 1px solid #ffd8a8;
      border-left: 5px solid #fd7e14;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }
    .welcome-card h2 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #c0392b;
      margin-bottom: .75rem;
    }
    .welcome-card p {
      margin-bottom: .6rem;
      color: #444;
      line-height: 1.65;
      font-size: .96rem;
    }
    .form-card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 2px 16px rgba(0,0,0,.07);
      padding: 2rem;
    }
    .form-section-title {
      font-size: .8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #9b59b6;
      margin: 1.5rem 0 .75rem;
      padding-bottom: .3rem;
      border-bottom: 2px solid #f3e5f5;
    }
    .form-label {
      font-weight: 600;
      font-size: .9rem;
      color: #2c3e50;
    }
    .form-control:focus {
      border-color: #9b59b6;
      box-shadow: 0 0 0 .2rem rgba(155,89,182,.2);
    }
    .btn-submit {
      background: linear-gradient(135deg, #8e44ad, #9b59b6);
      border: none;
      padding: .75rem 2rem;
      font-size: 1rem;
      font-weight: 600;
      border-radius: 8px;
      color: #fff;
      width: 100%;
    }
    .btn-submit:hover {
      background: linear-gradient(135deg, #7d3c98, #8e44ad);
      color: #fff;
    }
    .rodo-box {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 1rem 1.25rem;
      font-size: .85rem;
      color: #555;
    }
    .success-card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 2px 16px rgba(0,0,0,.07);
      padding: 3rem 2rem;
      text-align: center;
    }
    .success-icon {
      font-size: 3.5rem;
      color: #27ae60;
      margin-bottom: 1rem;
    }
    footer {
      text-align: center;
      font-size: .78rem;
      color: #aaa;
      margin-top: 2rem;
    }
  </style>
</head>
<body>
<div class="page-wrapper">

  <!-- Header -->
  <div class="brand-header">
    <?php if ($org_logo): ?>
      <img src="<?= h($org_logo) ?>" alt="<?= h($org_name) ?>">
    <?php endif; ?>
    <h1><?= h($org_name) ?></h1>
    <div class="subtitle">Uzupełnienie danych wolontariusza</div>
  </div>

  <?php if ($error): ?>
  <!-- Token invalid -->
  <div class="form-card text-center py-5">
    <div style="font-size:3rem;color:#e74c3c;margin-bottom:1rem">
      <i class="bi bi-shield-exclamation"></i>
    </div>
    <h2 class="h5 fw-bold text-danger mb-3">Link nieprawidłowy lub wygasły</h2>
    <p class="text-muted mb-0"><?= h($error) ?></p>
  </div>

  <?php elseif ($success): ?>
  <!-- Sukces -->
  <div class="success-card">
    <div class="success-icon">
      <i class="bi bi-check-circle-fill"></i>
    </div>
    <h2 class="h4 fw-bold mb-2">Dziękujemy!</h2>
    <p class="text-muted mb-3">Twoje dane zostały zapisane. Nasza dokumentacja jest teraz aktualna.</p>
    <p class="mb-4">Możesz teraz zalogować się do portalu wolontariusza.</p>
    <?php if ($app_url): ?>
    <a href="<?= h($app_url) ?>/portal.php" class="btn btn-primary px-4">
      <i class="bi bi-box-arrow-in-right me-2"></i>Przejdź do portalu
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <!-- Wiadomość powitalna -->
  <div class="welcome-card">
    <h2>Witaj w Fundacji FEER!</h2>
    <p><strong>Wracamy z porządkiem 🙌</strong></p>
    <p>Przez kilka ostatnich lat korzystaliśmy z różnych narzędzi do zarządzania wolontariatem — Trello, arkusze, papierowe listy. Wiemy, że to było chaotyczne i przepraszamy za niedogodności.</p>
    <p>Teraz wdrożyliśmy nowy system, który pozwala nam lepiej zadbać o Was i Wasze dane.</p>
    <p>Ze względu na RODO nie przechowywaliśmy elektronicznie wszystkich danych osobowych — część była tylko na papierze lub w ogóle nie była rejestrowana w systemie cyfrowym. Prosimy Cię o uzupełnienie poniższych danych, abyśmy mogli prawidłowo prowadzić dokumentację Twojej współpracy z nami.</p>
    <p class="mb-0"><em>To zajmie maksymalnie 3 minuty. Obiecujemy, że tym razem nie zgubimy 😊</em></p>
  </div>

  <!-- Formularz -->
  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger mb-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?php foreach ($errors as $e): ?>
      <div><?= h($e) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="form-card">
    <form method="post" novalidate>
      <input type="hidden" name="token" value="<?= h($token) ?>">

      <div class="form-section-title">Dane osobowe</div>

      <div class="mb-3">
        <label class="form-label" for="imie_nazwisko">Imię i nazwisko <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="imie_nazwisko" name="imie_nazwisko"
               value="<?= h($f['imie_nazwisko'] ?? '') ?>" required autocomplete="name">
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label" for="pesel">PESEL <span class="text-danger">*</span></label>
          <input type="text" class="form-control font-monospace" id="pesel" name="pesel"
                 value="<?= h($f['pesel'] ?? '') ?>" maxlength="11" pattern="\d{11}"
                 inputmode="numeric" required autocomplete="off">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="data_urodzenia">Data urodzenia</label>
          <input type="date" class="form-control" id="data_urodzenia" name="data_urodzenia"
                 value="<?= h($f['data_urodzenia'] ?? '') ?>">
          <div class="form-text">Wypełni się automatycznie po wpisaniu PESEL.</div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label" for="telefon">Telefon</label>
          <input type="tel" class="form-control" id="telefon" name="telefon"
                 value="<?= h($f['telefon'] ?? '') ?>" autocomplete="tel">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="email">Adres e-mail</label>
          <?php if ($has_email): ?>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= h($row['email']) ?>" readonly>
            <div class="form-text"><i class="bi bi-lock-fill"></i> Adres e-mail jest już ustawiony.</div>
          <?php else: ?>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= h($f['email'] ?? '') ?>" autocomplete="email">
          <?php endif; ?>
        </div>
      </div>

      <div class="form-section-title">Adres zamieszkania</div>

      <div class="row g-3 mb-3">
        <div class="col-8">
          <label class="form-label" for="addr_street">Ulica</label>
          <input type="text" class="form-control" id="addr_street" name="addr_street"
                 value="<?= h($f['addr_street'] ?? '') ?>" autocomplete="street-address">
        </div>
        <div class="col-2">
          <label class="form-label" for="addr_house">Nr domu</label>
          <input type="text" class="form-control" id="addr_house" name="addr_house"
                 value="<?= h($f['addr_house'] ?? '') ?>">
        </div>
        <div class="col-2">
          <label class="form-label" for="addr_flat">Nr lok.</label>
          <input type="text" class="form-control" id="addr_flat" name="addr_flat"
                 value="<?= h($f['addr_flat'] ?? '') ?>">
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label" for="addr_postal">Kod pocztowy</label>
          <input type="text" class="form-control" id="addr_postal" name="addr_postal"
                 value="<?= h($f['addr_postal'] ?? '') ?>" placeholder="00-000"
                 pattern="\d{2}-\d{3}" inputmode="numeric" autocomplete="postal-code">
        </div>
        <div class="col-md-8">
          <label class="form-label" for="addr_city">Miejscowość</label>
          <input type="text" class="form-control" id="addr_city" name="addr_city"
                 value="<?= h($f['addr_city'] ?? '') ?>" autocomplete="address-level2">
        </div>
      </div>

      <div class="form-section-title">Zgoda RODO</div>

      <div class="rodo-box mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="rodo_zgoda" name="rodo_zgoda" value="1"
                 <?= !empty($posted['rodo_zgoda']) ? 'checked' : '' ?> required>
          <label class="form-check-label" for="rodo_zgoda">
            <strong>Wyrażam zgodę na przetwarzanie moich danych osobowych</strong> przez <?= h($org_name) ?>
            w celu prowadzenia dokumentacji wolontariatu, zgodnie z Rozporządzeniem Parlamentu Europejskiego
            i Rady (UE) 2016/679 (RODO). Dane będą przetwarzane wyłącznie w zakresie niezbędnym
            do realizacji umowy wolontariatu i przez czas jej trwania oraz wynikający z przepisów prawa.
            Przysługuje mi prawo dostępu do danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania.
            <span class="text-danger">*</span>
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-submit mt-2">
        <i class="bi bi-save me-2"></i>Zapisz dane
      </button>
    </form>
  </div>
  <?php endif; ?>

  <footer>
    &copy; <?= date('Y') ?> <?= h($org_name) ?> &mdash; Twoje dane są chronione zgodnie z RODO.
  </footer>

</div>

<script>
// Auto-fill data urodzenia z PESEL
document.getElementById('pesel')?.addEventListener('input', function() {
    const val = this.value;
    if (val.length !== 11 || !/^\d{11}$/.test(val)) return;
    const y = parseInt(val.slice(0,2));
    let m = parseInt(val.slice(2,4));
    const d = parseInt(val.slice(4,6));
    let year;
    if (m >= 81) { year = 1800 + y; m -= 80; }
    else if (m >= 21) { year = 2000 + y; m -= 20; }
    else { year = 1900 + y; }
    const date = `${year}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const dobField = document.getElementById('data_urodzenia');
    if (dobField && !dobField.value) dobField.value = date;
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
