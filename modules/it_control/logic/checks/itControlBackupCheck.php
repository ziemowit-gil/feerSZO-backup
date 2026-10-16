<?php
/** Kopie zapasowe: wiek ostatniego udanego przebiegu agenta backup vs próg RPO (includes/backup.php). */

class ItControlBackupCheck extends ItControlCheck
{
    public function id(): string { return 'ops.backup'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Kopie zapasowe'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        require_once dirname(__DIR__, 4) . '/includes/backup.php';
        $last = backup_last_ok_ts();
        $maxH = backup_max_age_hours();
        $r->metric('rpo_hours', $maxH)->metric('encrypted', backup_encryption_enabled());
        $count = 0;
        foreach (glob(backup_root() . '/*') ?: [] as $f) if (is_file($f) && backup_kind(basename($f))) $count++;
        $r->metric('files', $count);
        if ($last === 0) {
            $r->finding('no_marker', 'medium', 'Brak znacznika udanego backupu',
                'Agent backup (cron/agents/backup.php) nie zakończył jeszcze udanego przebiegu albo nie działa.');
            $r->recommend('Uruchom agenta backup z panelu Harmonogram CRON i sprawdź Admin → Kopie zapasowe.');
            $r->summary = "Brak udanego backupu; plików kopii: {$count}.";
            return;
        }
        $ageH = round(($ctx->now - $last) / 3600, 1);
        $r->metric('last_ok', date('Y-m-d H:i:s', $last))->metric('age_hours', $ageH);
        if ($ageH > $maxH * max(1, (int)$ctx->setting('backup_fail_factor'))) {
            $r->finding('stale', 'high', "Ostatni udany backup {$ageH} h temu", "Próg RPO: {$maxH} h.");
            $r->recommend('Sprawdź log agenta backup i miejsce na dysku — kopie nie powstają.');
        } elseif ($ageH > $maxH) {
            $r->finding('stale', 'medium', "Backup starszy niż próg RPO: {$ageH} h", "Próg: {$maxH} h.");
        }
        $r->summary = "Ostatni udany backup: {$ageH} h temu (próg {$maxH} h), plików kopii: {$count}.";
    }
}
