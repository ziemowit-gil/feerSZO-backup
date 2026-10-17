<?php
/**
 * modules/ti/logic/nabor/NaborEzdService.php — most: zgłoszenie rekrutacyjne → wewnętrzny moduł EZD.
 *
 * Każde potwierdzone zgłoszenie dostaje własną koszulkę (sprawę) w rocznym segregatorze klasy JRWA
 * z ustawienia ti_nabor_ezd_jrwa (domyślnie 52 „Dokumentacja uczestników — zgłoszenia, zgody”).
 * W koszulce rejestrowane jest pismo PRZYCHODZĄCE (nadawca = opiekun, medium e-mail) z PDF zgłoszenia
 * jako dokumentem głównym. Powiązanie dwustronne: ezd_sprawy.ref_type='ti_nabor', ref_id=<id>,
 * a ti_nabor_submissions.ezd_case_id = id koszulki.
 *
 * Wzorem modules/ti_protokoly_ezd: no-op, gdy EZD wyłączone albo ti_nabor_ezd_auto='0'. Ponowne wywołanie
 * nie zakłada drugiej koszulki (idempotencja po ref).
 */
require_once __DIR__ . '/nabor.php';

final class NaborEzdService
{
    public const REF_TYPE = 'ti_nabor';

    public function active(): bool
    {
        return module_enabled('ezd_enabled') && org_setting('ti_nabor_ezd_auto') !== '0';
    }

    /**
     * Rejestruje zgłoszenie jako sprawę przychodzącą. Zwraca id koszulki (ezd_case_id) albo null, gdy EZD nieaktywne.
     * Rzuca wyjątek przy błędzie — wywołujący (naborAfterConfirm) loguje go w historii zgłoszenia.
     */
    public function registerIncomingCase(int $submissionId): ?int
    {
        if (!$this->active()) return null;
        require_once dirname(__DIR__, 4) . '/includes/ezd.php';
        require_once dirname(__DIR__, 2) . '/lib/naborPdf.php';

        $s = naborSubmissionGet($submissionId);
        if (!$s) throw new RuntimeException('Zgłoszenie nie istnieje.');
        if ($s['ezd_case_id']) return (int)$s['ezd_case_id'];
        if (!empty($s['is_test'])) throw new RuntimeException('Zgłoszenie testowe nie jest rejestrowane w EZD.');
        $uid = $this->systemUserId();

        $existing = ezd_sprawy_by_ref(self::REF_TYPE, $submissionId);
        if ($existing) {
            $sid = (int)$existing[0]['id'];
        } else {
            $sid = ezd_sprawa_create([
                'teczka_id'   => $this->teczkaId($uid),
                'title'       => 'Zgłoszenie rekrutacyjne ' . $s['registration_number'] . ' - ' . $s['participant_name'],
                'description' => $this->description($s),
                'status'      => 'open',
                'priority'    => 'normal',
                'owner_id'    => (int)org_setting('ti_nabor_ezd_owner_id') ?: null,
                'ref_type'    => self::REF_TYPE,
                'ref_id'      => $submissionId,
            ], $uid);
        }

        $pid = ezd_pismo_create([
            'sprawa_id'     => $sid,
            'kierunek'      => 'przychodzace',
            'title'         => 'Zgłoszenie do grupy TI ' . $s['registration_number'],
            'tresc'         => 'Zgłoszenie z formularza internetowego. Numer telefonu opiekuna potwierdzony kodem SMS ('
                             . naborTime($s['phone_verified_at']) . '), adres e-mail potwierdzony linkiem (' . naborTime($s['email_confirmed_at']) . '). '
                             . 'Dokument główny: PDF zgłoszenia ze zgodami RODO, adresem IP i znacznikiem czasu.',
            'nadawca'       => $s['guardian_name'] . ' <' . $s['guardian_email'] . '>',
            'odbiorca'      => '',
            'data_pisma'    => substr((string)$s['created_at'], 0, 10),
            'data_wplywu'   => date('Y-m-d'),
            'status'        => 'nowe',
            'owner_id'      => (int)org_setting('ti_nabor_ezd_owner_id') ?: null,
            'rodzaj_medium' => 'email',
        ], $uid);

        $pdf = naborSubmissionPdf($submissionId);
        $tmp = tempnam(sys_get_temp_dir(), 'tinabor_');
        try {
            file_put_contents($tmp, $pdf);
            ezd_attach_path($tmp, naborSubmissionPdfFilename($s), $sid, $pid, $uid);
        } finally {
            @unlink($tmp);
        }

        db_exec("UPDATE ti_nabor_submissions SET ezd_case_id=?, updated_at=datetime('now') WHERE id=?", [$sid, $submissionId]);
        $znak = ezd_sprawa_get($sid)['znak_sprawy'] ?? ('#' . $sid);
        naborHistoryNote($submissionId, 'Zarejestrowano w EZD: sprawa ' . $znak . ' (PDF zgłoszenia jako dokument główny).');
        return $sid;
    }

