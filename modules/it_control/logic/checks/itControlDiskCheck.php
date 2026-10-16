<?php
/** Miejsce na dysku i zapisywalność katalogów aplikacji (kod, uploads, backups). */

class ItControlDiskCheck extends ItControlCheck
{
    public function id(): string { return 'ops.disk'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Miejsce na dysku'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $root = dirname(__DIR__, 4);
        $dirs = ['aplikacja' => $root, 'uploads' => rtrim(UPLOAD_DIR, '/'), 'backups' => $root . '/backups'];
        $warn = (int)$ctx->setting('disk_warn_pct');
        $fail = (int)$ctx->setting('disk_fail_pct');
        $vols = [];                                         // wolumen => dane (katalogi na tym samym dysku razem)
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) continue;
            if ($label !== 'aplikacja' && !is_writable($dir)) {
                $r->finding('unwritable:' . $label, 'high', "Katalog {$label} nie jest zapisywalny", $dir);
                $r->recommend("Popraw uprawnienia katalogu {$label} ({$dir}) dla użytkownika serwera WWW/CRON.");
            }
            $total = @disk_total_space($dir);
            $free  = @disk_free_space($dir);
            if (!$total || $free === false) continue;
            $key = $total . ':' . (int)($free / 1048576);       // ten sam wolumen = jeden wpis
            if (isset($vols[$key])) { $vols[$key]['dirs'] .= ', ' . $label; continue; }
            $pct = round(100 - $free / $total * 100, 1);
            $vols[$key] = ['dirs' => $label, 'used_pct' => $pct, 'free_gb' => round($free / 1073741824, 1), 'total_gb' => round($total / 1073741824, 1)];
            if ($pct >= $fail) {
                $r->finding('disk:' . $label, 'critical', "Dysk prawie pełny ({$label}): {$pct}%", sprintf('Wolne %.1f GB z %.1f GB.', $free / 1073741824, $total / 1073741824));
                $r->recommend('Natychmiast zwolnij miejsce: stare kopie w backups/, pliki tymczasowe, logi; rozważ powiększenie wolumenu.');
            } elseif ($pct >= $warn) {
                $r->finding('disk:' . $label, 'medium', "Mało miejsca na dysku ({$label}): {$pct}%", sprintf('Wolne %.1f GB.', $free / 1073741824));
                $r->recommend('Zaplanuj porządki na dysku lub powiększenie wolumenu, zanim zabraknie miejsca na kopie zapasowe.');
            }
        }
        $vols = array_values($vols);
        $r->metric('volumes', $vols);
        $r->summary = $vols ? implode('; ', array_map(fn($v) => "{$v['dirs']}: {$v['used_pct']}% zajęte, {$v['free_gb']} GB wolne", $vols)) : 'Brak danych o dysku.';
    }
}
