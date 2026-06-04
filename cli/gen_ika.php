#!/usr/bin/env php
<?php
/**
 * cli/gen_ika.php — Generowanie i zarządzanie kodami IKA dla dowolnego konta.
 *
 * Użycie:
 *   php cli/gen_ika.php --list                          # lista wszystkich użytkowników
 *   php cli/gen_ika.php --list --role=admin             # filtruj po roli
 *   php cli/gen_ika.php --list --no-ika                 # tylko użytkownicy bez kodu
 *   php cli/gen_ika.php --show                          # lista z kodami IKA (UWAGA!)
 *   php cli/gen_ika.php --user=ID|email|"imię"         # generuj nowy kod IKA
 *   php cli/gen_ika.php --user=ID --code=123456         # ustaw konkretny kod IKA
 *   php cli/gen_ika.php --user=ID --password            # generuj losowe hasło do konta
 *   php cli/gen_ika.php --user=ID --password=MojeHaslo1 # ustaw konkretne hasło
 *   php cli/gen_ika.php --user=ID --show                # pokaż kod danego użytkownika
 *   php cli/gen_ika.php --user=ID --revoke              # unieważnij sesję IKA
 *   php cli/gen_ika.php --user=ID --unblock             # odblokuj (po 3 błędnych próbach)
 *   php cli/gen_ika.php --all                           # generuj dla wszystkich bez kodu
 *   php cli/gen_ika.php --all --role=admin              # dla wszystkich adminów bez kodu
 *   php cli/gen_ika.php --tenant=SLUG                   # baza tenanta
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("Tylko CLI.\n"); }

$opts = getopt('', ['list', 'show', 'user:', 'code:', 'password::', 'all', 'role:',
                    'no-ika', 'revoke', 'unblock', 'tenant:', 'yes', 'help']);

if (isset($opts['help'])) { echo <<<H
gen_ika.php — Zarządzanie kodami IKA/CPC dla dowolnych kont

TRYBY:
  --list              Lista użytkowników (bez kodów)
  --list --no-ika     Tylko użytkownicy bez przypisanego kodu IKA
  --list --role=ROLA  Filtruj po roli (admin, editor, viewer, crm_user, ...)
  --show              Lista z kodami IKA w jawnej postaci [⚠ wrażliwe dane]
  --all               Generuj kody dla wszystkich bez IKA (lub + --role=)

DLA KONKRETNEGO UŻYTKOWNIKA:
  --user=ID           Wyszukaj po ID
  --user=email        Wyszukaj po adresie e-mail (pełny)
  --user="Imię Naz"   Wyszukaj po nazwie (fragment, case-insensitive)
  --code=123456       Ustaw konkretny 6-cyfrowy kod IKA (z --user=)
  --password          Wygeneruj losowe hasło do konta (14 znaków)
  --password=Hasło1   Ustaw konkretne hasło (min. 8 znaków)
  --show              Pokaż istniejący kod (z --user=)
  --revoke            Unieważnij sesję IKA — wymusi ponowną weryfikację
  --unblock           Odblokuj po 3 nieudanych próbach

INNE:
  --tenant=SLUG       Baza wybranego tenanta SaaS
  --yes               Pomiń interaktywne potwierdzenia

H;
    exit(0);
}

// ── Środowisko ────────────────────────────────────────────────────────────────
if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';
if (!isset($_SERVER['REQUEST_URI']))   $_SERVER['REQUEST_URI']   = '/';

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/cpc.php';

// Tryb tenanta
$tenant = $opts['tenant'] ?? null;
if ($tenant !== null) {
    $slug    = preg_replace('/[^a-z0-9]/', '', strtolower($tenant));
    $db_path = $root . '/tenants/' . $slug . '/umowy.db';
    if (!is_file($db_path)) { fwrite(STDERR, "Brak bazy tenanta '$slug'\n"); exit(1); }
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    function _db(): PDO { global $pdo; return $pdo; }
    function _all(string $sql, array $p=[]): array { $st=_db()->prepare($sql); $st->execute($p); return $st->fetchAll()??[]; }
    function _one(string $sql, array $p=[]): ?array { $st=_db()->prepare($sql); $st->execute($p); return $st->fetch()??null; }
    $db_label = "tenant:$slug";
} else {
    function _db(): PDO { return db(); }
    function _all(string $sql, array $p=[]): array { return db_all($sql, $p); }
    function _one(string $sql, array $p=[]): ?array { return db_one($sql, $p); }
    $db_label = 'główna baza';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function _c(string $s, string $c): string { return "\033[{$c}m{$s}\033[0m"; }
function _ok(string $s): void   { echo _c('✔ ',$s=str_pad($s,1)) . "\n"; echo "\033[32m✔ $s\033[0m\n"; }
function out(string $s): void  { echo $s . "\n"; }
function ok(string $s): void   { echo "\033[32m✔ $s\033[0m\n"; }
function err(string $s): void  { fwrite(STDERR, "\033[31m✘ $s\033[0m\n"); }
function warn(string $s): void { echo "\033[33m⚠ $s\033[0m\n"; }
function head(string $s): void { echo "\n\033[1;34m$s\033[0m\n" . str_repeat('─', min(strlen($s), 72)) . "\n"; }
function confirm(string $q): bool {
    echo "\033[33m$q [t/N]: \033[0m";
    return strtolower(trim(fgets(STDIN))) === 't';
}

function find_user(string $query): ?array {
    if (is_numeric($query) && (int)$query > 0) {
        return _one("SELECT id,name,email,role,cpc_code,cpc_fails,cpc_blocked_until,ika_revoked_at,is_active FROM users WHERE id=?", [(int)$query]);
    }
    if (str_contains($query, '@')) {
        return _one("SELECT id,name,email,role,cpc_code,cpc_fails,cpc_blocked_until,ika_revoked_at,is_active FROM users WHERE LOWER(email)=LOWER(?)", [$query]);
    }
    // Szukaj po nazwie
    $like = '%' . $query . '%';
    $rows = _all("SELECT id,name,email,role,cpc_code,cpc_fails,cpc_blocked_until,ika_revoked_at,is_active FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 5", [$like, $like]);
    if (count($rows) === 1) return $rows[0];
    if (count($rows) > 1) {
        out("Znaleziono kilku użytkowników pasujących do \"$query\":");
        foreach ($rows as $r) {
            printf("  [%d] %-30s %-35s %s\n", $r['id'], $r['name'], $r['email'], $r['role']);
        }
        out("Podaj --user=ID konkretnego użytkownika.");
        return null;
    }
    return null;
}

function user_row(array $u, bool $show_code = false): string {
    $blocked = !empty($u['cpc_blocked_until']) && strtotime($u['cpc_blocked_until']) > time();
    $revoked = !empty($u['ika_revoked_at']);
    $has_code = !empty($u['cpc_code']);
    $active  = $u['is_active'] ?? 1;

    $code_col = $show_code
        ? ($has_code ? "\033[32m" . str_pad($u['cpc_code'],8) . "\033[0m" : "\033[31m" . str_pad('BRAK',8) . "\033[0m")
        : ($has_code ? "\033[32m" . str_pad('●',8) . "\033[0m" : "\033[31m" . str_pad('–',8) . "\033[0m");

    $flags = '';
    if (!$active)  $flags .= "\033[2m[nieaktywny]\033[0m ";
    if ($blocked)  $flags .= "\033[31m[ZABLOKOWANY]\033[0m ";
    if ($revoked)  $flags .= "\033[33m[unieważniony]\033[0m ";

    return sprintf("[%4d] %-28s %-36s %-12s %s  %s",
        $u['id'],
        mb_substr($u['name'] ?? '?', 0, 28),
        mb_substr($u['email'] ?? '', 0, 36),
        str_pad($u['role'] ?? '', 12),
        $code_col,
        $flags
    );
}

function set_ika(int $uid, string $code): void {
    _db()->prepare("UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")->execute([$code, $uid]);
}

function ika_generate(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function gen_password(int $len = 14): string {
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $special = '!@#$%&*';
    $all     = $upper . $lower . $digits . $special;
    $pass    = $upper[random_int(0, strlen($upper)-1)]
             . $lower[random_int(0, strlen($lower)-1)]
             . $digits[random_int(0, strlen($digits)-1)]
             . $special[random_int(0, strlen($special)-1)];
    for ($i = 4; $i < $len; $i++) $pass .= $all[random_int(0, strlen($all)-1)];
    return str_shuffle($pass);
}

function set_password(int $uid, string $plain): void {
    $hash = password_hash($plain, PASSWORD_BCRYPT);
    _db()->prepare(
        "UPDATE users SET password=?, must_change_password=0, locked_until=NULL WHERE id=?"
    )->execute([$hash, $uid]);
}

// ── Główna logika ─────────────────────────────────────────────────────────────
$noask    = isset($opts['yes']);
$show_all = isset($opts['show']);
$role_flt = $opts['role'] ?? '';
$no_ika   = isset($opts['no-ika']);

// ── Tryb: konkretny użytkownik ────────────────────────────────────────────────
if (isset($opts['user'])) {
    $query = (string)$opts['user'];
    $u = find_user($query);
    if (!$u) { err("Nie znaleziono użytkownika: $query"); exit(1); }

    head("Użytkownik #{$u['id']} — {$u['name']} <{$u['email']}>");
    printf("  Rola:         %s\n", $u['role']);
    printf("  Aktywny:      %s\n", $u['is_active'] ? 'tak' : 'nie');
    printf("  Kod IKA:      %s\n", !empty($u['cpc_code']) ? ($show_all ? $u['cpc_code'] : '●●●●●● (użyj --show)') : 'BRAK');
    printf("  Błędy:        %d\n", (int)($u['cpc_fails'] ?? 0));

    if (!empty($u['cpc_blocked_until'])) {
        $bt = strtotime($u['cpc_blocked_until']);
        $msg = $bt > time() ? "ZABLOKOWANY do " . date('H:i:s d.m.Y', $bt) : "blokada wygasła";
        printf("  Blokada:      %s\n", $msg);
    }
    if (!empty($u['ika_revoked_at'])) {
        printf("  Unieważniony: %s\n", date('d.m.Y H:i', strtotime($u['ika_revoked_at'])));
    }
    out('');

    // --revoke
    if (isset($opts['revoke'])) {
        if ($noask || confirm("Unieważnić sesję IKA dla {$u['name']}?")) {
            _db()->prepare("UPDATE users SET ika_revoked_at=datetime('now','localtime') WHERE id=?")->execute([$u['id']]);
            ok("Sesja IKA unieważniona — użytkownik zostanie poproszony o ponowne podanie kodu.");
        }
        exit(0);
    }

    // --unblock
    if (isset($opts['unblock'])) {
        _db()->prepare("UPDATE users SET cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")->execute([$u['id']]);
        ok("Odblokowano konto {$u['name']}.");
        exit(0);
    }

    // --password [=wartość]
    if (array_key_exists('password', $opts)) {
        $plain = is_string($opts['password']) && $opts['password'] !== ''
            ? $opts['password']
            : gen_password();
        if (strlen($plain) < 8) { err("Hasło musi mieć co najmniej 8 znaków."); exit(1); }
        if ($noask || confirm("Ustawić nowe hasło dla {$u['name']} <{$u['email']}>?")) {
            set_password((int)$u['id'], $plain);
            out('');
            ok("Hasło zmienione!");
            printf("  Użytkownik:  %s <%s>\n", $u['name'], $u['email']);
            printf("  Nowe hasło:  \033[1;32m%s\033[0m\n", $plain);
            out('');
            warn("Przekaż hasło użytkownikowi bezpiecznym kanałem i poproś o zmianę po pierwszym logowaniu.");
        }
        exit(0);
    }

    // --show (bez generowania IKA)
    if ($show_all && !isset($opts['code']) && !$noask) {
        if (!empty($u['cpc_code'])) {
            warn("Kod IKA (JAWNY): " . $u['cpc_code']);
        } else {
            warn("Brak kodu IKA dla tego użytkownika.");
        }
        exit(0);
    }

    // Generuj / ustaw kod IKA
    $code = isset($opts['code']) ? (string)$opts['code'] : ika_generate();

    // Walidacja gdy ręczny kod
    if (isset($opts['code'])) {
        if (!preg_match('/^\d{4,10}$/', $code)) {
            err("Kod IKA musi składać się z 4–10 cyfr. Podano: $code"); exit(1);
        }
    }

    if ($noask || confirm("Ustawić kod IKA \033[1m$code\033[0m dla {$u['name']} <{$u['email']}>?")) {
        set_ika((int)$u['id'], $code);
        out('');
        ok("Kod IKA ustawiony!");
        printf("  Użytkownik:  %s <%s>\n", $u['name'], $u['email']);
        printf("  Rola:        %s\n",      $u['role']);
        printf("  Kod IKA:     \033[1;32m%s\033[0m\n", $code);
        out('');
        warn("Przekaż kod IKA użytkownikowi bezpiecznym kanałem (osobiście lub szyfrowanym mailem).");
    }
    exit(0);
}

// ── Tryb: --all ───────────────────────────────────────────────────────────────
if (isset($opts['all'])) {
    $where = "WHERE (cpc_code IS NULL OR cpc_code = '') AND is_active=1";
    $params = [];
    if ($role_flt) { $where .= " AND role=?"; $params[] = $role_flt; }

    $users = _all("SELECT id,name,email,role,cpc_code,is_active FROM users $where ORDER BY role,name", $params);

    if (!$users) { ok("Wszyscy użytkownicy mają już kody IKA."); exit(0); }

    head("Generowanie IKA dla " . count($users) . " użytkowników bez kodu" . ($role_flt ? " [rola: $role_flt]" : ''));
    foreach ($users as $u) {
        printf("  [%4d] %-30s %-35s %s\n", $u['id'], $u['name'], $u['email'], $u['role']);
    }
    out('');

    if ($noask || confirm("Wygenerować kody dla " . count($users) . " użytkowników?")) {
        $generated = [];
        foreach ($users as $u) {
            $code = ika_generate();
            set_ika((int)$u['id'], $code);
            $generated[] = ['user' => $u, 'code' => $code];
        }
        out('');
        ok("Wygenerowano " . count($generated) . " kodów IKA:");
        out('');
        printf("%-5s %-30s %-36s %-12s %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola', 'Kod IKA');
        echo str_repeat('─', 100) . "\n";
        foreach ($generated as $g) {
            printf("[%4d] %-30s %-36s %-12s \033[1;32m%s\033[0m\n",
                $g['user']['id'], $g['user']['name'], $g['user']['email'], $g['user']['role'], $g['code']);
        }
        out('');
        warn("Kody IKA widoczne powyżej — przekaż je użytkownikom bezpiecznym kanałem.");
    }
    exit(0);
}

// ── Tryb: --list / --show ─────────────────────────────────────────────────────
head("Rejestr kont — $db_label");

if ($show_all) {
    warn("Tryb --show: kody IKA są widoczne w jawnej postaci!");
    if (!$noask && !confirm("Kontynuować?")) exit(0);
    out('');
}

$where  = 'WHERE 1=1';
$params = [];
if ($role_flt) { $where .= " AND role=?"; $params[] = $role_flt; }
if ($no_ika)   { $where .= " AND (cpc_code IS NULL OR cpc_code='')"; }

$users = _all(
    "SELECT id,name,email,role,cpc_code,cpc_fails,cpc_blocked_until,ika_revoked_at,is_active
     FROM users $where ORDER BY role,name",
    $params
);

if (!$users) { out("Brak użytkowników pasujących do filtrów."); exit(0); }

$legend = $show_all ? 'KOD IKA ' : 'IKA';
printf("%-6s %-28s %-36s %-12s %-8s  %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola', $legend, 'Flagi');
echo str_repeat('─', 110) . "\n";

$cnt_ok = $cnt_miss = $cnt_blocked = 0;
foreach ($users as $u) {
    out(user_row($u, $show_all));
    if (!empty($u['cpc_code'])) $cnt_ok++; else $cnt_miss++;
    if (!empty($u['cpc_blocked_until']) && strtotime($u['cpc_blocked_until']) > time()) $cnt_blocked++;
}

echo str_repeat('─', 110) . "\n";
printf("Łącznie: %d  |  z IKA: \033[32m%d\033[0m  |  bez IKA: \033[%sm%d\033[0m  |  zablokowanych: \033[%sm%d\033[0m\n",
    count($users), $cnt_ok,
    $cnt_miss  ? '31' : '32', $cnt_miss,
    $cnt_blocked ? '31' : '32', $cnt_blocked
);
out('');

if ($cnt_miss > 0 && !$show_all) {
    out("  Wskazówka: użyj \033[1m--all\033[0m lub \033[1m--user=ID\033[0m aby przypisać kody.");
}
