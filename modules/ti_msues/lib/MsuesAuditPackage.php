<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesAuditPackage.php — paczka audytowa ZIP usługi (grupy TI) pod audyt MSUES (WUP Kraków).
 *
 * Zawartość:
 *   00_raport_gotowosci.pdf      — checklista kadry i wskaźnik gotowości (mPDF)
 *   00_spis_zawartosci.csv       — spis plików z sumami SHA-256 (UTF-8 z BOM, średnik)
 *   NN_Nazwisko_Imie/            — dla każdej osoby z kadry:
 *       oswiadczenie_OKK-1_vN.pdf (wydruk z systemu), oswiadczenie_podpisane.* (skan), CV/dyplomy/certyfikaty/umowy (dokumenty kadry),
 *       umowa_<nr>.* (plik umowy z Rejestru Umów, gdy jest), umowy.txt (wykaz umów z rejestru)
 * Gdy wymóg formalnego oświadczenia jest włączony, a ktoś z kadry go nie ma — generowanie jest zablokowane (DomainException).
 */
require_once __DIR__ . '/MsuesComplianceService.php';
require_once __DIR__ . '/DeclarationDocument.php';

final class MsuesAuditPackage
{
    public function __construct(private readonly MsuesComplianceService $svc = new MsuesComplianceService()) {}

    /** Buduje ZIP w pliku tymczasowym. @return array{path:string, name:string, readiness:array, files:int} */
    public function build(int $serviceId, array $actor): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Brak rozszerzenia PHP zip.');
        $r = $this->svc->serviceReadiness($serviceId);
        if (MsuesComplianceService::declarationRequired()) {
            foreach ($r['staff'] as $p) {
                $v = $this->svc->validateTrainerReadiness((int)$p['id'], $serviceId);
                if (!$v->allowed) throw new DomainException('Paczka audytowa zablokowana: ' . $v->message());
            }
        }
        if (!$r['staff']) throw new DomainException('Usługa nie ma przypisanej kadry — nie ma czego pakować.');

        $tmp = tempnam(sys_get_temp_dir(), 'msues_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nie udało się utworzyć pliku ZIP.');
        $manifest = [];
        $add = function (string $inZip, string $bytes, string $what) use ($zip, &$manifest): void {
            $zip->addFromString($inZip, $bytes);
            $manifest[] = [$inZip, $what, strlen($bytes), hash('sha256', $bytes)];
        };
        $addFile = function (string $inZip, string $full, string $what) use ($zip, &$manifest): void {
            $zip->addFile($full, $inZip);
            $manifest[] = [$inZip, $what, (int)filesize($full), (string)hash_file('sha256', $full)];
        };
        $add('00_raport_gotowosci.pdf', $this->reportPdf($r, $actor), 'Raport gotowości usługi do audytu');

