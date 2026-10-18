<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesEzdService.php — oświadczenia MSUES i paczki audytowe w wewnętrznym EZD.
 *
 * JRWA (zakładane migracją jednorazową 2026-10_msues_jrwa, a awaryjnie przy pierwszej rejestracji):
 *   56  Kadra dydaktyczna — kwalifikacje i jakość usług (MSUES)          B10
 *   561 Oświadczenia o kwalifikacjach i kompetencjach prowadzących      B10   ← koszulki oświadczeń (ustawienie msues_ezd_jrwa)
 *   562 Audyty jakości MSUES — paczki audytowe i dokumentacja usług      B10   ← koszulki paczek (msues_ezd_jrwa_audit)
 *
 * Koszulka (sprawa) = seria oświadczenia (ref_type 'msues_oswiadczenie', ref_id = series_id) w rocznym segregatorze klasy 561;
 * każda wersja dokłada pismo przychodzące (od prowadzącego) z PDF oświadczenia i skanem podpisu. Zatwierdzenie pod audyt
 * rejestruje się samo, gdy msues_ezd_auto <> '0'. Paczka audytowa ZIP usługi trafia do koszulki usługi w klasie 562
 * (ref_type 'msues_audyt', ref_id = id grupy). No-op, gdy moduł EZD wyłączony.
 */
require_once __DIR__ . '/DeclarationRepository.php';
require_once __DIR__ . '/DeclarationDocument.php';

final class MsuesEzdService
{
    public const REF_DECL  = 'msues_oswiadczenie';
    public const REF_AUDIT = 'msues_audyt';
    public const JRWA = [
        '56'  => ['Kadra dydaktyczna — kwalifikacje i jakość usług (MSUES)', 'B10', '5', 'Dokumentacja kadry szkoleniowej pod Małopolskie Standardy Usług Edukacyjno-Szkoleniowych (znak jakości MSUES, WUP Kraków).'],
        '561' => ['Oświadczenia o kwalifikacjach i kompetencjach prowadzących', 'B10', '56', 'Oświadczenia OKK-1 (wersje, skany podpisów, weryfikacja) — koszulka na oświadczenie, zakładana z panelu dydaktyka › Certyfikacja MSUES.'],
        '562' => ['Audyty jakości MSUES — paczki audytowe i dokumentacja usług', 'B10', '56', 'Paczki audytowe ZIP usług (checklista kadry, oświadczenia, CV, dyplomy, umowy) przekazywane do audytu WUP Kraków.'],
    ];

    public static function active(): bool
    {
        return function_exists('module_enabled') && module_enabled('ezd_enabled');
    }

    public static function autoOnApprove(): bool
    {
        return msuesSetting('msues_ezd_auto') !== '0';
    }

    private static function lib(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
    }

    /** Zakłada brakujące hasła JRWA 56/561/562 (idempotentne). Zwraca liczbę dodanych. */
    public static function ensureJrwa(int $uid): int
    {
        self::lib();
        $added = 0;
        foreach (self::JRWA as $sym => [$title, $kat, $parent, $desc]) {
            $sym = (string)$sym;
            if (db_one("SELECT id FROM ezd_jrwa WHERE symbol = ?", [$sym])) continue;
            $pid = db_one("SELECT id FROM ezd_jrwa WHERE symbol = ?", [$parent])['id'] ?? null;
            db_exec("INSERT INTO ezd_jrwa (symbol, title, kat_arch, description, sort_order, parent_id) VALUES (?,?,?,?,?,?)",
                [$sym, $title, $kat, $desc, (int)substr($sym, -1) * 10 + 60, $pid]);
            $added++;
            ezd_log(null, null, null, null, $uid, 'jrwa_create', 'Dodano hasło JRWA: ' . $sym . ' ' . $title . ' (moduł Certyfikacja MSUES)');
        }
        return $added;
    }

