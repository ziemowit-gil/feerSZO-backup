#!/usr/bin/env php
<?php
/**
 * cli/ykReset.php — reset kluczy sprzętowych (YubiKey, WebAuthn) konta, np. po zgubieniu klucza.
 *
 * Po resecie konto nie ma żadnego klucza, więc przy następnym wejściu do panelu dydaktyka (kierownik, zastępca,
 * admin — karty30/ti/dydaktyk/yk_gate.php) zarejestruje nowy klucz. Uwaga: te same klucze (tabela
 * webauthn_credentials) służą w SZO — bramce logowania (webauthn_required) i zatwierdzaniu protokołów TI.
 *
 * Użycie:
 *   php cli/ykReset.php                       # w terminalu: menu graficzne (strzałki, Enter, q); poza terminalem: jak „accounts”
 *   php cli/ykReset.php menu                  # menu graficzne
 *   php cli/ykReset.php accounts              # konta z kluczami (ile, ostatnie użycie)
 *   php cli/ykReset.php list <email>          # klucze konta z numerami
 *   php cli/ykReset.php reset <email>         # usuń WSZYSTKIE klucze konta (pyta o potwierdzenie)
 *   php cli/ykReset.php reset <email> --id=N  # usuń jeden klucz (numer z „list”)
 *   … --yes                                   # bez pytania o potwierdzenie
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/approval.php'; // log_user_action()

webauthn_migrate();

$args  = array_slice($argv, 1);
$yes   = in_array('--yes', $args, true);
$keyId = 0;
foreach ($args as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $keyId = (int)$m[1];
$pos   = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$cmd   = $pos[0] ?? '';
$email = $pos[1] ?? '';

function ykFindUser(string $email): ?array {
    return $email !== '' ? db_one("SELECT id, name, email, role FROM users WHERE LOWER(email) = LOWER(?)", [trim($email)]) : null;
}

function ykUser(string $email): array {
    $u = ykFindUser($email);
    if (!$u) { fwrite(STDERR, "Nie znaleziono konta: {$email}\n"); exit(1); }
    return $u;
}

function ykAccounts(): array {
    return db_all(
        "SELECT u.id, u.name, u.email, u.role, COUNT(*) AS n, MAX(w.last_used_at) AS last_used
         FROM webauthn_credentials w JOIN users u ON u.id = w.user_id
         GROUP BY u.id, u.name, u.email, u.role ORDER BY u.name"
    );
}

function ykKeyLine(array $k): string {
    return sprintf('#%-5d %-24s dodany %s, użyty %s', $k['id'], (string)$k['name'],
        substr((string)$k['created_at'], 0, 16), $k['last_used_at'] ? substr((string)$k['last_used_at'], 0, 16) : '—');
}

function ykPrintKeys(array $u): array {
    $keys = webauthn_get_credentials((int)$u['id']);
    echo "{$u['name']} <{$u['email']}> (id {$u['id']}, rola {$u['role']}) — kluczy: " . count($keys) . PHP_EOL;
    foreach ($keys as $k) echo '  ' . ykKeyLine($k) . PHP_EOL;
    return $keys;
}

/** Usuwa jeden klucz ($keyId) albo wszystkie klucze konta; zwraca komunikat dla operatora. */
function ykDelete(array $u, int $keyId, array $keys): string {
    $what = $keyId ? "klucz #{$keyId}" : 'WSZYSTKIE klucze (' . count($keys) . ')';
    if ($keyId) {
        webauthn_delete_credential($keyId, (int)$u['id']);
    } else {
        db()->prepare("DELETE FROM webauthn_credentials WHERE user_id = ?")->execute([(int)$u['id']]);
    }
    if (function_exists('log_user_action')) {
        log_user_action((int)$u['id'], (int)$u['id'], 'webauthn_reset', "CLI: usunięto {$what} (cli/ykReset.php)");
    }
    return "Usunięto {$what}. " . (count($keys) > 1 && $keyId
        ? 'Pozostałe klucze konta działają dalej.'
        : 'Przy następnym logowaniu do panelu dydaktyka konto zarejestruje nowy klucz.');
}

// ── Menu graficzne (TUI): czysty PHP + stty, bez zależności ─────────────────────

function tuiStart(): void {
    static $on = false;
    if ($on) return;
    $on = true;
    $saved = trim((string)shell_exec('stty -g 2>/dev/null'));
    shell_exec('stty -icanon -echo 2>/dev/null');
    echo "\033[?1049h\033[?25l"; // osobny ekran terminala, ukryty kursor
    register_shutdown_function(function () use ($saved) {
        echo "\033[?25h\033[?1049l";
        if ($saved !== '') shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
    });
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn() => exit(130));
    }
}

function tuiWidth(): int {
    $cols = (int)trim((string)shell_exec('tput cols 2>/dev/null'));
    return max(40, min($cols ?: 80, 96));
}

