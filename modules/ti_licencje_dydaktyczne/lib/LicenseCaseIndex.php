<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseCaseIndex.php — spis dokumentów sprawy / operacji AM (druk AM56 + spis elektroniczny pod QR)
 * i historia zmian na wniosku AM (jedna oś czasu: korekty AMSK z polami „było → jest”, zmiany UPN, rotacje ASK-UP1, pakiety i regeneracje,
 * zmiany danych osoby z portalu, zdarzenia wniosku i decyzji).
 */
require_once __DIR__ . '/LicenseCorrection.php';
require_once __DIR__ . '/LicenseDocPackage.php';
require_once __DIR__ . '/LicenseRotation.php';
require_once __DIR__ . '/Am7TenantAccount.php';

final class LicenseCaseIndex
{
    // ── Token spisu elektronicznego (QR): L{id}.{mac} / O{id}.{mac}; mac = HMAC(APP_KEY) — bez tabeli, bez danych osobowych w adresie ──

    public static function token(string $scope, int $id): string
    {
        $k = $scope === 'operation' ? 'O' : 'L';
        return $k . $id . '.' . substr(hash_hmac('sha256', 'spis:' . $k . ':' . $id, (string)APP_KEY), 0, 16);
    }

    /** @return array{0:string,1:int}|null [scope, id] po poprawnym tokenie */
    public static function parseToken(string $token): ?array
    {
        if (!preg_match('/^([LO])(\d{1,9})\.([0-9a-f]{16})$/', $token, $m)) return null;
        $scope = $m[1] === 'O' ? 'operation' : 'license';
        return hash_equals(self::token($scope, (int)$m[2]), $token) ? [$scope, (int)$m[2]] : null;
    }

