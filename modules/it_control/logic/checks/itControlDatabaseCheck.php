<?php
/** Baza danych: połączenie i czas odpowiedzi, integralność (SQLite quick_check), rozmiar i WAL. */

class ItControlDatabaseCheck extends ItControlCheck
{
    public function id(): string { return 'ops.database'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Baza danych'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $t0 = hrtime(true);
        try {
            db()->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $e) {
            $r->finding('down', 'critical', 'Baza danych nie odpowiada', mb_substr($e->getMessage(), 0, 300));
            $r->recommend('Sprawdź dostępność serwera bazy / pliku SQLite i uprawnienia do niego.');
            return;
        }
        $ms = round((hrtime(true) - $t0) / 1e6, 1);
        $r->metric('engine', DB_TYPE)->metric('ping_ms', $ms);
        if ($ms > 500) $r->finding('slow', 'medium', "Wolna odpowiedź bazy: {$ms} ms", 'SELECT 1 powinien trwać kilka ms.');

        if (DB_TYPE === 'sqlite') {
            $size = (int)@filesize(DB_PATH);
            $wal  = (int)@filesize(DB_PATH . '-wal');
            $r->metric('size_mb', round($size / 1048576, 1))->metric('wal_mb', round($wal / 1048576, 1));
            if ($size > 0 && $size < 500 * 1048576) {
                $res = db()->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
                $r->metric('quick_check', $res[0] ?? '');
                if (($res[0] ?? '') !== 'ok') {
                    $r->finding('integrity', 'critical', 'Uszkodzenie bazy SQLite (quick_check)', mb_substr(implode('; ', array_slice($res, 0, 5)), 0, 400));
                    $r->recommend('Wstrzymaj zapisy, wykonaj kopię pliku bazy i przywróć ostatnią poprawną kopię (Admin → Kopie zapasowe).');
                }
            } else {
                $r->metric('quick_check', 'pominięty (baza > 500 MB)');
            }
            if ($wal > 256 * 1048576) {
                $r->finding('wal', 'medium', 'Duży plik WAL: ' . round($wal / 1048576) . ' MB', 'Checkpoint nie nadąża — długie transakcje lub zablokowany czytelnik.');
                $r->recommend('Wykonaj PRAGMA wal_checkpoint(TRUNCATE) w oknie serwisowym (Admin → Narzędzia bazy danych).');
            }
            if (!is_writable(DB_PATH)) $r->finding('readonly', 'critical', 'Plik bazy nie jest zapisywalny', DB_PATH);
            $r->summary = sprintf('SQLite %.1f MB, WAL %.1f MB, ping %s ms, integralność: %s.', $size / 1048576, $wal / 1048576, $ms, $r->metrics['quick_check']);
        } else {
            $r->summary = "MySQL odpowiada, ping {$ms} ms.";
        }
    }
}
