<?php
/**
 * POST-only handler: generuje token do uzupełnienia danych i wysyła e-mail
 * Wywołanie z contracts/wolontariat/view.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/cpc.php';

require_role('admin', 'editor');
csrf_check();
cpc_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$contract_id = (int)($_POST['contract_id'] ?? 0);
$redirect_url = defined('APP_URL')
    ? APP_URL . '/contracts/wolontariat/view.php?id=' . $contract_id
    : '../contracts/wolontariat/view.php?id=' . $contract_id;

if ($contract_id <= 0) {
    flash_set('error', 'Nieprawidłowe ID umowy.');
    header('Location: ' . $redirect_url);
    exit;
}

$row = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$contract_id]);
if (!$row) {
    flash_set('error', 'Nie znaleziono umowy.');
    header('Location: ' . $redirect_url);
    exit;
}

$email = trim($row['email'] ?? '');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'Wolontariusz nie ma przypisanego adresu e-mail. Uzupełnij najpierw e-mail w umowie.');
    header('Location: ' . $redirect_url);
    exit;
}

// Generuj token
$token = bin2hex(random_bytes(32));

// Zapisz token w bazie
db()->prepare(
    "UPDATE umowy_wolontariat SET data_token = ?, data_token_used_at = NULL WHERE id = ?"
)->execute([$token, $contract_id]);

// Loguj akcję
$user = current_user();
if (function_exists('log_contract_action')) {
    log_contract_action(
        'wolontariat',
        $contract_id,
        (int)($user['id'] ?? 0),
        'note',
        'Admin: wygenerowano i wysłano link do uzupełnienia danych (token)'
    );
}

// Buduj link
$page_url = (defined('APP_URL') ? APP_URL : '') . '/wolontariat/uzupelnij.php?token=' . urlencode($token);
$org_name = defined('ORG_NAME') ? ORG_NAME : (function_exists('org_setting') ? org_setting('org_name') : 'Organizacja');
$name     = h($row['imie_nazwisko'] ?? $email);

$subject  = "Uzupełnienie danych wolontariusza — {$org_name}";
$body     = <<<HTML
<html><body style="font-family:'Segoe UI',sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#8e44ad,#9b59b6);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">Uzupełnienie danych — {$org_name}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>Wdrożyliśmy nowy system zarządzania wolontariatem i zależy nam, żeby Twoja dokumentacja była kompletna.</p>
  <p>Prosimy o uzupełnienie kilku danych osobowych (wymaganych przez RODO). Zajmie to <strong>maksymalnie 3 minuty</strong>.</p>
  <div style="text-align:center;margin:28px 0">
    <a href="{$page_url}"
       style="background:linear-gradient(135deg,#8e44ad,#9b59b6);color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:1rem">
      Uzupełnij swoje dane →
    </a>
  </div>
  <div style="background:#f8f9fa;border-radius:6px;padding:12px 16px;font-size:.85rem;color:#6c757d;margin-bottom:12px">
    Jeśli przycisk nie działa, skopiuj i wklej poniższy link w przeglądarce:<br>
    <a href="{$page_url}" style="color:#8e44ad;word-break:break-all">{$page_url}</a>
  </div>
  <p style="font-size:.85rem;color:#888">
    Link jest jednorazowy i przypisany do Twojej umowy.<br>
    Jeśli masz pytania, napisz do nas.
  </p>
  <hr style="border-color:#eee">
  <p style="font-size:.8rem;color:#aaa;margin-bottom:0">
    Wiadomość wysłana przez system zarządzania NGO — {$org_name}
  </p>
</div>
</body></html>
HTML;

try {
    approval_send_email($email, $subject, $body);
    flash_set('success', "Link do uzupełnienia danych wysłany na adres {$email}.");
} catch (\Throwable $e) {
    // Token został zapisany mimo błędu wysyłki — możemy wyświetlić link ręcznie
    flash_set('warning', "Token wygenerowany, ale nie udało się wysłać e-maila ({$e->getMessage()}). Link ręczny: {$page_url}");
}

header('Location: ' . $redirect_url);
exit;

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
