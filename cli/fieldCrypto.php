<?php
/**
 * cli/fieldCrypto.php — narzędzie do szyfrowania pól w bazie (modules/field_crypto).
 *
 *   php cli/fieldCrypto.php keygen                      # wypisuje nowy klucz do DATA_KEY
 *   php cli/fieldCrypto.php keygen --write              # dopisuje DATA_KEY do config.local.php (odmawia, gdy klucz już jest)
 *   php cli/fieldCrypto.php keygen --write --file=ŚCIEŻKA   # j.w., do wskazanego pliku konfiguracji
 *   php cli/fieldCrypto.php selftest                    # sprawdza szyfrowanie na sztucznych danych (bez bazy)
 *   php cli/fieldCrypto.php status                      # ile wartości w zarejestrowanych kolumnach jest zaszyfrowanych
 *   php cli/fieldCrypto.php encrypt [--apply]           # szyfruje jawne wartości zarejestrowanych kolumn (domyślnie próba)
 *   php cli/fieldCrypto.php rotate  [--apply]           # przeszyfrowuje wartości starym kluczem (DATA_KEY_PREVIOUS) na bieżący
 *
 * Kolumny rejestruje modules/field_crypto/logic/fcColumns.php. Przed `--apply` zrób kopię bazy.
 * Kody wyjścia: 0 = OK, 1 = błąd.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
$cmd = $argv[1] ?? 'status';
$apply = in_array('--apply', $argv, true);

if ($cmd === 'keygen') {
    $key = bin2hex(random_bytes(32));
    $line = "define('DATA_KEY', '{$key}');";
    if (!in_array('--write', $argv, true)) {
        echo $line . "\n";
        echo "// Wklej do config.local.php (albo uruchom ponownie z --write). Przy wymianie klucza stary przenieś do DATA_KEY_PREVIOUS i uruchom: rotate --apply\n";
        exit(0);
    }
    $file = $base . '/config.local.php';
    foreach ($argv as $a) if (str_starts_with($a, '--file=')) $file = substr($a, 7);
    $src = is_file($file) ? (string)file_get_contents($file) : "<?php\n";
    // aktywny (nie zakomentowany) DATA_KEY → nie nadpisujemy: zmiana klucza przy zaszyfrowanych danych = utrata danych
    if (preg_match('/^\s*define\(\s*[\'"]DATA_KEY[\'"]/m', $src)) {
        fwrite(STDERR, "W {$file} jest już zdefiniowany DATA_KEY — nie nadpisuję (wymiana klucza: przenieś stary do DATA_KEY_PREVIOUS i użyj rotate --apply).\n");
        exit(1);
    }
    if (is_file($file) && !is_writable($file) || !is_file($file) && !is_writable(dirname($file))) {
        fwrite(STDERR, "Brak uprawnień do zapisu: {$file}\n"); exit(1);
    }
    $block = "\n// Klucz szyfrowania danych osobowych w bazie (wygenerowany " . date('Y-m-d H:i') . ", php cli/fieldCrypto.php keygen --write).\n"
           . "// NIE ZGUB: bez niego zaszyfrowane PESEL-e są nie do odczytania — trzymaj kopię poza serwerem.\n" . $line . "\n";
    $closing = preg_match('/\?>\s*$/', $src) ? true : false;
    $out = $closing ? preg_replace('/\?>\s*$/', ltrim($block, "\n") . "?>\n", $src) : rtrim($src) . "\n" . $block;
    $tmp = $file . '.tmp' . getmypid();
    if (file_put_contents($tmp, $out) === false) { fwrite(STDERR, "Nie udało się zapisać pliku tymczasowego.\n"); exit(1); }
    @chmod($tmp, is_file($file) ? (fileperms($file) & 0777) : 0640);
    if (!rename($tmp, $file)) { @unlink($tmp); fwrite(STDERR, "Nie udało się podmienić {$file}.\n"); exit(1); }
    echo "Dopisano DATA_KEY do {$file}.\n";
    echo "Klucz (zachowaj kopię poza serwerem — np. w menedżerze haseł):\n{$key}\n";
    exit(0);
}

require_once $base . '/config.php';
require_once $base . '/modules/field_crypto/lib/fieldCrypto.php';

if ($cmd === 'selftest') {
    $fail = 0;
    $t = function (string $name, bool $ok) use (&$fail): void { echo ($ok ? "OK    " : "BŁĄD  ") . $name . "\n"; if (!$ok) $fail++; };
    $e = fcEncrypt('44051401359', 'persons.pesel');
    $t('szyfrogram ma prefiks', fcIsEncrypted($e));
    $t('odszyfrowanie zwraca oryginał', fcDecrypt($e, 'persons.pesel') === '44051401359');
    $t('inny kontekst nie odszyfruje (AAD)', fcDecrypt($e, 'users.rpts_pesel') === null);
    $t('uszkodzony szyfrogram odrzucony', fcDecrypt(substr($e, 0, -2) . 'AA', 'persons.pesel') === null);
    $t('dwa szyfrowania tej samej wartości różnią się (nonce)', fcEncrypt('x', 'a.b') !== fcEncrypt('x', 'a.b'));
    $t('powtórne szyfrowanie nic nie zmienia', fcEncrypt($e, 'persons.pesel') === $e);
    $t('tekst jawny przechodzi przez fcDecrypt', fcDecrypt('jawny', 'a.b') === 'jawny');
    $t('null i pusty bez zmian', fcEncrypt(null, 'a.b') === null && fcEncrypt('', 'a.b') === '');
    $t('indeks ślepy: PESEL ze spacjami = bez', fcBlind('440514 01359', 'persons.pesel', 'digits') === fcBlind('44051401359', 'persons.pesel', 'digits'));
    $t('indeks ślepy: e-mail bez względu na wielkość liter', fcBlind('Jan@X.pl', 'u.email', 'email') === fcBlind('jan@x.pl', 'u.email', 'email'));
    $t('indeks ślepy zależy od kontekstu', fcBlind('1', 'a.b') !== fcBlind('1', 'a.c'));
    $t('maskowanie PESEL', fcMask('44051401359', 'digits') === '••••••••359');
    echo fcHasDedicatedKey() ? "Klucz: osobny DATA_KEY.\n" : "UWAGA: brak DATA_KEY — używany APP_KEY. Ustaw osobny klucz (php cli/fieldCrypto.php keygen).\n";
    exit($fail ? 1 : 0);
}

require_once $base . '/includes/db.php';
require_once $base . '/modules/field_crypto/lib/fcDb.php';
FcStatement::$raw = true;   // CLI widzi surowe wartości (bez odszyfrowania przezroczystego i bez rejestrowania zapisów)

$total = ['jawne' => 0, 'szyfr' => 0, 'stary_klucz' => 0, 'nieczytelne' => 0];
foreach (fcColumns() as $c) {
    [$table, $col] = [$c['table'], $c['column']];
    if (!fcColumnExists($table, $col)) { echo "- {$table}.{$col}: brak kolumny (pomijam)\n"; continue; }
    $ctx = "{$table}.{$col}";
    if (!empty($c['auto']) && $cmd !== 'status') fcEnsureIdx($c);
    $rows = db_all("SELECT id, `{$col}` AS v FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''");
    $n = ['jawne' => 0, 'szyfr' => 0, 'stary_klucz' => 0, 'nieczytelne' => 0];
    foreach ($rows as $r) {
        $v = (string)$r['v'];
        if (!fcIsEncrypted($v)) {
            $n['jawne']++;
            if ($cmd === 'encrypt' && $apply) fcWriteColumn($c, (int)$r['id'], $v);
            continue;
        }
        $plain = fcDecrypt($v, $ctx);
        if ($plain === null) { $n['nieczytelne']++; continue; }
        $n['szyfr']++;
        if (fcNeedsRotation($v)) {
            $n['stary_klucz']++;
            if ($cmd === 'rotate' && $apply) fcWriteColumn($c, (int)$r['id'], $plain);
        }
    }
    foreach ($n as $k => $v) $total[$k] += $v;
    echo sprintf("- %-40s jawne: %-5d zaszyfrowane: %-5d starym kluczem: %-5d nieczytelne: %d\n", $ctx, $n['jawne'], $n['szyfr'], $n['stary_klucz'], $n['nieczytelne']);
}
echo "Razem: jawne {$total['jawne']}, zaszyfrowane {$total['szyfr']}, starym kluczem {$total['stary_klucz']}, nieczytelne {$total['nieczytelne']}\n";
if (in_array($cmd, ['encrypt', 'rotate'], true) && !$apply) echo "To była próba — dodaj --apply, aby zapisać zmiany (najpierw kopia bazy).\n";
if ($total['nieczytelne'] > 0) { echo "UWAGA: są wartości, których nie da się odczytać (brak klucza o danym kid albo uszkodzone).\n"; exit(1); }
exit(0);
