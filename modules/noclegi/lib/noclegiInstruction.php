<?php
/**
 * modules/noclegi/lib/noclegiInstruction.php — filar 4: instrukcja zameldowania (adres, mapa, godziny, pokój, kod / skrytka).
 *
 * Kod do drzwi i skrytka ujawniają się osobie dopiero w oknie [przyjazd − nocl_code_reveal_days, wyjazd] (ustawienie
 * org_setting, domyślnie 2 dni); Logistyk widzi je zawsze. E-maile idą przez kolejkę mail_queue (nie wprost):
 *  • przy potwierdzeniu — informacja bez kodu (ustawienie nocl_mail_on_confirm = 0 wyłącza),
 *  • przypomnienie z pełną instrukcją w oknie ujawnienia — cron/agents/noclegiInstructions.php.
 * W testach można podstawić nadawcę: $GLOBALS['NOCL_MAILER'] = fn($to,$name,$subject,$html,$id) => …;
 */
require_once __DIR__ . '/noclegiRooming.php';

function noclRevealDays(): int {
    $v = trim(org_setting('nocl_code_reveal_days'));
    return $v !== '' && ctype_digit($v) ? min(30, (int)$v) : 2;
}

function noclCodesVisible(array $r, int $uid): bool {
    if (noclIsLogistyk($uid)) return true;
    if (!in_array($r['status'], ['potwierdzone', 'zrealizowane'], true)) return false;
    $today = date('Y-m-d');
    return $today >= date('Y-m-d', strtotime($r['date_from'] . ' -' . noclRevealDays() . ' days')) && $today <= $r['date_to'];
}

function noclMapUrl(array $fac): string {
    if (preg_match('#^https://#i', (string)$fac['map_url'])) return $fac['map_url'];
    $q = trim($fac['address'] . ' ' . $fac['city']);
    return $q === '' ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}

/** Dane instrukcji dla zgłoszenia (null gdy brak przydziału). */
function noclInstruction(array $r, bool $withCodes): ?array {
    if (!(int)$r['room_id']) return null;
    $room = noclRoomGet((int)$r['room_id']);
    $fac = $room ? noclFacilityGet((int)$room['facility_id']) : null;
    if (!$room || !$fac) return null;
    return [
        'facility' => $fac, 'room' => $room['name'], 'room_type' => noclTypyPokoi()[$room['room_type']] ?? $room['room_type'],
        'arrive' => $r['date_from'], 'leave' => $r['date_to'], 'nights' => noclNights($r['date_from'], $r['date_to']),
        'checkin' => $fac['checkin_from'] . '–' . $fac['checkin_to'], 'checkout' => $fac['checkout_until'],
        'address' => trim($fac['address'] . ($fac['city'] !== '' ? ', ' . $fac['city'] : ''), ', '), 'map' => noclMapUrl($fac),
        'phone' => $fac['phone'], 'access_info' => $fac['access_info'], 'rules' => $fac['rules'],
        'accessible' => (bool)$room['accessible'], 'accessibility_info' => $fac['accessibility_info'],
        'codes' => $withCodes, 'door_code' => $withCodes ? $room['door_code'] : '', 'lockbox' => $withCodes ? $room['lockbox'] : '',
        'reveal_from' => date('Y-m-d', strtotime($r['date_from'] . ' -' . noclRevealDays() . ' days')),
    ];
}

function noclInstructionHtml(array $ins, string $personName): string {
    $d = fn(string $x) => date('d.m.Y', strtotime($x));
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $o = '<p>Dzień dobry' . ($personName !== '' ? ', ' . $h($personName) : '') . '!</p><p>Instrukcja zameldowania:</p><ul>';
    $o .= '<li><strong>Obiekt:</strong> ' . $h($ins['facility']['name']) . ($ins['address'] !== '' ? ', ' . $h($ins['address']) : '') . '</li>';
    if ($ins['map'] !== '') $o .= '<li><strong>Mapa:</strong> <a href="' . $h($ins['map']) . '">otwórz mapę</a></li>';
    $o .= '<li><strong>Termin:</strong> przyjazd ' . $d($ins['arrive']) . ', wyjazd ' . $d($ins['leave']) . ' (' . (int)$ins['nights'] . ' ' . ((int)$ins['nights'] === 1 ? 'noc' : 'noce/nocy') . ')</li>';
    $o .= '<li><strong>Zameldowanie:</strong> ' . $h($ins['checkin']) . '; <strong>wymeldowanie</strong> do ' . $h($ins['checkout']) . '</li>';
    $o .= '<li><strong>Pokój:</strong> ' . $h($ins['room']) . ' (' . $h($ins['room_type']) . ')' . ($ins['accessible'] ? ', pokój dostosowany dla osób z niepełnosprawnościami' : '') . '</li>';
    if ($ins['phone'] !== '') $o .= '<li><strong>Kontakt do obiektu:</strong> ' . $h($ins['phone']) . '</li>';
    if ($ins['codes']) {
        if ($ins['door_code'] !== '') $o .= '<li><strong>Kod do drzwi:</strong> ' . $h($ins['door_code']) . '</li>';
        if ($ins['lockbox'] !== '') $o .= '<li><strong>Skrytka na klucz:</strong> ' . $h($ins['lockbox']) . '</li>';
    } else {
        $o .= '<li><strong>Kod do drzwi / skrytka:</strong> otrzymasz je w panelu i e-mailem od ' . $d($ins['reveal_from']) . '.</li>';
    }
    $o .= '</ul>';
    if ($ins['access_info'] !== '') $o .= '<p><strong>Jak wejść:</strong><br>' . nl2br($h($ins['access_info'])) . '</p>';
    if ($ins['rules'] !== '') $o .= '<p><strong>Zasady pobytu:</strong><br>' . nl2br($h($ins['rules'])) . '</p>';
    return $o . '<p>Zmiany i pytania: panel Noclegi w SZO.</p>';
}

