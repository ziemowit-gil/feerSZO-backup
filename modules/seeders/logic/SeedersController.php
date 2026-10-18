<?php
/**
 * modules/seeders/logic/SeedersController.php — Admin › Seedery (/seeder-test).
 *
 * index: lista seederów z formularzami parametrów i wynikiem ostatniego uruchomienia.
 * run:   uruchamia wybraną akcję W PROCESIE strony (includes/cli_inproc.php → szo_cli_run),
 *        bez proc_open i binarki PHP — jak zakładka „Testy” TI (na hostingu z open_basedir).
 *        Wynik trafia do sesji (ostatnie 5 uruchomień) i na stronę po przekierowaniu.
 */
require_once dirname(__DIR__) . '/lib/seedersRegistry.php';
require_once dirname(__DIR__, 3) . '/includes/cli_inproc.php';

final class SeedersController
{
    private const HISTORY = 5;
    private const MAX_OUT = 200000;

    public function index(): ?string
    {
        $registry = seedersRegistry();
        $history  = $_SESSION['seeders_history'] ?? [];
        $last     = $history[0] ?? null;
        $dbName   = basename((string)(defined('DB_PATH') ? DB_PATH : ''));
        $PAGE_TITLE = 'Seedery — dane testowe';
        ob_start();
        include dirname(__DIR__, 3) . '/includes/header.php';
        echo Router::view(dirname(__DIR__) . '/view/index.php', compact('registry', 'history', 'last', 'dbName'));
        include dirname(__DIR__, 3) . '/includes/footer.php';
        return (string)ob_get_clean();
    }

    public function run(): ?string
    {
        $registry = seedersRegistry();
        $key      = (string)($_POST['seeder'] ?? '');
        $actionId = (string)($_POST['action'] ?? '');
        $entry    = $registry[$key] ?? null;
        $action   = null;
        foreach ($entry['actions'] ?? [] as $a) if ($a['id'] === $actionId) $action = $a;
        if (!$entry || !$action) {
            flash_set('danger', 'Nieznany seeder albo akcja.');
            return Router::redirect(route('seeders.index'));
        }
        $input = (array)($_POST['f'] ?? []);
        [$script, $args, $err] = seedersBuildArgs($entry, $action, $input);
        if ($err !== null) {
            flash_set('danger', $err);
            return Router::redirect(route('seeders.index') . '#s-' . $key);
        }
        $secrets = seedersSecrets($entry, $input);
        $cmd     = seedersCommand($script, $args, $secrets);
        if (!empty($entry['cli_only'])) {
            flash_set('warning', 'Ten seeder uruchamia osobny proces PHP — uruchom go z terminala na serwerze (polecenie poniżej).');
            $this->remember($key, $entry, $action, $cmd, null, 'Polecenie do uruchomienia w katalogu aplikacji:' . "\n\n" . seedersCommand($script, $args) . "\n", 0);
            return Router::redirect(route('seeders.index') . '#wynik');
        }

        $path = dirname(__DIR__, 3) . '/cli/' . $script;
        if (!is_file($path)) {
            flash_set('danger', 'Brak pliku cli/' . $script . '.');
            return Router::redirect(route('seeders.index'));
        }
        @set_time_limit(300);
        $res = szo_cli_run($path, $args);
        $out = $res['out'];
        foreach ($secrets as $s) $out = str_replace($s, '********', $out);
        if (strlen($out) > self::MAX_OUT) $out = substr($out, 0, self::MAX_OUT) . "\n… (wyjście przycięte)\n";
        $this->remember($key, $entry, $action, $cmd, $res['code'], $out, $res['ms']);
        error_log(sprintf('[seeders] %s uruchomił %s (%s) → kod %d', (string)(current_user()['email'] ?? '?'), $cmd, $action['label'], $res['code']));
        flash_set($res['code'] === 0 ? 'success' : 'danger',
            $entry['title'] . ' — ' . $action['label'] . ': ' . ($res['code'] === 0 ? 'zakończono' : 'błąd (kod ' . $res['code'] . ')') . '.');
        return Router::redirect(route('seeders.index') . '#wynik');
    }

    private function remember(string $key, array $entry, array $action, string $cmd, ?int $code, string $out, int $ms = 0): void
    {
        $h = $_SESSION['seeders_history'] ?? [];
        array_unshift($h, [
            'key' => $key, 'title' => $entry['title'], 'action' => $action['label'], 'kind' => $action['kind'] ?? 'apply',
            'cmd' => $cmd, 'code' => $code, 'out' => $out, 'ms' => $ms, 'at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['seeders_history'] = array_slice($h, 0, self::HISTORY);
    }
}
