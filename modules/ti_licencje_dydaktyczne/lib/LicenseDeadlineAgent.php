<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseDeadlineAgent.php — agent CRON pilnujący terminów 7 dni w sprawach AM5 / AM7
 * (wejście: cron/agents/tiLicenseReminders.php, dyspozytor: agent ti_licencje_reminders, codziennie od 7:00).
 *
 *   1. Przydziały „czeka na oświadczenia” / „odrzucone” z terminem due_on dziś lub wcześniej → przypomnienie z nowym linkiem
 *      (LicenseManagementService::invite, typ Reminder — po terminie nadaje nowe 7 dni; to samo co przycisk na pulpicie).
 *   2. Wnioski AM7 wszczęte, a niepodpisane przez kursanta (i bez adnotacji „podpisany odręcznie”) → nowy link do podpisu
 *      (Am7Workflow::sendSignLink, typ Reminder).
 *   3. Wnioski AM5 po terminie decyzji licencjodawcy oraz projekty decyzji AM7 bez zatwierdzenia kierownika → JEDEN zbiorczy
 *      alert do biura (LicenseNotificationService::alertOffice); każda ujęta sprawa dostaje wpis deadline.office_alert w dzienniku.
 * Bezpieczniki:
 *   • każda osoba najwyżej raz na 3 dni: ostatni kontakt z osobą = notification_logs (wszczęcie, przydział, przypomnienie, wynik
 *     weryfikacji — także nieudane próby, żeby nie zasypywać biura alertami o niedoręczeniu) albo license_events (wysłanie linku);
 *     osobę rozpoznaje się po kursancie/współpracowniku z SZO, e-mailu i telefonie, więc kilka spraw jednej osoby = jedno przypomnienie;
 *   • sprawa w alercie dla biura najwyżej raz na 3 dni (projekt AM7 liczy też alert wysłany przy jego przygotowaniu);
 *   • włącznik: org_setting ld_reminders_enabled (pulpit modułu › „Automatyczne przypomnienia”), domyślnie WYŁĄCZONE.
 */
require_once __DIR__ . '/LicenseManagementService.php';

final class LicenseDeadlineAgent
{
    public const SETTING = 'ld_reminders_enabled';
    public const GAP_DAYS = 3;
    public const ACTOR = ['id' => 0, 'name' => 'Agent CRON — przypomnienia o terminie 7 dni'];
    /** Wiadomości do osoby, które liczą się jako kontakt (notification_logs.type). */
    private const PERSON_TYPES = ['reminder', 'request_started', 'license_assigned', 'declaration_decided'];

    private string $since = '';
    /** @var array<string, array{assignment: list<int>, license: list<int>}> klucz osoby → jej sprawy */
    private array $index = [];
    /** @var array<string, true> osoby przypomniane w tym przebiegu */
    private array $touched = [];

    public function __construct(private readonly LicenseManagementService $svc) {}

    public static function enabled(): bool
    {
        return org_setting(self::SETTING) === '1';
    }