    private function description(array $s): string
    {
        return 'Numer zgłoszenia: ' . $s['registration_number'] . "\n"
             . 'Uczestnik: ' . $s['participant_name'] . ($s['participant_school_class'] !== '' ? ' (klasa ' . $s['participant_school_class'] . ')' : '') . "\n"
             . 'Zajęcia: ' . ($s['is_custom_schedule'] ? 'termin do ustalenia' : (string)$s['course_name']) . "\n"
             . 'Wnioskodawca (opiekun): ' . $s['guardian_name'] . ', ' . $s['guardian_email'] . ', +' . $s['guardian_phone'] . "\n"
             . 'Adres: ' . $s['guardian_street'] . ', ' . $s['guardian_postal_code'] . ' ' . $s['guardian_city'];
    }

    /** Użytkownik techniczny zapisów EZD (created_by ma klucz obcy do users): ustawienie albo pierwszy administrator. */
    private function systemUserId(): int
    {
        $id = (int)org_setting('ti_nabor_ezd_owner_id');
        if ($id && db_one("SELECT id FROM users WHERE id=?", [$id])) return $id;
        $a = db_one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
        if (!$a) throw new RuntimeException('Brak konta administratora do rejestracji w EZD.');
        return (int)$a['id'];
    }

    private function jrwaSymbol(): string
    {
        $s = trim(org_setting('ti_nabor_ezd_jrwa'));
        return $s !== '' ? $s : '52';
    }

    /** Segregator roczny klasy JRWA — otwarty na bieżący rok albo nowy (klasa zakładana, gdy jej brak). */
    private function teczkaId(int $uid): int
    {
        $sym = $this->jrwaSymbol();
        $rok = (int)date('Y');
        $t = db_one("SELECT id FROM ezd_teczki WHERE symbol=? AND rok=? AND status='open' ORDER BY id DESC LIMIT 1", [$sym, $rok]);
        if ($t) return (int)$t['id'];
        $j = db_one("SELECT id, title FROM ezd_jrwa WHERE symbol=?", [$sym]);
        if (!$j) {
            db_exec("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)", [
                $sym, 'Dokumentacja uczestników — zgłoszenia, zgody', 'B5',
                'Zgłoszenia do grup TI — zakładane automatycznie z naboru.', 50,
                db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [substr($sym, 0, -1)])['id'] ?? null,
            ]);
            $j = ['id' => (int)db()->lastInsertId(), 'title' => 'Dokumentacja uczestników — zgłoszenia, zgody'];
            ezd_log(null, null, null, null, $uid, 'jrwa_create', 'Dodano hasło JRWA: ' . $sym . ' (automatycznie, nabór TI)');
        }
        return ezd_teczka_create([
            'jrwa_id'  => (int)$j['id'],
            'symbol'   => $sym,
            'title'    => 'Nabór do grup TI — zgłoszenia ' . $rok,
            'rok'      => $rok,
            'owner_id' => null,
        ], $uid);
    }
}
