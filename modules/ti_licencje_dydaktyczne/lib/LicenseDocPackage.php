<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseDocPackage.php — pakiet poprawionych druków AM po zatwierdzonej korekcie AMSK.
 *
 * Po wdrożeniu korekty (LicenseCorrection::approve) SZO:
 *   1. generuje WSZYSTKIE bieżące druki AM sprawy / operacji z poprawionych danych (wniosek, decyzja AM7, AM90/n, AM91 / druk operacji)
 *      — w stopce każdego kod Code128 z NOWĄ rewizją (LD000002/AM7-2/R2),
 *   2. UNIEWAŻNIA poprzednie druki: wpisy rejestru license_doc_issues → „void” (z numerem korekty); wskaźniki „oficjalnej kopii” sprawy
 *      (request_pdf, decision_pdf, pdf_path) wskazują już nowe pliki, stare pliki zostają w rejestrze jako unieważnione,
 *   3. wysyła pakiet (poprawione druki + AMSK) jako ZIP z hasłem do osoby i kierownika; hasło — osobnym SMS-em (LicenseZipDelivery).
 * Skan starego kodu (rewizja niższa niż bieżąca albo brak rewizji) pokazuje komunikat „druk unieważniony”.
 */
require_once __DIR__ . '/LicenseCorrection.php';
require_once __DIR__ . '/LicenseZipDelivery.php';
require_once __DIR__ . '/Am7TenantAccount.php';
require_once __DIR__ . '/LicenseCaseIndex.php';
require_once dirname(__DIR__, 2) . '/espis/lib/Espis.php';

final class LicenseDocPackage
{
    public static function get(int $id): ?array
    {
        ldMigrate();
        return db_one("SELECT * FROM license_doc_packages WHERE id = ?", [$id]) ?: null;
    }

    public static function forCorrection(int $correctionId): ?array
    {
        ldMigrate();
        return db_one("SELECT * FROM license_doc_packages WHERE correction_id = ?", [$correctionId]) ?: null;
    }

    /** Rejestr druków celu (najnowsze pierwsze); $status: valid | void | null (wszystkie). */
    public static function issues(string $scope, int $targetId, ?string $status = null): array
    {
        ldMigrate();
        return db_all("SELECT * FROM license_doc_issues WHERE scope = ? AND target_id = ?" . ($status ? " AND status = " . db()->quote($status) : '') . " ORDER BY revision DESC, id DESC", [$scope, $targetId]);
    }

    public static function packageIssues(int $packageId): array
    {
        return db_all("SELECT * FROM license_doc_issues WHERE package_id = ? ORDER BY id", [$packageId]);
    }

    /** Stan zeskanowanego kodu: valid | void | unknown (+ bieżąca rewizja). */
    public static function codeState(string $scope, int $id, string $symbol, int $rev): array
    {
        $cur = LicenseCodes::revision($scope, $id);
        if ($rev === 0) return ['state' => $cur > 1 ? 'void' : 'valid', 'current' => $cur, 'note' => 'druk bez oznaczenia wersji'];
        if ($rev < $cur) return ['state' => 'void', 'current' => $cur, 'note' => ''];
        if ($rev > $cur) return ['state' => 'unknown', 'current' => $cur, 'note' => ''];
        return ['state' => 'valid', 'current' => $cur, 'note' => ''];
    }

    // ── Generowanie ───────────────────────────────────────────────────────────────

