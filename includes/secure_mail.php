<?php
/**
 * includes/secure_mail.php
 * Współdzielone helpery do wysyłki dokumentów/pism zaszyfrowanych w ZIP (AES-256,
 * hasło = ostatnie cyfry PESEL) oraz wykrywania, czy wolontariusz ma aktywne konto.
 *
 * Używane przez:
 *   - contracts/letters/add.php  → auto-wysyłka pisma do wolontariusza bez konta,
 *   - cron/volunteer_account_reminder.php → przypomnienie po 14 dniach,
 *   - contracts/email_template_doc.php → wysyłka wzoru dokumentu.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail_queue.php';

// Ile ostatnich cyfr PESEL stanowi hasło do ZIP (spójne w całym systemie).
if (!defined('SECURE_PESEL_DIGITS')) define('SECURE_PESEL_DIGITS', 5);

/**
 * Zwraca hasło = ostatnie SECURE_PESEL_DIGITS cyfr z PESEL, lub null gdy za krótki.
 */
function secure_pesel_password(?string $pesel, int $digits = SECURE_PESEL_DIGITS): ?string {
    $pesel = preg_replace('/\D/', '', (string)$pesel);
    if (strlen($pesel) < $digits) return null;
    return substr($pesel, -$digits);
}

/**
 * Pakuje pojedynczy plik do zaszyfrowanego ZIP (AES-256) w UPLOAD_DIR/temp_docs/.
 * Zwraca ścieżkę BEZWZGLĘDNĄ do pliku ZIP lub null przy błędzie.
 */