function tuiKey(): string {
    $c = fread(STDIN, 1);
    if ($c === false || $c === '') return 'q';
    if ($c === "\033") {
        $r = [STDIN]; $w = $e = null;
        if (!stream_select($r, $w, $e, 0, 60000)) return 'esc';
        $seq = fread(STDIN, 2);
        return match ($seq) { '[A' => 'up', '[B' => 'down', '[H' => 'home', '[F' => 'end', default => 'esc' };
    }
    if ($c === "\n" || $c === "\r") return 'enter';
    if ($c === 'k') return 'up';
    if ($c === 'j') return 'down';
    return $c;
}

function tuiFit(string $t, int $w): string {
    $t = mb_strimwidth($t, 0, $w, '…');
    return $t . str_repeat(' ', max(0, $w - mb_strwidth($t)));
}

/** Rysuje ramkę: tytuł, wiersze informacyjne, opcje (zaznaczona podświetlona), podpowiedź klawiszy. */
function tuiDraw(string $title, array $info, array $items, ?int $sel, string $hint): void {
    $W = tuiWidth(); $in = $W - 4;
    $o = "\033[H\033[2J";
    $o .= "\033[36m╔" . str_repeat('═', $W - 2) . "╗\033[0m\n";
    $o .= "\033[36m║\033[0m \033[1m" . tuiFit('Klucze YubiKey — ' . $title, $in) . "\033[0m \033[36m║\033[0m\n";
    $o .= "\033[36m╠" . str_repeat('═', $W - 2) . "╣\033[0m\n";
    foreach ($info as $line) {
        [$txt, $col] = is_array($line) ? $line : [$line, ''];
        $o .= "\033[36m║\033[0m " . ($col ? "\033[{$col}m" : '') . tuiFit($txt, $in) . "\033[0m \033[36m║\033[0m\n";
    }
    if ($info && $items) $o .= "\033[36m╟" . str_repeat('─', $W - 2) . "╢\033[0m\n";
    foreach ($items as $i => $it) {
        $num  = $i < 9 ? ($i + 1) . '. ' : '   ';
        $line = tuiFit(($i === $sel ? '▶ ' : '  ') . $num . $it, $in);
        $o .= "\033[36m║\033[0m " . ($i === $sel ? "\033[7;1m{$line}\033[0m" : $line) . " \033[36m║\033[0m\n";
    }
    $o .= "\033[36m╚" . str_repeat('═', $W - 2) . "╝\033[0m\n";
    $o .= "\033[90m " . $hint . "\033[0m\n";
    echo $o;
}

/** Wybór z listy strzałkami/cyframi; zwraca indeks albo null (q, Esc, Backspace = wstecz). */
function tuiSelect(string $title, array $info, array $items, int $sel = 0): ?int {
    tuiStart();
    $n = count($items);
    while (true) {
        tuiDraw($title, $info, $items, $sel, '↑↓ wybór · Enter zatwierdź · 1–9 skrót · q / Esc wstecz');
        $k = tuiKey();
        if ($k === 'up')    $sel = ($sel - 1 + $n) % $n;
        elseif ($k === 'down')  $sel = ($sel + 1) % $n;
        elseif ($k === 'home')  $sel = 0;
        elseif ($k === 'end')   $sel = $n - 1;
        elseif ($k === 'enter') return $sel;
        elseif (in_array($k, ['q', 'Q', 'esc', "\x7f", "\x08"], true)) return null;
        elseif (ctype_digit($k) && (int)$k >= 1 && (int)$k <= min(9, $n)) return (int)$k - 1;
    }
}

function tuiMessage(string $title, array $info): void {
    tuiStart();
    tuiDraw($title, $info, [], null, 'Naciśnij dowolny klawisz…');
    tuiKey();
}

function tuiInput(string $title, string $prompt): string {
    tuiStart();
    tuiDraw($title, [$prompt], [], null, 'Enter zatwierdź · pusty = wstecz');
    shell_exec('stty icanon echo 2>/dev/null');
    echo "\033[?25h > ";
    $v = trim((string)fgets(STDIN));
    echo "\033[?25l";
    shell_exec('stty -icanon -echo 2>/dev/null');
    return $v;
}

function tuiConfirm(string $question, string $title = 'Potwierdzenie'): bool {
    return tuiSelect($title, [
        [$question, '1;33'],
        ['Te same klucze służą też w SZO (logowanie, protokoły TI).', '90'],
    ], ['Nie, wróć', 'Tak, usuń']) === 1;
}