    private static function teczkaId(string $settingKey, string $default, string $titlePrefix, int $uid): int
    {
        $sym = trim(msuesSetting($settingKey)) ?: $default;
        $rok = (int)date('Y');
        $t = db_one("SELECT id FROM ezd_teczki WHERE symbol = ? AND rok = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$sym, $rok]);
        if ($t) return (int)$t['id'];
        self::ensureJrwa($uid);
        $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol = ?", [$sym]);
        if (!$j) throw new RuntimeException('Brak hasła JRWA ' . $sym . ' — dodaj je w EZD › JRWA albo popraw ustawienie.');
        return ezd_teczka_create(['jrwa_id' => (int)$j['id'], 'symbol' => $sym, 'title' => $titlePrefix . ' ' . $rok, 'rok' => $rok, 'owner_id' => null], $uid);
    }

    /** created_by w EZD ma klucz obcy do users — aktor albo pierwszy administrator. */
    private static function actorId(array $actor): int
    {
        $id = (int)($actor['id'] ?? 0);
        if ($id && db_one("SELECT id FROM users WHERE id = ?", [$id])) return $id;
        $a = db_one("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        if (!$a) throw new RuntimeException('Brak konta administratora do rejestracji w EZD.');
        return (int)$a['id'];
    }

    /**
     * Rejestruje wersję oświadczenia: koszulka serii (zakładana przy pierwszej rejestracji) + pismo przychodzące z PDF i skanem.
     * Zwraca id koszulki. Rzuca wyjątek przy błędzie.
     */
    public static function registerDeclaration(DeclarationRepository $repo, int $id, array $actor): int
    {
        if (!self::active()) throw new DomainException('Moduł EZD jest wyłączony.');
        self::lib();
        $d = $repo->get($id);
        if (!$d) throw new DomainException('Nie ma takiego oświadczenia.');
        $uid = self::actorId($actor);
        $name = (string)($d['data']['imie_nazwisko'] ?: $d['trainer_name']);
        $course = $d['course_id'] ? MsuesSzoData::course((int)$d['course_id']) : null;
        $sid = (int)($d['ezd_sprawa_id'] ?? 0);
        $sprawa = $sid ? ezd_sprawa_get($sid) : null;
        if (!$sprawa) {
            $ex = ezd_sprawy_by_ref(self::REF_DECL, (int)$d['series_id']);
            $sprawa = $ex ? $ex[0] : null;
            $sid = $sprawa ? (int)$sprawa['id'] : 0;
        }
        if ($sprawa && ($sprawa['status'] ?? '') === 'closed') throw new DomainException('Koszulka EZD ' . ($sprawa['znak_sprawy'] ?? '#' . $sid) . ' jest zamknięta — otwórz ją w EZD.');
        if (!$sprawa) {
            $sid = ezd_sprawa_create([
                'teczka_id'   => self::teczkaId('msues_ezd_jrwa', '561', 'Oświadczenia o kwalifikacjach prowadzących (MSUES)', $uid),
                'title'       => 'Oświadczenie o kwalifikacjach i kompetencjach (MSUES) — ' . $name . ($course ? ' — ' . MsuesSzoData::courseLabel($course) : ' — ogólne'),
                'description' => 'Oświadczenie OKK-1 prowadzącego ' . $name . ($course ? ' dla usługi „' . MsuesSzoData::courseLabel($course) . '”' : ' (wszystkie usługi)')
                               . ($d['project'] !== '' ? ', projekt ' . $d['project'] : '') . '. Seria ' . DeclarationDocument::number($d) . '. Kolejne wersje trafiają do tej koszulki jako pisma.',
                'status'      => 'open',
                'priority'    => 'normal',
                'owner_id'    => (int)($actor['id'] ?? 0) ?: null,
                'ref_type'    => self::REF_DECL,
                'ref_id'      => (int)$d['series_id'],
            ], $uid);
        }
        $pid = ezd_pismo_create([
            'sprawa_id'     => $sid,
            'kierunek'      => 'przychodzace',
            'title'         => 'Oświadczenie o kwalifikacjach i kompetencjach — wersja ' . $d['version'] . ' (' . $d['statusEnum']->label() . ')',
            'tresc'         => 'Oświadczenie OKK-1 ' . DeclarationDocument::number($d) . ' prowadzącego ' . $name . '. Status: ' . $d['statusEnum']->label()
                             . ($d['decided_name'] !== '' ? ', zweryfikował(a): ' . $d['decided_name'] . ' ' . substr((string)$d['decided_at'], 0, 16) : '')
                             . '. Załączniki: wydruk z systemu (PDF)' . ($d['scan_path'] !== '' ? ' i skan podpisanego oświadczenia' : '') . '.',
            'nadawca'       => $name,
            'odbiorca'      => MsuesSzoData::orgName(),
            'data_pisma'    => (string)($d['data']['data'] ?: date('Y-m-d')),
            'data_wplywu'   => substr((string)($d['scan_at'] ?: msuesNow()), 0, 10),
            'status'        => 'zakonczone',
            'owner_id'      => (int)($actor['id'] ?? 0) ?: null,
            'rodzaj_medium' => 'inne',
        ], $uid);
        $tmp = tempnam(sys_get_temp_dir(), 'okk_');
        try {
            file_put_contents($tmp, DeclarationDocument::pdf($d));
            ezd_attach_path($tmp, DeclarationForm::filename($d, 'pdf'), $sid, $pid, $uid);
        } finally { @unlink($tmp); }
        if ($d['scan_path'] !== '' && ($scan = MsuesStorage::fullPath((string)$d['scan_path']))) {
            ezd_attach_path($scan, 'Skan_podpisu_' . DeclarationForm::filename($d, strtolower(pathinfo((string)$d['scan_name'], PATHINFO_EXTENSION)) ?: 'pdf'), $sid, $pid, $uid);
        }
        $znak = (string)(ezd_sprawa_get($sid)['znak_sprawy'] ?? '#' . $sid);
        $repo->setEzd($id, $sid, $pid, $actor, $znak);
        return $sid;
    }

    /** Odkłada paczkę audytową usługi do koszulki usługi (klasa 562). Zwraca id koszulki. */
    public static function archiveAuditPackage(int $serviceId, string $zipPath, string $zipName, array $readiness, array $actor): int
    {
        if (!self::active()) throw new DomainException('Moduł EZD jest wyłączony.');
        self::lib();
        $uid = self::actorId($actor);
        $label = (string)$readiness['service']['name'];
        $ex = ezd_sprawy_by_ref(self::REF_AUDIT, $serviceId);
        $open = array_values(array_filter($ex, fn($s) => ($s['status'] ?? '') !== 'closed'));
        $sid = $open ? (int)$open[0]['id'] : ezd_sprawa_create([
            'teczka_id'   => self::teczkaId('msues_ezd_jrwa_audit', '562', 'Audyty jakości MSUES — paczki audytowe', $uid),
            'title'       => 'Audyt MSUES — dokumentacja usługi ' . $label,
            'description' => 'Paczki audytowe usługi (grupy TI #' . $serviceId . ') generowane w panelu dydaktyka › Certyfikacja MSUES.',
            'status'      => 'open', 'priority' => 'normal', 'owner_id' => (int)($actor['id'] ?? 0) ?: null,
            'ref_type'    => self::REF_AUDIT, 'ref_id' => $serviceId,
        ], $uid);
        $pid = ezd_pismo_create([
            'sprawa_id' => $sid, 'kierunek' => 'wewnetrzne',
            'title'     => 'Paczka audytowa MSUES — ' . $label . ' (' . date('d.m.Y') . ')',
            'tresc'     => 'Gotowość usługi: ' . $readiness['score'] . '% (' . $readiness['done'] . '/' . $readiness['total'] . ' kryteriów), kadra: ' . count($readiness['staff']) . ' os. Paczka ZIP w załączniku.',
            'nadawca'   => MsuesSzoData::orgName(), 'odbiorca' => '', 'data_pisma' => date('Y-m-d'),
            'status'    => 'zakonczone', 'owner_id' => (int)($actor['id'] ?? 0) ?: null, 'rodzaj_medium' => 'inne',
        ], $uid);
        ezd_attach_path($zipPath, $zipName, $sid, $pid, $uid);
        return $sid;
    }

    public static function url(?int $sprawaId): ?string
    {
        return $sprawaId ? rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $sprawaId : null;
    }
}
