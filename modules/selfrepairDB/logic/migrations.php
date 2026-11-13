<?php
/**
 * modules/selfrepairDB/logic/migrations.php — rejestr migracji JEDNORAZOWYCH.
 *
 * Każda pozycja wykona się raz na zawsze (settings 'selfrepair:<id>'), na
 * końcu żądania, gdy schemat już istnieje — patrz selfRepairDB.php.
 * ZASADY:
 *   - id nigdy się nie zmienia (zmiana = ponowne wykonanie);
 *   - migracja jest idempotentna (drugie wykonanie nic nie psuje) i rzuca
 *     wyjątek przy porażce, żeby nie dostała znacznika;
 *   - potrzebne biblioteki dołącza sama (require_once), bo może ruszyć
 *     w dowolnym module;
 *   - NIE usuwamy starych pozycji — to historia zmian danych.
 * Kolejność = kolejność wykonania.
 *
 * @return array<string, array{desc: string, run: callable}>
 */
$ROOT = dirname(__DIR__, 3);

return [

    // Przeniesione z karty30_migrate() (dawniej przy KAŻDYM żądaniu)
    '2026-06_k30_client_status_keys' => [
        'desc' => 'Beneficjenci: stare statusy ready/to_settle/other → learning/graduated/enrolled',
        'run'  => static function (): void {
            db()->exec("UPDATE k30_clients SET status='learning'  WHERE status='ready'");
            db()->exec("UPDATE k30_clients SET status='graduated' WHERE status='to_settle'");
            db()->exec("UPDATE k30_clients SET status='enrolled'  WHERE status='other'");
        },
    ],

    // Przeniesione z ti_protocols_migrate() — dawna flaga ti_protocols_next_month_backfill
    '2026-09_ti_protocols_next_month_backfill' => [
        'desc' => 'Protokoły TI: zatwierdzone miesięczne bez otwartego następnego miesiąca → otwórz go',
        'run'  => static function () use ($ROOT): void {
            if (org_setting('ti_protocols_next_month_backfill') === '1') return;   // już zrobione starym mechanizmem
            require_once $ROOT . '/includes/karty30.php';
            require_once $ROOT . '/includes/ti_protocols.php';
            ti_protocols_backfill_next_months();
            org_setting_set('ti_protocols_next_month_backfill', '1');
        },
    ],

    // Przeniesione z ti_notices_migrate() — dawne flagi ti_notice_2026_09_szo141_v2/_v3
    '2026-09_ti_notice_141a_naglowek' => [
        'desc' => 'Komunikat 14.1: dopisek o nagłówku, selektorze grupy i menu (już zasiany wpis)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();   // tabela komunikatów istnieje
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Nagłówek panelu:%'"
            )->execute(["\n\n(Uzupełnienie 14.1a)\n• " . TI_NOTICE_141A_NAGLOWEK]);
        },
    ],
    '2026-09_ti_notice_141b_pulpit' => [
        'desc' => 'Komunikat 14.1: dopisek o nowym Pulpicie (już zasiany wpis)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Pulpit:%'"
            )->execute(["\n\n(Uzupełnienie 14.1b)\n• " . TI_NOTICE_141B_PULPIT]);
        },
    ],

    '2026-09_ti_notice_141f' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1f (Wydruki, dziennik, widoki Zajęć, PDF, szybkość)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1f:%'"
            )->execute(["\n\n(Uzupełnienie 14.1f)\n• " . TI_NOTICE_141F]);
        },
    ],

    '2026-09_ti_notice_141g' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1g (rozliczenia: tryb FVAT/zestawienie, wycofywanie, przeniesienia, podglądy, Przelicz ceny)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1g%'"
            )->execute(["\n\n(Uzupełnienie 14.1g)\n• " . TI_NOTICE_141G]);
        },
    ],

    '2026-09_ti_notice_141h' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1h (stawka online, cenniki i rabaty, nadpłaty, korekty, panel kursanta)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1h%'"
            )->execute(["\n\n(Uzupełnienie 14.1h)\n• " . TI_NOTICE_141H]);
        },
    ],

    '2026-09_ti_notice_141i' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1i (portal płatności /platnosci, dopasowanie wpłat z wyciągów EODoK)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1i%'"
            )->execute(["\n\n(Uzupełnienie 14.1i)\n• " . TI_NOTICE_141I]);
        },
    ],

    '2026-10_edok_rachunki_obieg_papierowy' => [
        'desc' => 'EODoK: rachunki do umów zlecenie czekające w obiegu elektronicznym → obieg papierowy (akceptacja na papierze), dokumenty do zapłaty',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/auth.php';   // edok_log → current_user() (także przy uruchomieniu z CLI)
            require_once $ROOT . '/includes/edok.php';
            edok_migrate();
            $docs = db_all("SELECT id FROM edok_documents
                             WHERE zlec_rachunek_id IS NOT NULL AND typ_dokumentu = 'rachunek'
                               AND status IN ('draft','w_obiegu') AND COALESCE(obieg_papierowy,0) = 0");
            foreach ($docs as $d) {
                edok_zatwierdz_papierowo((int)$d['id'], 'poprawka: rachunki do umów domyślnie w obiegu papierowym — dokument przeniesiony z obiegu elektronicznego');
            }
        },
    ],

    '2026-10_ti_szk_rename' => [
        'desc' => 'TI: szkolenia krótkie (grupy uproszczone) KON.xxxxx.yyy.rrrr → SzK.xxxxx.yyy.rrrr (KON = odtąd numeracja kart konsultacji)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/karty30.php';
            karty30_migrate();
            foreach (db_all("SELECT id, name FROM k30_ti_courses WHERE simple_mode > 0 AND name LIKE 'KON.%'") as $c) {
                if (!preg_match('/^KON\.(\d{5}\.\p{Lu}{3}\.\d{4})$/u', (string)$c['name'], $m)) continue;
                $new = 'SzK.' . $m[1];
                if (db_one("SELECT 1 FROM k30_ti_courses WHERE name=? AND id<>?", [$new, (int)$c['id']])) continue;
                db()->prepare("UPDATE k30_ti_courses SET name=? WHERE id=?")->execute([$new, (int)$c['id']]);
                ti_course_log((int)$c['id'], 'rename', 'Zmiana prefiksu szkolenia krótkiego: ' . $c['name'] . ' → ' . $new . '.', 0, 'system');
            }
        },
    ],

    '2026-10_ti_consultation_card_no' => [
        'desc' => 'Karty konsultacji: numer KON.XXX.MM.RRRR dla kart sprzed przeniesienia do panelu dydaktyka (kolejność wg daty konsultacji)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/karty30.php';
            require_once $ROOT . '/modules/ti_consultations/logic/consultations.php';
            karty30_migrate();
            foreach (db_all("SELECT id, consultation_datetime FROM k30_consultations WHERE COALESCE(card_no,'') = '' ORDER BY consultation_datetime, id") as $c) {
                db()->prepare("UPDATE k30_consultations SET card_no=? WHERE id=? AND COALESCE(card_no,'') = ''")
                   ->execute([TiConsultations::cardNumber((string)$c['consultation_datetime']), (int)$c['id']]);
            }
        },
    ],

    '2026-10_ti_protocol_numbers' => [
        'desc' => 'Protokoły zajęć TI: numer PRT/kod grupy/MM/RR dla protokołów sprzed wprowadzenia numeracji',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/karty30.php';
            require_once $ROOT . '/includes/ti_protocols.php';
            ti_protocols_migrate();
            foreach (db_all("SELECT id FROM k30_ti_protocols WHERE COALESCE(prot_no,'') = '' ORDER BY id") as $p) ti_protocol_get((int)$p['id']);   // nadaje i zapisuje numer
        },
    ],

    '2026-10_ti_terms_audience_defaults' => [
        'desc' => 'Regulaminy TI: vLAB i oświadczenie o poufności licencji — odbiorcy „kursanci szkoleń + uczestnicy SzK” (beneficjenci konsultacji z nich nie korzystają)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_terms.php';
            ti_terms_migrate();
            db()->exec("UPDATE k30_ti_terms SET audience='kursanci,szk' WHERE type IN ('vlab','licencje') AND COALESCE(audience,'') = ''");
        },
    ],

    '2026-10_it_portal_first_login_article' => [
        'desc' => 'Portal Usług IT: instrukcja „Pierwsze logowanie i zgody” (kreator krok po kroku, wariant bez konta M365)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/it_portal/logic/itPortal.php';
            itPortalMigrate();
            $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
            if (!$cat || db_one("SELECT 1 FROM itp_articles WHERE category_id = ? AND slug = 'pierwsze-logowanie'", [$cat['id']])) return;
            $app = rtrim(APP_URL, '/');
            $blocks = [
                ['type' => 'text', 'html' => '<p>Przy pierwszym logowaniu panel przeprowadzi Cię przez <strong>zgody i krótki kreator</strong>. Zajmuje to kilka minut. Poniżej wszystko po kolei — łącznie z tym, co zrobić, gdy <strong>nie masz jeszcze konta służbowego Microsoft 365</strong>.</p>'],
                ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Nie masz jeszcze konta służbowego?</strong> To normalne — konto zakłada dział IT i może powstać po Twoim pierwszym wejściu do panelu. Wejdź zwykłym logowaniem, przejdź zgody, a w kroku „Microsoft 365” kliknij <em>Dalej</em>. Konto połączysz później.</p>'],
                ['type' => 'wizard', 'title' => 'Pierwsze logowanie krok po kroku', 'intro' => '<p>Kolejność jest zawsze taka sama.</p>', 'steps' => [
                    ['title' => 'Zaloguj się', 'html' => '<p>Otwórz <a href="' . $app . '/auth/login.php">stronę logowania</a>. Jeśli masz już konto służbowe — wybierz <strong>Zaloguj przez Microsoft</strong>. Jeśli nie — użyj danych z zaproszenia (adres e-mail i hasło lub kod SMS).</p>', 'images' => []],
                    ['title' => 'Zaakceptuj regulamin panelu', 'html' => '<p>Zobaczysz ekran <em>Witaj w panelu</em>. Przeczytaj pouczenie i regulamin, zaznacz <strong>zgodę na dokumentową formę umów i korzystanie z panelu</strong>, a następnie kliknij „Akceptuję — przejdź do panelu”. Bez tej zgody dalsze kroki nie są dostępne.</p><p>Opcjonalnie podaj alternatywny e-mail i telefon do powiadomień.</p>', 'images' => []],
                    ['title' => 'Przejdź kreator pierwszych kroków', 'html' => '<p>Kreator pokazuje tylko kroki, które dotyczą Ciebie: <strong>Telefon</strong> (potwierdzenie kodem SMS), <strong>Microsoft 365 i Outlook</strong>, <strong>Profil w katalogu</strong> oraz <strong>Stopka e-mail</strong>. Każdy krok możesz odłożyć przyciskiem „Później”.</p>', 'images' => []],
                    ['title' => 'Nie masz konta Microsoft 365? Przejdź dalej', 'html' => '<p>W kroku „Microsoft 365 i Outlook” zobaczysz informację, że konta służbowego jeszcze nie ma. Kliknij <strong>Dalej</strong>. Gdy dział IT założy konto, dostaniesz wiadomość z adresem służbowym.</p><p>Jeśli czekasz dłużej niż kilka dni, <a href="' . $app . '/helpdesk/new.php">zgłoś to w Helpdesku</a>.</p>', 'images' => []],
                    ['title' => 'Złóż oświadczenie o ochronie danych (RODO)', 'html' => '<p>Na końcu panel poprosi o <strong>oświadczenie o ochronie danych osobowych</strong>. Przeczytaj treść, zaznacz potwierdzenie i złóż elektroniczny podpis. Zapisujemy datę i adres IP.</p>', 'images' => []],
                    ['title' => 'Gdy dostaniesz konto służbowe — połącz je', 'html' => '<p>Zaloguj się przez Microsoft albo wróć do kreatora (krok „Microsoft 365”) i kliknij <strong>Połącz z kontem Microsoft</strong>. Od tej chwili logujesz się jednym kliknięciem. Zgód nie składasz ponownie.</p>', 'images' => []],
                ]],
                ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Administracja i koordynatorzy z adresem @feer.org.pl logują się docelowo <strong>wyłącznie przez Microsoft 365</strong>. Gdyby logowanie Microsoft zawiodło, na stronie logowania jest link do hasła awaryjnego.</p>'],
            ];
            itPortalArticleSave(['title' => 'Pierwsze logowanie i zgody', 'slug' => 'pierwsze-logowanie', 'category_id' => $cat['id'],
                'keywords' => 'pierwsze logowanie, zgody, regulamin, RODO, kreator, brak konta, microsoft, konto służbowe',
                'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1]);
        },
    ],

    '2026-10_it_portal_seed' => [
        'desc' => 'Portal Usług IT: grupy „Dostępność usługi”, startowe kafle usług i wstęp strony Kontakt (tylko gdy portal jest pusty)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/it_portal/logic/itPortal.php';
            itPortalMigrate();
            if (!db_one("SELECT 1 FROM itp_audiences LIMIT 1")) {
                foreach ([['Pracownik', 'user'], ['Wolontariusz', 'volunteer'], ['Kursant', 'student'], ['IT', 'it']] as $i => [$name, $icon]) {
                    itPortalAudienceSave(['name' => $name, 'icon' => $icon, 'sort_order' => $i + 1]);
                }
            }
            if (db_one("SELECT 1 FROM itp_categories LIMIT 1")) return;
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $all = array_values($aud);
            $staff = array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0]));
            $app = rtrim(APP_URL, '/');
            $seed = [
                ['Mój login', 'moj-login', 'user', '', $all],
                ['Moja poczta', 'moja-poczta', 'mail', '<p>Pocztę służbową odczytasz w przeglądarce: <a href="https://poczta.feer.org.pl">poczta.feer.org.pl</a>.</p>', $staff],
                ['Helpdesk', 'helpdesk', 'help', '', $all, $app . '/helpdesk/new.php'],
                ['Microsoft 365', 'microsoft-365', 'cloud', '', $all],
                ['EZD', 'ezd', 'folder', '', $staff],
                ['VPN', 'vpn', 'lock', '', $staff],
                ['Bezpieczeństwo', 'bezpieczenstwo', 'shield', '', $all],
            ];
            foreach ($seed as $i => $c) {
                itPortalCategorySave(['name' => $c[0], 'slug' => $c[1], 'icon' => $c[2], 'intro_html' => $c[3], 'audiences' => $c[4],
                                      'external_url' => $c[5] ?? '', 'sort_order' => ($i + 1) * 10, 'is_active' => 1]);
            }
            if (org_setting('itp_contact_intro') === '') {
                itPortalSettingSet('contact_intro', '<h2>Helpdesk</h2><p><a href="' . $app . '/helpdesk/new.php">Zgłoś problem w systemie obsługi pomocy</a></p>');
            }
        },
    ],

    // Słownik Centralny: kategoria „Dotacje i granty” + instytucje udzielające dotacji (urzędy marszałkowskie, NIW-CRSO,
    // ministerstwa, PFRON…) — podpowiedzi pola „Organ zlecający” w umowie powierzenia. Import po kodach: nie nadpisze
    // wpisów zmienionych później ręcznie, bo wykonuje się raz.
    '2026-10_dictionary_instytucje_dotujace' => [
        'desc' => 'Słownik Centralny: zasiej instytucje udzielające dotacji',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import((string)file_get_contents($ROOT . '/modules/dictionary/lib/seeds/instytucjeDotujace.json'), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Zmiana danych współpracownika (modules/collaborator_data): pola formularza (etykiety, grupa, czy zmiana wymaga
    // akceptacji) i statusy zmian jako słowniki systemowe Słownika Centralnego. Kody używane w CollaboratorDataService.
    '2026-10_collab_data_dictionary' => [
        'desc' => 'Słownik Centralny: pola i statusy modułu Zmiana danych współpracownika',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/collaborator_data/lib/CollaboratorDataService.php';
            $res = dictTransfer()->import(CollaboratorDataService::dictionarySeed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // CRM: powody zamknięcia sprawy (panel „Zmień status”, szybkie zamknięcie, historia, eksport) jako słownik
    // systemowy Słownika Centralnego. Kody dotychczasowe (zalatwiona, duplikat… — używane w kodzie i w zapisanych sprawach).
    '2026-10_dictionary_crm_close_reasons' => [
        'desc' => 'Słownik Centralny: powody zamknięcia sprawy CRM',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/crm_case_extras.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(crm_case_close_reasons_seed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // CRM: statusy sprawy (etykiety, kolory, ikony, kolejność, stan aktywna/zamknieta) jako słownik systemowy.
    '2026-10_dictionary_crm_case_statuses' => [
        'desc' => 'Słownik Centralny: statusy sprawy CRM',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/crm.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(crm_case_statuses_seed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Helpdesk: kategorie zgłoszeń jako słownik systemowy; atrybut prefix = przedrostek numeru zgłoszenia per kategoria.
    '2026-10_dictionary_helpdesk_categories' => [
        'desc' => 'Słownik Centralny: kategorie Helpdesku i prefiksy numeracji',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/helpdesk.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(hd_categories_dict_seed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Helpdesk: podkategorie (drzewo) w słowniku kategorii — numer wg wzorca {PREFIX}-{YYYY}-{NNNNN}, prefiks dziedziczony.
    '2026-10_dictionary_helpdesk_categories_tree' => [
        'desc' => 'Słownik Centralny: podkategorie Helpdesku (drzewo)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/helpdesk.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(hd_categories_dict_seed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Helpdesk: kategoria „Wnioski AM (licencje)” i własne statusy (podstatusy) zależne od kategorii.
    '2026-10_dictionary_helpdesk_substatuses' => [
        'desc' => 'Słownik Centralny: kategoria wniosków AM i podstatusy Helpdesku',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/helpdesk.php';
            require_once $ROOT . '/modules/helpdeskStatuses/lib/helpdeskStatuses.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            foreach ([hd_categories_dict_seed(), hdSubstatusSeed()] as $seed) {
                $res = dictTransfer()->import($seed, 'json');
                foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
            }
        },
    ],

    // Helpdesk: cele SLA wg priorytetu jako słownik systemowy (edytowalne w Słowniku Centralnym).
    '2026-10_dictionary_helpdesk_sla' => [
        'desc' => 'Słownik Centralny: cele SLA Helpdesku wg priorytetu',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/helpdesk.php';
            require_once $ROOT . '/modules/helpdeskExtras/lib/helpdeskExtras.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(hdSlaSeed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // TERYT: województwa → powiaty → gminy jako słownik systemowy (drzewo, kody TERYT) z tabeli teryt_units
    // (import GUS); bez niej — 16 województw. Kolejne importy TERC (admin/teryt_import.php) aktualizują słownik.
    '2026-10_dictionary_teryt' => [
        'desc' => 'Słownik Centralny: TERYT — województwa, powiaty, gminy',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/teryt.php';
            $r = terytSyncDictionary();
            if ($r['errors']) throw new \RuntimeException('Słownik teryt: ' . implode('; ', array_slice($r['errors'], 0, 5)));
        },
    ],

    // Konsultacje TI: słowniki formularza zgłoszenia (forma kontaktu, kogo dotyczy, wzrok, tematy, systemy, forma)
    // z org_setting ti_consult_dicts do Słownika Centralnego (kategoria ti_konsultacje) — z dotychczasowymi zmianami.
    '2026-10_dictionary_ti_konsultacje' => [
        'desc' => 'Słownik Centralny: słowniki karty konsultacji TI',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/ti_consultations/logic/consultations.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(TiConsultations::dictionarySeed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Strategia: formy realizacji celów z § 3 ust. 1 statutu — słownik punktów statutu w Strategii + Słownik Centralny.
    '2026-10_strategy_statute_p3' => [
        'desc' => 'Strategia + Słownik Centralny: § 3 ust. 1 statutu (formy realizacji celów)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/strategy_org.php';
            $r = strat_statute_seed();
            if (!empty($r['dict']['errors'])) throw new \RuntimeException('Słownik statutu: ' . implode('; ', $r['dict']['errors']));
        },
    ],

    // Noclegi (modules/noclegi): słowniki Centralnego Słownika (statusy, role modułu, typy pokoi/obiektów, preferencje, kategorie kosztów,
    // statusy faktur) oraz kolumna umowy „Dop. noclegi” (bramka dostępu do modułu).
    '2026-10_noclegi_dict' => [
        'desc' => 'Noclegi: słowniki modułu i kolumna umowy dop_noclegi',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/noclegi/logic/noclegi.php';
            noclContractFlagMigrate();
            $err = noclDictSeedRun();
            if ($err) throw new \RuntimeException('Słowniki Noclegów: ' . implode('; ', $err));
        },
    ],

    // Przelewy do US (modules/us_przelewy): rodzaje zobowiązań podatkowych (symbol /SFP/, okres, ustawowy termin) jako
    // słownik systemowy Słownika Centralnego oraz rachunek US podany przez użytkownika 2026-10-20 — mikrorachunek NBP
    // dla NIP organizacji. Rachunek zapisywany tylko, gdy w ustawieniach jeszcze go nie ma; dalej edytowalny w module.
    '2026-10_us_przelewy' => [
        'desc' => 'Przelewy do US: słownik formularzy i rachunek urzędu skarbowego',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/us_przelewy/logic/usPrzelewy.php';
            $res = dictTransfer()->import(uspFormularzeSeed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
            $nrb = '57101000712222734357053900';
            if (uspRachunek() === '' && edok_nrb_valid($nrb)) org_setting_set('usp_rachunek', $nrb);
        },
    ],

    // Klasyfikacja zawodów i specjalności 2025 (Dz. U. poz. 1534, stan na 27 XI 2025) jako słownik systemowy `kzis`:
    // grupa wielka → duża → średnia → elementarna → zawód (6 cyfr), atrybuty poziom i szkolny (znacznik „s”).
    // Seed wyciągnięty z PDF MRPiPS (psz.praca.gov.pl); używa go pole „Kod zawodu” kreatora oświadczeń zleceniobiorcy.
    '2026-10_dictionary_kzis' => [
        'desc' => 'Słownik Centralny: klasyfikacja zawodów i specjalności 2025 (kody zawodów)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import((string)file_get_contents($ROOT . '/modules/dictionary/lib/seeds/kzis2025.json'), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', array_slice($r['errors'], 0, 5)));
        },
    ],

    // Umowy: statusy wszystkich typów (etykiety, kolory, ikony, grupy, typy, przejścia) jako słownik systemowy.
    '2026-10_dictionary_umowy_statusy' => [
        'desc' => 'Słownik Centralny: statusy umów',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/contract_status/lib/contractStatuses.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $res = dictTransfer()->import(contractStatusesSeed(), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Umowy: ujednolicenie zapisanych statusów do wartości ze słownika umowy_statusy — warianty bez polskich znaków,
    // inna wielkość liter/spacje, statusy spoza zestawu (wypowiedziana, oczekujaca, aktywna…), „obowiązująca” poza
    // umową o pracę. Nieznanych wartości nie zmienia (tylko error_log). Każda zmiana w historii umowy (contract_audit_log).
    '2026-10_umowy_statusy_dane' => [
        'desc' => 'Umowy: stare/niespójne wartości statusów → statusy ze słownika umowy_statusy',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/contract_status/lib/contractStatuses.php';
            require_once $ROOT . '/includes/approval.php';
            $alias = [
                'zakonczona' => 'zakończona', 'rozwiazana' => 'rozwiązana', 'wygasla' => 'wygasła', 'obowiazujaca' => 'obowiązująca',
                'aktywna' => 'w realizacji', 'aktywne' => 'w realizacji', 'w_realizacji' => 'w realizacji', 'w trakcie' => 'w realizacji', 'w_trakcie' => 'w realizacji',
                'wypowiedziana' => 'rozwiązana', 'oczekujaca' => 'do podpisu', 'oczekująca' => 'do podpisu',
                'do_podpisu' => 'do podpisu', 'do_rozliczenia' => 'do rozliczenia', 'w_podpisie_w_instytucji' => 'w podpisie w instytucji',
            ];
            $known = array_keys(CONTRACT_STATUS_DEFAULTS);
            $tables = ['zlecenie', 'dzielo', 'uslugi', 'wolontariat', 'inne', 'praca', 'powierzenie', 'szk'];
            foreach ($tables as $type) {
                $table = 'umowy_' . $type;
                try { $rows = db_all("SELECT id, status FROM {$table}"); } catch (\Throwable $e) { continue; } // brak tabeli
                foreach ($rows as $r) {
                    $old = (string)$r['status'];
                    $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $old)));
                    if ($v === '') continue;
                    $new = in_array($v, $known, true) ? $v : ($alias[$v] ?? null);
                    if ($new === 'obowiązująca' && $type !== 'praca') $new = 'w realizacji';
                    if ($new === null) { error_log("[umowy_statusy] {$table}#{$r['id']}: nieznany status „{$old}” — bez zmian"); continue; }
                    if ($new === $old) continue;
                    db()->prepare("UPDATE {$table} SET status = ? WHERE id = ?")->execute([$new, (int)$r['id']]);
                    log_contract_action($type, (int)$r['id'], 0, 'status_change', "Ujednolicenie statusu: {$old} → {$new}");
                }
            }
        },
    ],

    // Umowy: status systemowy „okres do rozliczenia” (zlecenia dłuższe niż miesiąc z niezamkniętym miesiącem w saldzie).
    // Dokłada tylko ten wpis — pozostałych wpisów słownika (i zmian administratora) nie rusza.
    '2026-10_dictionary_umowy_statusy_okres' => [
        'desc' => 'Słownik Centralny: status umowy „okres do rozliczenia”',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/contract_status/lib/contractStatuses.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $seed = json_decode(contractStatusesSeed(), true);
            $seed['dictionaries'][0]['entries'] = array_values(array_filter($seed['dictionaries'][0]['entries'], fn($e) => $e['code'] === 'okres_do_rozliczenia'));
            $res = dictTransfer()->import(json_encode($seed, JSON_UNESCAPED_UNICODE), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
        },
    ],

    // Umowy zlecenie: status „Aktywna — z rozliczaniem miesięcznym” (wartość `rozliczanie miesięczne`, grupa active, tylko zlecenie).
    // Dokłada wpis i DOPISUJE go do przejść `next` wpisów podpisana / w_realizacji / zawieszona (inne atrybuty tych wpisów bez zmian).
    '2026-11_dictionary_umowy_statusy_rozliczanie_miesieczne' => [
        'desc' => 'Słownik Centralny: status zlecenia „Aktywna — z rozliczaniem miesięcznym” + przejścia',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/contract_status/lib/contractStatuses.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $seed = json_decode(contractStatusesSeed(), true);
            $seed['dictionaries'][0]['entries'] = array_values(array_filter($seed['dictionaries'][0]['entries'], fn($e) => $e['code'] === 'rozliczanie_miesieczne'));
            $res = dictTransfer()->import(json_encode($seed, JSON_UNESCAPED_UNICODE), 'json');
            foreach ($res as $r) if ($r['errors']) throw new \RuntimeException('Słownik ' . $r['dictionary'] . ': ' . implode('; ', $r['errors']));
            $repo = dictRepository();
            $dict = $repo->findDictionaryByCode(CONTRACT_STATUS_DICT);
            if (!$dict) return;
            foreach (['podpisana', 'w_realizacji', 'zawieszona'] as $code) {
                $e = $repo->findEntryByCode((int)$dict->id, $code);
                if (!$e) continue;
                $attr = (array)$e->attributes;
                $next = is_array($attr['next'] ?? null) ? $attr['next'] : (is_string($attr['next'] ?? null) && $attr['next'] !== '' ? array_map('trim', explode(',', $attr['next'])) : []);
                if (in_array('rozliczanie miesięczne', $next, true)) continue;
                $next[] = 'rozliczanie miesięczne';
                $attr['next'] = array_values($next);
                $repo->updateEntry((int)$e->id, ['attributes' => json_encode($attr, JSON_UNESCAPED_UNICODE)]);
            }
        },
    ],

    // Umowy: status o wartości `w realizacji` („W trakcie”) nazywa się „Aktywna” — zmiana TYLKO etykiety wpisu słownika;
    // wartość w danych i w regułach zostaje. Nie rusza etykiety zmienionej już ręcznie przez administratora na coś innego niż „W realizacji”.
    '2026-11_dictionary_umowy_statusy_w_realizacji_aktywna' => [
        'desc' => 'Słownik Centralny: etykieta statusu umowy „W realizacji” → „Aktywna”',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/contract_status/lib/contractStatuses.php';
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            $repo = dictRepository();
            $dict = $repo->findDictionaryByCode(CONTRACT_STATUS_DICT);
            if (!$dict) return;
            $e = $repo->findEntryByCode((int)$dict->id, 'w_realizacji');
            if (!$e || !in_array(trim($e->label), ['W realizacji', 'W trakcie'], true)) return;
            $repo->updateEntry((int)$e->id, ['label' => 'Aktywna', 'updated_at' => date('Y-m-d H:i:s')]);
            $labels = [];
            foreach ($repo->labelsFor([(int)$e->id])[(int)$e->id] ?? [] as $loc => $lab) $labels[$loc] = in_array(trim($lab), ['W realizacji', 'W trakcie'], true) ? 'Aktywna' : $lab;
            if ($labels) $repo->replaceLabels((int)$e->id, $labels);
        },
    ],

    // EODoK: kategorie operacji bankowych (ZUS, Urząd Skarbowy, wynagrodzenia, opłaty banku, faktury FSP) dla
    // operacji zapisanych przed 2026-11-02, które nie mają jeszcze dokumentu ani oznaczenia.
    '2026-11_edok_bank_kategorie_backfill' => [
        'desc' => 'EODoK: rozpoznanie kategorii zapisanych operacji bankowych bez dokumentu',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/edok_bank.php';
            foreach (db_all("SELECT id FROM edok_bank_tx WHERE doc_id IS NULL AND ignored=0 AND COALESCE(transfer_id,0)=0 AND COALESCE(kategoria,'')=''") as $r) {
                try { edok_bank_apply_kategoria((int)$r['id']); } catch (\Throwable $e) { error_log('[edok bank backfill] ' . $e->getMessage()); }
            }
        },
    ],

    // EODoK: dokumenty „Inny” założone z wyciągu PRZED rozpoznawaniem kategorii (2026-11-02), których operacja to
    // opłata/odsetki banku → przerobione na operacje banku (typ, kontrahent PKO), żeby łapał je przycisk
    // „Zatwierdź operacje bankowe”. Tylko dokumenty w obiegu bez żadnej decyzji na etapach.
    '2026-11_edok_bank_docs_backfill' => [
        'desc' => 'EODoK: dokumenty z wyciągu rozpoznane jako operacje banku → typ „Opłata bankowa” / „Odsetki bankowe”',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/edok_bank.php';
            $rows = db_all("SELECT b.id AS tx_id, b.doc_id FROM edok_bank_tx b JOIN edok_documents d ON d.id = b.doc_id
                            WHERE d.status = 'w_obiegu' AND d.typ_dokumentu IN ('inny','wyciag_bankowy','inny_przychod')
                              AND NOT EXISTS (SELECT 1 FROM edok_steps s WHERE s.doc_id = d.id AND s.status IN ('ok','uwagi','odrzucono'))");
            foreach ($rows as $r) {
                try {
                    $tx = db_one("SELECT * FROM edok_bank_tx WHERE id=?", [(int)$r['tx_id']]);
                    $kat = $tx ? edok_bank_kategoria($tx) : null;
                    if (!$kat) continue;
                    db_exec("UPDATE edok_bank_tx SET kategoria=? WHERE id=?", [$kat['kod'], (int)$r['tx_id']]);
                    if (!in_array($kat['kod'], ['oplata_bankowa', 'odsetki_bankowe'], true)) continue;
                    $doc = db_one("SELECT * FROM edok_documents WHERE id=?", [(int)$r['doc_id']]);
                    if ($doc) edok_doc_make_bankowy($doc, 0);
                } catch (\Throwable $e) { error_log('[edok bank docs backfill] ' . $e->getMessage()); }
            }
        },
    ],

    // EODoK: faktury z KSeF — data doręczenia (data_wplywu) = data nadania numeru KSeF
    // (drugi człon numeru). Uzupełnia tylko dokumenty zakupowe bez daty wpływu.
    '2026-11_edok_ksef_data_doreczenia' => [
        'desc' => 'EODoK: data wpływu faktur z KSeF = data nadania numeru KSeF (uzupełnienie wstecz)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/edok.php';
            $rows = db_all("SELECT id, ksef_reference FROM edok_documents WHERE COALESCE(ksef_reference,'')<>'' AND COALESCE(data_wplywu,'')='' AND COALESCE(kierunek,'wydatek')='wydatek'");
            foreach ($rows as $r) {
                $d = edok_ksef_data_doreczenia($r['ksef_reference']);
                if ($d) db_exec("UPDATE edok_documents SET data_wplywu=? WHERE id=?", [$d, (int)$r['id']]);
            }
        },
    ],


    // Claude: oświadczenie o opłacie — z „25 zł ryczałtem” na 8 USD miesięcznie po kursie PKO BP (abonament, modules/claude_billing).
    // Nowa treść = ti_claude_oplata_body() (includes/ti_terms.php, ta sama co seed); wersja +1, więc osoby, które przyjęły wersję z 25 zł, zaakceptują nową.
    // Własne (zmienione w panelu) szablony e-mail/SMS ti_claude_statement z kwotą 25 zł — usuwane, wraca treść z kodu.
    '2026-11_claude_oplata_8usd' => [
        'desc' => 'Claude: oświadczenie o opłacie — 8 USD miesięcznie zamiast 25 zł (nowa wersja do akceptacji)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_terms.php';
            ti_terms_migrate();
            $t = db_one("SELECT id, body_html FROM k30_ti_terms WHERE type='claude_oplata'");
            if ($t && (str_contains((string)$t['body_html'], '25 zł') || str_contains((string)$t['body_html'], 'zryczałtowan'))) {
                $body_html = ti_claude_oplata_body();
                if (!str_contains($body_html, '8 USD')) throw new RuntimeException('Nowa treść oświadczenia nie zawiera kwoty 8 USD');
                db_exec("UPDATE k30_ti_terms SET title='Oświadczenie o opłacie za dostęp do Claude', body_html=?, version=version+1, updated_at=datetime('now') WHERE id=?", [$body_html, (int)$t['id']]);
            }
            foreach (['email_templates' => 'body_html', 'sms_templates' => 'message'] as $tbl => $col) {
                try { db_exec("DELETE FROM $tbl WHERE key_='ti_claude_statement' AND ($col LIKE '%25 z%' OR $col LIKE '%ryczałt%' OR $col LIKE '%ryczalt%')"); } catch (\Throwable $e) {}
            }
        },
    ],

    // Claude: do oświadczenia (wersja „8 USD”) dopisane 23% VAT od importu usługi i 1,23 zł prowizji — wersja +1 tylko, gdy w bazie jest
    // jeszcze treść bez VAT (gdy wyżej migracja 8 USD ruszyła razem z tą, treść jest już pełna i nic się nie dzieje).
    '2026-11_claude_oplata_vat_prowizja' => [
        'desc' => 'Claude: oświadczenie o opłacie — dopisany 23% VAT i 1,23 zł prowizji (nowa wersja do akceptacji)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_terms.php';
            ti_terms_migrate();
            $t = db_one("SELECT id, body_html FROM k30_ti_terms WHERE type='claude_oplata'");
            if (!$t || !str_contains((string)$t['body_html'], '8 USD') || str_contains((string)$t['body_html'], '23% VAT')) return;
            $body = ti_claude_oplata_body();
            if (!str_contains($body, '23% VAT')) throw new RuntimeException('Treść oświadczenia bez 23% VAT');
            db_exec("UPDATE k30_ti_terms SET body_html=?, version=version+1, updated_at=datetime('now') WHERE id=?", [$body, (int)$t['id']]);
        },
    ],


    // Claude / NordVPN: dokument (proforma) zawsze 1. dnia miesiąca — treść oświadczenia (wersja +1, gdy w bazie jest jeszcze „8. dnia”)
    // i dzień graniczny w ustawieniach produktów z 8 na 1 (gdy ktoś zapisał 8 — domyślnie już 1).
    // Grupa CRM „TI - płatne licencje i oprogramowanie” — jednorazowo dopisuje obecnych użytkowników płatnych licencji i oprogramowania
    // (dalej dodaje ich moduł sam: wniosek, włączenie, CRON). Kontakty bez automatyzacji CRM i bez e-maili.
    '2026-11_crm_grupa_platne_licencje' => [
        'desc' => 'CRM: grupa „TI - płatne licencje i oprogramowanie” z obecnymi użytkownikami płatnych licencji i oprogramowania',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/claude_billing/logic/LicenceCrm.php';
            LicenceCrm::syncAll();
        },
    ],
    '2026-11_claude_oplata_1_dnia' => [
        'desc' => 'Płatne licencje: dokument zawsze 1. dnia miesiąca (oświadczenie Claude — nowa wersja do akceptacji; dzień graniczny 1)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_terms.php';
            ti_terms_migrate();
            $t = db_one("SELECT id, body_html FROM k30_ti_terms WHERE type='claude_oplata'");
            if ($t && str_contains((string)$t['body_html'], '8. dnia')) {
                $body = ti_claude_oplata_body();
                if (!str_contains($body, '1. dnia')) throw new RuntimeException('Treść oświadczenia bez „1. dnia”');
                db_exec("UPDATE k30_ti_terms SET body_html=?, version=version+1, updated_at=datetime('now') WHERE id=?", [$body, (int)$t['id']]);
            }
            foreach (['claude_bill_due_day', 'nordvpn_bill_due_day'] as $k) db_exec("UPDATE settings SET value='1' WHERE key_=? AND value='8'", [$k]);
        },
    ],

    // Migrator poczty M365 → dhosting: data przełączenia (zmiana MX) podana przez użytkownika 2026-11-13 — 14.11.2026.
    // Tylko gdy administrator nie ustawił jeszcze własnej daty (ustawienie na /migracjaadmin).
    '2026-11_dhosting_switch_date' => [
        'desc' => 'Migracja poczty: data przełączenia na dhosting 2026-11-14 (gdy nieustawiona)',
        'run'  => static function (): void {
            if (org_setting('dhosting_switch_date') === '') org_setting_set('dhosting_switch_date', '2026-11-14');
        },
    ],

    // Logowanie jednokrotne: polecenie 2026-11-13 — każdy nowo zalogowany ma być poproszony o konto w chmurze (Nextcloud)
    // i w Logto, więc samodzielne założenie konta zakłada też konto w chmurze. Tylko gdy administrator nic nie wybrał
    // (zapis formularza /ssoadmin wpisuje '0' albo '1', więc świadome wyłączenie zostaje).
    '2026-11_sso_cloud_self_default' => [
        'desc' => 'Logowanie jednokrotne: przy samodzielnym zakładaniu konta także konto w chmurze (gdy nieustawione)',
        'run'  => static function (): void {
            if (org_setting('sso_cloud_self') === '') org_setting_set('sso_cloud_self', '1');
        },
    ],

    // Ekran logowania: „za 7 dni wyłączymy Microsoft” (polecenie 2026-11-13) → 20.11.2026; zmiana na /ssoadmin.
    '2026-11_sso_ms_off_date' => [
        'desc' => 'Ekran logowania: data wyłączenia Microsoft 365 2026-11-20 (gdy nieustawiona)',
        'run'  => static function (): void {
            if (org_setting('sso_ms_off_date') === '') org_setting_set('sso_ms_off_date', '2026-11-20');
        },
    ],

    // Portal Usług IT: poczta przeniesiona z Microsoft 365 na dhosting (13.11.2026) → usługa „Moja poczta” dostaje
    // nowy wstęp i instrukcje webmaila dPoczta.pl (modules/it_portal/lib/itPortalDpocztaSeed.php). Polecenie 2026-11-13:
    // „nadpisz artykuły o których mowa w office365” — artykuły z tym samym adresem są nadpisywane, a dawne artykuły
    // o poczcie w Office 365 / Outlooku ukrywane (is_published = 0, nie kasowane: przywrócisz je w panelu portalu).
    '2026-11_it_portal_dpoczta_articles' => [
        'desc' => 'Portal Usług IT: instrukcje poczty dPoczta.pl w „Moja poczta”, ukrycie dawnych artykułów o poczcie Office 365',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/it_portal/lib/itPortalDpocztaSeed.php';
            require_once $ROOT . '/modules/it_portal/lib/itPortalSzoLinks.php';
            itPortalDpocztaApply(ITP_SZO_URL);   // to samo ręcznie: php cli/it_portal_dpoczta_seed.php [--dry-run]
        },
    ],

    // Portal Usług IT: usługa „Migracja” z artykułem „migracja” — terminy (14.11 poczta, 20.11 wyłączenie Microsoft 365,
    // 1.12 jedno hasło IAM), powody odejścia od Microsoft 365, co zrobić, loginy i hasła, zmiany z 13.11.2026
    // (polecenie 2026-11-13; modules/it_portal/lib/itPortalMigracjaSeed.php). Nadpisuje tylko własną usługę i artykuł.
    '2026-11_it_portal_migracja_article' => [
        'desc' => 'Portal Usług IT: usługa „Migracja” i artykuł o odejściu od Microsoft 365 (terminy, powody, loginy i hasła)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/it_portal/lib/itPortalMigracjaSeed.php';
            require_once $ROOT . '/modules/it_portal/lib/itPortalSzoLinks.php';
            itPortalMigracjaApply(ITP_SZO_URL);   // to samo ręcznie: php cli/it_portal_migracja_seed.php [--dry-run]
        },
    ],

    // Portal Usług IT: linki do stron SZO w artykułach i wstępach usług zawsze na https://szo.feer.org.pl (polecenie 2026-11-13:
    // „podmień w artykułach adres na szo.feer.org.pl”). Seedery budowały je z APP_URL — z CLI/CRON to http://localhost, z innej
    // domeny np. ti.feer.org.pl. Zamienia tylko hosta przed ścieżką strony SZO (modules/it_portal/lib/itPortalSzoLinks.php).
    '2026-11_it_portal_szo_links' => [
        'desc' => 'Portal Usług IT: linki do stron SZO w artykułach na https://szo.feer.org.pl',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/it_portal/lib/itPortalSzoLinks.php';
            itPortalSzoLinksApply();   // to samo ręcznie: php cli/it_portal_szo_links.php [--dry-run]
        },
    ],

];
