<?php
/**
 * contracts/email_template_doc.php
 * Generuje dokument DOCX z szablonu, szyfruje ZIP (AES-256, hasło = 5 ostatnich
 * cyfr PESEL), wysyła na adres e-mail wolontariusza.
 *
 * POST: template_id, contract_id, type, [custom_email]
 * Odpowiedź: redirect z flash lub JSON gdy ?json=1
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/contract_template_engine.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
csrf_check();

$is_json     = !empty($_GET['json']);
$template_id = (int)($_POST['template_id'] ?? 0);
$contract_id = (int)($_POST['contract_id'] ?? 0);
$type        = preg_replace('/[^a-z]/', '', $_POST['type'] ?? 'wolontariat');
$custom_email= trim($_POST['custom_email'] ?? '');
$return_url  = $_POST['return_url'] ?? (APP_URL . '/contracts/' . $type . '/view.php?id=' . $contract_id . '&tab=docs');

function _fail(string $msg, string $return_url, bool $json): never {
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$msg]); }
    else { flash_set('danger', $msg); header('Location: ' . $return_url); }
    exit;
}
function _ok(string $msg, string $return_url, bool $json): never {
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>$msg]); }
    else { flash_set('success', $msg); header('Location: ' . $return_url); }
    exit;
}

if (!$template_id) _fail('Brak template_id.', $return_url, $is_json);

cte_migrate();
$tpl = db_one("SELECT * FROM contract_doc_templates WHERE id=?", [$template_id]);
if (!$tpl) _fail('Szablon nie istnieje.', $return_url, $is_json);

// ── Dane umowy ────────────────────────────────────────────────────────────────
$row = [];
$table_map = ['wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie',
              'dzielo'=>'umowy_dzielo','praca'=>'umowy_praca',
              'uslugi'=>'umowy_uslugi','inne'=>'umowy_inne'];

if ($contract_id && isset($table_map[$type])) {
    $row = db_one("SELECT * FROM {$table_map[$type]} WHERE id=?", [$contract_id]) ?: [];
}

// ── Adres e-mail ──────────────────────────────────────────────────────────────
$to_email = $custom_email ?: ($row['email'] ?? '');
if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
    _fail('Brak adresu e-mail wolontariusza. Uzupełnij e-mail w umowie lub podaj adres ręcznie.', $return_url, $is_json);
}
$to_name = $row['imie_nazwisko'] ?? $to_email;

// ── Hasło: ostatnie 5 cyfr PESEL ─────────────────────────────────────────────
$pesel = preg_replace('/\D/', '', $row['pesel'] ?? '');
if (strlen($pesel) < 5) {
    _fail('Brak numeru PESEL w umowie — nie można zaszyfrować dokumentu. Uzupełnij PESEL i spróbuj ponownie.', $return_url, $is_json);
}
$zip_password = substr($pesel, -5); // 5 ostatnich cyfr

// ── Org data ──────────────────────────────────────────────────────────────────
$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org     = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_adres = org_setting('org_adres') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_city  = org_setting('org_miejscowosc') ?: '';

$logo_path = '';
$_lf = org_setting('org_logo');
if ($_lf) {
    $lp = dirname(__DIR__) . '/assets/logo/' . basename($_lf);
    if (file_exists($lp) && filesize($lp) < 500_000) $logo_path = $lp;
}

// ── Generuj DOCX ─────────────────────────────────────────────────────────────
$map   = cte_build_map($type, $row);
$html  = cte_render($tpl['body'], $map);

$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);
$phpWord->addParagraphStyle('pNormal', ['spaceAfter' => 100, 'alignment' => Jc::BOTH, 'lineHeight' => 1.2]);

$section = $phpWord->addSection([
    'paperSize'    => 'A4',
    'marginTop'    => Converter::cmToTwip(2.0),
    'marginBottom' => Converter::cmToTwip(1.8),
    'marginLeft'   => Converter::cmToTwip(2.5),
    'marginRight'  => Converter::cmToTwip(2.0),
]);

// Nagłówek org
$hdrTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
$hdrTable->addRow();
$cellL = $hdrTable->addCell(8000, ['borderSize' => 0]);
$cellR = $hdrTable->addCell(3000, ['borderSize' => 0]);

if ($logo_path) {
    try { $cellL->addImage($logo_path, ['height' => 26, 'wrappingStyle' => 'inline']); } catch (\Throwable $e) {}
}
$cellL->addText($org, ['bold' => true, 'size' => 10], ['spaceAfter' => 0]);
$meta = trim(($org_adres ?: '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));
if ($meta) $cellL->addText($meta, ['size' => 8, 'color' => '444444'], ['spaceAfter' => 0]);
if (!empty($row['numer_umowy'])) {
    $cellR->addText($row['numer_umowy'], ['bold' => true, 'size' => 9], ['spaceAfter' => 0, 'alignment' => Jc::END]);
}
$cellR->addText(trim(($org_city ? $org_city . ', ' : '') . 'dnia ' . date('d.m.Y')),
    ['size' => 8, 'color' => '555555'], ['spaceAfter' => 0, 'alignment' => Jc::END]);

$section->addTextBreak(1);
$section->addText($tpl['name'],
    ['bold' => true, 'size' => 14, 'allCaps' => true],
    ['alignment' => Jc::CENTER, 'spaceAfter' => 160, 'spaceBefore' => 60]);

// Treść
$body_text = strip_tags($html, '<p><br><h1><h2><h3><h4><ul><ol><li><strong><b><em><i><u>');
$body_text = preg_replace('/<h[1-4][^>]*>(.*?)<\/h[1-4]>/si', "\n\n###H:\$1###\n\n", $body_text);
$body_text = preg_replace('/<li[^>]*>(.*?)<\/li>/si',          "\n• \$1", $body_text);
$body_text = preg_replace('/<\/?(ul|ol)[^>]*>/si',             "\n", $body_text);
$body_text = preg_replace('/<br\s*\/?>/si',                    "\n", $body_text);
$body_text = preg_replace('/<p[^>]*>(.*?)<\/p>/si',            "\$1\n\n", $body_text);
$body_text = html_entity_decode(strip_tags($body_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

foreach (array_filter(array_map('trim', preg_split('/\n{2,}/', $body_text))) as $p) {
    if (str_starts_with($p, '###H:') && str_ends_with($p, '###')) {
        $section->addText(trim(substr($p, 5, -3)), ['bold' => true, 'size' => 12],
            ['spaceAfter' => 60, 'spaceBefore' => 120, 'alignment' => Jc::BOTH]);
    } else {
        $run = $section->addTextRun(['pNormal']);
        $first = true;
        foreach (explode("\n", $p) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!$first) $run->addTextBreak();
            $run->addText($line, ['size' => 11]);
            $first = false;
        }
    }
}

// Zapisz DOCX do pliku tymczasowego
$safe_name   = preg_replace('/[^A-Za-z0-9_-]/', '_', $tpl['name']);
$docx_name   = 'Dokument_' . $safe_name . '_' . date('Ymd') . '.docx';
$tmp_dir     = UPLOAD_DIR . 'temp_docs/';
if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
$docx_path   = $tmp_dir . $docx_name;
IOFactory::createWriter($phpWord, 'Word2007')->save($docx_path);

// ── Zaszyfruj w ZIP (AES-256) ─────────────────────────────────────────────────
$zip_name    = 'Dokument_' . $safe_name . '_' . date('Ymd') . '.zip';
$zip_path    = $tmp_dir . $zip_name;

if (file_exists($zip_path)) @unlink($zip_path);

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($docx_path);
    _fail('Błąd tworzenia archiwum ZIP.', $return_url, $is_json);
}
$zip->addFile($docx_path, $docx_name);
if (!$zip->setEncryptionIndex(0, ZipArchive::EM_AES_256, $zip_password)) {
    $zip->close(); @unlink($docx_path); @unlink($zip_path);
    _fail('Błąd szyfrowania ZIP — sprawdź czy libzip obsługuje AES.', $return_url, $is_json);
}
$zip->close();
@unlink($docx_path); // DOCX już w ZIP — usuń plik tymczasowy

// ── Wyślij e-mail ─────────────────────────────────────────────────────────────
$org_short = $org;
$doc_title = h($tpl['name']);
$password_hint = '<strong>' . $zip_password . '</strong>';
// Dla prywatności nie pokazujemy pełnego hasła w mailu — tylko wskazówkę
$password_email_hint = 'ostatnie 5 cyfr Twojego numeru PESEL';

$subject = "Dokument: {$tpl['name']} — {$org}";

$body_html = <<<HTML
<html><body style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1a1a1a;padding:18px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">📄 Dokument do pobrania — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$to_name}</strong>!</p>
  <p>W załączniku znajdziesz dokument: <strong>{$doc_title}</strong></p>

  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:16px 0">
    <p style="margin:0 0 8px;font-weight:700">🔒 Dokument jest zaszyfrowany</p>
    <p style="margin:0;font-size:.92em">
      Aby otworzyć plik ZIP, podaj hasło: <strong>{$password_email_hint}</strong>.
      <br>Hasło składa się z 5 cyfr.
    </p>
  </div>

  <div style="background:#f8f9fa;border-radius:6px;padding:12px 16px;margin:16px 0;font-size:.88em">
    <strong>Instrukcja:</strong><br>
    1. Pobierz i otwórz plik ZIP (<code>.zip</code>)<br>
    2. Gdy pojawi się pytanie o hasło, wpisz <strong>ostatnie 5 cyfr swojego PESEL</strong><br>
    3. W środku znajdziesz dokument Word (<code>.docx</code>)
  </div>

  <p style="color:#6c757d;font-size:.84em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Wiadomość wysłana przez system {$org}. Jeśli masz pytania, skontaktuj się ze swoim opiekunem.
  </p>
</div></body></html>
HTML;

$body_text = "Dokument: {$tpl['name']}\n\nW załączniku znajdziesz zaszyfrowany plik ZIP.\n"
           . "Hasło: ostatnie 5 cyfr Twojego numeru PESEL.\n\n{$org}";

try {
    $att_path = 'temp_docs/' . $zip_name;
    mail_queue_add(
        $to_email, $to_name,
        $subject, $body_html, $body_text,
        $type, $contract_id,
        '', false,
        [[
            'path' => $att_path,
            'name' => $zip_name,
            'mime' => 'application/zip',
            'size' => filesize($zip_path),
        ]]
    );
    mail_queue_process(1); // wyślij od razu
} catch (\Throwable $e) {
    @unlink($zip_path);
    _fail('Błąd wysyłki e-mail: ' . $e->getMessage(), $return_url, $is_json);
}

// Log
try {
    require_once dirname(__DIR__) . '/includes/approval.php';
    log_contract_action($type, $contract_id, (int)current_user()['id'], 'note',
        "Wysłano dokument \"{$tpl['name']}\" na e-mail {$to_email} (zaszyfrowany ZIP).");
} catch (\Throwable $e) {}

_ok("Dokument \"{$tpl['name']}\" wyslany na <strong>{$to_email}</strong>. "
    . "Haslo do pliku ZIP: <strong>ostatnie 5 cyfr PESEL</strong>.", $return_url, $is_json);