function tuiAccount(array $u): void {
    $sel = 0;
    while (true) {
        $keys = webauthn_get_credentials((int)$u['id']);
        $info = [
            [$u['name'], '1'],
            ["{$u['email']} · id {$u['id']} · rola {$u['role']}", '90'],
            '',
            [$keys ? 'Klucze (' . count($keys) . '):' : 'Brak kluczy — przy następnym logowaniu do panelu konto zarejestruje nowy.', $keys ? '' : '33'],
        ];
        foreach ($keys as $k) $info[] = '  ' . ykKeyLine($k);
        $items = $acts = [];
        if ($keys) {
            $items[] = 'Usuń jeden klucz';                          $acts[] = 'one';
            $items[] = 'Usuń wszystkie klucze (' . count($keys) . ')'; $acts[] = 'all';
        }
        $items[] = 'Wróć'; $acts[] = 'back';
        $i = tuiSelect('konto', $info, $items, min($sel, count($items) - 1));
        if ($i === null || $acts[$i] === 'back') return;
        $sel = $i;
        if ($acts[$i] === 'all') {
            if (tuiConfirm('Usunąć WSZYSTKIE klucze (' . count($keys) . ") konta {$u['email']}?")) {
                tuiMessage('gotowe', [[ykDelete($u, 0, $keys), '32']]);
            }
        } else {
            $k = tuiSelect('wybierz klucz do usunięcia', [["Konto: {$u['email']}", '90']], array_map('ykKeyLine', $keys));
            if ($k !== null && tuiConfirm("Usunąć klucz #{$keys[$k]['id']} ({$keys[$k]['name']}) konta {$u['email']}?")) {
                tuiMessage('gotowe', [[ykDelete($u, (int)$keys[$k]['id'], $keys), '32']]);
            }
        }
    }
}

function tuiMain(): void {
    $sel = 0;
    while (true) {
        $acc = ykAccounts();
        $i = tuiSelect('menu', [
            'Reset kluczy sprzętowych, np. po zgubieniu klucza.',
            ['Kierownik, zastępca i admin muszą mieć klucz, aby wejść do panelu dydaktyka.', '90'],
        ], ['Konta z kluczami (' . count($acc) . ')', 'Znajdź konto po e-mailu', 'Wyjście'], $sel);
        if ($i === null || $i === 2) return;
        $sel = $i;
        if ($i === 0) {
            if (!$acc) { tuiMessage('konta', [['Żadne konto nie ma zarejestrowanego klucza.', '33']]); continue; }
            $a = 0;
            while (true) {
                $acc = ykAccounts();
                if (!$acc) break;
                $a = tuiSelect('konta z kluczami', [], array_map(fn($r) => sprintf('%-26s %-30s kluczy: %d · %s',
                    mb_strimwidth((string)$r['name'], 0, 26, '…'), mb_strimwidth((string)$r['email'], 0, 30, '…'),
                    $r['n'], $r['last_used'] ? substr((string)$r['last_used'], 0, 10) : 'nieużywany'), $acc), min($a, count($acc) - 1));
                if ($a === null) break;
                tuiAccount($acc[$a]);
            }
        } else {
            $e = tuiInput('szukaj konta', 'Podaj e-mail konta:');
            if ($e === '') continue;
            $u = ykFindUser($e);
            if ($u) tuiAccount($u);
            else tuiMessage('szukaj konta', [["Nie znaleziono konta: {$e}", '31']]);
        }
    }
}

// ── Polecenia ─────────────────────────────────────────────────────────────────

if ($cmd === 'menu' || ($cmd === '' && stream_isatty(STDIN) && stream_isatty(STDOUT))) {
    if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) { fwrite(STDERR, "Menu wymaga terminala.\n"); exit(1); }
    tuiMain();
    exit(0);
}

if ($cmd === '' || $cmd === 'accounts') {
    $rows = ykAccounts();
    if (!$rows) { echo "Żadne konto nie ma zarejestrowanego klucza.\n"; exit(0); }
    foreach ($rows as $r) printf("%-32s %-36s kluczy: %d, ostatnio: %s\n", $r['name'], $r['email'], $r['n'], $r['last_used'] ?: '—');
    exit(0);
}

if ($cmd === 'list') {
    ykPrintKeys(ykUser($email));
    exit(0);
}

if ($cmd === 'reset') {
    $u    = ykUser($email);
    $keys = ykPrintKeys($u);
    if (!$keys) { echo "Nic do usunięcia.\n"; exit(0); }
    if ($keyId && !in_array($keyId, array_map(fn($k) => (int)$k['id'], $keys), true)) {
        fwrite(STDERR, "Klucz #{$keyId} nie należy do tego konta.\n"); exit(1);
    }
    $what = $keyId ? "klucz #{$keyId}" : 'WSZYSTKIE klucze (' . count($keys) . ')';
    if (!$yes) {
        echo "Usunąć {$what} konta {$u['email']}? [t/N] ";
        if (!in_array(strtolower(trim((string)fgets(STDIN))), ['t', 'tak', 'y', 'yes'], true)) { echo "Przerwano.\n"; exit(1); }
    }
    echo ykDelete($u, $keyId, $keys) . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Nieznane polecenie: {$cmd}\nUżycie: php cli/ykReset.php [menu | accounts | list <email> | reset <email> [--id=N] [--yes]]\n");
exit(1);
