<?php
/**
 * includes/oswiadczenia.php — Moduł Oświadczeń (wolontariat).
 *
 * Oświadczenia w formie dokumentowej z uwierzytelnieniem elektronicznym
 * (art. 77(2) k.c.): treść ustalona przez organizację (szablon), a osoba
 * składająca oświadczenie potwierdza swoją tożsamość jednorazowym kodem
 * przesłanym na zweryfikowany kanał (SMS/e-mail) — to wiąże oświadczenie
 * z konkretną osobą bez podpisu kwalifikowanego.
 *
 * Ślad dowodowy podpisu: treść_hash (SHA-256 treści w chwili podpisu —
 * podpis jest ważny tylko dla dokładnie tej treści), IP, User-Agent, znacznik
 * czasu i podpis_hash (SHA-256 całego zdarzenia) — pozwala wykazać integralność
 * i autentyczność bez przechowywania samego kodu OTP po jego zużyciu.
 *
 * Wzorzec samonaprawy schematu jak w includes/rpts.php — CREATE TABLE IF NOT
 * EXISTS w try/catch, bezpieczne przy współbieżnym starcie wielu workerów.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS szablony_oswiadczen (
                id            INTEGER PRIMARY KEY " . (DB_TYPE === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
                kod           VARCHAR(50)  NOT NULL,
                tytul         VARCHAR(255) NOT NULL,
                tresc         TEXT         NOT NULL,
                wersja        INTEGER      NOT NULL DEFAULT 1,
                aktywny       INTEGER      NOT NULL DEFAULT 1,
                wymaga_2fa    INTEGER      NOT NULL DEFAULT 1,
                utworzyl_id   INTEGER,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME
            )
        ");
    } catch (\Throwable $e) {}

    try {
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_szablony_oswiadczen_kod ON szablony_oswiadczen (kod)");
    } catch (\Throwable $e) {}

    // Warunek zależności: nazwa kolumny w umowy_wolontariat, która musi być
    // prawdziwa (=1), aby dany szablon dotyczył danego wolontariusza — np.
    // szablon „Zgoda na kontakt z małoletnimi" wymagany tylko wtedy, gdy w
    // umowie zaznaczono checkbox `kontakt_z_maloletnimi`. NULL/'' = szablon
    // dotyczy każdego aktywnego wolontariusza bez warunku (jak dotychczas).
    try {
        db()->exec("ALTER TABLE szablony_oswiadczen ADD COLUMN warunek_pole_umowy VARCHAR(64)");
    } catch (\Throwable $e) {
        // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
    }

    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS uzytkownik_oswiadczenie (
                id                 INTEGER PRIMARY KEY " . (DB_TYPE === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
                user_id            INTEGER      NOT NULL,
                szablon_id         INTEGER      NOT NULL,
                status             VARCHAR(20)  NOT NULL DEFAULT 'oczekujace',
                tresc_hash         VARCHAR(64),
                kod_hash           VARCHAR(255),
                kod_kanal          VARCHAR(10),
                kod_wygasa_o       DATETIME,
                kod_prob           INTEGER      NOT NULL DEFAULT 0,
                podpisano_at       DATETIME,
                podpis_ip          VARCHAR(45),
                podpis_user_agent  VARCHAR(255),
                podpis_hash        VARCHAR(64),
                created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME
            )
        ");
    } catch (\Throwable $e) {}

    try {
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_uzytk_oswiadcz_user_szablon ON uzytkownik_oswiadczenie (user_id, szablon_id)");
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE INDEX IF NOT EXISTS ix_uzytk_oswiadcz_user ON uzytkownik_oswiadczenie (user_id)");
    } catch (\Throwable $e) {}
})();

// ─────────────────────────────────────────────────────────────────────────────
// Stałe
// ─────────────────────────────────────────────────────────────────────────────

const OSW_STATUS_OCZEKUJACE = 'oczekujace';
const OSW_STATUS_PODPISANE  = 'podpisane';

const OSW_KOD_TTL_SEKUND  = 300; // 5 minut — jak sms_generate_otp()
const OSW_KOD_MAX_PROB    = 5;   // blokada po 5 nieudanych próbach

// ─────────────────────────────────────────────────────────────────────────────
// Szablony
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('osw_szablony_aktywne')) {
    /** Lista aktywnych szablonów oświadczeń (do przypisania użytkownikom). */
    function osw_szablony_aktywne(): array {
        return db_all("SELECT * FROM szablony_oswiadczen WHERE aktywny = 1 ORDER BY tytul");
    }
}

