<?php
/**
 * cli/seedDokumentacjaDydaktyk.php — testowy użytkownik do robienia dokumentacji (zrzuty ekranu):
 * DYDAKTYK w panelu TI i równocześnie ZLECENIOBIORCA z umową zlecenie.
 *
 * Użycie:
 *   php cli/seedDokumentacjaDydaktyk.php status [domena]
 *   php cli/seedDokumentacjaDydaktyk.php create '<haslo>' [domena]
 *   php cli/seedDokumentacjaDydaktyk.php delete [domena]
 *
 * create zakłada (idempotentnie — ponowne uruchomienie uzupełnia brakujące elementy, hasła nie nadpisuje):
 *   - konto lokalne „Anna Przykładowa” <dokumentacja.dydaktyk@domena> (rola viewer + k30_consultant — dydaktyk),
 *   - kartę w rejestrze Osób (dane FIKCYJNE: adres, PESEL z poprawną sumą kontrolną, rachunek z poprawnym IBAN),
 *   - umowę zlecenie „DOK/TEST/1” w realizacji (prowadzenie zajęć TI, stawka godzinowa, dydaktyka_udostepnij,
 *     prowadzący w e-dzienniku = to konto) — bez numeru rejestru RU, PDF, M365, CRM i wiadomości,
 *   - kurs „Kurs przykładowy (DOK)” z tym prowadzącym.
 * Domena domyślnie feer.test (RFC 2606) — żaden e-mail nie wyjdzie. Telefonu nie ustawiamy, więc nie pójdzie SMS.
 *
 * delete usuwa WYŁĄCZNIE dane oznaczone znacznikiem seedera (SEED_MARK) i to konto: kursy (DOK) tego prowadzącego
 * z zapisami i lekcjami, umowy DOK/TEST/*, kartę osoby, a na końcu konto przez user_delete_execute()
 * (dane powiązane: CASCADE / SET NULL jak w panelu administratora).
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/approval.php';
require_once $base . '/includes/user_delete.php';
if (is_file($base . '/includes/zlecenie_rachunki.php')) require_once $base . '/includes/zlecenie_rachunki.php'; // samonaprawa kolumn umowy zlecenie

const SDD_NAME   = 'Anna Przykładowa';
const SDD_LOCAL  = 'dokumentacja.dydaktyk';
const SDD_MARK   = 'SEED:dokumentacja_dydaktyk';
const SDD_NUMER  = 'DOK/TEST/1';
const SDD_COURSE = 'Kurs przykładowy (DOK)';

$usage = "Użycie:\n"
       . "  php cli/seedDokumentacjaDydaktyk.php status [domena]\n"
       . "  php cli/seedDokumentacjaDydaktyk.php create '<haslo>' [domena]\n"
       . "  php cli/seedDokumentacjaDydaktyk.php delete [domena]\n";

$cmd    = $argv[1] ?? '';
$domain = $cmd === 'create' ? ($argv[3] ?? 'feer.test') : ($argv[2] ?? 'feer.test');
if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) { fwrite(STDERR, "Zła domena: {$domain}\n"); cli_exit(2); }
$email = SDD_LOCAL . '@' . $domain;

/** Kolumny tabeli (zapis tylko istniejących — schemat różni się między instalacjami). */
function sddCols(string $table): array {
    try { return array_column(db_all("PRAGMA table_info({$table})"), 'name'); } catch (\Throwable $e) { return []; }
}
function sddInsert(string $table, array $data): int {
    return (int)db_insert($table, array_intersect_key($data, array_flip(sddCols($table))));
}
/** PESEL fikcyjny z poprawną sumą kontrolną (1990-01-01, kobieta). */
function sddPesel(): string {
    $d = '9001011234'; $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3]; $s = 0;
    for ($i = 0; $i < 10; $i++) $s += (int)$d[$i] * $w[$i];
    return $d . ((10 - $s % 10) % 10);
}
/** Rachunek fikcyjny z poprawną sumą kontrolną IBAN (bank 1090 1014, numer konta 0000 0000 0000 0001). */
function sddIban(): string {
    $bban = '10901014' . '0000000000000001';
    $num = $bban . '2521' . '00';                           // PL = 25 21
    $mod = 0;
    foreach (str_split($num, 7) as $c) $mod = (int)(($mod . $c) % 97);
    $cd = str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT);
    return trim(chunk_split('PL' . $cd . $bban, 4, ' '));
}