    /**
     * Generuje pakiet po zatwierdzonej korekcie i unieważnia poprzednie druki. @return int id pakietu
     */
    public function generate(int $correctionId, array $actor, string $reason = 'Automatycznie po zatwierdzeniu korekty AMSK'): int
    {
        ldMigrate();
        $c = LicenseCorrection::get($correctionId);
        if (!$c) throw new DomainException('Nie ma takiej korekty.');
        if ($c['status'] !== LicenseCorrection::APPLIED) throw new DomainException('Pakiet powstaje po zatwierdzeniu korekty (podpis kierownika).');
        if (self::forCorrection($correctionId)) throw new DomainException('Pakiet dla tej korekty został już wygenerowany.');
        $scope = (string)$c['scope']; $tid = (int)$c['target_id'];
        [$t, $number] = LicenseCorrection::target($scope, $tid);
        $rev = LicenseCodes::revision($scope, $tid);
        $caseCode = LicenseCodes::caseCode($scope, $tid);
        $this->espisPrepare($scope, $tid, $number, $actor);   // koszulka EZD przed renderem — druki dostają QR eSpisu w stopce
        $docs = $this->collect($scope, $tid, $t);

        $now = ldNow(); $by = (string)($actor['name'] ?? '');
        db_insert('license_doc_packages', ['scope' => $scope, 'target_id' => $tid, 'revision' => $rev, 'correction_id' => $correctionId, 'doc_count' => count($docs), 'gen_no' => 1, 'gen_reason' => $reason,
            'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => $by, 'created_at' => $now]);
        $pid = (int)db()->lastInsertId();
        $voided = 0;
        foreach ($docs as [$token, $label, $num, $rel, $sha, $oldRel, $oldSha]) {
            $code = LicenseCodes::formCode($caseCode, $token) . '/R' . $rev;
            $prev = db_all("SELECT id FROM license_doc_issues WHERE scope = ? AND target_id = ? AND symbol = ? AND status = 'valid'", [$scope, $tid, $token]);
            foreach ($prev as $p) { db_exec("UPDATE license_doc_issues SET status = 'void', void_correction_id = ?, void_at = ? WHERE id = ?", [$correctionId, $now, (int)$p['id']]); $voided++; }
            if (!$prev && $oldRel !== '' && $oldRel !== $rel) {   // druk sprzed rejestru: zapisujemy go jako unieważniony (R poprzednia)
                db_insert('license_doc_issues', ['scope' => $scope, 'target_id' => $tid, 'symbol' => $token, 'label' => $label, 'doc_number' => $num,
                    'code' => LicenseCodes::formCode($caseCode, $token) . '/R' . max(1, $rev - 1), 'revision' => max(1, $rev - 1), 'status' => 'void', 'file_path' => $oldRel, 'sha256' => $oldSha,
                    'void_correction_id' => $correctionId, 'void_at' => $now, 'created_name' => 'sprzed korekty', 'created_at' => $now]);
                $voided++;
            }
            db_insert('license_doc_issues', ['scope' => $scope, 'target_id' => $tid, 'symbol' => $token, 'label' => $label, 'doc_number' => $num, 'code' => $code, 'revision' => $rev,
                'status' => 'valid', 'file_path' => $rel, 'sha256' => $sha, 'package_id' => $pid, 'correction_id' => $correctionId, 'created_name' => $by, 'created_at' => $now]);
        }
        $this->espisFile($scope, $tid, $number, $rev, $docs, $c, $actor);
        $ev = ['action' => 'amsk.package', 'note' => 'Wygenerowano pakiet druków AM (rewizja R' . $rev . ', ' . count($docs) . ' dok.), unieważniono poprzednie (' . $voided . ') po korekcie ' . LicenseCorrection::code($correctionId) . '.',
               'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => $by, 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => $now];
        db_insert('license_events', ($scope === 'operation' ? ['license_id' => 0, 'operation_id' => $tid] : ['license_id' => $tid]) + $ev);
        return $pid;
    }

    /**
     * Renderuje bieżące druki celu z aktualnych danych i zapisuje pliki (+ wskaźniki „oficjalnych kopii” sprawy).
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}> [token, label, numer, plik, sha, poprzedni plik, poprzedni sha]
     */
    private function collect(string $scope, int $tid, array $t): array
    {
        $svc = new LicenseManagementService();
        $docs = [];   // [token, label, number, rel, sha, oldRel, oldSha]

        if ($scope === 'license') {
            $am7 = $t['form'] === LicenseForm::Am7;
            $old = [(string)$t['request_pdf'], (string)$t['request_pdf_sha256']];
            $r = $svc->renderRequest($tid);
            $docs[] = [$am7 ? 'AM7-1' : 'AM5-1', 'Wniosek ' . ($am7 ? 'AM7/1' : 'AM5/1'), $r['number'], $r['path'], $r['sha'], $old[0], $old[1]];
            if ($am7 && !empty($t['decision']['approved_at'])) {
                $old = [(string)$t['decision_pdf'], (string)$t['decision_pdf_sha256']];
                $d = (new Am7Workflow($svc))->storeDecision($tid);
                $row = db_one("SELECT decision_pdf_sha256 FROM licenses WHERE id = ?", [$tid]);
                $docs[] = [LicenseCodes::symbolToken($d['doc']->symbol()), 'Decyzja ' . $d['doc']->symbol(), $d['number'], $d['path'], (string)$row['decision_pdf_sha256'], $old[0], $old[1]];
            }
            $l2 = $svc->licenses->get($tid);
            if ($am7 && trim((string)(db_one("SELECT tenant_upn FROM licenses WHERE id = ?", [$tid])['tenant_upn'] ?? '')) !== '') {
                $acc = new Am7TenantAccount($svc);
                $o = $acc->am90Output($l2);
                [$rel, $sha] = LicenseStorage::put($o['out']['bytes'], $o['out']['ext'], 'am90p' . $tid);
                $docs[] = [LicenseCodes::docToken($o['number'], 'AM90'), 'Potwierdzenie wykonania operacji (AM90)', $o['number'], $rel, $sha, '', ''];
                if (Am7TenantAccount::upnChanges($tid)) {
                    $o = $acc->am91Output($l2);
                    [$rel, $sha] = LicenseStorage::put($o['out']['bytes'], $o['out']['ext'], 'am91p' . $tid);
                    $docs[] = [LicenseCodes::docToken($o['number'], 'AM91'), 'Zmiana loginu (UPN) — AM91', $o['number'], $rel, $sha, '', ''];
                }
            }
        } else {
            $ops = new LicenseOperationService();
            $op = $ops->get($tid);
            $f = $op['formEnum'];
            $out = $ops->document($op);
            [$rel, $sha] = LicenseStorage::put($out['bytes'], $out['ext'], 'op' . $tid . 'p');
            db_exec("UPDATE license_operations SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?", [$rel, $sha, $tid]);
            $docs[] = [LicenseCodes::symbolToken($f->value), $f->title(), (string)$op['number'], $rel, $sha, (string)$op['pdf_path'], (string)$op['pdf_sha256']];
        }

        return $docs;
    }

    /**
     * Regeneracja pakietu BIEŻĄCEJ rewizji: druki odtwarzane z aktualnych danych, pliki w rejestrze podmieniane (ta sama rewizja i kody),
     * nic nie jest unieważniane; pakiet oznaczony jako niewysłany. Nieaktualne rewizje nie podlegają regeneracji.
     */
    public function regenerate(int $packageId, array $actor, string $reason = 'Regeneracja z aktualnych danych'): int
    {
        ldMigrate();
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        $scope = (string)$pk['scope']; $tid = (int)$pk['target_id'];
        if (!empty($pk['locked_at'])) throw new DomainException('Pakiet jest zablokowany (' . $pk['locked_at'] . ') — nie można go regenerować. Zmiany wymagają nowej korekty AMSK.');
        if ((int)$pk['revision'] !== LicenseCodes::revision($scope, $tid)) throw new DomainException('To pakiet nieaktualnej rewizji (R' . $pk['revision'] . ') — regenerować można tylko pakiet bieżącej rewizji R' . LicenseCodes::revision($scope, $tid) . '.');
        [$t] = LicenseCorrection::target($scope, $tid);
        $caseCode = LicenseCodes::caseCode($scope, $tid);
        $rev = (int)$pk['revision'];
        $this->espisPrepare($scope, $tid, (string)(LicenseCorrection::target($scope, $tid)[1]), $actor);
        $docs = $this->collect($scope, $tid, $t);
        $now = ldNow(); $by = (string)($actor['name'] ?? '');
        foreach ($docs as [$token, $label, $num, $rel, $sha]) {
            $row = db_one("SELECT id FROM license_doc_issues WHERE package_id = ? AND symbol = ? AND status = 'valid' ORDER BY id DESC LIMIT 1", [$packageId, $token]);
            if ($row) {
                db_exec("UPDATE license_doc_issues SET file_path = ?, sha256 = ?, doc_number = ?, label = ?, created_at = ?, created_name = ? WHERE id = ?", [$rel, $sha, $num, $label, $now, $by, (int)$row['id']]);
            } else {   // nowy rodzaj druku (np. AM91 po zmianie UPN) — dopisujemy jako obowiązujący
                db_insert('license_doc_issues', ['scope' => $scope, 'target_id' => $tid, 'symbol' => $token, 'label' => $label, 'doc_number' => $num, 'code' => LicenseCodes::formCode($caseCode, $token) . '/R' . $rev,
                    'revision' => $rev, 'status' => 'valid', 'file_path' => $rel, 'sha256' => $sha, 'package_id' => $packageId, 'correction_id' => (int)$pk['correction_id'], 'created_name' => $by, 'created_at' => $now]);
            }
        }
        $cReg = LicenseCorrection::get((int)$pk['correction_id']);
        $this->espisFile($scope, $tid, (string)(LicenseCorrection::target($scope, $tid)[1]), $rev, $docs, $cReg, $actor);
        db_exec("UPDATE license_doc_packages SET doc_count = ?, created_at = ?, created_name = ?, created_by = ?, sent_at = NULL, manager_sent_at = NULL, gen_no = gen_no + 1, gen_reason = ? WHERE id = ?", [count($docs), $now, $by, ($actor['id'] ?? 0) ?: null, $reason, $packageId]);
        $ev = ['action' => 'amsk.package_regen', 'note' => 'Zregenerowano pakiet druków AM (rewizja R' . $rev . ', ' . count($docs) . ' dok.) z aktualnych danych — wymaga ponownej wysyłki.',
               'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => $by, 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => $now];
        db_insert('license_events', ($scope === 'operation' ? ['license_id' => 0, 'operation_id' => $tid] : ['license_id' => $tid]) + $ev);
        return count($docs);
    }

    // ── ZIP i wysyłka ─────────────────────────────────────────────────────────────

    /**
     * Blokada pakietu: zamraża zawartość (brak regeneracji) i generuje JEDEN scalony PDF: spis AM56 (z QR do spisu elektronicznego) + wszystkie druki pakietu
     * (wnioski, decyzje, AM90/AM91, druk operacji) — BEZ druków AMSK (korekta, AMSK/1). @return string ścieżka względna PDF
     */
    public function lock(int $packageId, array $actor): string
    {
        ldMigrate();
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        if (!empty($pk['locked_at'])) throw new DomainException('Pakiet jest już zablokowany.');
        $bytes = $this->buildMerged($pk);
        [$rel, $sha] = LicenseStorage::put($bytes, 'pdf', 'pakiet_scalony_' . $packageId);
        $now = ldNow();
        db_exec("UPDATE license_doc_packages SET locked_at = ?, locked_by = ?, locked_name = ?, merged_path = ?, merged_sha256 = ? WHERE id = ?", [$now, ($actor['id'] ?? 0) ?: null, (string)($actor['name'] ?? ''), $rel, $sha, $packageId]);
        $ev = ['action' => 'amsk.package_lock', 'note' => 'Zablokowano pakiet R' . $pk['revision'] . ' i wygenerowano scalony PDF (spis AM56 + ' . count(self::packageIssues($packageId)) . ' druków, bez AMSK); SHA-256 ' . substr($sha, 0, 16) . '.',
               'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => $now];
        db_insert('license_events', ($pk['scope'] === 'operation' ? ['license_id' => 0, 'operation_id' => (int)$pk['target_id']] : ['license_id' => (int)$pk['target_id']]) + $ev);
        return $rel;
    }

    /** Scalony PDF: AM56 + druki pakietu (tylko PDF; bez AMSK). */
    private function buildMerged(array $pk): string
    {
        $tmp = []; $paths = [];
        try {
            $a56 = LicenseCaseIndex::output((string)$pk['scope'], (int)$pk['target_id']);
            if ($a56['out']['ext'] !== 'pdf') throw new DomainException('Scalanie wymaga PDF (mPDF niedostępny).');
            $f = tempnam(sys_get_temp_dir(), 'am56'); file_put_contents($f, $a56['out']['bytes']); $tmp[] = $f; $paths[] = $f;
            $n = 0;
            foreach (self::packageIssues((int)$pk['id']) as $i) {
                $p = LicenseStorage::fullPath((string)$i['file_path']);
                if (!$p || !str_ends_with($p, '.pdf')) throw new DomainException('Druk „' . $i['label'] . '” nie jest plikiem PDF — nie można scalić pakietu.');
                $paths[] = $p; $n++;
            }
            if ($n === 0) throw new DomainException('Pakiet nie zawiera druków do scalenia.');
            return self::mergePdf($paths);
        } finally { foreach ($tmp as $f) @unlink($f); }
    }

    /** Scala pliki PDF w jeden (mPDF + FPDI). @param list<string> $paths @return string bajty PDF */
    private static function mergePdf(array $paths): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmpDir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmpDir]);
        $pdf->SetAuthor(ldOrgName());
        $pdf->SetTitle('Pakiet druków AM (scalony)');
        foreach ($paths as $path) {
            $cnt = $pdf->setSourceFile($path);
            for ($p = 1; $p <= $cnt; $p++) {
                $tpl = $pdf->importPage($p);
                $pdf->AddPage();
                $pdf->useTemplate($tpl);
            }
        }
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Bajty scalonego PDF zablokowanego pakietu. */
    public static function mergedBytes(array $pk): string
    {
        $p = LicenseStorage::fullPath((string)$pk['merged_path']);
        if (!$p) throw new DomainException('Brak scalonego PDF — pakiet nie został zablokowany.');
        return (string)file_get_contents($p);
    }

    private static function espisRef(string $scope): string
    {
        return $scope === 'operation' ? 'operacja_am' : 'licencja_am';
    }

    /** Koszulka EZD sprawy AM istnieje PRZED renderem druków (QR eSpisu w stopce). Bez EZD — po cichu pomijane. */
    private function espisPrepare(string $scope, int $tid, string $number, array $actor): void
    {
        try { Espis::ensureSprawa(self::espisRef($scope), $tid, ($scope === 'operation' ? 'Operacja AM ' : 'Sprawa AM ') . $number, 'Dokumenty licencji AM: wnioski, decyzje, AM90/AM91, korekty AMSK, pakiety.', (int)($actor['id'] ?? 0)); }
        catch (\Throwable $e) { error_log('ld espisPrepare: ' . $e->getMessage()); }
    }

    /** Wpisuje druki pakietu (i druk AMSK korekty) do koszulki EZD i rejestru eSpisu. Błąd nie przerywa generowania pakietu. */
    private function espisFile(string $scope, int $tid, string $number, int $rev, array $docs, ?array $c, array $actor): void
    {
        try {
            $caseCode = LicenseCodes::caseCode($scope, $tid);
            $common = ['ref_type' => self::espisRef($scope), 'ref_id' => $tid, 'sprawa_title' => ($scope === 'operation' ? 'Operacja AM ' : 'Sprawa AM ') . $number,
                       'sprawa_desc' => 'Dokumenty licencji AM.', 'uid' => (int)($actor['id'] ?? 0), 'user_name' => (string)($actor['name'] ?? '')];
            foreach ($docs as [$token, $label, $num, $rel]) {
                $p = LicenseStorage::fullPath((string)$rel);
                if (!$p) continue;
                Espis::file($common + ['doc_key' => $token, 'label' => $label, 'number' => $num, 'code' => LicenseCodes::formCode($caseCode, $token) . '/R' . $rev, 'revision' => $rev,
                    'bytes' => (string)file_get_contents($p), 'file_name' => ldFileName($num) . '.' . pathinfo($p, PATHINFO_EXTENSION)]);
            }
            if ($c) {   // korekta AMSK (druk dla osoby) — dokument sprawy, bez rewizji (jedno wystawienie)
                $a = (new LicenseCorrection())->document($c, false);
                Espis::file($common + ['doc_key' => 'AMSK-' . (int)$c['id'], 'label' => 'Korekta ' . LicenseCorrection::code((int)$c['id']) . ' (AMSK)', 'number' => $a['number'], 'code' => LicenseCorrection::code((int)$c['id']),
                    'revision' => 1, 'bytes' => $a['out']['bytes'], 'file_name' => ldFileName($a['number']) . '.' . $a['out']['ext']]);
            }
        } catch (\Throwable $e) { error_log('ld espisFile: ' . $e->getMessage()); }
    }

    /** Kontekst rejestru przekazań haseł (PP). */
    private static function handoverCtx(array $pk, string $number, string $kind): array
    {
        return ['package_id' => (int)$pk['id'], 'scope' => (string)$pk['scope'], 'target_id' => (int)$pk['target_id'], 'target_number' => $number, 'kind' => $kind];
    }

    public static function ppCode(int $packageId): string
    {
        return 'PP-' . str_pad((string)$packageId, 6, '0', STR_PAD_LEFT);
    }

    /** Przekazania haseł pakietu (najstarsze pierwsze). */
    public static function handovers(int $packageId): array
    {
        ldMigrate();
        return db_all("SELECT * FROM license_pwd_handovers WHERE package_id = ? ORDER BY id", [$packageId]);
    }

    /** Druk PP (AM Pre-shared Protocol): protokół przekazania hasła do pakietu. @return array{out:array,number:string} */
    public function ppOutput(array $pk): array
    {
        $c = LicenseCorrection::get((int)$pk['correction_id']);
        if (!$c) throw new DomainException('Brak korekty pakietu.');
        $number = (string)$c['target_number'] . ' · ' . self::ppCode((int)$pk['id']) . ' · PP';
        $html = (new LicenseDocument())->ppHtml($pk, $c, self::handovers((int)$pk['id']), self::packageIssues((int)$pk['id']));
        return ['out' => LicenseDocument::render($html, 'Protokół przekazania hasła do pakietu (PP) — ' . $c['target_number'], $number, 'PP'), 'number' => $number];
    }

    /** Wpisy ZIP-a: [nazwa pliku => bajty] — poprawione druki pakietu + druk AMSK (korekta) + SPIS.txt (kody, rewizja, lista unieważnionych). */
    public function entries(int $packageId, bool $withAmsg = false): array
    {
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        $c = LicenseCorrection::get((int)$pk['correction_id']);
        $out = []; $list = [];
        foreach (self::packageIssues($packageId) as $i) {
            $p = LicenseStorage::fullPath((string)$i['file_path']);
            if (!$p) continue;
            $ext = pathinfo($p, PATHINFO_EXTENSION) ?: 'pdf';
            $out[ldFileName($i['doc_number']) . '.' . $ext] = (string)file_get_contents($p);
            $list[] = $i['label'] . ' — ' . $i['doc_number'] . ' — kod ' . $i['code'];
        }
        if ($c) {
            $a = (new LicenseCorrection())->document($c, false);
            $out[ldFileName($a['number']) . '.' . $a['out']['ext']] = $a['out']['bytes'];
        }
        $void = [];
        foreach (db_all("SELECT label, doc_number, code FROM license_doc_issues WHERE scope = ? AND target_id = ? AND status = 'void' AND void_correction_id = ? ORDER BY id", [$pk['scope'], $pk['target_id'], $pk['correction_id']]) as $v) {
            $void[] = $v['label'] . ' — ' . $v['doc_number'] . ' — kod ' . $v['code'];
        }
        $txt = "PAKIET POPRAWIONYCH DOKUMENTÓW AM — rewizja R" . $pk['revision'] . "\r\nSprawa: " . ($c['target_number'] ?? '') . "\r\nKorekta: " . ($c ? LicenseCorrection::code((int)$c['id']) : '')
             . "\r\nWygenerowano: " . $pk['created_at'] . "\r\n\r\nDOKUMENTY OBOWIĄZUJĄCE (zastępują dotychczasowe):\r\n" . implode("\r\n", $list ?: ['—'])
             . "\r\n\r\nDOKUMENTY UNIEWAŻNIONE (nie używać):\r\n" . implode("\r\n", $void ?: ['—'])
             . "\r\n\r\nKod Code128 w stopce każdego druku zawiera rewizję (np. LD000002/AM7-2/R" . $pk['revision'] . "). Druk z niższą rewizją lub bez rewizji jest nieaktualny.\r\n";
        if ($withAmsg && !str_starts_with((string)$pk['gen_reason'], 'Automatycznie')) {   // protokół regeneracji — dla kierownika, nie dla osoby
            $a = $this->amsgOutput($pk);
            $out[ldFileName($a['number']) . '.' . $a['out']['ext']] = $a['out']['bytes'];
        }
        try { $a56 = LicenseCaseIndex::output((string)$pk['scope'], (int)$pk['target_id']); $out[ldFileName($a56['number']) . '.' . $a56['out']['ext']] = $a56['out']['bytes']; }
        catch (\Throwable $e) { error_log('ld am56: ' . $e->getMessage()); }
        $out['SPIS.txt'] = $txt;
        return $out;
    }

    /** Druk AMSG „Regeneracja pakietu” (protokół: powód, rewizja, kody, unieważnione). @return array{out:array,number:string} */
    public function amsgOutput(array $pk): array
    {
        $c = LicenseCorrection::get((int)$pk['correction_id']);
        if (!$c) throw new DomainException('Brak korekty pakietu.');
        $void = db_all("SELECT label, doc_number, code FROM license_doc_issues WHERE scope = ? AND target_id = ? AND status = 'void' AND void_correction_id = ? ORDER BY id", [$pk['scope'], $pk['target_id'], $pk['correction_id']]);
        $html = (new LicenseDocument())->amsgHtml($pk, $c, self::packageIssues((int)$pk['id']), $void);
        $number = (string)$c['target_number'] . ' · AMSG/' . (int)$pk['gen_no'];
        return ['out' => LicenseDocument::render($html, 'Regeneracja pakietu druków AM (AMSG) — ' . $c['target_number'], $number, 'AMSG'), 'number' => $number];
    }

    /** Jawny ZIP do pobrania w panelu (bez hasła — dostęp tylko kadra zarządzająca). @return array{bytes:string,name:string} */
    public function zip(int $packageId): array
    {
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        $tmp = tempnam(sys_get_temp_dir(), 'amp');
        $z = new \ZipArchive();
        if ($z->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nie udało się utworzyć ZIP.');
        foreach ($this->entries($packageId, true) as $name => $bytes) $z->addFromString($name, $bytes);
        $z->close();
        $bytes = (string)file_get_contents($tmp); @unlink($tmp);
        return ['bytes' => $bytes, 'name' => 'pakiet-AM-R' . $pk['revision'] . '-' . LicenseCodes::caseCode((string)$pk['scope'], (int)$pk['target_id']) . '.zip'];
    }

    /**
     * Wysyła pakiet: ZIP z hasłem do osoby i kierownika (kopia e-mailem), hasło SMS-em do osoby i kierownika.
     * Treść: przeprosiny + „pakiet poprawionych dokumentów AM; poprzednie druki są unieważnione”.
     * @return string raport
     */
    public function send(int $packageId, array $actor): string
    {
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        [$t, $number] = LicenseCorrection::target((string)$pk['scope'], (int)$pk['target_id']);
        $contact = $pk['scope'] === 'operation'
            ? ['name' => $t['account_name'], 'email' => $t['account_email'], 'phone' => $t['account_phone']]
            : (new LicenseManagementService())->interestedContact($t);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rev = (int)$pk['revision'];
        $body = '<p>Dzień dobry ' . $e($contact['name']) . ',</p>'
              . '<p><b>Przepraszamy za pomyłkę.</b> W załączniku przesyłamy <b>pakiet poprawionych dokumentów AM</b> (rewizja <b>R' . $rev . '</b>) w sprawie <b>' . $e($number) . '</b> — w archiwum ZIP zabezpieczonym hasłem.</p>'
              . '<p>Poprawione dokumenty <b>zastępują dotychczasowe</b>; dotychczasowe druki są <b>unieważnione</b> (kod w stopce ma niższą rewizję). Spis dokumentów i kody — w pliku SPIS.txt w archiwum.</p>'
              . '<p>Hasło do archiwum wysłaliśmy <b>SMS-em</b> na numer kończący się na {PHONE}. Dokumenty nie wymagają podpisu. <b>Nie jest wymagane żadne działanie z Twojej strony.</b></p>';
        $svc = new LicenseManagementService();
        $r = LicenseZipDelivery::deliver($svc, $contact, $number, ['type' => $pk['scope'] === 'operation' ? 'operation' : 'license', 'id' => (int)$pk['target_id']], $this->entries($packageId),
            'Pakiet poprawionych dokumentów AM (R' . $rev . ') — przepraszamy za pomyłkę, sprawa ' . $number, 'Pakiet poprawionych dokumentów AM', $body, $actor,
            'pakiet_am_' . $packageId, 'Pakiet_poprawionych_dokumentow_AM_R' . $rev . '_' . $number, true, self::handoverCtx($pk, $number, 'initial'));
        if ($r['mailed']) db()->prepare("UPDATE license_doc_packages SET sent_at = ? WHERE id = ?")->execute([ldNow(), $packageId]);
        $ev = ['action' => 'amsk.package_sent', 'note' => 'Pakiet R' . $rev . ': ' . $r['report'], 'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''),
               'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => ldNow()];
        db_insert('license_events', ($pk['scope'] === 'operation' ? ['license_id' => 0, 'operation_id' => (int)$pk['target_id']] : ['license_id' => (int)$pk['target_id']]) + $ev);
        return 'Pakiet R' . $rev . ' (' . $r['report'] . ')';
    }

    /**
     * Ten sam pakiet (+ druk AMSG, gdy generowanie było ręczne / regeneracją) ZIP-em z hasłem DO KIEROWNIKA; hasło SMS-em. Osoba dostaje pakiet osobno (send()).
     * @return string raport
     */
    public function sendToManagers(int $packageId, array $actor): string
    {
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        [$t, $number] = LicenseCorrection::target((string)$pk['scope'], (int)$pk['target_id']);
        $person = (string)($pk['scope'] === 'operation' ? ($t['account_name'] ?? '') : ($t['interested_name'] ?? ''));
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rev = (int)$pk['revision'];
        $body = '<p>Dzień dobry,</p><p>w załączniku <b>pakiet poprawionych dokumentów AM</b> (rewizja <b>R' . $rev . '</b>) w sprawie <b>' . $e($number) . '</b> (' . $e($person) . ') — archiwum ZIP zabezpieczone hasłem, hasło dostaniesz osobnym SMS-em.</p>'
              . '<p><b>Powód wygenerowania:</b> ' . $e($pk['gen_reason']) . '. Druk <b>AMSG — Regeneracja pakietu</b> (w archiwum) dokumentuje wygenerowanie; dotychczasowe druki są unieważnione, spis i kody w SPIS.txt.</p>'
              . '<p>Osoba, której dotyczy sprawa, otrzyma pakiet po jego wysłaniu z karty korekty' . ($pk['sent_at'] ? ' (<b>już wysłano ' . $e(ldDate(substr((string)$pk['sent_at'], 0, 10))) . '</b>)' : ' (<b>jeszcze nie wysłano</b>)') . '.</p>';
        $r = LicenseZipDelivery::deliverManagers(new LicenseManagementService(), $number, $person, ['type' => $pk['scope'] === 'operation' ? 'operation' : 'license', 'id' => (int)$pk['target_id']],
            $this->entries($packageId, true), '[dla kierownika] Pakiet poprawionych dokumentów AM (R' . $rev . ', AMSG/' . (int)$pk['gen_no'] . ') — sprawa ' . $number, 'Pakiet poprawionych dokumentów AM — dla kierownika', $body, $actor,
            'pakiet_am_kier_' . $packageId, 'Pakiet_AM_R' . $rev . '_AMSG' . (int)$pk['gen_no'] . '_' . $number, self::handoverCtx($pk, $number, 'initial'));
        if ($r['mailed'] > 0) db()->prepare("UPDATE license_doc_packages SET manager_sent_at = ? WHERE id = ?")->execute([ldNow(), $packageId]);
        $ev = ['action' => 'amsk.package_mgr', 'note' => 'Pakiet R' . $rev . ' (AMSG/' . (int)$pk['gen_no'] . ') do kierownika: ' . $r['report'], 'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''),
               'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => ldNow()];
        db_insert('license_events', ($pk['scope'] === 'operation' ? ['license_id' => 0, 'operation_id' => (int)$pk['target_id']] : ['license_id' => (int)$pk['target_id']]) + $ev);
        return 'do kierownika (' . $r['report'] . ')';
    }

    /**
     * Regeneracja HASŁA do pakietu — tylko kierownik (rola „kierownik” panelu TI) albo administrator. Nowy ZIP z nowym hasłem trafia e-mailem do zlecającego,
     * hasło — SMS-em na wskazany numer. Stan wysyłek pakietu (do osoby / kierownika) nie zmienia się; w dzienniku: kto i na jaki numer (końcówka), bez hasła.
     * @return string raport
     */
    public function regeneratePassword(int $packageId, string $phone, array $actor): string
    {
        if (!Am7Workflow::canApprove((int)($actor['id'] ?? 0))) throw new DomainException('Hasło do archiwum pakietu regeneruje wyłącznie kierownik (rola „kierownik” w panelu TI) albo administrator.');
        $pk = self::get($packageId);
        if (!$pk) throw new DomainException('Nie ma takiego pakietu.');
        [$t, $number] = LicenseCorrection::target((string)$pk['scope'], (int)$pk['target_id']);
        $person = (string)($pk['scope'] === 'operation' ? ($t['account_name'] ?? '') : ($t['interested_name'] ?? ''));
        $me = db_one("SELECT name, email FROM users WHERE id = ?", [(int)$actor['id']]) ?: ['name' => (string)($actor['name'] ?? ''), 'email' => ''];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rev = (int)$pk['revision'];
        $body = '<p>Dzień dobry ' . $e($me['name']) . ',</p><p>na Twoje polecenie wygenerowaliśmy <b>nowe hasło</b> do pakietu poprawionych dokumentów AM (rewizja <b>R' . $rev . '</b>) w sprawie <b>' . $e($number) . '</b>. '
              . 'W załączniku nowe archiwum ZIP; <b>nowe hasło wysłaliśmy SMS-em</b> na wskazany numer. Poprzednie archiwa zachowują dotychczasowe hasła.</p>';
        $r = LicenseZipDelivery::regeneratePassword(new LicenseManagementService(), ['name' => (string)$me['name'], 'email' => (string)$me['email']], $phone, $number, $person,
            ['type' => $pk['scope'] === 'operation' ? 'operation' : 'license', 'id' => (int)$pk['target_id']], $this->entries($packageId, true),
            '[nowe hasło] Pakiet poprawionych dokumentów AM (R' . $rev . ') — sprawa ' . $number, 'Pakiet poprawionych dokumentów AM — nowe hasło', $body, $actor,
            'pakiet_am_haslo_' . $packageId, 'Pakiet_AM_R' . $rev . '_nowe_haslo_' . $number, self::handoverCtx($pk, $number, 'regenerated'));
        $ev = ['action' => 'amsk.package_pwd', 'note' => 'Regeneracja hasła do pakietu R' . $rev . ' (kierownik ' . ($actor['name'] ?? '') . '): ' . $r['report'], 'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''),
               'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => ldNow()];
        db_insert('license_events', ($pk['scope'] === 'operation' ? ['license_id' => 0, 'operation_id' => (int)$pk['target_id']] : ['license_id' => (int)$pk['target_id']]) + $ev);
        return 'Hasło do pakietu: ' . $r['report'];
    }

    /** Brak pakietu → ręczne generowanie: pakiet + druk AMSG + ten sam pakiet do kierownika (osoba — osobnym przyciskiem). @return string raport */
    public function generateManual(int $correctionId, array $actor): string
    {
        $pid = $this->generate($correctionId, $actor, 'Brak pakietu po zatwierdzeniu korekty — ręczne generowanie');
        $rep = 'wygenerowano pakiet i druk AMSG, poprzednie druki unieważniono';
        try { $rep .= '; ' . $this->sendToManagers($pid, $actor); } catch (\Throwable $e) { $rep .= '; UWAGA: pakiet nie został wysłany do kierownika (' . $e->getMessage() . ')'; }
        return $rep;
    }

    /** Regeneracja bieżącej rewizji: ponownie druki + AMSG (nowy numer generowania) + ten sam pakiet do kierownika. @return string raport */
    public function regenerateManual(int $packageId, array $actor): string
    {
        $n = $this->regenerate($packageId, $actor, 'Ręczna regeneracja z aktualnych danych');
        $rep = 'zregenerowano pakiet (' . $n . ' dok.) i druk AMSG; pakiet oznaczony jako niewysłany do osoby';
        try { $rep .= '; ' . $this->sendToManagers($packageId, $actor); } catch (\Throwable $e) { $rep .= '; UWAGA: pakiet nie został wysłany do kierownika (' . $e->getMessage() . ')'; }
        return $rep;
    }

    /**
     * Wykonuje zaległe, automatyczne pakiety (termin package_due_at minął): dla każdego celu NAJNOWSZA zatwierdzona korekta bez pakietu;
     * starsze niespakietowane korekty tego samego celu są oznaczane jako pominięte (pakiet obejmuje już ich zmiany). Jeżeli najnowsza korekta
     * celu jeszcze czeka na swój termin — wstrzymujemy też starsze (jeden pakiet na serię korekt).
     * @return list<string> linie raportu
     */
    public function runDue(int $limit = 10): array
    {
        ldMigrate();
        $now = ldNow(); $lines = [];
        $all = db_all("SELECT c.* FROM license_corrections c WHERE c.status = 'applied' AND COALESCE(c.package_skipped, 0) = 0 AND c.package_due_at IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM license_doc_packages p WHERE p.correction_id = c.id) ORDER BY c.id");
        $byTarget = [];
        foreach ($all as $c) $byTarget[$c['scope'] . ':' . $c['target_id']][] = $c;
        foreach ($byTarget as $list) {
            if ($limit <= 0) break;
            $newest = end($list);
            if ((string)$newest['package_due_at'] > $now) continue;   // najnowsza jeszcze nie ma terminu — czekamy na całą serię
            $actor = ['id' => (int)($newest['applied_by'] ?? 0), 'name' => trim((string)$newest['applied_name']) . ' (automat)'];
            foreach ($list as $c) {
                if ((int)$c['id'] === (int)$newest['id']) continue;
                db_exec("UPDATE license_corrections SET package_skipped = 1 WHERE id = ?", [(int)$c['id']]);
                $lines[] = LicenseCorrection::code((int)$c['id']) . ': pominięta — pakiet obejmuje nowszą korektę ' . LicenseCorrection::code((int)$newest['id']);
            }
            $lines[] = LicenseCorrection::code((int)$newest['id']) . ': ' . $this->run((int)$newest['id'], $actor);
            $limit--;
        }
        return $lines;
    }

    /**
     * Całość po zatwierdzeniu korekty: pakiet + unieważnienie + wysyłka. Błąd wysyłki NIE cofa korekty (zostaje przycisk ręczny).
     * @return string raport
     */
    public function run(int $correctionId, array $actor): string
    {
        $out = [];
        try { $pid = $this->generate($correctionId, $actor); $out[] = 'wygenerowano pakiet poprawionych druków AM i unieważniono poprzednie'; }
        catch (\Throwable $e) { return 'UWAGA: pakiet druków NIE powstał (' . $e->getMessage() . ') — wygeneruj go ręcznie na karcie korekty'; }
        try { $out[] = $this->send($pid, $actor); }
        catch (\Throwable $e) {
            $out[] = 'UWAGA: pakiet nie został wysłany do osoby (' . $e->getMessage() . ') — wyślij go z karty korekty';
            try { $out[] = 'ten sam pakiet ' . $this->sendToManagers($pid, $actor); } catch (\Throwable $e2) { $out[] = 'UWAGA: pakiet nie trafił też do kierownika (' . $e2->getMessage() . ')'; }
        }
        return implode('; ', $out);
    }
}
