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

    // Certyfikacja MSUES (modules/ti_msues): kryteria checklisty kadry jako słownik systemowy Słownika Centralnego
    // (etykiety/opisy/„required” edytowalne, kody używane w MsuesChecklist).
    '2026-10_msues_dictionary' => [
        'desc' => 'Słownik Centralny: kryteria checklisty kadry MSUES',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
            require_once $ROOT . '/modules/ti_msues/lib/MsuesChecklist.php';
            $res = dictTransfer()->import(MsuesChecklist::dictionarySeed(), 'json');
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

    // Certyfikacja MSUES: hasła JRWA 56 (kadra dydaktyczna — MSUES), 561 (oświadczenia o kwalifikacjach, koszulki oświadczeń),
    // 562 (audyty MSUES — paczki audytowe). Tylko gdy EZD ma już tabelę JRWA; brakujące hasła doda też pierwsza rejestracja.
    '2026-10_msues_jrwa' => [
        'desc' => 'EZD: hasła JRWA 56/561/562 dla Certyfikacji MSUES',
        'run'  => static function () use ($ROOT): void {
            try { db()->query("SELECT 1 FROM ezd_jrwa LIMIT 1"); } catch (\Throwable $e) { return; }
            require_once $ROOT . '/modules/ti_msues/lib/MsuesEzdService.php';
            $a = db_one("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
            MsuesEzdService::ensureJrwa((int)($a['id'] ?? 0));
        },
    ],

];
