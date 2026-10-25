<?php
/**
 * cli/smsKindsLint.php — sprawdza, czy każde wywołanie wysyłki SMS ma rodzaj z katalogu (modules/sms_senders).
 *
 * Rodzaj SMS decyduje o nadawcy (Ustawienia SMS → „Nadawca wg rodzaju SMS”). Wywołanie bez rodzaju albo z rodzajem spoza katalogu
 * nie zgłasza błędu w działaniu — po cichu dostaje domyślnego nadawcę — więc łatwo je przeoczyć. To narzędzie parsuje kod (tokenizer PHP,
 * nie regex po tekście) i sprawdza:
 *   • sms_send(telefon, treść, RODZAJ), sms_send_with_fallback(telefon, treść, e-mail, RODZAJ), ti_lesson_sms_notify(kurs, treść, RODZAJ)
 *     — rodzaj jest, i jest literałem z katalogu (albo jednym z kilku wskazanych wyrażeń dynamicznych, patrz DYNAMIC);
 *   • definicje tych funkcji przyjmują rodzaj jako ostatni parametr $kind (inaczej rodzaj przekazany przez wywołania trafiłby w próżnię);
 *   • każdy rodzaj z listy statycznej ma przynajmniej jedno wywołanie (martwy rodzaj w tabeli ustawień wprowadza w błąd).
 *
 * Użycie:   php cli/smsKindsLint.php            # błędy → kod wyjścia 1
 *           php cli/smsKindsLint.php --list     # + użycie rodzajów: ile wywołań i gdzie
 *           php cli/smsKindsLint.php --root=DIR # przeskanuj inny katalog (np. do testu samego narzędzia); katalog rodzajów zawsze z tego repo
 * Kody wyjścia: 0 = OK, 1 = znaleziono problemy.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
require_once $base . '/modules/sms_senders/logic/smsSenders.php';

/** Funkcje wysyłki → pozycja rodzaju wśród argumentów (od 0) i dopuszczalna liczba argumentów. */
const SMS_LINT_FUNCS = [
    'sms_send'               => 2,
    'sms_send_with_fallback' => 3,
    'ti_lesson_sms_notify'   => 2,
];

/**
 * Wyrażenia dynamiczne dopuszczone w wskazanych plikach (rodzaj nie jest tam literałem, bo wynika z kontekstu):
 *   $kind  — helper ti_lesson_sms_notify przekazuje rodzaj dalej; ekran testu w Ustawieniach SMS wybiera go z listy;
 *   $key / $tpl / self::TEMPLATE — klucz szablonu SMS, który jest jednocześnie rodzajem (sprawdzany względem rejestru szablonów).
 */
const SMS_LINT_DYNAMIC = [
    '$kind'          => ['includes/karty30.php', 'admin/sms_settings.php', 'includes/sms.php', 'includes/ti_cert_issue.php'],
    '$key'           => ['karty30/ti/dydaktyk/szablonySms.php'],
    '$tpl'           => ['modules/ti_licencje/logic/tiLicencjeNotify.php', 'modules/ti_licencje/logic/tiLicencjeBilling.php'],
    'self::TEMPLATE' => ['modules/ti_payment_reminders/logic/tiPaymentReminders.php'],
];

$list    = in_array('--list', $argv, true);
$root    = $base;
foreach ($argv as $a) if (str_starts_with($a, '--root=')) $root = rtrim(substr($a, 7), '/');
$kinds   = SmsSenders::kinds();
$skipDir = ['vendor', 'node_modules', 'uploads', 'storage', 'ng-app', 'kursantApp'];   // + katalogi ukryte (.git, .claude/worktrees — cudze kopie repo)

/** Następny/poprzedni token inny niż odstęp lub komentarz. */
function sms_lint_sig(array $tokens, int $i, int $dir): ?int
{
    for ($j = $i + $dir; $j >= 0 && $j < count($tokens); $j += $dir) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $j;
    }
    return null;
}

