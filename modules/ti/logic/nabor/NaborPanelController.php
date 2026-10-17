<?php
/**
 * modules/ti/logic/nabor/NaborPanelController.php — Nabór do grup w panelu dydaktyka.
 *
 * Kierownik (middleware dyd.staff): lista / kanban zgłoszeń, masowa zmiana statusu, karta zgłoszenia (status, grupa,
 * notatki, EZD, CRM, PDF), grupy TI otwarte w naborze, przełącznik formularza publicznego.
 * Prowadzący (middleware dyd): instructor() — TYLKO przyjęci uczestnicy własnych grup, dane zawężone (bez e-maila
 * i adresu opiekuna, PESEL, zgód, historii, EZD i CRM).
 */
require_once __DIR__ . '/nabor.php';
require_once __DIR__ . '/naborAccount.php';

final class NaborPanelController
{
    private const VIEW = __DIR__ . '/../../view/nabor/panel/';

    private function me(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    public function index(): string
    {
        naborExpireUnconfirmed();
        $f = ['status' => (string)($_GET['status'] ?? ''), 'course' => (string)($_GET['course'] ?? ''), 'q' => (string)($_GET['q'] ?? '')];
        $counts = [];
        foreach (db_all("SELECT status, COUNT(*) n FROM ti_nabor_submissions GROUP BY status") as $r) $counts[$r['status']] = (int)$r['n'];
        return Router::view(self::VIEW . 'index.php', [
            'me'      => $this->me(),
            'f'       => $f,
            'view'    => ($_GET['view'] ?? '') === 'kanban' ? 'kanban' : 'list',
            'rows'    => naborSubmissionsList($f),
            'courses' => naborCourses(),
            'counts'  => $counts,
        ]);
    }

    public function bulk(): ?string
    {
        $me  = $this->me();
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $to  = (string)($_POST['to_status'] ?? '');
        if (!$ids || !in_array($to, NABOR_MANUAL_STATUSES, true)) {
            flash_set('warning', 'Zaznacz zgłoszenia i wybierz status.');
        } else {
            $r = naborBulkStatus($ids, $to, $me['id'], trim((string)($_POST['comment'] ?? '')), !empty($_POST['notify']), $me['name']);
            flash_set($r['errors'] ? 'warning' : 'success', 'Zmieniono status ' . $r['changed'] . ' zgłoszeń na „' . naborStatusLabel($to) . '”.'
                . ($r['errors'] ? ' Pominięto: ' . implode(' ', $r['errors']) : ''));
        }
        return Router::redirect($this->back());
    }

    /** Przeniesienie karty w kanbanie (JSON). */
    public function move(): array
    {
        $me = $this->me();
        try {
            naborChangeStatus((int)($_POST['id'] ?? 0), (string)($_POST['to_status'] ?? ''), $me['id'], 'Zmiana w kanbanie', !empty($_POST['notify']), $me['name']);
            return ['ok' => true];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Przycisk „Utwórz zgłoszenie testowe”. */
    public function createTest(): ?string
    {
        $me = $this->me();
        $id = naborCreateTest($me['id'], $me['name']);
        flash_set('success', 'Utworzono zgłoszenie testowe (bez SMS/e-maili, EZD i CRM).');
        return Router::redirect(route('nabor.show', ['id' => $id]));
    }

    public function toggleOpen(): ?string
    {
        $open = !naborPublicOpen();
        org_setting_set('ti_nabor_enabled', $open ? '1' : '0');
        flash_set('success', 'Formularz naboru: ' . ($open ? 'otwarty' : 'zamknięty') . '.');
        return Router::redirect($this->back());
    }

    public function show(int $id): string
    {
        $s = $this->find($id);
        $ezdZnak = null;
        if ($s['ezd_case_id']) {
            try { $ezdZnak = db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?", [(int)$s['ezd_case_id']])['znak_sprawy'] ?? null; } catch (\Throwable $e) {}
        }
        $creds = $_SESSION['nabor_creds'][$id] ?? null;           // hasła pokazujemy raz, po przyjęciu
        unset($_SESSION['nabor_creds'][$id]);
        $account = $s['client_id'] ? db_one("SELECT login, parent_login, is_minor FROM k30_ti_student_accounts WHERE client_id=?", [(int)$s['client_id']]) : null;
        naborMessagesMarkRead($id, 'staff');
        return Router::view(self::VIEW . 'zgloszenie.php', [
            'msgs' => naborMessages($id), 'docs' => naborDocuments($id),
            'candAcc' => $s['account_id'] ? naborAccountGet((int)$s['account_id']) : null,
            'creds' => $creds, 'account' => $account,
            'me' => $this->me(), 's' => $s, 'cons' => naborConsents($id) ?: [], 'hist' => naborHistory($id),
            'courses' => naborCourses(), 'ezdZnak' => $ezdZnak,
        ]);
    }

    /** Operacje z karty zgłoszenia (pole _op): status | course | edit | note | ezd | crm. */
    public function update(int $id): ?string
    {
        $this->find($id);
        $me = $this->me();
        try {
            switch ($_POST['_op'] ?? '') {
                case 'status':
                    $ok = naborChangeStatus($id, (string)$_POST['to_status'], $me['id'], trim((string)($_POST['comment'] ?? '')), !empty($_POST['notify']), $me['name']);
                    flash_set($ok ? 'success' : 'info', $ok ? 'Status zmieniony.' : 'Status bez zmian.');
                    break;
                case 'course':
                    naborAssignCourse($id, (int)($_POST['course_id'] ?? 0) ?: null, $me['id'], $me['name']);
                    flash_set('success', 'Przypisanie do grupy zapisane.');
                    break;
                case 'edit':
                    $ch = naborUpdateData($id, $_POST, $me['id'], $me['name']);
                    flash_set($ch ? 'success' : 'info', $ch ? 'Zapisano zmiany: ' . implode(', ', $ch) . '.' : 'Bez zmian.');
                    break;
                case 'message':
                    naborMessageAdd($id, 'staff', (string)($_POST['body'] ?? ''), $me['id'], $me['name']);
                    naborHistoryNote($id, 'Wiadomość do opiekuna wysłana z panelu.', $me['id'], $me['name']);
                    flash_set('success', 'Wiadomość wysłana — opiekun dostał powiadomienie e-mail i SMS.');
                    break;
                case 'doc_request':
                    naborDocRequest($id, (string)($_POST['doc_name'] ?? ''), (string)($_POST['doc_note'] ?? ''), $me['id'], $me['name']);
                    flash_set('success', 'Prośba o dokument wysłana do opiekuna.');
                    break;
                case 'doc_review':
                    naborDocReview($id, (int)($_POST['doc_id'] ?? 0), ($_POST['verdict'] ?? '') === 'ok', (string)($_POST['review_note'] ?? ''), $me['id'], $me['name']);
                    flash_set('success', 'Zapisano ocenę dokumentu.');
                    break;
                case 'account_invite':
                    $s = naborSubmissionGet($id);
                    $accId = naborAccountLinkSubmission($id);
                    $acc = $accId ? naborAccountGet($accId) : null;
                    if ($acc && $acc['password_hash']) naborAccountRequestReset($acc['email']);
                    flash_set($acc ? 'success' : 'warning', $acc ? 'Wysłano opiekunowi link do konta kandydata (' . $acc['email'] . ').' : 'Nie udało się przygotować konta.');
                    break;
                case 'note':
                    if (($n = trim((string)($_POST['note'] ?? ''))) !== '') naborHistoryNote($id, $n, $me['id'], $me['name']);
                    break;
                case 'ezd':
                    require_once __DIR__ . '/NaborEzdService.php';
                    $svc = new NaborEzdService();
                    if (!$svc->active()) throw new InvalidArgumentException('Moduł EZD jest wyłączony albo automatyczna rejestracja jest wyłączona (ti_nabor_ezd_auto).');
                    $svc->registerIncomingCase($id);
                    flash_set('success', 'Zgłoszenie zarejestrowane w EZD.');
                    break;
                case 'crm':
                    require_once __DIR__ . '/naborCrm.php';
                    if (!module_enabled('crm_enabled')) throw new InvalidArgumentException('Moduł CRM jest wyłączony.');
                    flash_set(naborCrmSync($id, 'Ręczna synchronizacja z panelu dydaktyka') ? 'success' : 'warning', 'Synchronizacja z CRM wykonana.');
                    break;
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('nabor.show', ['id' => $id]));
    }

    public function document(int $id, int $doc): ?string
    {
        $this->find($id);
        require_once __DIR__ . '/NaborCandidateController.php';
        return naborSendDocument($id, $doc);
    }

    public function pdf(int $id): ?string
    {
        require_once dirname(__DIR__, 2) . '/lib/naborPdf.php';
        $s = $this->find($id);
        $pdf = naborSubmissionPdf($id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . naborSubmissionPdfFilename($s) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
        return null;
    }

    public function courses(): string
    {
        return Router::view(self::VIEW . 'grupy.php', ['me' => $this->me(), 'courses' => naborCourses(), 'editId' => (int)($_GET['id'] ?? 0)]);
    }

    public function courseSave(int $id): ?string
    {
        try {
            naborCourseSettingsSave($id, $_POST);
            flash_set('success', 'Zapisano ustawienia naboru grupy.');
        } catch (InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('nabor.courses'));
    }

    /** Edytor wizualny formularza publicznego. */
    public function formEditor(): string
    {
        return Router::view(self::VIEW . 'formularz.php', [
            'me' => $this->me(), 'form' => naborForm(), 'defaults' => naborFormDefaults(),
            'previewUrl' => naborUrl('/zapisy', ['podglad' => naborPreviewToken()]),
            'staffEmail' => org_setting('ti_nabor_staff_email'), 'smsProvider' => naborSmsProvider() ?: 'chain',
        ]);
    }

    /** Zapis konfiguracji z edytora (pole config = JSON). */
    public function formSave(): ?string
    {
        $cfg = json_decode((string)($_POST['config'] ?? ''), true);
        if (!is_array($cfg)) {
            flash_set('danger', 'Nie udało się odczytać zmian formularza.');
        } elseif (!empty($_POST['reset'])) {
            org_setting_set('ti_nabor_form', '');
            flash_set('success', 'Przywrócono domyślny wygląd formularza.');
        } else {
            naborFormSave($cfg);
            $mail = trim((string)($_POST['staff_email'] ?? ''));
            org_setting_set('ti_nabor_staff_email', filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '');
            $prov = (string)($_POST['sms_provider'] ?? 'sendly');
            org_setting_set('ti_nabor_sms_provider', $prov === 'chain' || isset(sms_providers()[$prov]) ? $prov : 'sendly');
            flash_set('success', 'Zapisano formularz. Zmiany widać od razu w formularzu publicznym.');
        }
        return Router::redirect(route('nabor.form.edit'));
    }

    /** Prowadzący: przyjęci uczestnicy własnych grup (kierownik widzi wszystkie). */
    public function instructor(): string
    {
        $me    = $this->me();
        $staff = dyd_is_staff();
        $courses = array_values(array_filter(naborCourses(), fn($c) => $staff || dyd_owns_course($me['id'], (int)$c['id'])));
        $byCourse = [];
        if ($ids = array_map(fn($c) => (int)$c['id'], $courses)) {
            $rows = db_all("SELECT course_id, participant_name, participant_school_class, participant_medical_notes,
                                   equipment, equipment_notes, guardian_name, guardian_phone
                              FROM ti_nabor_submissions WHERE status='accepted' AND course_id IN (" . implode(',', $ids) . ")
                          ORDER BY participant_name");
            foreach ($rows as $r) $byCourse[(int)$r['course_id']][] = $r;
        }
        // Tylko grupy, w których są uczestnicy z naboru (pozostałe grupy prowadzący ma w swoim panelu)
        $courses = array_values(array_filter($courses, fn($c) => !empty($byCourse[(int)$c['id']])));
        return Router::view(self::VIEW . 'prowadzacy.php', ['me' => $me, 'courses' => $courses, 'byCourse' => $byCourse, 'staff' => $staff]);
    }

    private function find(int $id): array
    {
        $s = naborSubmissionGet($id);
        if (!$s) { http_response_code(404); exit('Nie znaleziono zgłoszenia.'); }
        return $s;
    }

    /** Powrót na listę z zachowaniem filtrów (pola ukryte _back w formularzach). */
    private function back(): string
    {
        $q = array_intersect_key((array)($_POST['_back'] ?? []), array_flip(['status', 'course', 'q', 'view']));
        return route('nabor.index', array_filter($q, fn($v) => $v !== ''));
    }
}