    public static function url(string $scope, int $id): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/licencja.php?' . http_build_query(['_route' => '/spis/' . self::token($scope, $id)]);
    }

    public static function number(array $target, string $targetNumber): string
    {
        return $targetNumber . ' · AM56';
    }

    // ── Spis dokumentów ───────────────────────────────────────────────────────────

    /**
     * @return list<array{group:string,label:string,number:string,code:string,state:string,color:string,date:string}>
     */
    public static function documents(string $scope, int $id): array
    {
        [$t, $tn] = LicenseCorrection::target($scope, $id);
        $rows = [];
        $add = function (string $group, string $label, string $number, string $code, string $state, string $color, string $date) use (&$rows) {
            $rows[] = ['group' => $group, 'label' => $label, 'number' => $number, 'code' => $code, 'state' => $state, 'color' => $color, 'date' => $date];
        };
        $rev = LicenseCodes::revision($scope, $id);
        $reg = [];   // rejestr druków: symbol → wiersz obowiązujący
        foreach (LicenseDocPackage::issues($scope, $id) as $i) if ($i['status'] === 'valid') $reg[$i['symbol']] = $i;
        $codeOf = fn(string $num, string $sym) => LicenseCodes::forNumber($num, $sym);
        $ok = fn() => ['Obowiązuje', '#008a00'];

        if ($scope === 'license') {
            $am7 = $t['form'] === LicenseForm::Am7;
            $rd = $t['form']->requestDoc();
            $num = LicenseDocument::number($rd, $t);
            [$st, $col] = $ok();
            $add('Wnioski i decyzje', 'Wniosek ' . $rd->symbol(), $num, $codeOf($num, $t['form']->value), $st, $col, (string)($t['started_at'] ?: $t['created_at']));
            if ($am7 && !empty($t['decision'])) {
                $dd = !empty($t['decision']['grant']) ? LicenseDoc::Am7Grant : LicenseDoc::Am7Refusal;
                $dn = LicenseDocument::number($dd, $t);
                $add('Wnioski i decyzje', 'Decyzja ' . $dd->symbol(), $dn, $codeOf($dn, 'AM7'), empty($t['decision']['approved_at']) ? 'Projekt' : 'Obowiązuje', empty($t['decision']['approved_at']) ? '#f0a30a' : '#008a00', (string)($t['decision']['approved_at'] ?? ''));
            }
            foreach (db_all("SELECT d.*, a.holder_name FROM license_declarations d JOIN license_assignments a ON a.id = d.assignment_id WHERE a.license_id = ? ORDER BY d.id", [$id]) as $d) {
                $add('Oświadczenia', 'Oświadczenie ' . $d['doc'] . ' — ' . $d['holder_name'], (string)$d['number'], $codeOf((string)$d['number'], 'AM5'), (string)$d['status'], '#647687', (string)($d['signed_at'] ?? ''));
            }
            $row = db_one("SELECT tenant_upn, tenant_created_at FROM licenses WHERE id = ?", [$id]);
            if ($am7 && trim((string)($row['tenant_upn'] ?? '')) !== '') {
                $n90 = LicenseDocument::am90Number($t, Am7TenantAccount::am90Revision($id));
                $add('Konto w tenancie', 'Potwierdzenie wykonania operacji (AM90)', $n90, $codeOf($n90, 'AM90'), 'Obowiązuje', '#008a00', (string)$row['tenant_created_at']);
                foreach (Am7TenantAccount::upnChanges($id) as $k => $ch) {
                    $n91 = LicenseDocument::am91Number($t, $k + 1);
                    $add('Konto w tenancie', 'Zmiana loginu (UPN) — AM91: ' . $ch['old'] . ' → ' . $ch['new'], $n91, $codeOf($n91, 'AM91'), 'Obowiązuje', '#008a00', (string)$ch['at']);
                }
            }
        } else {
            $f = $t['formEnum'];
            $add('Wnioski i decyzje', $f->title(), (string)$t['number'], $codeOf((string)$t['number'], $f->value), $t['statusEnum']->label(), $t['statusEnum']->color(), (string)$t['created_at']);
        }
        foreach (LicenseCorrection::forTarget($scope, $id) as $c) {
            $add('Korekty (AMSK)', 'Korekta ' . LicenseCorrection::code((int)$c['id']) . ' — ' . count($c['itemsList']) . ' pól', LicenseCorrection::docNumber($c, true), LicenseCorrection::code((int)$c['id']),
                LicenseCorrection::statusLabel($c['status']), LicenseCorrection::statusColor($c['status']), (string)($c['applied_at'] ?: $c['created_at']));
        }
        foreach (LicenseRotation::forTarget($scope, $id) as $r) {
            $add('Rotacje (ASK-UP1)', 'Uzasadnienie rotacji ' . LicenseRotation::code((int)$r['id']), LicenseRotation::docNumber($r), LicenseRotation::code((int)$r['id']),
                LicenseRotation::statusLabel($r['status']), LicenseRotation::statusColor($r['status']), (string)($r['confirmed_at'] ?: $r['created_at']));
        }
        foreach (db_all("SELECT * FROM license_doc_packages WHERE scope = ? AND target_id = ? ORDER BY id", [$scope, $id]) as $p) {
            $add('Pakiety', 'Pakiet druków R' . $p['revision'] . ' (AMSG/' . $p['gen_no'] . ($p['locked_at'] ? ', zablokowany' : '') . ')', $tn . ' · AMSG/' . $p['gen_no'], LicenseDocPackage::ppCode((int)$p['id']) . ' (PP)',
                $p['locked_at'] ? 'Zablokowany' : 'Aktualny', $p['locked_at'] ? '#1e3a8a' : '#008a00', (string)$p['created_at']);
        }
        foreach (LicenseDocPackage::issues($scope, $id, 'void') as $i) {
            $add('Unieważnione', $i['label'] . ' (R' . $i['revision'] . ')', (string)$i['doc_number'], (string)$i['code'], 'UNIEWAŻNIONY', '#a20025', (string)$i['void_at']);
        }
        return $rows;
    }

    /** Druk AM56 (PDF): spis dokumentów + skrót historii + QR do spisu elektronicznego. @return array{out:array,number:string} */
    public static function output(string $scope, int $id): array
    {
        [$t, $tn] = LicenseCorrection::target($scope, $id);
        $person = (string)($scope === 'operation' ? ($t['account_name'] ?? '') : ($t['interested_name'] ?? ''));
        $html = (new LicenseDocument())->am56Html($scope, $id, $tn, $person, self::documents($scope, $id), self::changes($scope, $id));
        $number = self::number($t, $tn);
        return ['out' => LicenseDocument::render($html, 'Spis dokumentów sprawy (AM56) — ' . $tn, $number, 'AM56'), 'number' => $number];
    }

    // ── Historia zmian ────────────────────────────────────────────────────────────

    /**
     * Oś czasu zmian (najnowsze pierwsze): [at, kind, color, title, detail, by, link(route name + params)|null].
     * @return list<array{at:string,kind:string,color:string,title:string,detail:string,by:string,link:?array}>
     */
    public static function changes(string $scope, int $id): array
    {
        $out = [];
        $add = function (string $at, string $kind, string $color, string $title, string $detail, string $by, ?array $link = null) use (&$out) {
            $out[] = ['at' => $at, 'kind' => $kind, 'color' => $color, 'title' => $title, 'detail' => $detail, 'by' => $by, 'link' => $link];
        };
        foreach (LicenseCorrection::forTarget($scope, $id) as $c) {
            $code = LicenseCorrection::code((int)$c['id']);
            $lines = [];
            foreach ($c['itemsList'] as $it) $lines[] = $it['label'] . ': ' . $it['old_disp'] . ' → ' . $it['new_disp'];
            $detail = implode("\n", $lines) . "\nUzasadnienie: " . $c['reason'];
            if ($c['status'] === LicenseCorrection::APPLIED) $add((string)$c['applied_at'], 'Korekta AMSK', '#7c2d12', $code . ' — wprowadzona po podpisie kierownika', $detail, (string)$c['applied_name'], ['ld.corr', ['id' => $c['id']]]);
            $add((string)$c['created_at'], 'Korekta AMSK', '#7c2d12', $code . ' — wystawiona' . ($c['status'] === LicenseCorrection::CANCELLED ? ' (anulowana)' : ($c['status'] === LicenseCorrection::PENDING ? ' (czeka na podpis)' : '')), $detail, (string)$c['created_name'], ['ld.corr', ['id' => $c['id']]]);
        }
        if ($scope === 'license') {
            foreach (Am7TenantAccount::upnChanges($id) as $ch) {
                $add((string)$ch['at'], 'Zmiana UPN (AM91)', '#9f1239', $ch['old'] . ' → ' . $ch['new'], 'Uzasadnienie: ' . $ch['reason'], (string)($ch['by'] ?? ''));
            }
            foreach (db_all("SELECT h.*, a.holder_name FROM license_holder_changes h JOIN license_assignments a ON a.id = h.assignment_id WHERE a.license_id = ? ORDER BY h.id", [$id]) as $h) {
                $add((string)($h['created_at'] ?? ''), 'Dane osoby (portal)', '#647687', ($h['field'] ?? $h['field_name'] ?? 'pole') . ' — ' . $h['holder_name'], (string)($h['old_value'] ?? '') . ' → ' . (string)($h['new_value'] ?? '') . ' [' . ($h['status'] ?? '') . ']', (string)$h['holder_name']);
            }
        }
        foreach (LicenseRotation::forTarget($scope, $id) as $r) {
            $add((string)$r['created_at'], 'Rotacja (ASK-UP1)', '#14532d', LicenseRotation::code((int)$r['id']) . ': ' . ($r['from_holder'] ?: '—') . ' → ' . $r['to_holder'], 'Uzasadnienie: ' . $r['reason'] . ' [' . LicenseRotation::statusLabel($r['status']) . ']', (string)$r['created_name'], ['ld.rot', ['id' => $r['id']]]);
        }
        foreach (db_all("SELECT * FROM license_doc_packages WHERE scope = ? AND target_id = ? ORDER BY id", [$scope, $id]) as $p) {
            $add((string)$p['created_at'], 'Pakiet druków', '#1e3a8a', 'Pakiet R' . $p['revision'] . ' — generowanie nr ' . $p['gen_no'] . ($p['locked_at'] ? ' (zablokowany ' . $p['locked_at'] . ')' : ''), 'Powód: ' . $p['gen_reason'] . '; druki poprzedniej rewizji unieważnione.', (string)$p['created_name']);
        }
        $ev = $scope === 'operation'
            ? db_all("SELECT * FROM license_events WHERE operation_id = ? AND (action LIKE 'op.%') ORDER BY id", [$id])
            : db_all("SELECT * FROM license_events WHERE license_id = ? AND (action LIKE 'request.%' OR action LIKE 'decision.%' OR action LIKE 'tenant.%' OR action LIKE 'assign%' OR action LIKE 'suspend%' OR action LIKE 'revoke%') ORDER BY id", [$id]);
        foreach ($ev as $e) $add((string)$e['created_at'], 'Zdarzenie', '#0050ef', (string)$e['action'], (string)$e['note'], (string)$e['actor_name']);
        usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }
}
