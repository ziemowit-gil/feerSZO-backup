<?php
/** Wysyłka danych logowania (konto M365) na e-mail alternatywny — treść edytowalna przed wysyłką. */

const MS_EDU_BULK_MAIL_SUBJECT = 'Twoje konto Microsoft 365 — dane logowania';
const MS_EDU_BULK_MAIL_BODY = "Dzień dobry {imie},\n\nzałożyliśmy dla Ciebie konto Microsoft 365. Dane do pierwszego logowania:\n\nLogin: {login}\nHasło tymczasowe: {haslo}\n\nZaloguj się na https://www.office.com — przy pierwszym logowaniu system poprosi o ustawienie własnego hasła.\nNie udostępniaj tych danych innym osobom.\n\nPozdrawiamy\n{organizacja}";

const MS_EDU_BULK_RESET_MAIL_SUBJECT = 'Nowe hasło do konta Microsoft 365';
const MS_EDU_BULK_RESET_MAIL_BODY = "Dzień dobry {imie},\n\nz przyczyn technicznych nadaliśmy nowe hasło dla konta Microsoft 365 w Edukacji:\n\nLogin: {login}\nNowe hasło: {haslo}\n\nZaloguj się na https://www.office.com — przy pierwszym logowaniu system poprosi o ustawienie własnego hasła. Poprzednie hasło przestało działać.\nNie udostępniaj tych danych innym osobom.\n\nPozdrawiamy\n{organizacja}";

const MS_EDU_BULK_MAIL_VARS = ['{imie}', '{nazwisko}', '{login}', '{haslo}', '{grupa}', '{szkolenie}', '{organizacja}'];

/** Podstawia zmienne; zwraca [temat, html, tekst]. Login i hasło w HTML idą jako <code>. */
function msEduBulkMailRender(string $subject, string $body, array $row, string $szkolenie = ''): array {
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $vals = ['{imie}' => $row['imie'] ?? '', '{nazwisko}' => $row['nazwisko'] ?? '', '{login}' => $row['upn'] ?? '',
             '{haslo}' => $row['password'] ?? '', '{klasa}' => $row['klasa'] ?? '', '{grupa}' => $row['grupa'] ?? ($row['klasa'] ?? ''), '{szkolenie}' => $szkolenie, '{organizacja}' => $org];
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $text = strtr($body, $vals);
    $htmlVals = $vals;
    foreach (['{login}', '{haslo}'] as $k) $htmlVals[$k] = '<code style="font-size:14px;background:#f1f5f9;padding:1px 5px">' . $e($vals[$k]) . '</code>';
    foreach (['{imie}', '{nazwisko}', '{klasa}', '{grupa}', '{szkolenie}', '{organizacja}'] as $k) $htmlVals[$k] = $e($vals[$k]);
    // Najpierw zamiana na znaczniki, potem escapowanie reszty treści szablonu
    $parts = preg_split('/(\{[a-z]+\})/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts as $p) $html .= isset($htmlVals[$p]) ? $htmlVals[$p] : $e($p);
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">' . nl2br($html, false) . '</div>';
    return [strtr($subject, $vals), $html, $text];
}

/**
 * Kolejkuje wiadomości (mail_queue). Pomija wiersze już wysłane i bez poprawnego e-maila.
 * @param array $rows wiersze z kluczami email, imie, nazwisko, upn, password, [sent]
 * @param array|null $only indeksy wierszy do wysyłki (null = wszystkie niewysłane)
 * @return array{rows:array,sent:int,failed:string[]}
 */
function msEduBulkMailSend(array $rows, string $subject, string $body, string $szkolenie = '', ?array $only = null): array {
    if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $sent = 0; $failed = [];
    foreach ($rows as $i => &$r) {
        if ($only !== null && !in_array($i, $only, true)) continue;
        if (!empty($r['sent'])) continue;
        $to = trim((string)($r['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed[] = ($r['imie'] ?? '') . ' ' . ($r['nazwisko'] ?? '') . ': brak e-maila'; continue; }
        [$sub, $html, $text] = msEduBulkMailRender($subject, $body, $r, $szkolenie);
        try {
            mail_queue_add($to, trim(($r['imie'] ?? '') . ' ' . ($r['nazwisko'] ?? '')), $sub, $html, $text, 'ms_edu_bulk', null, '', true);
            $r['sent'] = date('Y-m-d H:i:s'); $sent++;
        } catch (\Throwable $ex) {
            $failed[] = $to . ': ' . $ex->getMessage();
        }
    }
    unset($r);
    return ['rows' => $rows, 'sent' => $sent, 'failed' => $failed];
}