        $repo = $this->svc->declarations();
        $docs = $this->svc->documents();
        $kinds = MsuesChecklist::documentKinds();
        foreach (array_values($r['staff']) as $i => $p) {
            $dir = sprintf('%02d_%s/', $i + 1, self::slug((string)$p['name']));
            $decl = $repo->approvedFor((int)$p['id'], $serviceId) ?? $repo->latestFor((int)$p['id'], $serviceId);
            if ($decl) {
                $add($dir . 'oswiadczenie_OKK-1_v' . $decl['version'] . '.pdf', DeclarationDocument::pdf($decl), 'Oświadczenie OKK-1 — ' . $decl['statusEnum']->label());
                if ($decl['scan_path'] !== '' && ($f = MsuesStorage::fullPath((string)$decl['scan_path']))) {
                    $addFile($dir . 'oswiadczenie_podpisane.' . strtolower(pathinfo($f, PATHINFO_EXTENSION)), $f, 'Skan podpisanego oświadczenia');
                }
            }
            foreach ($docs->list((int)$p['id']) as $j => $doc) {
                if (!($f = MsuesStorage::fullPath((string)$doc['file_path']))) continue;
                $addFile($dir . sprintf('%s_%02d_%s.%s', $doc['kind'], $j + 1, self::slug((string)$doc['title']), strtolower(pathinfo($f, PATHINFO_EXTENSION))), $f,
                    ($kinds[$doc['kind']] ?? $doc['kind']) . ': ' . $doc['title'] . (MsuesStaffDocuments::isValid($doc) ? '' : ' (PO TERMINIE WAŻNOŚCI ' . $doc['valid_to'] . ')'));
            }
            $lines = [];
            foreach (MsuesSzoData::contracts((int)$p['id']) as $c) {
                $lines[] = $c['label'] . ' ' . $c['numer_umowy'] . ' | status: ' . $c['status'] . ' | od ' . $c['data_zawarcia'] . ' do ' . ($c['data_zakonczenia'] ?: '—');
                if ($c['active'] && $c['plik_umowy'] !== '' && ($f = MsuesStorage::fullPath($c['plik_umowy']))) {
                    $addFile($dir . 'umowa_' . self::slug($c['numer_umowy'] ?: $c['type'] . $c['id']) . '.' . strtolower(pathinfo($f, PATHINFO_EXTENSION)), $f, $c['label'] . ' ' . $c['numer_umowy'] . ' (Rejestr Umów)');
                }
            }
            $add($dir . 'umowy.txt', "Umowy z Rejestru Umów SZO — " . $p['name'] . "\n\n" . ($lines ? implode("\n", $lines) : 'brak umów w rejestrze') . "\n", 'Wykaz umów');
        }
        $csv = "\xEF\xBB\xBFplik;opis;rozmiar_B;sha256\n";
        foreach ($manifest as $m) $csv .= implode(';', array_map(fn($x) => '"' . str_replace('"', '""', (string)$x) . '"', $m)) . "\n";
        $zip->addFromString('00_spis_zawartosci.csv', $csv);
        $zip->setArchiveComment('Paczka audytowa MSUES — ' . $r['service']['name'] . ' — ' . msuesNow());
        $zip->close();
        if (function_exists('audit_log')) { try { audit_log('msues.audit_package', ['service_id' => $serviceId, 'files' => count($manifest), 'score' => $r['score']], $actor['id'] ?: null); } catch (\Throwable $e) {} }
        return ['path' => $tmp, 'name' => 'Paczka_audytowa_MSUES_' . self::slug($r['service']['name']) . '_' . date('Ymd') . '.zip', 'readiness' => $r, 'files' => count($manifest) + 1];
    }

    private function reportPdf(array $r, array $actor): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $h = '<style>body{font-family:dejavusans;font-size:9pt} h1{font-size:13pt;margin:0 0 1mm} .m{color:#444;font-size:8pt}
              table{width:100%;border-collapse:collapse;margin-top:2mm} th,td{border:0.25mm solid #000;padding:1mm 1.4mm;vertical-align:top}
              th{background:#bfbfbf;text-align:left;font-size:8pt} .ok{color:#1f7a4d;font-weight:bold} .no{color:#b02a37;font-weight:bold} .p{background:#f2f2f2;font-weight:bold}</style>'
            . '<h1>Raport gotowości usługi do audytu MSUES</h1>'
            . '<div class="m">' . $e(MsuesSzoData::orgName()) . ' · usługa: <b>' . $e($r['service']['name']) . '</b> · wygenerowano ' . $e(date('d.m.Y H:i')) . ' przez ' . $e($actor['name'] ?? '') . '</div>'
            . '<p>Wskaźnik gotowości: <b>' . (int)$r['score'] . '%</b> (' . (int)$r['done'] . ' z ' . (int)$r['total'] . ' wymaganych kryteriów) · '
            . 'wymóg formalnego oświadczenia: <b>' . ($r['enforced'] ? 'włączony' : 'wyłączony') . '</b></p>'
            . '<table><tr><th width="28%">Kryterium</th><th width="10%">Stan</th><th>Szczegóły</th></tr>';
        foreach ($r['staff'] as $p) {
            $h .= '<tr><td class="p" colspan="3">' . $e($p['name']) . ' — ' . $e($p['role']) . ' (' . (int)$p['done'] . '/' . (int)$p['required'] . ')</td></tr>';
            foreach ($p['items'] as $it) {
                $h .= '<tr><td>' . $e($it['label']) . ($it['required'] ? '' : ' <i>(opcjonalne)</i>') . '</td><td class="' . ($it['ok'] ? 'ok">spełnione' : 'no">brak') . '</td><td>' . $e($it['detail']) . '</td></tr>';
            }
        }
        $h .= '</table>';
        $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 10, 'margin_bottom' => 12, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $m->SetHTMLFooter('<div style="font-size:7pt;text-align:right">Strona {PAGENO}/{nbpg}</div>');
        $m->WriteHTML($h);
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private static function slug(string $s): string
    {
        $a = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^A-Za-z0-9]+/', '_', $a), '_') ?: 'plik';
    }
}
