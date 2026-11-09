<?php
/**
 * modules/backup_restore/logic/BackupRestoreController.php — strona „Przywróć bazę SQL z kopii” (tylko admin):
 * wybór kopii, porównanie liczby wierszy tabel (teraz / w kopii) i przywrócenie całej bazy albo wybranych tabel.
 * Wynik trafia do sesji (nie do adresu), ślad do audit_logs i admin_audit_log.
 */

require_once __DIR__ . '/backupRestore.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 3) . '/includes/admin_audit.php';

final class BackupRestoreController
{
    private const VIEW = __DIR__ . '/../view/';
    private const RESULT_KEY = 'backupRestoreResult';

    public function index(): string
    {
        @set_time_limit(300);
        $candidates = backupRestoreCandidates();
        $rel   = trim((string)($_GET['file'] ?? ''));
        $cand  = $rel !== '' ? backupRestoreFind($rel) : backupRestoreDefault($candidates);
        $error = ($rel !== '' && !$cand) ? 'Nie znaleziono wskazanej kopii bazy — wybierz inną z listy.' : '';
        $compare = $cand ? backupRestoreCompare($cand) : null;
        $result = $_SESSION[self::RESULT_KEY] ?? null;
        unset($_SESSION[self::RESULT_KEY]);
        return Router::view(self::VIEW . 'index.php', [
            'candidates' => $candidates,
            'cand'       => $cand,
            'error'      => $error,
            'compare'    => $compare,
            'result'     => $result,
        ]);
    }

    public function restore(): ?string
    {
        @set_time_limit(900);
        @ignore_user_abort(true);
        $rel    = trim((string)($_POST['file'] ?? ''));
        $mode   = (string)($_POST['mode'] ?? '');
        $tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? []))));
        $typed  = mb_strtoupper(trim((string)($_POST['confirm_text'] ?? '')), 'UTF-8');
        $back   = route('backupRestore.index', $rel !== '' ? ['file' => $rel] : []);

        $cand = backupRestoreFind($rel);
        if (!$cand) return $this->fail($back, 'Nie znaleziono wskazanej kopii bazy.');
        if (!in_array($mode, ['full', 'tables'], true)) return $this->fail($back, 'Wybierz zakres przywracania: wybrane tabele albo cała baza.');
        if (empty($_POST['confirm_check']) || !in_array($typed, ['PRZYWRÓĆ', 'PRZYWROC'], true)) {
            return $this->fail($back, 'Nie potwierdzono przywrócenia: zaznacz pole „Rozumiem…” i wpisz słowo PRZYWRÓĆ.');
        }

        $res = backupRestoreRun($cand, $mode, $tables);

        $details = [
            'file'        => $cand['rel'],
            'backup_time' => date('Y-m-d H:i:s', $cand['ts']),
            'mode'        => $mode,
            'ok'          => $res['ok'],
            'msg'         => $res['msg'],
            'safety_copy' => $res['safety'] ?? null,
            'tables'      => $res['tables'] ?? ($mode === 'tables' ? $tables : null),
            'created'     => $res['created'] ?? [],
            'newer_kept'  => $res['newer'] ?? [],
        ];
        $action = !$res['ok'] ? 'backups.restore_failed' : ($mode === 'full' ? 'backups.restore_full' : 'backups.restore_tables');
        try { audit_log($action, $details); } catch (\Throwable $e) { error_log('[backup_restore] audit_log: ' . $e->getMessage()); }
        admin_audit_migrate();
        admin_audit(
            $res['ok'] ? 'backup_restore' : 'backup_restore_failed',
            'backups',
            ($mode === 'full' ? 'Cała baza' : 'Tabele: ' . implode(', ', array_keys($res['tables'] ?? array_flip($tables)))) . ' — ' . $res['msg'],
            0,
            $cand['name']
        );

        $_SESSION[self::RESULT_KEY] = $res + ['mode' => $mode, 'file' => $cand['rel']];
        return Router::redirect($back);
    }

    private function fail(string $back, string $msg): ?string
    {
        $_SESSION[self::RESULT_KEY] = ['ok' => false, 'msg' => $msg];
        return Router::redirect($back);
    }
}