function noclMail(string $to, string $name, string $subject, string $html, int $requestId): bool {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (isset($GLOBALS['NOCL_MAILER']) && is_callable($GLOBALS['NOCL_MAILER'])) return (bool)($GLOBALS['NOCL_MAILER'])($to, $name, $subject, $html, $requestId);
    if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    return mail_queue_add($to, $name, $subject, $html, '', 'noclegi', $requestId, '', false) > 0;
}

/** E-mail po potwierdzeniu (bez kodów). Pomija, gdy osoba nie ma adresu albo wyłączono ustawienie. */
function noclSendConfirmation(int $requestId): bool {
    if (trim(org_setting('nocl_mail_on_confirm')) === '0') return false;
    $r = noclRequestGet($requestId);
    if (!$r || $r['confirm_mail_at']) return false;
    $mail = noclPersonEmail($r);
    $ins = noclInstruction($r, false);
    if ($mail === '' || !$ins) return false;
    $ok = noclMail($mail, $r['person_name'], 'Nocleg potwierdzony: ' . $ins['facility']['name'] . ', ' . date('d.m.Y', strtotime($r['date_from'])), noclInstructionHtml($ins, $r['person_name']), $requestId);
    if ($ok) {
        db()->prepare("UPDATE nocl_requests SET confirm_mail_at = ? WHERE id = ?")->execute([noclNow(), $requestId]);
        noclLog($requestId, 'mail', 'wysłano potwierdzenie na ' . $mail, 'request', 0);
    }
    return $ok;
}

/** Pełna instrukcja z kodami (przypomnienie w oknie ujawnienia lub ręczna wysyłka Logistyka). */
function noclSendInstruction(int $requestId, bool $force = false): bool {
    $r = noclRequestGet($requestId);
    if (!$r || $r['status'] !== 'potwierdzone') return false;
    if (!$force && $r['reminder_at']) return false;
    $mail = noclPersonEmail($r);
    $ins = noclInstruction($r, true);
    if ($mail === '' || !$ins) return false;
    $ok = noclMail($mail, $r['person_name'], 'Instrukcja zameldowania: ' . $ins['facility']['name'] . ', ' . date('d.m.Y', strtotime($r['date_from'])), noclInstructionHtml($ins, $r['person_name']), $requestId);
    if ($ok) {
        db()->prepare("UPDATE nocl_requests SET reminder_at = ? WHERE id = ?")->execute([noclNow(), $requestId]);
        noclLog($requestId, 'mail', 'wysłano instrukcję zameldowania na ' . $mail, 'request', 0);
    }
    return $ok;
}

function noclPersonEmail(array $r): string {
    if ($r['person_email'] !== '') return $r['person_email'];
    if ((int)$r['user_id']) return (string)(db_one("SELECT email FROM users WHERE id = ?", [(int)$r['user_id']])['email'] ?? '');
    return '';
}

/** Zgłoszenia, którym należy teraz wysłać instrukcję z kodami: potwierdzone, w oknie ujawnienia, bez wysłanej instrukcji. */
function noclDueInstructions(): array {
    $until = date('Y-m-d', strtotime('+' . noclRevealDays() . ' days'));
    return db_all("SELECT id FROM nocl_requests WHERE status = 'potwierdzone' AND reminder_at IS NULL AND date_from <= ? AND date_to >= ?", [$until, date('Y-m-d')]);
}