if (!function_exists('osw_szablon_hash')) {
    /** Hash treści szablonu w danej wersji — wiąże podpis z dokładną treścią. */
    function osw_szablon_hash(array $szablon): string {
        return hash('sha256', $szablon['id'] . '|' . $szablon['wersja'] . '|' . $szablon['tresc']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Mechanizm zależności — czy szablon dotyczy danego użytkownika
// ─────────────────────────────────────────────────────────────────────────────

/** Tabele umów sprawdzane przez mechanizm zależności — w tej kolejności. */
const OSW_TABELE_UMOW = ['umowy_wolontariat', 'umowy_zlecenie'];

if (!function_exists('_osw_kolumna_istnieje')) {
    /** Czy kolumna istnieje w danej tabeli umów — cache per tabela, bezpieczne na SQLite i MySQL. */
    function _osw_kolumna_istnieje(string $tabela, string $kolumna): bool {
        static $cols = [];
        if (!array_key_exists($tabela, $cols)) {
            try {
                $cols[$tabela] = (DB_TYPE === 'sqlite')
                    ? db()->query("PRAGMA table_info(`{$tabela}`)")->fetchAll(PDO::FETCH_COLUMN, 1)
                    : db()->query("SHOW COLUMNS FROM `{$tabela}`")->fetchAll(PDO::FETCH_COLUMN, 0);
            } catch (\Throwable $e) {
                $cols[$tabela] = [];
            }
        }
        return in_array($kolumna, $cols[$tabela], true);
    }
}

if (!function_exists('osw_uzytkownik_ma_pole_umowy')) {
    /**
     * Sprawdza, czy dowolna umowa powiązana z użytkownikiem — wolontariacka
     * ALBO zlecenie (po e-mailu lub loginie/ID M365 — jak w panel_contracts())
     * — ma zaznaczone dane pole (wartość „1"). Wystarczy jedna pasująca umowa
     * z dowolnej z tabel. Nazwa pola musi być bezpiecznym identyfikatorem
     * (litery/cyfry/podkreślenie) i istnieć w danej tabeli — inaczej fail-closed
     * (nie wymagaj) dla tej tabeli, żeby błędna konfiguracja szablonu nigdy nie
     * zablokowała logowania do panelu.
     */
    function osw_uzytkownik_ma_pole_umowy(array $user, string $pole): bool {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $pole)) return false;

        $email = trim($user['email'] ?? '');
        $ms_id = trim($user['microsoft_id'] ?? '');
        if ($email === '' && $ms_id === '') return false;

        $conds = []; $params = [];
        if ($email !== '') { $conds[] = 'email = ?';       $params[] = $email; }
        if ($email !== '') { $conds[] = 'm365_login = ?';  $params[] = $email; }
        if ($ms_id !== '') { $conds[] = 'm365_user_id = ?'; $params[] = $ms_id; }

        foreach (OSW_TABELE_UMOW as $tabela) {
            if (!_osw_kolumna_istnieje($tabela, $pole)) continue;

            try {
                $umowa = db_one(
                    "SELECT `{$pole}` AS wartosc FROM `{$tabela}`
                     WHERE (" . implode(' OR ', $conds) . ")
                     ORDER BY created_at DESC LIMIT 1",
                    $params
                );
            } catch (\Throwable $e) {
                continue;
            }

            if ($umowa !== null && (string)$umowa['wartosc'] === '1') return true;
        }

        return false;
    }
}

if (!function_exists('osw_szablon_dotyczy_uzytkownika')) {
    /** Czy dany szablon w ogóle dotyczy tego użytkownika (warunek zależności z umowy). */
    function osw_szablon_dotyczy_uzytkownika(array $szablon, array $user): bool {
        $pole = trim($szablon['warunek_pole_umowy'] ?? '');
        if ($pole === '') return true; // brak warunku = dotyczy każdego aktywnego wolontariusza
        return osw_uzytkownik_ma_pole_umowy($user, $pole);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Lista oświadczeń dla zalogowanego użytkownika
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('osw_lista_dla_uzytkownika')) {
    /**
     * Zwraca oświadczenia użytkownika podzielone na statusy. Dla każdego
     * aktywnego szablonu, do którego użytkownik jeszcze nie ma rekordu,
     * tworzy go automatycznie ze statusem "oczekujace" (przypisanie „na żądanie”
     * przy pierwszym wejściu do panelu — nie wymaga osobnego kroku admina).
     */
    function osw_lista_dla_uzytkownika(int $user_id): array {
        $user = db_one("SELECT id, email, microsoft_id FROM users WHERE id = ?", [$user_id]);
        if (!$user) return [];

        $pomijane_id = []; // szablony bez warunku spełnionego I bez istniejącego rekordu — w ogóle nie dotyczą
        foreach (osw_szablony_aktywne() as $szablon) {
            $dotyczy = osw_szablon_dotyczy_uzytkownika($szablon, $user);

            $istnieje = db_one(
                "SELECT id FROM uzytkownik_oswiadczenie WHERE user_id = ? AND szablon_id = ?",
                [$user_id, $szablon['id']]
            );

            if (!$istnieje) {
                if (!$dotyczy) { $pomijane_id[] = $szablon['id']; continue; }
                try {
                    db_insert('uzytkownik_oswiadczenie', [
                        'user_id'    => $user_id,
                        'szablon_id' => $szablon['id'],
                        'status'     => OSW_STATUS_OCZEKUJACE,
                    ]);
                } catch (\Throwable $e) {
                    // Wyścig przy równoległym żądaniu — unikalny indeks (user_id, szablon_id)
                    // już to zablokował, rekord istnieje. Ignorujemy.
                }
            } elseif (!$dotyczy) {
                // Warunek już nie spełniony (np. zmiana umowy) — jeśli oświadczenie
                // wciąż oczekuje na podpis, przestaje straszyć użytkownika na liście;
                // rekord zostaje w bazie na wypadek, gdyby warunek znów zaczął obowiązywać.
                // Podpisane oświadczenia (ślad historyczny) zawsze pozostają widoczne.
                $status = db_one("SELECT status FROM uzytkownik_oswiadczenie WHERE id = ?", [$istnieje['id']]);
                if (($status['status'] ?? '') !== OSW_STATUS_PODPISANE) $pomijane_id[] = $szablon['id'];
            }
        }

        $sql = "SELECT o.id, o.status, o.podpisano_at, o.created_at,
                       s.id AS szablon_id, s.kod, s.tytul, s.wersja, s.wymaga_2fa
                FROM uzytkownik_oswiadczenie o
                JOIN szablony_oswiadczen s ON s.id = o.szablon_id
                WHERE o.user_id = ?";
        $params = [$user_id];
        if ($pomijane_id) {
            $ph = implode(',', array_fill(0, count($pomijane_id), '?'));
            $sql .= " AND s.id NOT IN ({$ph})";
            $params = array_merge($params, $pomijane_id);
        }
        $sql .= " ORDER BY (o.status = ?) ASC, s.tytul";
        $params[] = OSW_STATUS_PODPISANE;

        return db_all($sql, $params);
    }
}

if (!function_exists('osw_wymagane_niepodpisane')) {
    /**
     * Oświadczenia wymagane a jeszcze niepodpisane dla użytkownika — do bramki
     * przy wejściu do panelu (panel/includes/header_panel.php). Lekka odmiana
     * osw_lista_dla_uzytkownika(): NIE tworzy nowych rekordów (to robi dopiero
     * wejście na stronę /oswiadczenia/), tylko sprawdza, co już czeka.
     */
    function osw_wymagane_niepodpisane(array $user): array {
        $uid = (int)($user['id'] ?? 0);
        if (!$uid) return [];

        $wynik = [];
        foreach (osw_szablony_aktywne() as $szablon) {
            if (!osw_szablon_dotyczy_uzytkownika($szablon, $user)) continue;

            $rekord = db_one(
                "SELECT status FROM uzytkownik_oswiadczenie WHERE user_id = ? AND szablon_id = ?",
                [$uid, $szablon['id']]
            );
            // Brak rekordu = jeszcze nieprzypisane „na żądanie" — dla bramki liczy się
            // tak samo jak „oczekujace" (i tak trzeba je będzie podpisać).
            if ($rekord === null || $rekord['status'] !== OSW_STATUS_PODPISANE) {
                $wynik[] = ['szablon_id' => $szablon['id'], 'tytul' => $szablon['tytul'], 'kod' => $szablon['kod']];
            }
        }
        return $wynik;
    }
}

if (!function_exists('osw_pobierz')) {
    /** Pojedyncze oświadczenie użytkownika wraz z treścią szablonu — z kontrolą właściciela. */
    function osw_pobierz(int $id, int $user_id): ?array {
        return db_one(
            "SELECT o.*, s.kod, s.tytul, s.tresc, s.wersja, s.wymaga_2fa
             FROM uzytkownik_oswiadczenie o
             JOIN szablony_oswiadczen s ON s.id = o.szablon_id
             WHERE o.id = ? AND o.user_id = ?",
            [$id, $user_id]
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Podpis — krok 1: inicjacja (wygenerowanie i wysyłka kodu 2FA)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('osw_rozpocznij_podpis')) {
    /**
     * Generuje 6-cyfrowy kod autoryzacyjny (ważny 5 min), zapisuje jego hash
     * (nigdy jawny kod) i próbuje wysłać SMS-em (jeśli użytkownik ma numer
     * i kanał SMS jest gotowy) lub e-mailem jako fallback.
     *
     * @return array{ok:bool, error?:string, kanal?:string, kod_dev?:string}
     *   kod_dev jest zwracany WYŁĄCZNIE w APP_ENV=development — symulacja 2FA
     *   bez realnej bramki SMS na środowisku deweloperskim.
     */
    function osw_rozpocznij_podpis(int $id, int $user_id): array {
        $osw = osw_pobierz($id, $user_id);
        if (!$osw) return ['ok' => false, 'error' => 'Nie znaleziono oświadczenia.'];
        if ($osw['status'] === OSW_STATUS_PODPISANE) {
            return ['ok' => false, 'error' => 'To oświadczenie jest już podpisane.'];
        }

        $user = db_one("SELECT phone_number, email FROM users WHERE id = ?", [$user_id]);
        if (!$user) return ['ok' => false, 'error' => 'Nie znaleziono konta użytkownika.'];

        $kod     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $wygasa  = date('Y-m-d H:i:s', time() + OSW_KOD_TTL_SEKUND);

        db()->prepare(
            "UPDATE uzytkownik_oswiadczenie
             SET kod_hash = ?, kod_kanal = ?, kod_wygasa_o = ?, kod_prob = 0
             WHERE id = ?"
        )->execute([password_hash($kod, PASSWORD_DEFAULT), '', $wygasa, $id]);

        $tresc  = "Kod autoryzacyjny do podpisania oświadczenia \"{$osw['tytul']}\": {$kod}. Ważny 5 minut. Nikomu go nie udostępniaj.";
        $kanal  = '';
        $blad   = null;

        $telefon = trim((string)($user['phone_number'] ?? ''));
        if ($telefon !== '' && function_exists('sms_send_with_fallback') && function_exists('sms_is_mobile') && sms_is_mobile($telefon)) {
            try {
                $kanal = sms_send_with_fallback($telefon, $tresc, (string)($user['email'] ?? ''));
            } catch (\Throwable $e) {
                $blad = $e->getMessage();
            }
        } elseif (!empty($user['email'])) {
            $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
            $subj = "[{$org}] Kod autoryzacyjny do podpisu oświadczenia";
            $body = '<p>Kod autoryzacyjny do podpisania oświadczenia <strong>' . htmlspecialchars($osw['tytul'], ENT_QUOTES) . '</strong>:</p>'
                  . '<p style="font-size:1.5em;letter-spacing:.15em"><strong>' . htmlspecialchars($kod, ENT_QUOTES) . '</strong></p>'
                  . '<p>Kod jest ważny 5 minut. Nie udostępniaj go nikomu.</p>';
            try {
                if (function_exists('mail_queue_add')) {
                    mail_queue_add($user['email'], '', $subj, $body, '', 'oswiadczenie', $id, '', true);
                } else {
                    mail($user['email'], $subj, strip_tags($body));
                }
                $kanal = 'email';
            } catch (\Throwable $e) {
                $blad = $e->getMessage();
            }
        } else {
            $blad = 'Brak numeru telefonu i adresu e-mail do wysyłki kodu.';
        }

        if ($kanal === '') {
            return ['ok' => false, 'error' => $blad ?: 'Nie udało się wysłać kodu autoryzacyjnego.'];
        }

        $wynik = ['ok' => true, 'kanal' => $kanal];
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $wynik['kod_dev'] = $kod; // wyłącznie dev — symulacja 2FA bez realnej bramki
        }
        return $wynik;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Podpis — krok 2: weryfikacja kodu i zapis podpisu
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('osw_zweryfikuj_i_podpisz')) {
    /**
     * Weryfikuje kod 2FA i — jeśli poprawny — zapisuje podpis w formie
     * dokumentowej: status "podpisane", data, IP, User-Agent i dwa hashe:
     * treść_hash (dowód TEGO, co zostało podpisane) i podpis_hash (dowód
     * całego zdarzenia podpisu — nie do podrobienia bez znajomości wszystkich
     * składowych). Kod przechowywany jest tylko jako hash i jest zerowany po
     * zużyciu — nie da się go odtworzyć ani użyć powtórnie.
     */
    function osw_zweryfikuj_i_podpisz(int $id, int $user_id, string $kod, string $ip, string $user_agent): array {
        $osw = osw_pobierz($id, $user_id);
        if (!$osw) return ['ok' => false, 'error' => 'Nie znaleziono oświadczenia.'];
        if ($osw['status'] === OSW_STATUS_PODPISANE) {
            return ['ok' => false, 'error' => 'To oświadczenie jest już podpisane.'];
        }
        if (empty($osw['kod_hash']) || empty($osw['kod_wygasa_o'])) {
            return ['ok' => false, 'error' => 'Najpierw wygeneruj kod autoryzacyjny.'];
        }
        if ((int)$osw['kod_prob'] >= OSW_KOD_MAX_PROB) {
            return ['ok' => false, 'error' => 'Przekroczono limit prób. Wygeneruj kod ponownie.'];
        }
        if (strtotime($osw['kod_wygasa_o']) < time()) {
            return ['ok' => false, 'error' => 'Kod wygasł. Wygeneruj nowy.'];
        }

        if (!password_verify($kod, (string)$osw['kod_hash'])) {
            db()->prepare("UPDATE uzytkownik_oswiadczenie SET kod_prob = kod_prob + 1 WHERE id = ?")->execute([$id]);
            $pozostalo = OSW_KOD_MAX_PROB - ((int)$osw['kod_prob'] + 1);
            return ['ok' => false, 'error' => "Nieprawidłowy kod. Pozostało prób: " . max(0, $pozostalo) . '.'];
        }

        $tresc_hash = osw_szablon_hash($osw);
        $teraz      = date('Y-m-d H:i:s');
        $podpis_hash = hash('sha256', implode('|', [
            $user_id, $osw['szablon_id'], $tresc_hash, $teraz, $ip, bin2hex(random_bytes(16)),
        ]));

        db()->prepare(
            "UPDATE uzytkownik_oswiadczenie
             SET status = ?, tresc_hash = ?, podpisano_at = ?, podpis_ip = ?, podpis_user_agent = ?,
                 podpis_hash = ?, kod_hash = NULL, kod_wygasa_o = NULL, kod_prob = 0
             WHERE id = ?"
        )->execute([
            OSW_STATUS_PODPISANE, $tresc_hash, $teraz, $ip, mb_substr($user_agent, 0, 255), $podpis_hash, $id,
        ]);

        try {
            osw_wyslij_potwierdzenie_podpisu($id, $user_id);
        } catch (\Throwable $e) {
            // E-mail potwierdzający nie może cofnąć już zapisanego podpisu —
            // błąd wysyłki tylko logujemy, podpis pozostaje ważny.
            error_log('[oswiadczenia] potwierdzenie podpisu: ' . $e->getMessage());
        }

        return ['ok' => true, 'podpisano_at' => $teraz, 'podpis_hash' => $podpis_hash];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Powiadomienia e-mail
// ─────────────────────────────────────────────────────────────────────────────

const OSW_PLATFORMA_URL = 'szo.feer.org.pl';

if (!function_exists('_osw_stopka_mail')) {
    function _osw_stopka_mail(): string {
        return "Bezpieczny dostęp do platformy: " . OSW_PLATFORMA_URL . "\n\n"
             . "W przypadku pytań lub trudności technicznych, prosimy o kontakt z administratorem systemu.\n\n"
             . "Z pozdrowieniami,\n\n"
             . "Zespół Fundacji FEER";
    }
}

if (!function_exists('osw_powiadom_nowe_oswiadczenie')) {
    /**
     * Wysyła e-mail (czysty tekst) do wszystkich aktywnych wolontariuszy/
     * zleceniobiorców, których dotyczy nowo utworzony szablon (mechanizm
     * zależności — patrz osw_szablon_dotyczy_uzytkownika()). Wywoływane
     * jednorazowo przy tworzeniu szablonu (osw_szablon_utworz()).
     *
     * @return int liczba wysłanych powiadomień
     */
    function osw_powiadom_nowe_oswiadczenie(array $szablon): int {
        $uzytkownicy = db_all(
            "SELECT id, email, name, microsoft_id FROM users WHERE is_active = 1 AND role = 'viewer'"
        );

        $subj  = 'Nowe oświadczenie do podpisania w Systemie Zarządzania Organizacją (SZO)';
        $wyslano = 0;

        foreach ($uzytkownicy as $user) {
            if (empty($user['email'])) continue;
            if (!osw_szablon_dotyczy_uzytkownika($szablon, $user)) continue;

            $body = "Dzień dobry,\n\n"
                  . "Informujemy, że w Systemie Zarządzania Organizacją (SZO) pod adresem " . OSW_PLATFORMA_URL . " "
                  . "zostało udostępnione nowe oświadczenie („" . $szablon['tytul'] . "”) wymagające Twojego "
                  . "zapoznania się oraz złożenia podpisu.\n\n"
                  . "Prosimy o zalogowanie się do systemu, przejście do sekcji dokumentów oczekujących i dopełnienie "
                  . "niezbędnych formalności.\n\n"
                  . _osw_stopka_mail();

            try {
                if (function_exists('mail_queue_add')) {
                    mail_queue_add($user['email'], (string)($user['name'] ?? ''), $subj, '', $body, 'oswiadczenie', (int)$szablon['id']);
                } else {
                    mail($user['email'], $subj, $body);
                }
                $wyslano++;
            } catch (\Throwable $e) {
                error_log('[oswiadczenia] powiadomienie o nowym oświadczeniu: ' . $e->getMessage());
            }
        }

        return $wyslano;
    }
}

if (!function_exists('osw_szablon_utworz')) {
    /**
     * Tworzy nowy szablon oświadczenia i powiadamia e-mailem wszystkich
     * użytkowników, których od razu dotyczy (wg mechanizmu zależności).
     * Jedyny punkt wejścia do tworzenia szablonów — tak, żeby powiadomienie
     * zawsze towarzyszyło udostępnieniu nowej treści.
     */
    function osw_szablon_utworz(array $dane): int {
        $id = db_insert('szablony_oswiadczen', [
            'kod'                => $dane['kod'],
            'tytul'              => $dane['tytul'],
            'tresc'              => $dane['tresc'],
            'wersja'             => $dane['wersja'] ?? 1,
            'aktywny'            => $dane['aktywny'] ?? 1,
            'wymaga_2fa'         => $dane['wymaga_2fa'] ?? 1,
            'warunek_pole_umowy' => $dane['warunek_pole_umowy'] ?? null,
            'utworzyl_id'        => $dane['utworzyl_id'] ?? null,
        ]);

        $szablon = db_one("SELECT * FROM szablony_oswiadczen WHERE id = ?", [$id]);
        if ($szablon && !empty($szablon['aktywny'])) {
            try {
                osw_powiadom_nowe_oswiadczenie($szablon);
            } catch (\Throwable $e) {
                error_log('[oswiadczenia] masowe powiadomienie o nowym szablonie: ' . $e->getMessage());
            }
        }

        return $id;
    }
}

if (!function_exists('osw_pdf_potwierdzenia_html')) {
    /** Treść HTML potwierdzenia podpisu — do wydruku PDF (mPDF, jak guardian_consent_generate_pdf()). */
    function osw_pdf_potwierdzenia_html(array $osw, array $user): string {
        $org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
        return '
        <h2 style="text-align:center">Potwierdzenie podpisania oświadczenia</h2>
        <p style="text-align:center;color:#555">' . htmlspecialchars($org, ENT_QUOTES) . '</p>
        <hr>
        <table style="width:100%;font-size:10.5pt">
          <tr><td style="width:35%"><strong>Tytuł oświadczenia</strong></td><td>' . htmlspecialchars($osw['tytul'], ENT_QUOTES) . '</td></tr>
          <tr><td><strong>Wersja</strong></td><td>' . (int)$osw['wersja'] . '</td></tr>
          <tr><td><strong>Osoba składająca</strong></td><td>' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES) . ' (' . htmlspecialchars($user['email'] ?? '', ENT_QUOTES) . ')</td></tr>
          <tr><td><strong>Data i godzina podpisu</strong></td><td>' . htmlspecialchars($osw['podpisano_at'], ENT_QUOTES) . '</td></tr>
          <tr><td><strong>Adres IP</strong></td><td>' . htmlspecialchars($osw['podpis_ip'] ?? '', ENT_QUOTES) . '</td></tr>
          <tr><td><strong>Hash treści</strong></td><td style="font-family:monospace;font-size:8.5pt">' . htmlspecialchars($osw['tresc_hash'] ?? '', ENT_QUOTES) . '</td></tr>
          <tr><td><strong>Hash podpisu</strong></td><td style="font-family:monospace;font-size:8.5pt">' . htmlspecialchars($osw['podpis_hash'] ?? '', ENT_QUOTES) . '</td></tr>
        </table>
        <hr>
        <p style="font-size:10.5pt"><strong>Treść podpisanego oświadczenia:</strong></p>
        <div style="font-size:10.5pt">' . $osw['tresc'] . '</div>
        <p style="margin-top:2em;font-size:8.5pt;color:#777">
          Dokument wygenerowany automatycznie jako potwierdzenie złożenia oświadczenia w formie dokumentowej
          (art. 77(2) Kodeksu cywilnego) — tożsamość osoby podpisującej została potwierdzona jednorazowym
          kodem autoryzacyjnym przesłanym na zweryfikowany kanał (SMS/e-mail).
        </p>';
    }
}

if (!function_exists('osw_generuj_pdf_potwierdzenia')) {
    /** Renderuje PDF potwierdzenia i zapisuje go pod UPLOAD_DIR; zwraca ścieżkę względną (do mail_queue_add) albo null. */
    function osw_generuj_pdf_potwierdzenia(array $osw, array $user): ?string {
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';

            $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
            if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

            $mpdf = new \Mpdf\Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_left'   => 25,
                'margin_right'  => 20,
                'margin_top'    => 18,
                'margin_bottom' => 18,
                'default_font'  => 'dejavusans',
                'tempDir'       => $mpdf_tmp,
            ]);
            $mpdf->SetTitle('Potwierdzenie podpisania oświadczenia — ' . $osw['tytul']);
            $mpdf->WriteHTML(osw_pdf_potwierdzenia_html($osw, $user));
            $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

            $dir = UPLOAD_DIR . 'oswiadczenia_potwierdzenia/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $nazwa = 'oswiadczenie_' . $osw['id'] . '_' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents($dir . $nazwa, $pdf);

            return 'oswiadczenia_potwierdzenia/' . $nazwa;
        } catch (\Throwable $e) {
            error_log('[oswiadczenia_pdf] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('osw_wyslij_potwierdzenie_podpisu')) {
    /** Wysyła e-mail (czysty tekst) potwierdzający podpis, z PDF-em potwierdzenia w załączniku. */
    function osw_wyslij_potwierdzenie_podpisu(int $id, int $user_id): void {
        $user = db_one("SELECT id, email, name FROM users WHERE id = ?", [$user_id]);
        if (!$user || empty($user['email'])) return;

        $osw = osw_pobierz($id, $user_id);
        if (!$osw) return;

        $sciezka_pdf = osw_generuj_pdf_potwierdzenia($osw, $user);

        $subj = 'Potwierdzenie podpisania oświadczenia w Systemie Zarządzania Organizacją (SZO)';
        $body = "Dzień dobry,\n\n"
              . "Potwierdzamy, że w Systemie Zarządzania Organizacją (SZO) pod adresem " . OSW_PLATFORMA_URL . " "
              . "zostało złożone Twoje podpisane oświadczenie („" . $osw['tytul'] . "”).\n\n"
              . "W załączniku przesyłamy potwierdzenie podpisania oświadczenia w formie dokumentowej.\n\n"
              . _osw_stopka_mail();

        $attachments = [];
        if ($sciezka_pdf !== null) {
            $attachments[] = [
                'path' => $sciezka_pdf,
                'name' => 'potwierdzenie_oswiadczenia.pdf',
                'mime' => 'application/pdf',
                'size' => filesize(UPLOAD_DIR . $sciezka_pdf) ?: 0,
            ];
        }

        if (function_exists('mail_queue_add')) {
            mail_queue_add(
                $user['email'], (string)($user['name'] ?? ''), $subj, '', $body,
                'oswiadczenie', $id, '', false, $attachments
            );
        } else {
            mail($user['email'], $subj, $body);
        }
    }
}