    /** Adres karty sprawy w panelu dydaktyka. */
    public static function panelUrl(int $licenseId): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/licencjeDydaktyczne.php?' . http_build_query(['_route' => '/licencje/' . $licenseId]);
    }

    /**
     * @return array{note:string, lines:list<string>, errors:list<string>, sent:array{assignments:int, sign:int, office:int}, skipped:int}
     */
    public function run(bool $dry = false, bool $force = false): array
    {
        $out = ['note' => '', 'lines' => [], 'errors' => [], 'sent' => ['assignments' => 0, 'sign' => 0, 'office' => 0], 'skipped' => 0];
        if (!$force && !self::enabled()) { $out['note'] = 'Przypomnienia wyłączone (pulpit Licencji › „Automatyczne przypomnienia”) — nic nie wysłano.'; return $out; }
        ldMigrate();
        $this->since = date('Y-m-d H:i:s', time() - self::GAP_DAYS * 86400);
        $this->buildIndex();
        $this->remindAssignments($dry, $out);
        $this->remindSignatures($dry, $out);
        $this->alertOffice($dry, $out);
        return $out;
    }

    // ── 1. Oświadczenia po terminie ─────────────────────────────────────────────

    private function remindAssignments(bool $dry, array &$out): void
    {
        $rows = db_all("SELECT a.id, a.license_id, a.holder_kind, a.holder_id, a.holder_name, a.holder_email, a.holder_phone, a.due_on, l.case_number
                        FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                        WHERE a.status IN ('invited', 'rejected') AND a.due_on <> '' AND a.due_on <= ? ORDER BY a.due_on, a.id", [ldToday()]);
        foreach ($rows as $a) {
            $who = $a['holder_name'] . ' (' . $a['case_number'] . ', oświadczenia — termin ' . ldDate($a['due_on']) . ')';
            $keys = self::keys((string)$a['holder_kind'], $a['holder_id'], (string)$a['holder_email'], (string)$a['holder_phone'], 'asg:' . $a['id']);
            if ($why = $this->recent($keys)) { $out['lines'][] = 'pominięto: ' . $who . ' — ' . $why; $out['skipped']++; continue; }
            $this->touch($keys);
            if ($dry) { $out['lines'][] = 'do wysłania: ' . $who; $out['sent']['assignments']++; continue; }
            try {
                $r = $this->svc->invite((int)$a['id'], self::ACTOR, LicenseNotificationType::Reminder);
                $out['lines'][] = 'przypomnienie: ' . $who . ' — ' . $r;
                $out['sent']['assignments']++;
            } catch (\Throwable $e) { $out['errors'][] = 'przydział #' . $a['id'] . ': ' . $e->getMessage(); }
        }
    }

    // ── 2. Wnioski AM7 bez podpisu kursanta ─────────────────────────────────────

    private function remindSignatures(bool $dry, array &$out): void
    {
        $rows = db_all("SELECT * FROM licenses WHERE form_kind = ? AND request_status = ? AND (requester_signed_at IS NULL OR requester_signed_at = '') ORDER BY due_on, id",
                       [LicenseForm::Am7->value, LicenseRequestStatus::Started->value]);
        $wf = new Am7Workflow($this->svc);
        foreach ($rows as $l) {
            $d = json_decode((string)($l['decision_data'] ?? ''), true) ?: [];
            if (!empty($d['paper_signed'])) continue;   // wniosek podpisany odręcznie — portal niepotrzebny
            $who = $l['interested_name'] . ' (' . $l['case_number'] . ', podpis wniosku AM7/1 — termin ' . ldDate($l['due_on']) . ')';
            $c = $this->svc->interestedContact($l);
            $keys = self::keys((string)$l['interested_kind'], $l['interested_id'], $c['email'], $c['phone'], 'lic:' . $l['id']);
            if ($why = $this->recent($keys)) { $out['lines'][] = 'pominięto: ' . $who . ' — ' . $why; $out['skipped']++; continue; }
            $this->touch($keys);
            if ($dry) { $out['lines'][] = 'do wysłania: ' . $who; $out['sent']['sign']++; continue; }
            try {
                $r = $wf->sendSignLink((int)$l['id'], self::ACTOR, LicenseNotificationType::Reminder);
                $out['lines'][] = 'link do podpisu: ' . $who . ' — ' . $r;
                $out['sent']['sign']++;
            } catch (\Throwable $e) { $out['errors'][] = 'sprawa ' . $l['case_number'] . ': ' . $e->getMessage(); }
        }
    }

    // ── 3. Alert dla biura ──────────────────────────────────────────────────────

    private function alertOffice(bool $dry, array &$out): void
    {
        $am5 = []; $am7 = [];
        foreach (db_all("SELECT * FROM licenses WHERE form_kind <> ? AND request_status = ? AND due_on <> '' AND due_on < ? ORDER BY due_on, id",
                        [LicenseForm::Am7->value, LicenseRequestStatus::Started->value, ldToday()]) as $l) {
            if (!$this->alertedRecently((int)$l['id'], ['deadline.office_alert'])) $am5[] = $l;
        }
        foreach (db_all("SELECT * FROM licenses WHERE form_kind = ? AND request_status = ? AND decision_data IS NOT NULL AND decision_data <> '' ORDER BY id",
                        [LicenseForm::Am7->value, LicenseRequestStatus::Started->value]) as $l) {
            $d = json_decode((string)$l['decision_data'], true) ?: [];
            if (empty($d['prepared_at']) || !empty($d['approved_at'])) continue;
            if (!$this->alertedRecently((int)$l['id'], ['deadline.office_alert', 'decision.prepare'])) $am7[] = $l + ['_d' => $d];
        }
        if (!$am5 && !$am7) return;
        foreach ($am5 as $l) $out['lines'][] = ($dry ? 'do alertu: ' : 'alert: ') . $l['case_number'] . ' — AM5 czeka na decyzję licencjodawcy od ' . ldDate($l['due_on']);
        foreach ($am7 as $l) $out['lines'][] = ($dry ? 'do alertu: ' : 'alert: ') . $l['case_number'] . ' — projekt decyzji AM7 bez zatwierdzenia kierownika';
        if ($dry) { $out['sent']['office'] = count($am5) + count($am7); return; }

        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $item = fn(array $l, string $txt) => '<li><a href="' . $e(self::panelUrl((int)$l['id'])) . '"><b>' . $e($l['case_number']) . '</b></a> — ' . $txt . '</li>';
        $body = '';
        if ($am5) {
            $body .= '<p><b>Wnioski AM5 po terminie ' . LD_REALIZATION_DAYS . ' dni — licencjodawca nie podjął decyzji (' . count($am5) . '):</b></p><ul>';
            foreach ($am5 as $l) $body .= $item($l, $e($l['title']) . ', licencjodawca ' . $e($l['licensor_name']) . ' — termin minął ' . $e(ldDate($l['due_on'])));
            $body .= '</ul><p>Skontaktuj się z licencjodawcą, ponów wysyłkę wniosku z karty sprawy albo wpisz decyzję z papieru.</p>';
        }
        if ($am7) {
            $body .= '<p><b>Projekty decyzji AM7 czekające na zatwierdzenie kierownika (' . count($am7) . '):</b></p><ul>';
            foreach ($am7 as $l) $body .= $item($l, (!empty($l['_d']['grant']) ? 'przyznanie' : 'odmowa') . ' Office 365 dla ' . $e($l['interested_name'])
                . ', projekt z ' . $e(ldDate(substr((string)$l['_d']['prepared_at'], 0, 10))) . ' (' . $e($l['_d']['prepared_name'] ?? '') . '), termin ' . $e(ldDate($l['due_on'])));
            $body .= '</ul><p>Decyzję zatwierdza kierownik (rola „kierownik” panelu TI) albo administrator — dopiero wtedy trafia do kursanta.</p>';
        }
        $body .= '<p style="font-size:12px;color:#666">Zestawienie wysyła agent CRON raz dziennie; ta sama sprawa wraca najwyżej co ' . self::GAP_DAYS . ' dni.</p>';
        $ok = $this->svc->notify->alertOffice('Terminy spraw licencyjnych — ' . (count($am5) + count($am7)) . ' do załatwienia', $body,
                                               ['type' => 'digest', 'id' => 0], self::ACTOR, LicenseNotificationType::Reminder);
        if (!$ok) { $out['errors'][] = 'alert dla biura nie wyszedł (brak adresów albo błąd poczty) — ponowi jutro'; return; }
        foreach ($am5 as $l) $this->svc->licenses->event((int)$l['id'], 'deadline.office_alert', 'Agent CRON: alert dla biura — brak decyzji licencjodawcy po terminie ' . ldDate($l['due_on']) . '.', self::ACTOR);
        foreach ($am7 as $l) $this->svc->licenses->event((int)$l['id'], 'deadline.office_alert', 'Agent CRON: alert dla biura — projekt decyzji czeka na zatwierdzenie kierownika.', self::ACTOR);
        $out['sent']['office'] = count($am5) + count($am7);
    }

    private function alertedRecently(int $licenseId, array $actions): bool
    {
        $in = implode(',', array_fill(0, count($actions), '?'));
        return (bool)db_one("SELECT 1 FROM license_events WHERE license_id = ? AND action IN ({$in}) AND created_at >= ? LIMIT 1", [$licenseId, ...$actions, $this->since]);
    }

    // ── Osoby i ostatni kontakt ─────────────────────────────────────────────────

    /** Klucze osoby: rekord SZO (kursant / współpracownik), e-mail, telefon (9 ostatnich cyfr); bez danych — klucz samej sprawy. @return list<string> */
    private static function keys(string $kind, mixed $id, string $email, string $phone, string $fallback): array
    {
        $k = [];
        if (in_array($kind, ['client', 'user'], true) && (int)$id > 0) $k[] = $kind . ':' . (int)$id;
        $m = mb_strtolower(trim($email));
        if ($m !== '') $k[] = 'mail:' . $m;
        $p = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($p) >= 9) $k[] = 'tel:' . substr($p, -9);
        return $k ?: [$fallback];
    }

    /** Mapa: klucz osoby → jej przydziały i sprawy (jako osoba zainteresowana). */
    private function buildIndex(): void
    {
        $this->index = [];
        $add = function (array $keys, string $type, int $id) {
            foreach ($keys as $k) $this->index[$k][$type][] = $id;
        };
        foreach (db_all("SELECT id, holder_kind, holder_id, holder_email, holder_phone FROM license_assignments") as $a)
            $add(self::keys((string)$a['holder_kind'], $a['holder_id'], (string)$a['holder_email'], (string)$a['holder_phone'], 'asg:' . $a['id']), 'assignment', (int)$a['id']);
        foreach (db_all("SELECT id, interested_kind, interested_id, interested_email, interested_phone FROM licenses") as $l)
            $add(self::keys((string)$l['interested_kind'], $l['interested_id'], (string)$l['interested_email'], (string)$l['interested_phone'], 'lic:' . $l['id']), 'license', (int)$l['id']);
    }

    private function touch(array $keys): void
    {
        foreach ($keys as $k) $this->touched[$k] = true;
    }

    /** Powód pominięcia, gdy osoba miała kontakt w ostatnich 3 dniach (albo w tym przebiegu); '' — można przypomnieć. */
    private function recent(array $keys): string
    {
        if (!$keys) return '';
        foreach ($keys as $k) if (isset($this->touched[$k])) return 'przypomniano dziś w innej sprawie';
        $asg = []; $lic = [];
        foreach ($keys as $k) { $asg = array_merge($asg, $this->index[$k]['assignment'] ?? []); $lic = array_merge($lic, $this->index[$k]['license'] ?? []); }
        $asg = array_values(array_unique($asg)); $lic = array_values(array_unique($lic));
        if (!$asg && !$lic) return '';
        $ph = fn(array $ids) => implode(',', array_fill(0, count($ids), '?'));
        $types = $ph(self::PERSON_TYPES);

        $refs = []; $p = [LicenseNotificationService::MODULE, ...self::PERSON_TYPES, $this->since];
        if ($asg) { $refs[] = "(ref_type = 'assignment' AND ref_id IN (" . $ph($asg) . "))"; array_push($p, ...$asg); }
        if ($lic) { $refs[] = "(ref_type = 'license' AND ref_id IN (" . $ph($lic) . "))"; array_push($p, ...$lic); }
        $last = (string)(db_one("SELECT MAX(created_at) AS t FROM notification_logs WHERE module = ? AND type IN ({$types}) AND created_at >= ? AND (" . implode(' OR ', $refs) . ")", $p)['t'] ?? '');

        $ev = []; $p = [$this->since];
        if ($asg) { $ev[] = "(assignment_id IN (" . $ph($asg) . ") AND action IN ('invite.send', 'invite.remind'))"; array_push($p, ...$asg); }
        if ($lic) { $ev[] = "(assignment_id IS NULL AND license_id IN (" . $ph($lic) . ") AND action IN ('request.start', 'request.sign_link'))"; array_push($p, ...$lic); }
        $last = max($last, (string)(db_one("SELECT MAX(created_at) AS t FROM license_events WHERE created_at >= ? AND (" . implode(' OR ', $ev) . ")", $p)['t'] ?? ''));

        return $last !== '' ? 'ostatni kontakt ' . ldDate(substr($last, 0, 10)) . ' ' . substr($last, 11, 5) . ' (najwyżej raz na ' . self::GAP_DAYS . ' dni)' : '';
    }
}
