<?php
/**
 * modules/ti/logic/nabor/naborAccounts.php — konta dla przyjętego z naboru: panel uczestnika + dostęp rodzica.
 *
 * Przy przyjęciu (naborChangeStatus → naborCreateAccounts):
 *   • konto panelu uczestnika (k30_ti_student_accounts) — TiSimpleGroups::createAccount (login j.kowalski, hasło
 *     jednorazowe, numer uczestnika, rachunek wirtualny); małoletni → is_minor=1 i guardian_name/phone/email z naboru;
 *   • konto rodzica (parent_login z dopiskiem „-r”, hasło jednorazowe, parent_must_change=1) — tylko dla małoletniego,
 *     jak „Utwórz konto rodzica” w dydaktyk/konta.php.
 * Istniejące konto nie jest nadpisywane (uzupełniane są tylko puste dane opiekuna).
 * Dane logowania: SMS na telefon opiekuna (rodzaj access_data), e-mail z adresami logowania i loginami (BEZ haseł),
 * a hasła jednorazowo dla kierownika na karcie zgłoszenia ($_SESSION['nabor_creds']). Zgłoszenia testowe — pomijane.
 */
require_once __DIR__ . '/nabor.php';
require_once dirname(__DIR__, 3) . '/ti_simple_groups/logic/simpleGroups.php';

