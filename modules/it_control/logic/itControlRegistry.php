<?php
/**
 * modules/it_control/logic/itControlRegistry.php — rejestr sprawdzeń kontroli IT.
 *
 * Nowe sprawdzenie: klasa dziedzicząca po ItControlCheck w logic/checks/
 * (albo w logic/checks/ innego modułu) + jedna linia w ITC_CHECKS poniżej.
 * Inny moduł może też dopisać się w locie: ItControlRegistry::register(Klasa::class, plik).
 * Kolejność wpisów = kolejność wykonania i wyświetlania.
 */

const ITC_SUITES = [
    'logs' => ['label' => 'Kontrola Dzienników', 'icon' => 'bi-journal-text',   'url' => '/modules/log_control/index.php'],
    'ops'  => ['label' => 'Kontrola ogólna IT',   'icon' => 'bi-hdd-network',    'url' => '/modules/it_control/index.php'],
];

/** Klasa => plik (względem katalogu głównego aplikacji). */
const ITC_CHECKS = [
    // ── Kontrola Dzienników (modules/log_control) ──────────────────────────────
    'LogControlPhpErrorsCheck'    => 'modules/log_control/logic/checks/logControlPhpErrorsCheck.php',
    'LogControlAppLogsCheck'      => 'modules/log_control/logic/checks/logControlAppLogsCheck.php',
    'LogControlAuthCheck'         => 'modules/log_control/logic/checks/logControlAuthCheck.php',
    'LogControlAuditBypassCheck'  => 'modules/log_control/logic/checks/logControlAuditBypassCheck.php',
    'LogControlApiAccessCheck'    => 'modules/log_control/logic/checks/logControlApiAccessCheck.php',
    'LogControlSamlCheck'         => 'modules/log_control/logic/checks/logControlSamlCheck.php',
    'LogControlMailFailuresCheck' => 'modules/log_control/logic/checks/logControlMailFailuresCheck.php',
    'LogControlErrorSpikeCheck'   => 'modules/log_control/logic/checks/logControlErrorSpikeCheck.php',
    // ── Kontrola ogólna (modules/it_control) ───────────────────────────────────
    'ItControlDatabaseCheck'      => 'modules/it_control/logic/checks/itControlDatabaseCheck.php',
    'ItControlDiskCheck'          => 'modules/it_control/logic/checks/itControlDiskCheck.php',
    'ItControlMailQueueCheck'     => 'modules/it_control/logic/checks/itControlMailQueueCheck.php',
    'ItControlBackupCheck'        => 'modules/it_control/logic/checks/itControlBackupCheck.php',
    'ItControlCertCheck'          => 'modules/it_control/logic/checks/itControlCertCheck.php',
    'ItControlCronCheck'          => 'modules/it_control/logic/checks/itControlCronCheck.php',
    'ItControlAdmin2faCheck'      => 'modules/it_control/logic/checks/itControlAdmin2faCheck.php',
    'ItControlLogControlCheck'    => 'modules/it_control/logic/checks/itControlLogControlCheck.php',
];

final class ItControlRegistry
{
    /** @var array<string,string> dopisane przez inne moduły */
    private static array $extra = [];

    public static function register(string $class, string $file): void {
        self::$extra[$class] = $file;
    }

    /** @return ItControlCheck[] wszystkie sprawdzenia (opcjonalnie tylko z zestawu) */
    public static function all(?string $suite = null): array {
        $root = dirname(__DIR__, 3);
        $out = [];
        foreach (ITC_CHECKS + self::$extra as $class => $file) {
            if (!class_exists($class, false)) {
                $path = str_starts_with($file, '/') ? $file : $root . '/' . $file;
                if (!is_file($path)) continue;
                require_once $path;
            }
            if (!class_exists($class, false) || !is_subclass_of($class, ItControlCheck::class)) continue;
            $check = new $class();
            if ($suite === null || $check->suite() === $suite) $out[$check->id()] = $check;
        }
        return $out;
    }
}