function sddState(string $email): array {
    $u = db_one("SELECT id, name, email, role, k30_consultant FROM users WHERE lower(email) = lower(?)", [$email]);
    $p = db_one("SELECT id, imie_nazwisko FROM persons WHERE lower(email) = lower(?) AND uwagi LIKE ?", [$email, '%' . SDD_MARK . '%']);
    $c = $u ? db_all("SELECT id, numer_umowy, status FROM umowy_zlecenie WHERE numer_umowy LIKE 'DOK/TEST/%' AND (ti_instructor_user_id = ? OR lower(email) = lower(?))", [(int)$u['id'], $email]) : [];
    $k = $u ? db_all("SELECT id, name FROM k30_ti_courses WHERE name = ? AND instructor_id = ?", [SDD_COURSE, (int)$u['id']]) : [];
    return ['user' => $u, 'person' => $p, 'contracts' => $c, 'courses' => $k];
}

switch ($cmd) {
    case 'status':
        $s = sddState($email);
        echo 'Konto:   ' . ($s['user'] ? "#{$s['user']['id']} {$s['user']['name']} <{$s['user']['email']}> rola={$s['user']['role']} dydaktyk=" . ((int)$s['user']['k30_consultant'] ? 'tak' : 'NIE') : "brak ({$email})") . "\n";
        echo 'Osoba:   ' . ($s['person'] ? "#{$s['person']['id']} {$s['person']['imie_nazwisko']}" : 'brak') . "\n";
        echo 'Umowy:   ' . ($s['contracts'] ? implode(', ', array_map(fn($c) => "#{$c['id']} {$c['numer_umowy']} ({$c['status']})", $s['contracts'])) : 'brak') . "\n";
        echo 'Kursy:   ' . ($s['courses'] ? implode(', ', array_map(fn($c) => "#{$c['id']} {$c['name']}", $s['courses'])) : 'brak') . "\n";
        cli_exit(0);

    case 'create':
        $password = $argv[2] ?? '';
        if (strlen($password) < 8) { fwrite(STDERR, "Podaj hasło (min. 8 znaków).\n" . $usage); cli_exit(2); }
        $now = date('Y-m-d H:i:s');

        // ── Konto ──────────────────────────────────────────────────────────
        $u = db_one("SELECT id, name FROM users WHERE lower(email) = lower(?)", [$email]);
        if ($u && $u['name'] !== SDD_NAME) {
            fwrite(STDERR, "Konto {$email} istnieje, ale nie jest testowe (nazwa „{$u['name']}”) — nie ruszam.\n");
            cli_exit(1);
        }
        if ($u) {
            $uid = (int)$u['id'];
            db()->prepare("UPDATE users SET is_active = 1, k30_consultant = 1 WHERE id = ?")->execute([$uid]);
            echo "ISTNIEJE konto #{$uid} {$email} — hasło bez zmian.\n";
        } else {
            $uid = sddInsert('users', [
                'name' => SDD_NAME, 'first_name' => 'Anna', 'last_name' => 'Przykładowa', 'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'viewer', 'is_active' => 1,
                'k30_consultant' => 1, 'must_change_password' => 0, 'created_at' => $now,
            ]);
            echo "OK konto #{$uid} — login: {$email}\n";
        }

        // ── Osoba (rejestr Osób) ───────────────────────────────────────────
        $p = db_one("SELECT id FROM persons WHERE lower(email) = lower(?)", [$email]);
        if ($p) {
            $pid = (int)$p['id'];
            echo "ISTNIEJE osoba #{$pid}.\n";
        } else {
            $pid = sddInsert('persons', [
                'imie_nazwisko' => SDD_NAME, 'email' => $email, 'telefon' => '', 'pesel' => sddPesel(), 'data_urodzenia' => '1990-01-01',
                'addr_street' => 'ul. Przykładowa', 'addr_house' => '1', 'addr_flat' => '2', 'addr_postal' => '00-001', 'addr_city' => 'Warszawa', 'addr_country' => 'PL',
                'adres' => 'ul. Przykładowa 1/2, 00-001 Warszawa', 'urzad_skarbowy' => 'Urząd Skarbowy Warszawa-Śródmieście',
                'rachunek_bankowy' => sddIban(), 'uwagi' => SDD_MARK . ' — dane fikcyjne do dokumentacji (cli/seedDokumentacjaDydaktyk.php).',
                'created_by' => $uid, 'created_at' => $now,
            ]);
            echo "OK osoba #{$pid} (dane fikcyjne)\n";
        }

        // ── Umowa zlecenie ─────────────────────────────────────────────────
        $c = db_one("SELECT id FROM umowy_zlecenie WHERE numer_umowy = ? AND (person_id = ? OR lower(email) = lower(?))", [SDD_NUMER, $pid, $email]);
        if ($c) {
            echo "ISTNIEJE umowa #{$c['id']} " . SDD_NUMER . ".\n";
        } else {
            $start = date('Y-m-01', strtotime('-1 month'));
            $cid = sddInsert('umowy_zlecenie', [
                'numer_umowy' => SDD_NUMER, 'status' => 'w realizacji', 'imie_nazwisko' => SDD_NAME, 'person_id' => $pid,
                'email' => $email, 'm365_login' => $email,              // panel współpracownika wiąże umowy zlecenie po m365_login
                'pesel' => sddPesel(), 'adres' => 'ul. Przykładowa 1/2, 00-001 Warszawa',
                'addr_street' => 'ul. Przykładowa', 'addr_house' => '1', 'addr_flat' => '2', 'addr_postal' => '00-001', 'addr_city' => 'Warszawa', 'addr_country' => 'PL',
                'urzad_skarbowy' => 'Urząd Skarbowy Warszawa-Śródmieście', 'rachunek_bankowy' => sddIban(),
                'przedmiot_zlecenia' => 'Prowadzenie zajęć z technologii informacyjnej (dane testowe do dokumentacji)',
                'data_zawarcia' => $start, 'data_rozpoczecia' => $start, 'data_zakonczenia' => date('Y-12-31'), 'bezterminowa' => 0,
                'typ_stawki' => 'godzinowa', 'stawka_kwota' => 80, 'forma_podpisania' => 'elektroniczna', 'wymagany_rachunek' => 1,
                'nr_rejestru' => '', 'zrodlo_godzin' => 'karty30', 'ti_instructor_user_id' => $uid, 'dydaktyka_udostepnij' => 1,
                'uwagi' => SDD_MARK . ' — umowa testowa do dokumentacji, poza rejestrem umów (bez RU, PDF, M365).',
                'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
            ]);
            echo "OK umowa zlecenie #{$cid} " . SDD_NUMER . " (w realizacji, od {$start})\n";
        }

        // ── Kurs TI z tym prowadzącym ──────────────────────────────────────
        $k = db_one("SELECT id FROM k30_ti_courses WHERE name = ? AND instructor_id = ?", [SDD_COURSE, $uid]);
        if ($k) {
            echo "ISTNIEJE kurs #{$k['id']}.\n";
        } else {
            $kid = sddInsert('k30_ti_courses', [
                'name' => SDD_COURSE, 'description' => SDD_MARK . ' — kurs testowy do dokumentacji panelu dydaktyka.',
                'internal_desc' => SDD_MARK, 'instructor_id' => $uid, 'day_of_week' => 3, 'time_from' => '16:00', 'time_to' => '17:30',
                'duration_min' => 90, 'location' => 'Sala 1 (przykładowa)', 'is_active' => 1, 'status' => 'active', 'created_by' => $uid, 'created_at' => $now,
            ]);
            echo "OK kurs #{$kid} " . SDD_COURSE . "\n";
        }
        echo "\nGotowe. Logowanie: " . APP_URL . " — {$email}. Panel dydaktyka przy pierwszym wejściu poprosi o ustawienie aplikacji uwierzytelniającej.\n";
        cli_exit(0);

    case 'delete':
        $s = sddState($email);
        if (!$s['user'] && !$s['person']) { echo "Brak danych do usunięcia ({$email}).\n"; cli_exit(0); }
        if ($s['user'] && $s['user']['name'] !== SDD_NAME) {
            fwrite(STDERR, "Konto {$email} nie jest testowe (nazwa „{$s['user']['name']}”) — nie ruszam.\n");
            cli_exit(1);
        }
        foreach ($s['courses'] as $k) {
            foreach (['k30_ti_lessons', 'k30_ti_enrollments'] as $t) {
                try { db()->prepare("DELETE FROM {$t} WHERE course_id = ?")->execute([(int)$k['id']]); } catch (\Throwable $e) {}
            }
            db()->prepare("DELETE FROM k30_ti_courses WHERE id = ?")->execute([(int)$k['id']]);
            echo "USUNIĘTO kurs #{$k['id']}\n";
        }
        foreach ($s['contracts'] as $c) {
            db()->prepare("DELETE FROM umowy_zlecenie WHERE id = ? AND numer_umowy LIKE 'DOK/TEST/%'")->execute([(int)$c['id']]);
            echo "USUNIĘTO umowę #{$c['id']} {$c['numer_umowy']}\n";
        }
        if ($s['person']) {
            db()->prepare("DELETE FROM persons WHERE id = ? AND uwagi LIKE ?")->execute([(int)$s['person']['id'], '%' . SDD_MARK . '%']);
            echo "USUNIĘTO osobę #{$s['person']['id']}\n";
        }
        if ($s['user']) {
            $r = user_delete_execute((int)$s['user']['id'], 0, 'Seeder dokumentacji — usunięcie danych testowych');
            echo ($r['ok'] ? 'USUNIĘTO' : 'BŁĄD') . " konto #{$s['user']['id']}: " . ($r['msg'] ?? '') . "\n";
            if (!$r['ok']) cli_exit(1);
        }
        cli_exit(0);

    default:
        fwrite(STDERR, $usage);
        cli_exit(2);
}