$errors = []; $usage = []; $calls = 0; $files = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn(SplFileInfo $f) => !($f->isDir() && (in_array($f->getFilename(), $skipDir, true) || str_starts_with($f->getFilename(), '.')))
));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $src = (string)file_get_contents($file->getPathname());
    if (!str_contains($src, 'sms_send') && !str_contains($src, 'ti_lesson_sms_notify')) continue;
    $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');
    $files++;
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !isset(SMS_LINT_FUNCS[$t[1]])) continue;
        $pi = sms_lint_sig($tokens, $i, -1);
        if ($pi !== null && is_array($tokens[$pi]) && $tokens[$pi][0] === T_FUNCTION) {          // definicja: ostatni parametr musi być $kind
            $ni = sms_lint_sig($tokens, $i, 1);
            if ($ni !== null && $tokens[$ni] === '(') {
                $depth = 0; $params = [];
                for ($j = $ni; $j < $n; $j++) {
                    $tk = $tokens[$j];
                    if ($tk === '(') { $depth++; continue; }
                    if ($tk === ')') { $depth--; if ($depth === 0) break; continue; }
                    if ($depth === 1 && is_array($tk) && $tk[0] === T_VARIABLE) $params[] = $tk[1];
                }
                if (end($params) !== '$kind' || count($params) !== SMS_LINT_FUNCS[$t[1]] + 1) {
                    $errors[] = $rel . ':' . $t[2] . ' definicja ' . $t[1] . '() — ostatni parametr musi być $kind (jest: ' . implode(', ', $params) . ')';
                }
            }
            continue;
        }
        if ($pi !== null && is_array($tokens[$pi]) && in_array($tokens[$pi][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_NEW], true)) continue;   // metoda
        $ni = sms_lint_sig($tokens, $i, 1);
        if ($ni === null || $tokens[$ni] !== '(') continue;                                   // sama nazwa, np. w function_exists('sms_send')

        // argumenty najwyższego poziomu
        $depth = 0; $args = ['']; $close = null;
        for ($j = $ni; $j < $n; $j++) {
            $tk = $tokens[$j];
            if ($tk === '(' || $tk === '[') { $depth++; if ($depth > 1) $args[count($args) - 1] .= $tk; continue; }
            if ($tk === ')' || $tk === ']') { $depth--; if ($depth === 0) { $close = $j; break; } $args[count($args) - 1] .= $tk; continue; }
            if ($tk === ',' && $depth === 1) { $args[] = ''; continue; }
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $args[count($args) - 1] .= is_array($tk) ? $tk[1] : $tk;
        }
        $calls++;
        $where = $rel . ':' . $t[2] . ' ' . $t[1] . '()';
        $pos   = SMS_LINT_FUNCS[$t[1]];
        $kindArg = $args[$pos] ?? null;
        if ($close === null)                         { $errors[] = "$where — nie znaleziono nawiasu zamykającego"; continue; }
        if ($kindArg === null || $kindArg === '')    { $errors[] = "$where — brak rodzaju SMS (argument " . ($pos + 1) . ")"; continue; }
        if (count($args) > $pos + 1)                 { $errors[] = "$where — za dużo argumentów (" . count($args) . ")"; continue; }

        if (preg_match("/^'([a-z0-9_]+)'$/", $kindArg, $m)) {                                   // literał
            if (!isset($kinds[$m[1]])) { $errors[] = "$where — rodzaj „{$m[1]}” nie istnieje w katalogu"; continue; }
            $usage[$m[1]][] = $rel . ':' . $t[2];
        } elseif (isset(SMS_LINT_DYNAMIC[$kindArg])) {
            if (!in_array($rel, SMS_LINT_DYNAMIC[$kindArg], true)) $errors[] = "$where — wyrażenie $kindArg dozwolone tylko w: " . implode(', ', SMS_LINT_DYNAMIC[$kindArg]);
            else $usage['(dynamiczny ' . $kindArg . ')'][] = $rel . ':' . $t[2];
        } else {
            $errors[] = "$where — rodzaj musi być literałem z katalogu (jest: $kindArg)";
        }
    }
}

// rodzaje z listy statycznej bez żadnego wywołania — martwe wpisy w tabeli ustawień
foreach ($kinds as $kind => $info) {
    if (!$info['template'] && empty($usage[$kind])) $errors[] = "rodzaj „{$kind}” ({$info['label']}) nie ma żadnego wywołania w kodzie";
}

if ($list) {
    ksort($usage);
    foreach ($usage as $kind => $where) {
        echo sprintf("  %-34s %2d  %s\n", $kind, count($where), implode(', ', array_slice($where, 0, 3)) . (count($where) > 3 ? ', …' : ''));
    }
    echo "\n";
}
foreach ($errors as $e) fwrite(STDERR, "BŁĄD: $e\n");
echo "Przejrzano plików: $files, wywołań wysyłki SMS: $calls, rodzajów w katalogu: " . count($kinds) . ($errors ? ", problemów: " . count($errors) : ' — wszystko spójne') . "\n";
exit($errors ? 1 : 0);
