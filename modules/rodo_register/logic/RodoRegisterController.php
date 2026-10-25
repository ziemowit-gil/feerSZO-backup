<?php
/**
 * modules/rodo_register/logic/RodoRegisterController.php — akcje modułu Obsługa RODO (trasy w router.php).
 */

require_once __DIR__ . '/rodoRegister.php';

final class RodoRegisterController
{
    private const VIEW = __DIR__ . '/../view/';

    private function uid(): ?int { return (int)(current_user()['id'] ?? 0) ?: null; }
    private function back(string $name, array $p = []): ?string { return Router::redirect(route($name, $p)); }

    public function index(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'index.php', ['d' => rodoDashboard(), 'open' => rodoReqList(), 'breaches' => array_map('rodoBreachDecode', db_all("SELECT * FROM rodo_breaches WHERE status NOT IN ('zamkniete','bez_zgloszenia') ORDER BY wykryto_at"))]);
    }

    // ── rejestr czynności ──
    public function activities(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'activities.php', ['rows' => db_all("SELECT * FROM rodo_activities ORDER BY zbior")]);
    }

    public function activityShow(int $id): string {
        rodoMigrate();
        $a = $id ? db_one("SELECT * FROM rodo_activities WHERE id = ?", [$id]) : ['id' => 0, 'rola' => 'administrator', 'dpia' => 'nie_dotyczy', 'status' => 'szkic', 'szczegolne' => 0];
        if (!$a) { http_response_code(404); return 'Nie znaleziono czynności.'; }
        return Router::view(self::VIEW . 'activityForm.php', ['a' => $a]);
    }

    public function activitySave(int $id): ?string {
        rodoMigrate();
        $f = ['zbior', 'cel', 'podstawa', 'kategorie_osob', 'kategorie_danych', 'odbiorcy', 'transfer_poza_eog', 'retencja', 'srodki', 'wlasciciel', 'moduly'];
        $d = [];
        foreach ($f as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
        $d['szczegolne'] = empty($_POST['szczegolne']) ? 0 : 1;
        $d['rola'] = in_array($_POST['rola'] ?? '', ['administrator', 'procesor', 'wspoladministrator'], true) ? $_POST['rola'] : 'administrator';
        $d['dpia'] = in_array($_POST['dpia'] ?? '', ['nie_dotyczy', 'wymagane', 'wykonana'], true) ? $_POST['dpia'] : 'nie_dotyczy';
        $d['status'] = in_array($_POST['status'] ?? '', ['szkic', 'zatwierdzone'], true) ? $_POST['status'] : 'szkic';
        if ($d['zbior'] === '' || $d['cel'] === '') { flash_set('warning', 'Nazwa zbioru i cel przetwarzania są wymagane.'); return $this->back('rodo.activity', ['id' => $id]); }
        if (!empty($_POST['przeglad'])) $d['przegladniete_at'] = date('Y-m-d H:i:s');
        if ($id) db_update('rodo_activities', $d, $id); else $id = db_insert('rodo_activities', $d);
        audit_log('rodo.activity.save', ['id' => $id]);
        flash_set('success', 'Zapisano czynność przetwarzania.');
        return $this->back('rodo.activity', ['id' => $id]);
    }

    /** Eksport rejestru (art. 30) do CSV — do okazania UODO. */
    public function activitiesCsv(): ?string {
        rodoMigrate();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rejestr-czynnosci-przetwarzania-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        $cols = ['zbior' => 'Zbiór / czynność', 'rola' => 'Rola', 'cel' => 'Cel', 'podstawa' => 'Podstawa prawna', 'kategorie_osob' => 'Kategorie osób', 'kategorie_danych' => 'Kategorie danych',
            'szczegolne' => 'Dane szczególne', 'odbiorcy' => 'Odbiorcy', 'transfer_poza_eog' => 'Transfer poza EOG', 'retencja' => 'Termin usunięcia', 'srodki' => 'Środki bezpieczeństwa', 'dpia' => 'DPIA', 'status' => 'Status'];
        fputcsv($o, array_values($cols), ';');
        foreach (db_all("SELECT * FROM rodo_activities ORDER BY zbior") as $r) {
            $r['szczegolne'] = $r['szczegolne'] ? 'tak' : 'nie';
            fputcsv($o, array_map(fn($k) => $r[$k] ?? '', array_keys($cols)), ';');
        }
        fclose($o);
        audit_log('rodo.activities.export');
        return null;
    }

    // ── podmioty przetwarzające ──
    public function processors(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'processors.php', ['rows' => db_all("SELECT * FROM rodo_processors ORDER BY nazwa")]);
    }

    public function processorSave(int $id): ?string {
        rodoMigrate();
        $d = [];
        foreach (['nazwa', 'zakres', 'kraj', 'transfer_podstawa', 'uwagi'] as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
        $d['umowa_status'] = in_array($_POST['umowa_status'] ?? '', ['brak', 'w_toku', 'podpisana', 'nie_dotyczy'], true) ? $_POST['umowa_status'] : 'brak';
        $d['umowa_data'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['umowa_data'] ?? '')) ? $_POST['umowa_data'] : null;
        if ($d['nazwa'] === '') { flash_set('warning', 'Nazwa jest wymagana.'); return $this->back('rodo.processors'); }
        if ($id) db_update('rodo_processors', $d, $id); else db_insert('rodo_processors', $d);
        audit_log('rodo.processor.save', ['id' => $id, 'nazwa' => $d['nazwa']]);
        flash_set('success', 'Zapisano podmiot przetwarzający.');
        return $this->back('rodo.processors');
    }

    // ── wnioski osób ──
    public function requests(): string {
        rodoMigrate();
        $st = (string)($_GET['status'] ?? '');
        return Router::view(self::VIEW . 'requests.php', ['rows' => rodoReqList($st), 'status' => $st]);
    }

    public function requestNew(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'requestForm.php', []);
    }

    public function requestCreate(): ?string {
        rodoMigrate();
        $email = trim((string)($_POST['email'] ?? ''));
        if (trim((string)($_POST['wnioskodawca'] ?? '')) === '' || ($email === '' && trim((string)($_POST['pesel'] ?? '')) === '')) {
            flash_set('warning', 'Podaj imię i nazwisko oraz e-mail albo PESEL wnioskodawcy — bez tego nie da się znaleźć jego danych.');
            return $this->back('rodo.request.new');
        }
        $id = rodoReqCreate($_POST, $this->uid());
        flash_set('success', 'Zarejestrowano wniosek. Termin odpowiedzi: ' . rodoReqGet($id)['termin'] . '.');
        return $this->back('rodo.request', ['id' => $id]);
    }

    public function requestShow(int $id): string {
        rodoMigrate();
        $r = rodoReqGet($id);
        if (!$r) { http_response_code(404); return 'Nie znaleziono wniosku.'; }
        audit_log('rodo.request.view', ['id' => $id]);
        return Router::view(self::VIEW . 'requestShow.php', [
            'r' => $r, 'found' => rodoFindSubject(rodoSubjectOf($r)), 'log' => db_all("SELECT * FROM rodo_request_log WHERE request_id = ? ORDER BY id DESC", [$id]),
        ]);
    }

    public function requestUpdate(int $id): ?string {
        rodoMigrate();
        $r = rodoReqGet($id);
        if (!$r) { http_response_code(404); return 'Nie znaleziono wniosku.'; }
        $action = (string)($_POST['akcja'] ?? 'zapisz');
        if ($action === 'zweryfikowano') {
            db_update('rodo_requests', ['tozsamosc_zweryfikowana_at' => date('Y-m-d H:i:s'), 'tozsamosc_metoda' => trim((string)($_POST['metoda'] ?? '')), 'status' => 'w_toku'], $id);
            rodoReqLog($id, 'tożsamość zweryfikowana', trim((string)($_POST['metoda'] ?? '')), $this->uid());
        } elseif ($action === 'przedluz') {
            if (!$r['termin_przedluzony']) {
                db_update('rodo_requests', ['termin' => date('Y-m-d', strtotime($r['termin'] . ' +60 days')), 'termin_przedluzony' => 1], $id);
                rodoReqLog($id, 'przedłużono termin o 2 miesiące', 'art. 12 ust. 3 — powiadom wnioskodawcę przed upływem pierwszego miesiąca', $this->uid());
            }
        } else {
            $status = isset(RODO_REQUEST_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : $r['status'];
            $done = in_array($status, ['zrealizowane', 'odmowa'], true);
            db_update('rodo_requests', [
                'status' => $status, 'odpowiedz' => fcEncrypt(trim((string)($_POST['odpowiedz'] ?? '')), rodoReqCtx('odpowiedz')),
                'zamkniete_at' => $done ? ($r['zamkniete_at'] ?? date('Y-m-d H:i:s')) : null,
            ], $id);
            rodoReqLog($id, 'zmiana statusu', $status, $this->uid());
        }
        audit_log('rodo.request.update', ['id' => $id, 'akcja' => $action]);
        flash_set('success', 'Zapisano.');
        return $this->back('rodo.request', ['id' => $id]);
    }

    public function requestExport(int $id): ?string {
        rodoMigrate();
        $r = rodoReqGet($id);
        if (!$r) { http_response_code(404); return 'Nie znaleziono wniosku.'; }
        if (empty($r['tozsamosc_zweryfikowana_at'])) { flash_set('warning', 'Eksport danych jest możliwy dopiero po zweryfikowaniu tożsamości.'); return $this->back('rodo.request', ['id' => $id]); }
        rodoReqLog($id, 'eksport danych', '', $this->uid());
        audit_log('rodo.request.export', ['id' => $id]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dane-osoby-wniosek-' . $id . '.json"');
        return json_encode(rodoExport($r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function requestErase(int $id): ?string {
        rodoMigrate();
        $r = rodoReqGet($id);
        if (!$r) { http_response_code(404); return 'Nie znaleziono wniosku.'; }
        if (($_POST['potwierdz'] ?? '') !== 'USUN') { flash_set('warning', 'Wpisz USUN, aby potwierdzić nieodwracalną anonimizację.'); return $this->back('rodo.request', ['id' => $id]); }
        try {
            $done = rodoErase($r, $this->uid());
            flash_set('success', $done ? 'Zanonimizowano: ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($done), $done)) . '.' : 'Nie było danych do anonimizacji — pozostałe zbiory są zatrzymane z mocy prawa.');
        } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
        return $this->back('rodo.request', ['id' => $id]);
    }

    // ── naruszenia ──
    public function breaches(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'breaches.php', ['rows' => array_map('rodoBreachDecode', db_all("SELECT * FROM rodo_breaches ORDER BY wykryto_at DESC"))]);
    }

    public function breachShow(int $id): string {
        rodoMigrate();
        $b = $id ? db_one("SELECT * FROM rodo_breaches WHERE id = ?", [$id]) : ['id' => 0, 'wykryto_at' => date('Y-m-d H:i:s'), 'ryzyko' => 'nieoceniono', 'status' => 'wykryte', 'opis' => '', 'skutki' => ''];
        if (!$b) { http_response_code(404); return 'Nie znaleziono naruszenia.'; }
        return Router::view(self::VIEW . 'breachForm.php', ['b' => $id ? rodoBreachDecode($b) : $b]);
    }

    public function breachSave(int $id): ?string {
        rodoMigrate();
        if (trim((string)($_POST['opis'] ?? '')) === '') { flash_set('warning', 'Opis naruszenia jest wymagany.'); return $this->back('rodo.breach', ['id' => $id]); }
        $id = rodoBreachSave($_POST, $id ?: null, $this->uid());
        flash_set('success', 'Zapisano naruszenie. Termin zgłoszenia do UODO: ' . rodoBreachDecode(db_one("SELECT * FROM rodo_breaches WHERE id = ?", [$id]))['uodo_do'] . '.');
        return $this->back('rodo.breach', ['id' => $id]);
    }

    // ── retencja ──
    public function retention(): string {
        rodoMigrate();
        return Router::view(self::VIEW . 'retention.php', ['rows' => rodoRetentionReport()]);
    }

    public function retentionSave(): ?string {
        rodoMigrate();
        $t = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['tabela'] ?? ''));
        $c = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['kolumna_daty'] ?? ''));
        $m = max(1, (int)($_POST['miesiecy'] ?? 12));
        if (!in_array($c, rodoTableCols($t), true)) { flash_set('warning', 'Nie ma takiej tabeli lub kolumny daty.'); return $this->back('rodo.retention'); }
        db_exec("INSERT INTO rodo_retention_rules (zbior, tabela, kolumna_daty, miesiecy, uwagi) VALUES (?,?,?,?,?)
                 ON CONFLICT(tabela, kolumna_daty) DO UPDATE SET zbior = excluded.zbior, miesiecy = excluded.miesiecy, uwagi = excluded.uwagi, aktywna = 1",
            [trim((string)($_POST['zbior'] ?? $t)) ?: $t, $t, $c, $m, trim((string)($_POST['uwagi'] ?? ''))]);
        audit_log('rodo.retention.save', ['tabela' => $t, 'miesiecy' => $m]);
        flash_set('success', 'Zapisano regułę retencji.');
        return $this->back('rodo.retention');
    }
}