function zip_encrypt_file(string $srcPath, string $entryName, string $password): ?string {
    if (!is_file($srcPath)) return null;

    $tmp_dir = UPLOAD_DIR . 'temp_docs/';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

    $zip_name = pathinfo($entryName, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
    $zip_path = $tmp_dir . $zip_name;
    if (file_exists($zip_path)) @unlink($zip_path);

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $zip->addFile($srcPath, $entryName);
    if (!$zip->setEncryptionIndex(0, ZipArchive::EM_AES_256, $password)) {
        $zip->close();
        @unlink($zip_path);
        return null; // libzip bez obsługi AES
    }
    $zip->close();

    return $zip_path;
}

/**
 * Czy dla danej umowy wolontariatu istnieje powiązane AKTYWNE konto użytkownika?
 * Linkowanie odwrotne do panel/index.php (panel_contracts): po microsoft_id,
 * loginie/e-mailu M365, e-mailu wolontariusza oraz e-mailu opiekuna (rodzic_email).
 */
function volunteer_account_exists(array $contract): bool {
    $conds = [];
    $params = [];

    $ms_id = trim((string)($contract['m365_user_id'] ?? ''));
    if ($ms_id !== '') { $conds[] = 'microsoft_id = ?'; $params[] = $ms_id; }

    foreach (['m365_login', 'email', 'rodzic_email'] as $f) {
        $val = trim((string)($contract[$f] ?? ''));
        if ($val !== '') { $conds[] = 'LOWER(email) = LOWER(?)'; $params[] = $val; }
    }

    if (!$conds) return false;

    $row = db_one(
        "SELECT 1 FROM users WHERE is_active = 1 AND (" . implode(' OR ', $conds) . ") LIMIT 1",
        $params
    );
    return (bool)$row;
}

/**
 * Treść HTML maila informującego o zaszyfrowanym załączniku.
 */
function secure_doc_email_html(string $to_name, string $doc_title, string $org, int $digits = SECURE_PESEL_DIGITS): string {
    $to_name   = htmlspecialchars($to_name);
    $doc_title = htmlspecialchars($doc_title);
    $org       = htmlspecialchars($org);
    return <<<HTML
<html><body style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1a1a1a;padding:18px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">📄 Pismo do umowy — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$to_name}</strong>!</p>
  <p>W załączniku znajdziesz pismo: <strong>{$doc_title}</strong></p>

  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:16px 0">
    <p style="margin:0 0 8px;font-weight:700">🔒 Załącznik jest zaszyfrowany</p>
    <p style="margin:0;font-size:.92em">
      Aby otworzyć plik ZIP, podaj hasło: <strong>ostatnie {$digits} cyfr Twojego numeru PESEL</strong>.
    </p>
  </div>

  <div style="background:#f8f9fa;border-radius:6px;padding:12px 16px;margin:16px 0;font-size:.88em">
    <strong>Instrukcja:</strong><br>
    1. Pobierz i otwórz plik ZIP (<code>.zip</code>)<br>
    2. Gdy pojawi się pytanie o hasło, wpisz <strong>ostatnie {$digits} cyfr swojego PESEL</strong><br>
    3. W środku znajdziesz dokument pisma
  </div>

  <p style="background:#e7f1ff;border:1px solid #b6d4fe;border-radius:6px;padding:12px 16px;font-size:.88em;margin:16px 0">
    💡 Otrzymujesz pisma e-mailem, bo nie masz jeszcze aktywnego konta w systemie.
    Po założeniu konta wszystkie pisma będą dostępne online, bez konieczności podawania hasła.
  </p>

  <p style="color:#6c757d;font-size:.84em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Wiadomość wysłana automatycznie przez system {$org}. Jeśli masz pytania, skontaktuj się ze swoim opiekunem.
  </p>
</div></body></html>
HTML;
}

/**
 * Generuje prosty DOCX z treści pisma (gdy pismo nie ma załączonego pliku).
 * Zwraca ścieżkę bezwzględną do .docx w temp_docs/ lub null.
 */
function _secure_letter_docx(string $title, string $html_or_text): ?string {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return null;
    require_once $autoload;
    if (!class_exists(\PhpOffice\PhpWord\PhpWord::class)) return null;

    // HTML (CKEditor) → tekst akapitowy
    $text = preg_replace('/<\/(p|div|h[1-6]|li)>/i', "\n\n", $html_or_text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(11);
    $section = $phpWord->addSection();
    if ($title !== '') {
        $section->addText($title, ['bold' => true, 'size' => 13], ['spaceAfter' => 160]);
    }
    foreach (preg_split('/\n{2,}/', trim($text)) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $run = $section->addTextRun(['spaceAfter' => 100]);
        $first = true;
        foreach (explode("\n", $para) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!$first) $run->addTextBreak();
            $run->addText($line, ['size' => 11]);
            $first = false;
        }
    }

    $tmp_dir = UPLOAD_DIR . 'temp_docs/';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $docx = $tmp_dir . 'pismo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.docx';
    try {
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($docx);
    } catch (\Throwable $e) {
        return null;
    }
    return is_file($docx) ? $docx : null;
}

/**
 * Auto-wysyłka pisma do wolontariusza, który NIE ma aktywnego konta (po 14 dniach
 * od założenia umowy). Pismo trafia jako zaszyfrowany ZIP na e-mail wolontariusza
 * (lub opiekuna, gdy niepełnoletni).
 *
 * Zwraca ['sent'=>bool, 'reason'=>string, 'to'=>string].
 * Gdy warunki nie są spełnione — ['sent'=>false, 'reason'=>...]; wywołujący może
 * wtedy zachować standardową ścieżkę wysyłki.
 *
 * @param array      $letter   Wiersz pisma (contract_letters) — wymaga kierunek, tytul, plik|tresc.
 * @param array|null $contract Wiersz umowy; gdy null — pobierany z umowy_wolontariat.
 */
function send_encrypted_letter_to_volunteer(string $type, int $contract_id, array $letter, ?array $contract = null): array {
    $r = fn(string $reason, bool $sent = false, string $to = '') => ['sent' => $sent, 'reason' => $reason, 'to' => $to];

    if ($type !== 'wolontariat') return $r('not_volunteer');
    if (($letter['kierunek'] ?? '') !== 'wychodzące') return $r('not_outgoing');

    if ($contract === null) {
        $contract = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$contract_id]) ?: [];
    }
    if (!$contract) return $r('no_contract');

    // Tylko gdy brak aktywnego konta…
    if (volunteer_account_exists($contract)) return $r('has_account');

    // …i minęło ≥14 dni od założenia umowy.
    $created = $contract['created_at'] ?? '';
    if (!$created) return $r('no_created_at');
    $days = (int) floor((strtotime('today') - strtotime(substr($created, 0, 10))) / 86400);
    if ($days < 14) return $r('too_early');

    // Adresat: opiekun, gdy niepełnoletni; inaczej sam wolontariusz.
    $to_email = (!empty($contract['niepelnoletni']) && !empty($contract['rodzic_email']))
        ? $contract['rodzic_email']
        : ($contract['email'] ?? '');
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return $r('no_email');
    $to_name = $contract['imie_nazwisko'] ?? $to_email;

    // Hasło z PESEL.
    $password = secure_pesel_password($contract['pesel'] ?? '');
    if ($password === null) return $r('no_pesel');

    // Źródło: załączony plik pisma, inaczej DOCX z treści.
    $cleanup = [];
    $src = '';
    $entry_ext = 'pdf';
    if (!empty($letter['plik'])) {
        $src = UPLOAD_DIR . $letter['plik'];
        if (!is_file($src)) return $r('file_missing');
        $entry_ext = strtolower(pathinfo($letter['plik'], PATHINFO_EXTENSION)) ?: 'pdf';
    } elseif (!empty($letter['tresc'])) {
        $src = _secure_letter_docx($letter['tytul'] ?? 'Pismo', $letter['tresc']);
        if (!$src) return $r('docx_failed');
        $entry_ext = 'docx';
        $cleanup[] = $src;
    } else {
        return $r('no_content');
    }

    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $letter['tytul'] ?? 'Pismo');
    $entry_name = 'Pismo_' . $safe . '_' . date('Ymd') . '.' . $entry_ext;

    $zip_path = zip_encrypt_file($src, $entry_name, $password);
    foreach ($cleanup as $f) @unlink($f); // DOCX źródłowy już w ZIP
    if (!$zip_path) return $r('zip_failed');

    $org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $doc_title = $letter['tytul'] ?? 'Pismo do umowy';
    $subject   = "Pismo do umowy: {$doc_title}" . ($org ? " — {$org}" : '');
    $body_html = secure_doc_email_html($to_name, $doc_title, $org);
    $body_text = "Pismo: {$doc_title}\n\nW załączniku znajdziesz zaszyfrowany plik ZIP.\n"
               . "Hasło: ostatnie " . SECURE_PESEL_DIGITS . " cyfr Twojego numeru PESEL.\n\n{$org}";

    try {
        mail_queue_add(
            $to_email, $to_name, $subject, $body_html, $body_text,
            $type, $contract_id, '', false,
            [[
                'path' => 'temp_docs/' . basename($zip_path),
                'name' => basename($zip_path),
                'mime' => 'application/zip',
                'size' => filesize($zip_path),
            ]]
        );
        mail_queue_process(1);
    } catch (\Throwable $e) {
        @unlink($zip_path);
        return $r('mail_error:' . $e->getMessage());
    }

    // Log w historii umowy (best-effort).
    try {
        require_once __DIR__ . '/approval.php';
        log_contract_action($type, $contract_id, 0, 'note',
            "Pismo \"{$doc_title}\" wysłano e-mailem (zaszyfrowany ZIP) na {$to_email} — brak aktywnego konta.");
    } catch (\Throwable $e) {}

    return $r('ok', true, $to_email);
}
