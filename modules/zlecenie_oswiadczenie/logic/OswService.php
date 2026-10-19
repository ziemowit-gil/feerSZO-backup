<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/logic/OswService.php — logika oświadczeń zleceniobiorcy.
 *
 * Pakiet (zlec_osw) = jedno wypełnienie kreatora przy umowie zlecenia; dokumenty pakietu (zlec_osw_docs):
 *   'zus' — oświadczenie dla celów podatkowych i ubezpieczenia ZUS (oryginalny wzór DOCX, OswiadczenieDocx),
 *   'zlb' — oświadczenie o numerze rachunku bankowego (wydruk ZLB w układzie formularza urzędowego, OswiadczeniePdf).
 * Status dokumentu: wypelnione → wydrukowane (pobranie wydruku) → skan (zleceniobiorca przesłał podpisany skan)
 *   → zweryfikowane | odrzucone (pracownik; po odrzuceniu można przesłać skan ponownie).
 * Dostęp zleceniobiorcy: zalogowany w panelu (viewer_owns_contract) albo link z tokenem wygenerowany przez pracownika.
 * Pakiet bez umowy z linku pracownika (source 'invite') po złożeniu tworzy projekt umowy zlecenia (status „projekt”).
 * Publiczny kreator (source 'public', startPublic()) NIE tworzy umowy — po przesłaniu skanu powstaje konto oświadczeń
 * (OswAccount), a pracownik wiąże pakiet z umową (linkContract) albo tworzy z niego projekt (createProject).
 * Po weryfikacji skanu: plik trafia do dokumentów umowy (contract_extra_docs); ZLB ustawia rachunek bankowy umowy,
 * ZUS uzupełnia PUSTE pola umowy (PESEL, adres, urząd skarbowy). Nic nie wysyła e-maili ani SMS-ów.
 */
require_once __DIR__ . '/oswSchema.php';
require_once dirname(__DIR__) . '/lib/OswForm.php';

final class OswService
{
    public const KINDS = [
        'zus' => ['Oświadczenie dla celów podatkowych i ubezpieczenia ZUS', 'ZUS/PIT'],
        'zlb' => ['Oświadczenie o numerze rachunku bankowego', 'ZLB'],
    ];
    public const DOC_STATUS = [
        'wypelnione'    => ['Wypełnione — do wydruku', 'secondary'],
        'wydrukowane'   => ['Wydrukowane — czeka na skan', 'info'],
        'skan'          => ['Skan przesłany — do weryfikacji', 'warning'],
        'zweryfikowane' => ['Zweryfikowane', 'success'],
        'odrzucone'     => ['Odrzucone — prześlij ponownie', 'danger'],
    ];
    public const TOKEN_DAYS = 60;
    public const SCAN_MAX = 20 * 1024 * 1024;

    public static function init(): void { oswMigrate(); }

    /** Pełny adres w module: /zlecenie + ścieżka (czysty adres z .htaccess, bez „router.php”). */
    public static function url(string $path = '', array $q = []): string
    {
        $path = trim($path, '/');
        return rtrim(APP_URL, '/') . '/zlecenie' . ($path !== '' ? '/' . $path : '') . ($q ? '?' . http_build_query($q) : '');
    }

    // ── Odczyt ────────────────────────────────────────────────────────────────

    public static function byId(int $id): ?array
    {
        self::init();
        return db_one("SELECT * FROM zlec_osw WHERE id = ?", [$id]) ?: null;
    }

    /** Pakiet po tokenie z linku (ważny, bieżący). */
    public static function byToken(string $token): ?array
    {
        self::init();
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $o = db_one("SELECT * FROM zlec_osw WHERE token = ? AND is_current = 1", [$token]);
        if (!$o || ($o['token_expires'] && $o['token_expires'] < date('Y-m-d H:i:s'))) return null;
        return $o;
    }

