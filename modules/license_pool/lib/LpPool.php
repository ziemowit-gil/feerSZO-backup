<?php
declare(strict_types=1);

/**
 * modules/license_pool/lib/LpPool.php — pula licencji Fundacji: produkty, import, przydziały (jedyna droga zapisu do lp_*).
 *
 * Przydział uczestnikowi TI (holder 'client') zapisuje też przypisanie w k30_ti_client_licenses przez
 * TiLicencjeManager (forma rozliczenia obowiązkowa, powiadomienia kursanta) — widać go wtedy w panelu uczestnika.
 */
require_once __DIR__ . '/lpSchema.php';
require_once __DIR__ . '/LpImport.php';
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';

final class LpPool
{
    private const CTX = 'lp_licenses.code';

    private static function ready(): void { lp_migrate(); }

    public static function today(): string { return date('Y-m-d'); }

    // ── Produkty ────────────────────────────────────────────────────────────

    /** Produkty z podsumowaniem miejsc (łącznie / zajęte / wolne; wygasłe licencje nie liczą się do wolnych). */
    public static function products(bool $activeOnly = false): array
    {
        self::ready();
        $t = self::today();
        return db_all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM lp_licenses l WHERE l.product_id = p.id AND l.status = 'active') AS licenses,
                    (SELECT COALESCE(SUM(l.seats),0) FROM lp_licenses l WHERE l.product_id = p.id AND l.status = 'active'
                        AND (l.valid_until IS NULL OR l.valid_until >= ?)) AS seats_total,
                    (SELECT COUNT(*) FROM lp_assignments a JOIN lp_licenses l ON l.id = a.license_id
                        WHERE l.product_id = p.id AND a.revoked_at IS NULL) AS seats_used,
                    (SELECT COUNT(*) FROM lp_licenses l WHERE l.product_id = p.id AND l.status = 'active'
                        AND l.valid_until IS NOT NULL AND l.valid_until < ?) AS expired
               FROM lp_products p " . ($activeOnly ? 'WHERE p.is_active = 1 ' : '') . "ORDER BY p.is_active DESC, p.name COLLATE NOCASE",
            [$t, $t]
        );
    }

    public static function product(int $id): ?array
    {
        self::ready();
        return db_one("SELECT * FROM lp_products WHERE id = ?", [$id]);
    }

    /** @return array{ok:bool,errors:list<string>,id:int} */
    public static function saveProduct(array $in, int $id = 0, ?int $by = null): array
    {
        self::ready();
        $d = [];
        $errors = [];
        foreach (['name' => ['Nazwa', 190], 'vendor' => ['Producent', 190], 'category' => ['Kategoria', 190], 'vendor_url' => ['Link', 500], 'notes' => ['Uwagi', 500], 'instructions' => ['Instrukcja aktywacji', 2000]] as $k => [$label, $max]) {
            $d[$k] = trim((string)($in[$k] ?? ''));
            if (mb_strlen($d[$k]) > $max) $errors[] = "Pole „{$label}” jest za długie (maks. {$max}).";
        }
        if ($d['name'] === '') $errors[] = 'Podaj nazwę produktu.';
        if ($d['vendor_url'] !== '' && !preg_match('~^https?://[^\s]+$~i', $d['vendor_url'])) $errors[] = 'Link musi zaczynać się od http:// lub https://.';
        $dup = db_one("SELECT id FROM lp_products WHERE name = ? COLLATE NOCASE AND id <> ?", [$d['name'], $id]);
        if ($dup) $errors[] = 'Produkt o tej nazwie już istnieje.';
        if ($errors) return ['ok' => false, 'errors' => $errors, 'id' => $id];
        $d['is_active'] = $id > 0 ? (!empty($in['is_active']) ? 1 : 0) : 1;
        if ($id > 0) {
            db_exec("UPDATE lp_products SET name=?, vendor=?, category=?, vendor_url=?, notes=?, instructions=?, is_active=? WHERE id=?",
                [$d['name'], $d['vendor'], $d['category'], $d['vendor_url'], $d['notes'], $d['instructions'], $d['is_active'], $id]);
        } else {
            $id = db_insert('lp_products', $d + ['created_by' => $by]);
        }
        audit_log('license_pool.product_saved', ['product_id' => $id, 'name' => $d['name']], $by);
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    // ── Licencje (klucze) ───────────────────────────────────────────────────

    /** Licencje produktu z liczbą zajętych miejsc; kod tylko w postaci wskazówki (ostatnie znaki). */
    public static function licenses(int $productId): array
    {
        self::ready();
        return db_all(
            "SELECT l.id, l.product_id, l.code_hint, l.seats, l.valid_until, l.note, l.status, l.created_at,
                    (SELECT COUNT(*) FROM lp_assignments a WHERE a.license_id = l.id AND a.revoked_at IS NULL) AS used
               FROM lp_licenses l WHERE l.product_id = ? ORDER BY l.status, l.id", [$productId]
        );
    }

    public static function license(int $id): ?array
    {
        self::ready();
        return db_one(
            "SELECT l.id, l.product_id, l.code_hint, l.seats, l.valid_until, l.note, l.status, l.created_at, p.name AS product_name, p.ti_license_id,
                    (SELECT COUNT(*) FROM lp_assignments a WHERE a.license_id = l.id AND a.revoked_at IS NULL) AS used
               FROM lp_licenses l JOIN lp_products p ON p.id = l.product_id WHERE l.id = ?", [$id]
        );
    }

    public static function isUsable(array $lic, ?string $today = null): bool
    {
        $today ??= self::today();
        return $lic['status'] === 'active' && ($lic['valid_until'] === null || $lic['valid_until'] === '' || $lic['valid_until'] >= $today);
    }

    /** Odsłonięcie kodu — zawsze z wpisem w audycie. */
    public static function revealCode(int $licenseId, ?int $by): ?string
    {
        self::ready();
        $r = db_one("SELECT code_enc FROM lp_licenses WHERE id = ?", [$licenseId]);
        if (!$r) return null;
        audit_log('license_pool.code_revealed', ['license_id' => $licenseId], $by);
        return fcDecrypt($r['code_enc'], self::CTX);
    }

    /** Wycofuje licencję z puli (nie da się jej już przydzielać); przydziały zostają. */
    public static function retire(int $licenseId, ?int $by): void
    {
        self::ready();
        db_exec("UPDATE lp_licenses SET status = 'retired' WHERE id = ?", [$licenseId]);
        audit_log('license_pool.retired', ['license_id' => $licenseId], $by);
    }

    // ── Import TXT ──────────────────────────────────────────────────────────

    /**
     * Podgląd importu bez zapisu: dla każdej linii oznacza duplikat (ten sam kod w produkcie albo powtórzony w pliku)
     * i nowy produkt. Wynik zawiera kody — trzymaj go tylko w sesji.
     * @return array{rows:list<array>,errors:list<array>,new_products:list<string>,counts:array{ok:int,dup:int,bad:int}}
     */
    public static function previewImport(string $raw, string $defaultProduct = ''): array
    {
        self::ready();
        $p = LpImport::parse($raw, $defaultProduct);
        $known = [];
        foreach (db_all("SELECT id, name FROM lp_products") as $r) $known[mb_strtolower($r['name'])] = (int)$r['id'];
        $seen = []; $newProducts = []; $dup = 0;
        foreach ($p['rows'] as &$row) {
            $pid = $known[mb_strtolower($row['product'])] ?? 0;
            $row['product_id'] = $pid;
            if (!$pid) $newProducts[mb_strtolower($row['product'])] = $row['product'];
            $hash = fcBlind($row['code'], self::CTX);
            $key = mb_strtolower($row['product']) . '|' . $hash;
            $row['duplicate'] = isset($seen[$key]) || ($pid && db_one("SELECT 1 FROM lp_licenses WHERE product_id = ? AND code_hash = ?", [$pid, $hash]));
            $seen[$key] = true;
            if ($row['duplicate']) $dup++;
        }
        unset($row);
        return ['rows' => $p['rows'], 'errors' => $p['errors'], 'new_products' => array_values($newProducts),
                'counts' => ['ok' => count($p['rows']) - $dup, 'dup' => $dup, 'bad' => count($p['errors'])]];
    }

    /**
     * Zapisuje podgląd do puli (duplikaty pomijane, brakujące produkty tworzone). Wszystko w jednej transakcji.
     * @return array{added:int,duplicates:int,invalid:int,import_id:int,products_created:int}
     */
    public static function commitImport(array $preview, string $filename, ?int $by): array
    {
        self::ready();
        $pdo = db();
        $added = 0; $dups = 0; $created = 0;
        $pdo->beginTransaction();
        try {
            $impId = db_insert('lp_imports', ['filename' => mb_substr($filename, 0, 190), 'total' => count($preview['rows']) + count($preview['errors']),
                'added' => 0, 'duplicates' => 0, 'invalid' => count($preview['errors']), 'created_by' => $by]);
            $pids = [];
            foreach ($preview['rows'] as $row) {
                $lc = mb_strtolower($row['product']);
                if (!isset($pids[$lc])) {
                    $ex = db_one("SELECT id FROM lp_products WHERE name = ? COLLATE NOCASE", [$row['product']]);
                    if ($ex) $pids[$lc] = (int)$ex['id'];
                    else { $pids[$lc] = db_insert('lp_products', ['name' => mb_substr($row['product'], 0, 190), 'created_by' => $by]); $created++; }
                }
                $hash = fcBlind($row['code'], self::CTX);
                if (db_one("SELECT 1 FROM lp_licenses WHERE product_id = ? AND code_hash = ?", [$pids[$lc], $hash])) { $dups++; continue; }
                db_insert('lp_licenses', [
                    'product_id' => $pids[$lc], 'code_enc' => fcEncrypt($row['code'], self::CTX), 'code_hash' => $hash,
                    'code_hint' => '…' . mb_substr($row['code'], -4), 'seats' => $row['seats'], 'valid_until' => $row['valid_until'],
                    'note' => $row['note'], 'import_id' => $impId, 'created_by' => $by,
                ]);
                $added++;
            }
            db_exec("UPDATE lp_imports SET added = ?, duplicates = ? WHERE id = ?", [$added, $dups, $impId]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
        audit_log('license_pool.imported', ['import_id' => $impId, 'file' => $filename, 'added' => $added, 'duplicates' => $dups, 'products_created' => $created], $by);
        return ['added' => $added, 'duplicates' => $dups, 'invalid' => count($preview['errors']), 'import_id' => $impId, 'products_created' => $created];
    }

    public static function imports(int $limit = 10): array
    {
        self::ready();
        return db_all("SELECT i.*, u.name AS by_name FROM lp_imports i LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT " . (int)$limit);
    }

    // ── Przydziały ──────────────────────────────────────────────────────────

    public static function assignments(int $licenseId): array
    {
        self::ready();
        return db_all(
            "SELECT a.*, u.name AS by_name FROM lp_assignments a LEFT JOIN users u ON u.id = a.assigned_by
              WHERE a.license_id = ? ORDER BY (a.revoked_at IS NOT NULL), a.id DESC", [$licenseId]
        );
    }

    /** Aktywne przydziały produktu (do listy „kto ma”). */
    public static function productAssignments(int $productId): array
    {
        self::ready();
        return db_all(
            "SELECT a.*, l.code_hint, l.valid_until, u.name AS by_name FROM lp_assignments a
               JOIN lp_licenses l ON l.id = a.license_id LEFT JOIN users u ON u.id = a.assigned_by
              WHERE l.product_id = ? ORDER BY (a.revoked_at IS NOT NULL), a.id DESC LIMIT 500", [$productId]
        );
    }

    /** Pierwsza licencja produktu z wolnym miejscem i ważna (najwcześniej wygasająca najpierw). */
    public static function pickFree(int $productId): ?int
    {
        self::ready();
        $r = db_one(
            "SELECT l.id FROM lp_licenses l
              WHERE l.product_id = ? AND l.status = 'active' AND (l.valid_until IS NULL OR l.valid_until >= ?)
                AND l.seats > (SELECT COUNT(*) FROM lp_assignments a WHERE a.license_id = l.id AND a.revoked_at IS NULL)
              ORDER BY (l.valid_until IS NULL), l.valid_until, l.id LIMIT 1", [$productId, self::today()]
        );
        return $r ? (int)$r['id'] : null;
    }

    /** Osoby do wyboru: pracownicy SZO i uczestnicy TI. */
    public static function holders(string $type): array
    {
        self::ready();
        if ($type === 'user') {
            try { return db_all("SELECT id, name FROM users WHERE COALESCE(is_active, 1) = 1 ORDER BY name LIMIT 3000"); } catch (\Throwable $e) { return []; }
        }
        if ($type === 'client') {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
            return TiLicencjeManager::clients();
        }
        return [];
    }

    /**
     * Przydziela licencję. $in: license_id | product_id (wtedy pierwsza wolna), holder_type, holder_id / holder_label, note, context;
     * dla 'client': billing_form (FAK|FAZ|DSC|GST), billing_ref, expires_at, notify (1 = powiadom uczestnika).
     * @return array{ok:bool,id:int,errors:list<string>}
     */
    public static function assign(array $in, ?int $by = null): array
    {
        self::ready();
        $type = (string)($in['holder_type'] ?? '');
        if (!in_array($type, ['user', 'client', 'other'], true)) return self::fail('Wybierz rodzaj odbiorcy.');
        $licId = (int)($in['license_id'] ?? 0);
        if ($licId <= 0 && (int)($in['product_id'] ?? 0) > 0) $licId = (int)self::pickFree((int)$in['product_id']);
        $lic = $licId > 0 ? self::license($licId) : null;
        if (!$lic) return self::fail('Brak wolnej licencji na ten produkt.');
        if (!self::isUsable($lic)) return self::fail('Ta licencja jest wycofana albo wygasła.');
        if ((int)$lic['used'] >= (int)$lic['seats']) return self::fail('Wszystkie miejsca tej licencji są zajęte.');

        $holderId = (int)($in['holder_id'] ?? 0); $label = trim((string)($in['holder_label'] ?? ''));
        if ($type === 'other') {
            if ($label === '' || mb_strlen($label) > 190) return self::fail('Podaj odbiorcę (do 190 znaków).');
            $holderId = 0;
        } elseif ($type === 'user') {
            $u = $holderId > 0 ? db_one("SELECT name FROM users WHERE id = ?", [$holderId]) : null;
            if (!$u) return self::fail('Wybierz pracownika.');
            $label = (string)$u['name'];
        } else {
            $c = $holderId > 0 ? db_one("SELECT name FROM k30_clients WHERE id = ?", [$holderId]) : null;
            if (!$c) return self::fail('Wybierz uczestnika TI.');
            $label = (string)$c['name'];
        }
        $dup = db_one(
            "SELECT 1 FROM lp_assignments a JOIN lp_licenses l ON l.id = a.license_id
              WHERE l.product_id = ? AND a.revoked_at IS NULL AND a.holder_type = ? AND "
              . ($type === 'other' ? "a.holder_label = ? COLLATE NOCASE" : "a.holder_id = ?"),
            [(int)$lic['product_id'], $type, $type === 'other' ? $label : $holderId]
        );
        if ($dup) return self::fail('Ten odbiorca ma już aktywną licencję na ten produkt.');

        $tiId = null;
        if ($type === 'client') {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
            $tiProduct = self::ensureTiCatalog((int)$lic['product_id'], $by);
            $code = (string)self::revealCodeSilent($licId);
            $notify = [];   // wiadomość z kluczem wysyła pula (sendKeyMail), bez drugiej, bezkluczowej od TI
            $r = TiLicencjeManager::assign([
                'license_id' => $tiProduct, 'client_id' => $holderId, 'access_key' => $code,
                'billing_form' => $in['billing_form'] ?? '', 'billing_ref' => $in['billing_ref'] ?? '',
                'expires_at' => ($in['expires_at'] ?? '') !== '' ? $in['expires_at'] : ($lic['valid_until'] ?? ''),
                'billing_note' => 'Pula licencji Fundacji, licencja nr ' . $licId,
            ], $by, $notify);
            if (!$r['ok']) return ['ok' => false, 'id' => 0, 'errors' => $r['errors']];
            $tiId = (int)$r['id'];
        }

        // Ostatnia bariera: wstaw tylko, gdy miejsce wciąż wolne (dwa jednoczesne kliknięcia).
        $pdo = db();
        $st = $pdo->prepare(
            "INSERT INTO lp_assignments (license_id, holder_type, holder_id, holder_label, ti_assignment_id, context, note, assigned_by)
             SELECT ?,?,?,?,?,?,?,? WHERE (SELECT COUNT(*) FROM lp_assignments WHERE license_id = ? AND revoked_at IS NULL)
                                          < (SELECT seats FROM lp_licenses WHERE id = ?)"
        );
        $st->execute([$licId, $type, $holderId ?: null, $label, $tiId, (string)($in['context'] ?? ''), mb_substr(trim((string)($in['note'] ?? '')), 0, 300), $by, $licId, $licId]);
        if ($st->rowCount() < 1) {
            if ($tiId) TiLicencjeManager::revoke($tiId, 'Pula licencji: brak wolnego miejsca', $by);
            return self::fail('Wszystkie miejsca tej licencji są zajęte.');
        }
        $id = (int)$pdo->lastInsertId();
        $mail = ['status' => 'skipped', 'to' => '', 'detail' => 'bez wysyłki'];
        if (!empty($in['notify'])) $mail = self::sendKeyMail($id, trim((string)($in['holder_email'] ?? '')), $by);
        audit_log('license_pool.assigned', ['assignment_id' => $id, 'license_id' => $licId, 'product' => $lic['product_name'], 'holder_type' => $type, 'holder_id' => $holderId, 'ti_assignment_id' => $tiId], $by);
        return ['ok' => true, 'id' => $id, 'errors' => [], 'mail' => $mail];
    }

    /** Odbiera przydział; dla uczestnika TI unieważnia też przypisanie w panelu uczestnika. */
    public static function revoke(int $assignmentId, string $reason = '', ?int $by = null): array
    {
        self::ready();
        $a = db_one("SELECT * FROM lp_assignments WHERE id = ?", [$assignmentId]);
        if (!$a) return ['ok' => false, 'error' => 'Nie znaleziono przydziału.'];
        if ($a['revoked_at']) return ['ok' => false, 'error' => 'Przydział jest już odebrany.'];
        $reason = mb_substr(trim($reason), 0, 300);
        if ($a['ti_assignment_id']) {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeManager.php';
            $r = TiLicencjeManager::revoke((int)$a['ti_assignment_id'], $reason !== '' ? $reason : 'Odebrano z puli licencji Fundacji', $by);
            if (!$r['ok'] && !str_contains((string)$r['error'], 'już unieważniona')) return ['ok' => false, 'error' => $r['error']];
        }
        db_exec("UPDATE lp_assignments SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ?", [date('Y-m-d H:i:s'), $by, $reason, $assignmentId]);
        audit_log('license_pool.revoked', ['assignment_id' => $assignmentId, 'license_id' => (int)$a['license_id'], 'reason' => $reason], $by);
        return ['ok' => true, 'error' => ''];
    }


    /**
     * Wysyła odbiorcy e-mail z kluczem (szablon lp_license_assigned). Adres: pracownik — users.email; uczestnik TI — jego adres
     * (małoletni: adres opiekuna); inny — $emailOverride. Wynik zapisany przy przydziale (adres zamaskowany).
     * @return array{status:string,to:string,detail:string}
     */
    public static function sendKeyMail(int $assignmentId, string $emailOverride = '', ?int $by = null): array
    {
        self::ready();
        $a = db_one("SELECT a.*, l.id AS lid, l.valid_until, p.name AS product, p.instructions FROM lp_assignments a
                       JOIN lp_licenses l ON l.id = a.license_id JOIN lp_products p ON p.id = l.product_id WHERE a.id = ?", [$assignmentId]);
        if (!$a) return self::mailNote($assignmentId, 'failed', '', 'nie znaleziono przydziału');
        $to = $emailOverride; $name = (string)$a['holder_label'];
        if ($to === '' && $a['holder_type'] === 'user') $to = trim((string)(db_one("SELECT email FROM users WHERE id = ?", [(int)$a['holder_id']])['email'] ?? ''));
        if ($to === '' && $a['holder_type'] === 'client') {
            $c = db_one("SELECT cl.email, ac.is_minor, ac.guardian_email FROM k30_clients cl
                           LEFT JOIN k30_ti_student_accounts ac ON ac.client_id = cl.id AND ac.is_active = 1 WHERE cl.id = ? LIMIT 1", [(int)$a['holder_id']]);
            $to = trim((string)(($c['is_minor'] ?? 0) ? ($c['guardian_email'] ?? '') : ($c['email'] ?? '')));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return self::mailNote($assignmentId, 'skipped', '', 'brak poprawnego adresu e-mail odbiorcy');
        $code = (string)self::revealCodeSilent((int)$a['lid']);
        $first = trim(explode(' ', $name)[0] ?? '');
        $ins = trim((string)$a['instructions']);
        $r = email_tpl_render('lp_license_assigned', [
            'org' => 'Fundacja FEER',
            'greeting' => $first !== '' && $a['holder_type'] !== 'other' ? ' ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') : '',
            'product' => htmlspecialchars((string)$a['product'], ENT_QUOTES, 'UTF-8'),
            'expires' => $a['valid_until'] ? date('d.m.Y', strtotime((string)$a['valid_until'])) : 'bezterminowo',
            'key' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            'instructions_block' => $ins !== '' ? '<p><strong>Instrukcja aktywacji i instalacji:</strong><br>' . nl2br(htmlspecialchars($ins, ENT_QUOTES, 'UTF-8')) . '</p>' : '',
        ]);
        if (empty($r['enabled'])) return self::mailNote($assignmentId, 'skipped', $to, 'szablon e-mail jest wyłączony');
        try {
            $mid = mail_queue_add($to, $name, $r['subject'], $r['html'], '', 'lp_license_assigned', $assignmentId, '', false);
            return self::mailNote($assignmentId, $mid > 0 ? 'sent' : 'skipped', $to, $mid > 0 ? '' : 'zablokowane (kontakt wirtualny)');
        } catch (\Throwable $e) {
            return self::mailNote($assignmentId, 'failed', $to, $e->getMessage());
        }
    }

    private static function mailNote(int $id, string $status, string $to, string $detail): array
    {
        $masked = $to === '' ? '' : preg_replace('/^(.).*(@.*)$/u', '$1***$2', $to);
        if ($id > 0) db_exec("UPDATE lp_assignments SET mail_status = ?, mailed_to = ?, mailed_at = ? WHERE id = ?",
            [$status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 150) : ''), $masked, date('Y-m-d H:i:s'), $id]);
        return ['status' => $status, 'to' => $masked, 'detail' => $detail];
    }

    // ── Pomocnicze ──────────────────────────────────────────────────────────

    /** Powiązanie produktu puli z katalogiem TI (tworzone przy pierwszym przydziale uczestnikowi). */
    private static function ensureTiCatalog(int $productId, ?int $by): int
    {
        $p = self::product($productId);
        if ($p && (int)$p['ti_license_id'] > 0 && db_one("SELECT 1 FROM k30_ti_licenses WHERE id = ?", [(int)$p['ti_license_id']])) return (int)$p['ti_license_id'];
        $r = TiLicencjeManager::saveSoftware(['name' => $p['name'], 'vendor' => $p['vendor'], 'category' => $p['category'],
            'vendor_url' => $p['vendor_url'], 'notes' => '', 'is_active' => 1], 0, $by);
        $id = (int)$r['id'];
        if ($id > 0) db_exec("UPDATE lp_products SET ti_license_id = ? WHERE id = ?", [$id, $productId]);
        return $id;
    }

    private static function revealCodeSilent(int $licenseId): ?string
    {
        $r = db_one("SELECT code_enc FROM lp_licenses WHERE id = ?", [$licenseId]);
        return $r ? fcDecrypt($r['code_enc'], self::CTX) : null;
    }

    private static function fail(string $m): array { return ['ok' => false, 'id' => 0, 'errors' => [$m]]; }
}
