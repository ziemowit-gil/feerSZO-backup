<?php
/**
 * cli/exam_sandbox_smoketest.php — ręczna weryfikacja piaskownicy Equi Exams.
 *
 * Uruchamiać JAKO www-data (żeby faktycznie przejść przez `sudo -n -u examrun`,
 * tak jak w produkcji), np. z hosta:
 *
 *   docker compose -f docker/docker-compose.yml exec -u www-data app \
 *       php cli/exam_sandbox_smoketest.php
 *
 * Sprawdza: happy path per język, celowy timeout, celowe przekroczenie limitu
 * pamięci/CPU, próbę połączenia sieciowego (dokumentuje zaakceptowany brak
 * izolacji sieciowej — patrz plan migracji, sekcja "Decyzja bezpieczeństwa").
 * Nic tu nie jest asercją automatyczną — wypisuje wyniki do przeczytania.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../modules/equi_exams/logic/sandbox.php';

function smoke_case(string $title, string $lang, string $source, ?string $stdin = null, int $timeoutMs = 5000, int $memMb = 128): void {
    echo "=== {$title} [{$lang}] ===\n";
    if (!ex_sandbox_available($lang)) {
        echo "  POMINIĘTO: brak narzędzia dla języka '{$lang}' w obrazie.\n\n";
        return;
    }
    $t0 = microtime(true);
    $r = ex_sandbox_run($lang, $source, $stdin, $timeoutMs, $memMb);
    $ms = round((microtime(true) - $t0) * 1000);
    echo '  wall: ' . $ms . " ms (worker durationMs: {$r['durationMs']})\n";
    echo '  started=' . var_export($r['started'], true)
        . ' compiled=' . var_export($r['compiled'], true)
        . ' exitCode=' . $r['exitCode']
        . ' timedOut=' . var_export($r['timedOut'], true) . "\n";
    if ($r['engineError'] !== '')  echo '  engineError: ' . $r['engineError'] . "\n";
    if ($r['compileError'] !== '') echo '  compileError: ' . mb_substr($r['compileError'], 0, 300) . "\n";
    if ($r['stdout'] !== '')       echo '  stdout: ' . trim(mb_substr($r['stdout'], 0, 300)) . "\n";
    if ($r['stderr'] !== '')       echo '  stderr: ' . trim(mb_substr($r['stderr'], 0, 300)) . "\n";
    echo "\n";
}

echo "Języki wykryte jako dostępne: " . implode(', ', array_filter(ex_lang_ids(), 'ex_sandbox_available')) . "\n\n";

// ── Happy path per język ──────────────────────────────────────────────────
smoke_case('Happy path', 'python', "print('hello from python')\n");
smoke_case('Happy path', 'php', "<?php echo 'hello from php';\n");
smoke_case('Happy path', 'javascript', "console.log('hello from node');\n");
smoke_case('Happy path', 'java', "public class Solution {\n  public static void main(String[] a) {\n    System.out.println(\"hello from java\");\n  }\n}\n");
smoke_case('Happy path', 'c', "#include <stdio.h>\nint main(){ printf(\"hello from c\\n\"); return 0; }\n");
smoke_case('Happy path', 'cpp', "#include <iostream>\nint main(){ std::cout << \"hello from cpp\\n\"; return 0; }\n");
smoke_case('Happy path', 'shell', "echo 'hello from shell'\n");

// ── Stdin ─────────────────────────────────────────────────────────────────
smoke_case('Stdin echo', 'python', "import sys\nprint(sys.stdin.read().strip().upper())\n", "abc\n");

// ── Celowy timeout (pętla nieskończona) ─────────────────────────────────────
smoke_case('Timeout (pętla nieskończona)', 'python', "while True:\n    pass\n", null, 2000);

// ── Celowe przekroczenie limitu CPU (ulimit -t, niezależnie od timeoutu ściennego) ──
smoke_case('Limit CPU (ulimit -t)', 'c',
    "int main(){ volatile long i=0; for(;;) i++; return 0; }\n", null, 30000);

// ── Celowe przekroczenie limitu pamięci (ulimit -v, tylko języki z limitVirtualMemory) ──
smoke_case('Limit pamięci (ulimit -v)', 'python',
    "a = []\nwhile True:\n    a.append('x' * 1024 * 1024)\n", null, 5000, 32);

// ── Próba dostępu sieciowego — dokumentuje zaakceptowany brak izolacji sieciowej ──
smoke_case('Próba połączenia sieciowego (oczekiwany brak izolacji!)', 'python',
    "import socket\ns = socket.socket(socket.AF_INET, socket.SOCK_STREAM)\n"
    . "s.settimeout(3)\n"
    . "try:\n"
    . "    s.connect(('1.1.1.1', 80))\n"
    . "    print('SIEĆ DOSTĘPNA — brak izolacji, zgodnie z zaakceptowanym ryzykiem')\n"
    . "except Exception as e:\n"
    . "    print('brak połączenia:', e)\n");

echo "Gotowe.\n";