    /** Bieżący pakiet umowy; $create — załóż, gdy brak. */
    public static function forContract(int $cid, bool $create = false, int $by = 0): ?array
    {
        self::init();
        $o = db_one("SELECT * FROM zlec_osw WHERE contract_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1", [$cid]);
        if ($o || !$create || $cid <= 0) return $o ?: null;
        $c = self::contract($cid);
        $id = db_insert('zlec_osw', ['contract_id' => $cid, 'answers' => json_encode(self::prefill($c), JSON_UNESCAPED_UNICODE),
                                     'created_by' => $by ?: null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return self::byId((int)$id);
    }

    public static function contract(int $cid): ?array
    {
        return $cid > 0 ? (db_one("SELECT * FROM umowy_zlecenie WHERE id = ?", [$cid]) ?: null) : null;
    }

    public static function answers(array $o): array
    {
        $a = json_decode((string)($o['answers'] ?? '{}'), true);
        return is_array($a) ? $a : [];
    }

    /** Dokumenty pakietu [kind => wiersz]. */
    public static function docs(int $oswId): array
    {
        self::init();
        $out = [];
        foreach (db_all("SELECT * FROM zlec_osw_docs WHERE osw_id = ? ORDER BY id", [$oswId]) as $d) $out[$d['kind']] = $d;
        return $out;
    }

    public static function doc(int $id): ?array
    {
        self::init();
        return db_one("SELECT * FROM zlec_osw_docs WHERE id = ?", [$id]) ?: null;
    }

    /** Lista pakietów dla pracownika (najnowsze pierwsze). */
    public static function listAll(int $limit = 300): array
    {
        self::init();
        $rows = db_all("SELECT o.*, z.numer_umowy, z.imie_nazwisko, z.status AS umowa_status
                          FROM zlec_osw o LEFT JOIN umowy_zlecenie z ON z.id = o.contract_id
                         WHERE o.is_current = 1 AND NOT (o.source = 'public' AND o.status = 'roboczy' AND o.step <= 1)
                         ORDER BY COALESCE(o.updated_at, o.created_at) DESC LIMIT " . (int)$limit);
        foreach ($rows as &$r) $r['_docs'] = self::docs((int)$r['id']);
        return $rows;
    }

    /** Dane z umowy na start kreatora (żeby nie wpisywać drugi raz tego, co Fundacja już ma). */
    private static function prefill(?array $c): array
    {
        if (!$c) return ['obywatelstwo' => 'polskie', 'adr_kraj' => 'Polska'];
        $p = preg_replace('/\D/', '', (string)($c['pesel'] ?? ''));
        $a = ['nazwisko_imiona' => self::surnameFirst((string)($c['imie_nazwisko'] ?? '')), 'obywatelstwo' => 'polskie',
              'pesel' => $p, 'data_urodzenia' => $p !== '' ? (string)(pesel_to_birthdate($p) ?? '') : '', 'plec' => OswForm::plecFromPesel($p),
              'adr_kraj' => 'Polska', 'adr_ulica' => (string)($c['addr_street'] ?? ''), 'adr_nr_domu' => (string)($c['addr_house'] ?? ''),
              'adr_nr_mieszkania' => (string)($c['addr_flat'] ?? ''), 'adr_kod' => (string)($c['addr_postal'] ?? ''),
              'adr_miejscowosc' => (string)($c['addr_city'] ?? ''), 'urzad_skarbowy' => (string)($c['urzad_skarbowy'] ?? ''),
              'nrb' => preg_replace('/\s/', '', (string)($c['rachunek_bankowy'] ?? ''))];
        return array_filter($a, fn($v) => $v !== '');
    }

    /** „Anna Maria Kowalska” → „Kowalska Anna Maria” (wzór pyta o „Nazwisko i imiona”). */
    private static function surnameFirst(string $n): string
    {
        $p = preg_split('/\s+/u', trim($n)) ?: [];
        if (count($p) < 2) return trim($n);
        $last = array_pop($p);
        return $last . ' ' . implode(' ', $p);
    }

    // ── Link dla zleceniobiorcy ───────────────────────────────────────────────

    /** Link (token) do umowy — tworzy pakiet albo odnawia ważność istniejącego linku. */
    public static function linkForContract(int $cid, int $by): array
    {
        $o = self::forContract($cid, true, $by);
        return self::ensureToken($o);
    }

    /** Link dla osoby BEZ umowy: po złożeniu oświadczeń powstanie projekt umowy zlecenia. */
    public static function inviteNew(string $name, string $email, int $by): array
    {
        self::init();
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = trim($email);
        if ($name === '') throw new InvalidArgumentException('Podaj imię i nazwisko osoby.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Nieprawidłowy adres e-mail.');
        $id = db_insert('zlec_osw', ['contract_id' => 0, 'invite_name' => $name, 'invite_email' => $email ?: null,
            'answers' => json_encode(['nazwisko_imiona' => self::surnameFirst($name), 'obywatelstwo' => 'polskie', 'adr_kraj' => 'Polska'], JSON_UNESCAPED_UNICODE),
            'created_by' => $by ?: null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return self::ensureToken((array)self::byId((int)$id));
    }

    private static function ensureToken(array $o): array
    {
        $tok = (string)($o['token'] ?? '');
        if ($tok === '') $tok = bin2hex(random_bytes(24));
        db_exec("UPDATE zlec_osw SET token = ?, token_expires = ?, updated_at = ? WHERE id = ?",
            [$tok, date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_DAYS . ' days')), date('Y-m-d H:i:s'), (int)$o['id']]);
        return (array)self::byId((int)$o['id']);
    }

    /** Publiczny kreator: nowy pakiet bez umowy (limit prób z IP sprawdza kontroler). */
    public static function startPublic(string $ip): array
    {
        self::init();
        $id = db_insert('zlec_osw', ['contract_id' => 0, 'source' => 'public',
            'answers' => json_encode(['_need_contact' => 1, 'obywatelstwo' => 'polskie', 'adr_kraj' => 'Polska'], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'submitted_ip' => $ip]);
        return self::ensureToken((array)self::byId((int)$id));
    }

    // ── Kreator ───────────────────────────────────────────────────────────────

    /** Zapis kroku (bez walidacji całości). Zwraca błędy kroku; zapisuje także przy błędach (szkic). */
    public static function saveStep(array $o, int $step, array $post): array
    {
        if ($o['status'] !== 'roboczy') return ['_' => 'Oświadczenia są już złożone — aby coś zmienić, użyj „Popraw dane”.'];
        $a = array_merge(self::answers($o), OswForm::normalize($step, $post));
        if ($step === 4 && OswForm::studentExempt($a)) { $a['dobr_emerytalne'] = 0; $a['dobr_chorobowe'] = 0; }
        if ($step === 4 && OswForm::mandatory($a)) $a['dobr_emerytalne'] = 0;   // obowiązkowe — nie wybiera się dobrowolnie
        $errors = OswForm::validate($step, $a);
        $next = $errors ? $step : max((int)$o['step'], $step + 1);
        db_exec("UPDATE zlec_osw SET answers = ?, step = ?, updated_at = ? WHERE id = ?",
            [json_encode($a, JSON_UNESCAPED_UNICODE), min($next, count(OswForm::STEPS)), date('Y-m-d H:i:s'), (int)$o['id']]);
        return $errors;
    }

    /** Pierwszy krok z błędami (do złożenia muszą przejść wszystkie). */
    public static function firstInvalidStep(array $a): ?int
    {
        foreach (array_keys(OswForm::STEPS) as $s) if ($s < 6 && OswForm::validate($s, $a)) return $s;
        return null;
    }

    /** Złożenie: tworzy/odświeża dokumenty; pakiet bez umowy → projekt umowy zlecenia. */
    public static function submit(array $o, array $post, string $ip, int $by): array
    {
        $a = array_merge(self::answers($o), OswForm::normalize(6, $post));
        if ($bad = self::firstInvalidStep($a)) return ['ok' => false, 'step' => $bad, 'errors' => OswForm::validate($bad, $a)];
        if ($e = OswForm::validate(6, $a)) return ['ok' => false, 'step' => 6, 'errors' => $e];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cid = (int)$o['contract_id'];
            $draft = $cid === 0 && ($o['source'] ?? 'invite') !== 'public';
            if ($draft) $cid = self::createDraftContract($a, $o, $by);
            $now = date('Y-m-d H:i:s');
            db_exec("UPDATE zlec_osw SET answers = ?, status = 'zlozony', contract_id = ?, submitted_at = ?, submitted_ip = ?, draft_created = draft_created OR ?, updated_at = ? WHERE id = ?",
                [json_encode($a, JSON_UNESCAPED_UNICODE), $cid, $now, $ip, $draft ? 1 : 0, $now, (int)$o['id']]);
            $docs = self::docs((int)$o['id']);
            foreach (array_keys(self::KINDS) as $k) {
                if (isset($docs[$k])) {
                    db_exec("UPDATE zlec_osw_docs SET status = 'wypelnione', scan_path = NULL, scan_name = NULL, scan_at = NULL, scan_ip = NULL, verified_by = NULL,
                             verified_name = NULL, verified_at = NULL, note = NULL, updated_at = ? WHERE id = ?", [$now, (int)$docs[$k]['id']]);
                } else {
                    db_insert('zlec_osw_docs', ['osw_id' => (int)$o['id'], 'kind' => $k, 'numer' => $k === 'zlb' ? self::nextZlbNumber() : null,
                                                'status' => 'wypelnione', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($cid, $by, 'oswiadczenie_zlozone', 'Złożono w kreatorze oświadczenia ZUS/PIT i ZLB (rachunek bankowy)' . ($draft ? ' — utworzono projekt umowy' : ''));
        return ['ok' => true, 'contract_id' => $cid];
    }

    /** „Popraw dane” — wraca do kreatora (nie po weryfikacji); dotychczasowe skany przestają obowiązywać. */
    public static function reopen(array $o, int $by): bool
    {
        foreach (self::docs((int)$o['id']) as $d) if ($d['status'] === 'zweryfikowane') return false;
        db_exec("UPDATE zlec_osw SET status = 'roboczy', step = 1, updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$o['id']]);
        self::log((int)$o['contract_id'], $by, 'oswiadczenie_poprawa', 'Zleceniobiorca otworzył oświadczenia do poprawy');
        return true;
    }

    /** Pracownik: powiązanie pakietu (np. z publicznego kreatora) z istniejącą umową zlecenia. */
    public static function linkContract(array $o, int $cid, int $by): void
    {
        $c = self::contract($cid);
        if (!$c) throw new RuntimeException('Nie znaleziono umowy zlecenia.');
        if ((int)$o['contract_id'] > 0 && (int)$o['contract_id'] !== $cid) throw new RuntimeException('Oświadczenia są już powiązane z inną umową.');
        db_exec("UPDATE zlec_osw SET is_current = 0 WHERE contract_id = ? AND id <> ? AND is_current = 1", [$cid, (int)$o['id']]);
        db_exec("UPDATE zlec_osw SET contract_id = ?, is_current = 1, updated_at = ? WHERE id = ?", [$cid, date('Y-m-d H:i:s'), (int)$o['id']]);
        self::log($cid, $by, 'oswiadczenie_powiazane', 'Powiązano oświadczenia zleceniobiorcy (pakiet #' . (int)$o['id'] . ', ' . ($o['source'] === 'public' ? 'publiczny kreator' : 'link') . ') z umową');
        // Dokumenty już zweryfikowane — przenieś skutki weryfikacji na umowę (skan do dokumentów, rachunek, puste pola)
        foreach (self::docs((int)$o['id']) as $d) {
            if ($d['status'] !== 'zweryfikowane' || $d['extra_doc_id']) continue;
            $extra = (int)db_insert('contract_extra_docs', ['contract_type' => 'zlecenie', 'contract_id' => $cid,
                'label' => self::KINDS[$d['kind']][0] . ($d['numer'] ? ' ' . $d['numer'] : '') . ' — podpisany skan (zweryfikowany)',
                'stored_path' => $d['scan_path'], 'uploaded_by' => $by ?: null, 'uploaded_at' => date('Y-m-d H:i:s')]);
            db_exec("UPDATE zlec_osw_docs SET extra_doc_id = ? WHERE id = ?", [$extra, (int)$d['id']]);
            self::applyToContract($d['kind'], self::answers($o), $cid, ['id' => $by]);
        }
    }

    /** Pracownik: projekt umowy zlecenia (status „projekt”) z danych złożonych oświadczeń. */
    public static function createProject(array $o, int $by): int
    {
        if ((int)$o['contract_id'] > 0) throw new RuntimeException('Oświadczenia są już powiązane z umową.');
        if ($o['status'] !== 'zlozony') throw new RuntimeException('Projekt umowy można utworzyć dopiero ze złożonych oświadczeń.');
        $a = self::answers($o);
        if (!empty($a['email']) && empty($o['invite_email'])) $o['invite_email'] = $a['email'];
        $cid = self::createDraftContract($a, $o, $by);
        db_exec("UPDATE zlec_osw SET draft_created = 1 WHERE id = ?", [(int)$o['id']]);
        self::linkContract((array)self::byId((int)$o['id']), $cid, $by);
        return $cid;
    }

    /** Umowy zlecenia, które mogą należeć do osoby z oświadczenia (PESEL albo imię i nazwisko). */
    public static function contractCandidates(array $a): array
    {
        $pesel = (string)($a['pesel'] ?? '');
        $name = OswForm::nameFirst((string)($a['nazwisko_imiona'] ?? ''));
        return db_all("SELECT id, numer_umowy, imie_nazwisko, status, data_zawarcia FROM umowy_zlecenie
                        WHERE (? <> '' AND pesel = ?) OR (? <> '' AND LOWER(imie_nazwisko) = LOWER(?)) ORDER BY id DESC LIMIT 20",
            [$pesel, $pesel, $name, $name]);
    }

    private static function nextZlbNumber(): string
    {
        $y = date('Y');
        $n = (int)(db_one("SELECT COUNT(*) AS n FROM zlec_osw_docs WHERE kind = 'zlb' AND numer LIKE ?", ['ZLB/%/' . $y])['n'] ?? 0) + 1;
        do {
            $num = sprintf('ZLB/%04d/%s', $n++, $y);
        } while (db_one("SELECT 1 FROM zlec_osw_docs WHERE numer = ?", [$num]));
        return $num;
    }

    /** Projekt umowy zlecenia z danych oświadczenia (status „projekt”, numer z licznika umów). */
    private static function createDraftContract(array $a, array $o, int $by): int
    {
        $name = OswForm::nameFirst((string)$a['nazwisko_imiona']);   // jak w pozostałych umowach: imiona, potem nazwisko
        $street = trim((string)($a['adr_ulica'] ?? ''));
        $note = 'Projekt utworzony automatycznie z oświadczeń złożonych w kreatorze ' . date('d.m.Y H:i') . ".\n"
              . 'ZUS (z oświadczenia, do weryfikacji): ' . OswForm::zusHint($a)
              . ' Dobrowolne emerytalne i rentowe: ' . (!empty($a['dobr_emerytalne']) ? 'tak' : 'nie')
              . '; dobrowolne chorobowe: ' . (!empty($a['dobr_chorobowe']) ? 'tak' : 'nie') . '.'
              . (($a['kod_zawodu'] ?? '') !== '' ? ' Kod zawodu: ' . $a['kod_zawodu'] . '.' : '');
        $data = [
            'numer_umowy' => next_contract_number('zlecenie'), 'status' => 'projekt', 'imie_nazwisko' => $name,
            'pesel' => (string)($a['pesel'] ?? '') ?: null, 'email' => (string)(($o['invite_email'] ?? '') ?: ($a['email'] ?? '')) ?: null,
            'adres' => OswForm::addressLine($a), 'addr_street' => $street, 'addr_house' => (string)($a['adr_nr_domu'] ?? ''),
            'addr_flat' => (string)($a['adr_nr_mieszkania'] ?? ''), 'addr_postal' => (string)($a['adr_kod'] ?? ''),
            'addr_city' => (string)($a['adr_miejscowosc'] ?? ''), 'addr_country' => OswForm::isPoland((string)($a['adr_kraj'] ?? '')) ? 'PL' : (string)$a['adr_kraj'],
            'urzad_skarbowy' => (string)($a['urzad_skarbowy'] ?? ''), 'rachunek_bankowy' => OswForm::nrbFormat((string)($a['nrb'] ?? '')),
            'zus_skladki' => OswForm::studentExempt($a) ? 0 : 1,
            'zwolnienie_wiek' => OswForm::dateOk((string)($a['data_urodzenia'] ?? '')) && OswForm::age((string)$a['data_urodzenia']) < 26 ? 1 : 0,
            'uwagi' => $note, 'created_by' => $by ?: ((int)$o['created_by'] ?: null),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        assign_nr_rejestru($data);
        $id = (int)db_insert('umowy_zlecenie', $data);
        self::log($id, $by, 'create', 'Projekt umowy z oświadczeń zleceniobiorcy (kreator): ' . $data['numer_umowy']);
        return $id;
    }

    // ── Wydruk i skan ─────────────────────────────────────────────────────────

    public static function markPrinted(array $doc): void
    {
        db_exec("UPDATE zlec_osw_docs SET print_count = print_count + 1, printed_at = COALESCE(printed_at, ?),
                 status = CASE WHEN status = 'wypelnione' THEN 'wydrukowane' ELSE status END, updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$doc['id']]);
    }

    /** Zapis skanu (PDF/JPG/PNG ≤ 20 MB, nagłówek pliku zgodny z rozszerzeniem). */
    public static function storeScan(array $doc, array $file, string $ip, int $by, int $contractId): void
    {
        if (in_array($doc['status'], ['zweryfikowane'], true)) throw new RuntimeException('Ten dokument jest już zweryfikowany.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Wybierz plik ze skanem.');
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Nie udało się przesłać pliku (kod ' . (int)$file['error'] . ').');
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::SCAN_MAX) throw new RuntimeException('Plik musi mieć od 1 B do 20 MB.');
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        $head = (string)file_get_contents((string)$file['tmp_name'], false, null, 0, 8);
        $okHead = ['pdf' => str_starts_with($head, '%PDF'), 'jpg' => str_starts_with($head, "\xFF\xD8\xFF"), 'png' => str_starts_with($head, "\x89PNG")];
        if (!isset($okHead[$ext])) throw new RuntimeException('Dozwolone pliki: PDF, JPG, PNG.');
        if (!$okHead[$ext]) throw new RuntimeException('Zawartość pliku nie odpowiada rozszerzeniu .' . $ext . ' — zapisz skan ponownie jako PDF, JPG lub PNG.');
        $dir = UPLOAD_DIR . 'zlecenie/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $rel = 'zlecenie/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $moved = is_uploaded_file((string)$file['tmp_name']) ? move_uploaded_file((string)$file['tmp_name'], UPLOAD_DIR . $rel)
               : (PHP_SAPI === 'cli' && copy((string)$file['tmp_name'], UPLOAD_DIR . $rel));
        if (!$moved) throw new RuntimeException('Nie udało się zapisać pliku na serwerze.');
        upload_sync_sharepoint($rel);
        $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', (string)$file['name']) ?? 'skan', 0, 120);
        db_exec("UPDATE zlec_osw_docs SET status = 'skan', scan_path = ?, scan_name = ?, scan_at = ?, scan_ip = ?, note = NULL, updated_at = ? WHERE id = ?",
            [$rel, $name, date('Y-m-d H:i:s'), $ip, date('Y-m-d H:i:s'), (int)$doc['id']]);
        self::log($contractId, $by, 'oswiadczenie_skan', 'Przesłano skan: ' . self::KINDS[$doc['kind']][0] . ' (' . $name . ')');
    }

    /** Weryfikacja skanu przez pracownika. */
    public static function verify(array $doc, bool $ok, string $note, array $user): void
    {
        if ($doc['status'] !== 'skan') throw new RuntimeException('Do weryfikacji jest tylko przesłany skan.');
        if (!$ok && trim($note) === '') throw new RuntimeException('Podaj powód odrzucenia — zobaczy go zleceniobiorca.');
        $o = (array)self::byId((int)$doc['osw_id']);
        $cid = (int)$o['contract_id'];
        $now = date('Y-m-d H:i:s');
        $extraId = null;
        if ($ok) {
            $extraId = (int)db_insert('contract_extra_docs', ['contract_type' => 'zlecenie', 'contract_id' => $cid,
                'label' => self::KINDS[$doc['kind']][0] . ($doc['numer'] ? ' ' . $doc['numer'] : '') . ' — podpisany skan (zweryfikowany)',
                'stored_path' => $doc['scan_path'], 'uploaded_by' => (int)$user['id'] ?: null, 'uploaded_at' => $now]);
            self::applyToContract($doc['kind'], self::answers($o), $cid, $user);
        }
        db_exec("UPDATE zlec_osw_docs SET status = ?, verified_by = ?, verified_name = ?, verified_at = ?, note = ?, extra_doc_id = ?, updated_at = ? WHERE id = ?",
            [$ok ? 'zweryfikowane' : 'odrzucone', (int)$user['id'], (string)($user['name'] ?? ''), $now, trim($note) ?: null, $extraId, $now, (int)$doc['id']]);
        self::log($cid, (int)$user['id'], $ok ? 'oswiadczenie_zweryfikowane' : 'oswiadczenie_odrzucone',
            self::KINDS[$doc['kind']][0] . ($ok ? ' — zweryfikowano skan' : ' — odrzucono skan: ' . trim($note)));
    }

    /** Po weryfikacji: ZLB → rachunek bankowy umowy; ZUS → tylko PUSTE pola umowy. */
    private static function applyToContract(string $kind, array $a, int $cid, array $user): void
    {
        $c = self::contract($cid);
        if (!$c) return;
        $set = [];
        if ($kind === 'zlb' && ($a['nrb'] ?? '') !== '') {
            $new = OswForm::nrbFormat((string)$a['nrb']);
            if (preg_replace('/\s/', '', (string)$c['rachunek_bankowy']) !== preg_replace('/\s/', '', $new)) {
                $set['rachunek_bankowy'] = $new;
                $old = preg_replace('/\D/', '', (string)$c['rachunek_bankowy']);
                self::log($cid, (int)$user['id'], 'update', 'Rachunek bankowy z oświadczenia ZLB: ' . ($old !== '' ? '…' . substr($old, -4) : '(brak)') . ' → …' . substr(preg_replace('/\D/', '', $new), -4));
            }
        }
        if ($kind === 'zus') {
            $fill = ['pesel' => (string)($a['pesel'] ?? ''), 'urzad_skarbowy' => (string)($a['urzad_skarbowy'] ?? ''),
                     'addr_street' => (string)($a['adr_ulica'] ?? ''), 'addr_house' => (string)($a['adr_nr_domu'] ?? ''),
                     'addr_flat' => (string)($a['adr_nr_mieszkania'] ?? ''), 'addr_postal' => (string)($a['adr_kod'] ?? ''),
                     'addr_city' => (string)($a['adr_miejscowosc'] ?? '')];
            $addrEmpty = trim((string)$c['addr_city']) === '' && trim((string)$c['addr_street']) === '' && trim((string)$c['addr_postal']) === '';
            foreach ($fill as $k => $v) {
                $isAddr = str_starts_with($k, 'addr_');
                if ($v !== '' && ($isAddr ? $addrEmpty : trim((string)($c[$k] ?? '')) === '')) $set[$k] = $v;
            }
            if ($addrEmpty && trim((string)$c['adres']) === '') $set['adres'] = OswForm::addressLine($a);
        }
        if (!$set) return;
        $set['updated_at'] = date('Y-m-d H:i:s');
        $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($set)));
        db_exec("UPDATE umowy_zlecenie SET {$cols} WHERE id = ?", [...array_values($set), $cid]);
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    /** Status pakietu do list: najsłabszy status dokumentów. */
    public static function summaryStatus(array $o, array $docs): array
    {
        if (($o['source'] ?? '') === 'public' && $o['status'] === 'roboczy' && (int)$o['step'] <= 1) return ['Publiczny kreator — nie rozpoczęto', 'secondary'];
        if ($o['status'] === 'roboczy') return [(int)$o['step'] > 1 ? 'W trakcie wypełniania (krok ' . (int)$o['step'] . '/6)' : 'Nie rozpoczęto', 'secondary'];
        $order = ['odrzucone', 'wypelnione', 'wydrukowane', 'skan', 'zweryfikowane'];
        $worst = 'zweryfikowane';
        foreach ($docs as $d) if (array_search($d['status'], $order, true) < array_search($worst, $order, true)) $worst = $d['status'];
        return self::DOC_STATUS[$worst];
    }

    public static function umowaMeta(?array $c): array
    {
        return ['numer' => (string)($c['numer_umowy'] ?? ''),
                'data' => !empty($c['data_zawarcia']) ? date('d.m.Y', (int)strtotime((string)$c['data_zawarcia'])) : ''];
    }

    private static function log(int $cid, int $by, string $action, string $note): void
    {
        if ($cid <= 0) return;
        try {
            require_once dirname(__DIR__, 3) . '/includes/approval.php';
            log_contract_action('zlecenie', $cid, $by, $action, $note);
        } catch (\Throwable $e) { error_log('[zlecenie_oswiadczenie] log: ' . $e->getMessage()); }
    }
}