/** @return array{child:?array, parent:?array, notes:string[]} hasła tylko w wyniku (w bazie same skróty) */
function naborCreateAccounts(int $sid, int $uid): array {
    $out = ['child' => null, 'parent' => null, 'notes' => []];
    $s = naborSubmissionGet($sid);
    if (!$s || !empty($s['is_test']) || !$s['client_id']) return $out;
    $cid   = (int)$s['client_id'];
    $minor = naborIsMinor($s);

    $child = TiSimpleGroups::createAccount($cid, $uid, false);
    if ($child) $out['child'] = ['login' => $child['login'], 'password' => $child['password']];
    else $out['notes'][] = 'Uczestnik miał już konto panelu — bez zmian.';

    $acc = db_one("SELECT id, guardian_name, guardian_phone, guardian_email, parent_login FROM k30_ti_student_accounts WHERE client_id=?", [$cid]);
    if (!$acc) return $out;
    if ($minor) {
        db_exec("UPDATE k30_ti_student_accounts SET is_minor=1,
                    guardian_name  = CASE WHEN COALESCE(guardian_name,'')=''  THEN ? ELSE guardian_name  END,
                    guardian_phone = CASE WHEN COALESCE(guardian_phone,'')='' THEN ? ELSE guardian_phone END,
                    guardian_email = CASE WHEN COALESCE(guardian_email,'')='' THEN ? ELSE guardian_email END,
                    updated_at=datetime('now') WHERE id=?",
                [$s['guardian_name'], '+' . $s['guardian_phone'], $s['guardian_email'], $acc['id']]);
        if (trim((string)$acc['parent_login']) === '') {
            $base = naborLoginBase($s['participant_name']) . '-r';
            $login = $base; $i = 2;
            while (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE parent_login=?", [$login])) $login = $base . $i++;
            $pass = naborOneTimePassword();
            db_exec("UPDATE k30_ti_student_accounts SET parent_login=?, parent_password_hash=?, parent_must_change=1, updated_at=datetime('now') WHERE id=?",
                    [$login, password_hash($pass, PASSWORD_BCRYPT), $acc['id']]);
            $out['parent'] = ['login' => $login, 'password' => $pass];
        } else {
            $out['notes'][] = 'Konto rodzica już istniało (login ' . $acc['parent_login'] . ').';
        }
    }
    return $out;
}

/** Małoletni: data urodzenia z PESEL albo z pola daty; nieznana → traktujemy jak małoletniego (formularz jest dla rodziców). */
function naborIsMinor(array $s): bool {
    if (($s['applicant_type'] ?? 'guardian') === 'self') return false;   // osoba pełnoletnia zgłosiła siebie — bez konta rodzica
    $v   = trim((string)$s['participant_birth_date_or_pesel']);
    $dob = preg_match('/^\d{11}$/', $v) ? naborPeselBirthDate($v) : naborDateIso($v);
    if (!$dob) return true;
    return (new DateTime($dob))->diff(new DateTime('today'))->y < 18;
}

function naborLoginBase(string $name): string {
    $n = strtr($name, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                       'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
    $p = preg_split('/\s+/', trim($n)) ?: [''];
    $l = preg_replace('/[^a-z0-9._-]/', '', strtolower(count($p) >= 2 ? $p[0][0] . '.' . end($p) : $p[0]));
    return $l !== '' ? $l : 'uczestnik';
}

/** Hasło jednorazowe w stylu panelu (słowo + cyfry) — zmieniane przy pierwszym logowaniu. */
function naborOneTimePassword(): string {
    $w = ['Kot', 'Pies', 'Dom', 'Las', 'Rok', 'Nos', 'Byk', 'Lis', 'Mak', 'Rak', 'Sowa', 'Jez'];
    return $w[random_int(0, count($w) - 1)] . random_int(100, 999);
}

/**
 * Wysyłka danych logowania opiekunowi: SMS z loginami i hasłami (access_data) + e-mail z adresami i loginami.
 * Zwraca opis do historii zgłoszenia.
 */
function naborSendAccounts(array $s, array $acc): string {
    if (!$acc['child'] && !$acc['parent']) return '';
    require_once __DIR__ . '/naborNotify.php';
    require_once dirname(__DIR__, 4) . '/includes/sms.php';
    $base = naborBaseUrl() . '/karty30/ti/kursant/';
    $log  = [];

    $sms = [];
    if ($acc['child'])  $sms[] = 'Panel uczestnika: login ' . $acc['child']['login'] . ', haslo ' . $acc['child']['password'];
    if ($acc['parent']) $sms[] = 'Panel rodzica: login ' . $acc['parent']['login'] . ', haslo ' . $acc['parent']['password'];
    try {
        require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
        if (smsPreferredReady(naborSmsProvider())) {
            $via = naborSms($s['guardian_phone'], naborOrgName() . ' - ' . implode('. ', $sms) . '. Haslo zmienisz przy 1. logowaniu.', 'access_data');
            $log[] = 'SMS z hasłami (' . sms_provider_label($via ?: '—') . ')';
        } else {
            $log[] = 'SMS wyłączony — przekaż hasła ręcznie';
        }
    } catch (\Throwable $e) {
        $log[] = 'SMS: błąd (' . $e->getMessage() . ') — przekaż hasła ręcznie';
    }

    $rows = '';
    if ($acc['child'])  $rows .= '<li><strong>Panel uczestnika</strong> (plan zajęć, materiały): <a href="' . h($base . 'login.php') . '">' . h($base . 'login.php') . '</a>, login <strong>' . h($acc['child']['login']) . '</strong></li>';
    if ($acc['parent']) $rows .= '<li><strong>Panel rodzica</strong> (frekwencja, oceny, płatności): <a href="' . h($base . 'parent.php?m=pwd') . '">' . h($base . 'parent.php') . '</a>, login <strong>' . h($acc['parent']['login']) . '</strong></li>';
    $body = '<p>Dzień dobry,</p><p>dla <strong>' . h($s['participant_name']) . '</strong> założyliśmy dostęp do panelu zajęć:</p><ul>' . $rows . '</ul>'
          . '<p>Hasła jednorazowe wysłaliśmy SMS-em na numer +' . h($s['guardian_phone']) . '. Przy pierwszym logowaniu system poprosi o ustawienie własnego hasła.</p>';
    try {
        mail_queue_add($s['guardian_email'], $s['guardian_name'], 'Dostęp do panelu zajęć — ' . $s['participant_name'],
                       naborMailHtml('Dostęp do panelu zajęć', $body), '', 'ti_nabor', (int)$s['id']);
        $log[] = 'e-mail z loginami';
    } catch (\Throwable $e) {
        $log[] = 'e-mail: błąd (' . $e->getMessage() . ')';
    }
    return implode(', ', $log);
}
