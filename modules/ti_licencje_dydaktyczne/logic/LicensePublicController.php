<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/logic/LicensePublicController.php — portal zewnętrzny licencji AM5 (bez logowania).
 *
 *   licensorForm / licensorSubmit — licencjodawca: podgląd wniosku AM5/1 (PDF) i decyzja (sekcja H) po tokenie z e-maila,
 *   holderForm / holderSubmit     — licencjobiorca: dane osobowe/adresowe + oświadczenia AM5/2 i AM5/3 po tokenie,
 *   done                          — potwierdzenie (numery dokumentów z sesji).
 * Zabezpieczenia: CSRF (Router, pole _csrf), token 48 hex (w bazie SHA-256, ważny 30 dni, jednorazowy — po złożeniu kasowany),
 * honeypot, limit prób z IP (10 nieudanych tokenów / 15 min), walidacja DTO, nagłówki noindex / no-referrer / DENY.
 */
require_once dirname(__DIR__) . '/lib/LicenseManagementService.php';
require_once dirname(__DIR__) . '/lib/LicenseOperationService.php';
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';

final class LicensePublicController
{
    private const VIEW = __DIR__ . '/../view/public/';
    private LicenseManagementService $svc;

    public function __construct()
    {
        $this->svc = new LicenseManagementService();
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['org' => ldOrgName()]);
    }

    /** Limit nieudanych prób tokenu z jednego IP (ochrona przed zgadywaniem). */
    private function throttled(): bool
    {
        $k = 'ld_fail_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $row = $_SESSION[$k] ?? ['n' => 0, 't' => time()];
        if (time() - $row['t'] > 900) $row = ['n' => 0, 't' => time()];
        return $row['n'] >= 10;
    }

    private function failToken(): string
    {
        $k = 'ld_fail_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $row = $_SESSION[$k] ?? ['n' => 0, 't' => time()];
        $_SESSION[$k] = ['n' => $row['n'] + 1, 't' => $row['t']];
        usleep(300000);
        http_response_code(404);
        return $this->view('message.php', ['title' => 'Link jest nieaktualny', 'tone' => 'danger',
            'text' => 'Ten link wygasł, został już wykorzystany albo jest niepełny. Jeśli potrzebujesz nowego, skontaktuj się z biurem ' . ldOrgName() . '.']);
    }

    private function blocked(): string
    {
        http_response_code(429);
        return $this->view('message.php', ['title' => 'Zbyt wiele prób', 'tone' => 'danger', 'text' => 'Spróbuj ponownie za kilkanaście minut.']);
    }

    // ── Licencjodawca ───────────────────────────────────────────────────────────

    public function licensorForm(string $token, array $errors = [], array $old = []): string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byLicensorToken((string)$token);
        if (!$l) return $this->failToken();
        if ($l['requestStatus'] !== LicenseRequestStatus::Started) {
            return $this->view('message.php', ['title' => 'Decyzja została już podjęta', 'tone' => 'info', 'text' => 'W tej sprawie decyzja jest już zapisana. Dziękujemy.']);
        }
        return $this->view('licensor.php', ['l' => $l, 'token' => $token, 'errors' => $errors,
            'old' => $old ?: ['period_from' => $l['period_from'], 'period_to' => $l['period_to'], 'territory' => $l['territory'],
                              'terms' => $l['request']['remuneration'] ?? '', 'signer_name' => $l['licensor_name'], 'kind' => 'niewylaczna',
                              'accepted_scope' => implode("\n", array_map(fn($k, $d) => '• ' . LicenseRequestDto::SCOPES[$k][0] . ': ' . $d, array_keys((array)($l['request']['scopes'] ?? [])), (array)($l['request']['scopes'] ?? [])))]]);
    }

    public function licensorPdf(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byLicensorToken((string)$token);
        if (!$l) return $this->failToken();
        $rel = (string)$l['request_pdf'];
        if ($rel === '' || !LicenseStorage::fullPath($rel)) $rel = $this->svc->renderRequest((int)$l['id'])['path'];
        return LicenseStorage::send($rel, ldFileName(LicenseDocument::number(LicenseDoc::Am5Request, $l)));
    }

    public function licensorSubmit(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byLicensorToken((string)$token);
        if (!$l) return $this->failToken();
        if (trim((string)($_POST['www'] ?? '')) !== '') return Router::redirect(route('ld.pub.done'));
        $dto = LicensorDecisionDto::fromArray($_POST);
        try {
            $r = $this->svc->decide((int)$l['id'], $dto, 'portal', ['id' => 0, 'name' => $dto->signerName . ' (licencjodawca, portal)']);
        } catch (DomainException $e) {
            return $this->view('message.php', ['title' => 'Nie można zapisać decyzji', 'tone' => 'danger', 'text' => $e->getMessage()]);
        }
        if ($r['errors']) return $this->licensorForm($token, $r['errors'], $_POST);
        $_SESSION['ld_done'] = ['kind' => 'decision', 'grant' => $dto->grant, 'numbers' => [(string)$l['case_number']]];
        return Router::redirect(route('ld.pub.done'));
    }

    // ── AM20: potwierdzenie zmiany płatnika przez osobę ─────────────────────────

    public function paymentForm(string $token, array $errors = [], array $old = []): string
    {
        if ($this->throttled()) return $this->blocked();
        $op = (new LicenseOperationService())->bySignToken((string)$token);
        if (!$op) return $this->failToken();
        return $this->view('payment.php', ['op' => $op, 'token' => $token, 'errors' => $errors, 'old' => $old ?: ['name' => $op['account_name']], 'sym' => 'AM20']);
    }

    public function paymentPdf(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $ops = new LicenseOperationService();
        $op = $ops->bySignToken((string)$token);
        if (!$op) return $this->failToken();
        $out = $ops->document($op);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . ldFileName((string)$op['number']) . '.' . $out['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $out['bytes'];
        return null;
    }

    public function paymentSubmit(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $ops = new LicenseOperationService();
        $op = $ops->bySignToken((string)$token);
        if (!$op) return $this->failToken();
        if (trim((string)($_POST['www'] ?? '')) !== '') return Router::redirect(route('ld.pub.done'));
        try {
            $errors = $ops->confirm($op, LdInput::str($_POST, 'name', 190), LdInput::bool($_POST, 'confirm'));
        } catch (\Throwable $e) {
            error_log('am20 confirm: ' . $e);
            return $this->view('message.php', ['title' => 'Coś poszło nie tak', 'tone' => 'danger', 'text' => 'Nie zapisaliśmy potwierdzenia. Spróbuj ponownie za chwilę — link nadal działa.', 'sym' => 'AM20']);
        }
        if ($errors) return $this->paymentForm($token, $errors, $_POST);
        $_SESSION['ld_done'] = ['kind' => 'payment', 'numbers' => [(string)$op['number']]];
        return Router::redirect(route('ld.pub.done'));
    }

    // ── Kursant: podpis wniosku AM7 ─────────────────────────────────────────────

    public function requesterForm(string $token, array $errors = [], array $old = []): string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byRequesterToken((string)$token);
        if (!$l) return $this->failToken();
        return $this->view('requester.php', ['l' => $l, 'token' => $token, 'errors' => $errors,
            'old' => $old ?: ['name' => $l['interested_name'], 'place' => $l['request']['place'] ?? '', 'justification' => $l['request']['justification'] ?? '']]);
    }

    public function requesterPdf(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byRequesterToken((string)$token);
        if (!$l) return $this->failToken();
        $rel = (string)$l['request_pdf'];
        if ($rel === '' || !LicenseStorage::fullPath($rel)) $rel = $this->svc->renderRequest((int)$l['id'])['path'];
        return LicenseStorage::send($rel, ldFileName((string)$l['case_number'] . ' AM7-1'));
    }

    public function requesterSubmit(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $l = $this->svc->licenses->byRequesterToken((string)$token);
        if (!$l) return $this->failToken();
        if (trim((string)($_POST['www'] ?? '')) !== '') return Router::redirect(route('ld.pub.done'));
        try {
            $errors = (new Am7Workflow($this->svc))->sign($l, Am7SignDto::fromArray($_POST));
        } catch (DomainException $e) {
            return $this->view('message.php', ['title' => 'Nie można podpisać wniosku', 'tone' => 'danger', 'text' => $e->getMessage()]);
        }
        if ($errors) return $this->requesterForm($token, $errors, $_POST);
        $_SESSION['ld_done'] = ['kind' => 'request', 'numbers' => [(string)$l['case_number']]];
        return Router::redirect(route('ld.pub.done'));
    }

    // ── Licencjobiorca ──────────────────────────────────────────────────────────

    public function holderForm(string $token, array $errors = [], array $old = []): string
    {
        if ($this->throttled()) return $this->blocked();
        $a = $this->svc->assignments->byToken((string)$token);
        if (!$a) return $this->failToken();
        if (!$a['statusEnum']->canSubmit()) {
            return $this->view('message.php', ['title' => 'Oświadczenia są już złożone', 'tone' => 'info', 'text' => 'Dziękujemy — oświadczenia czekają na weryfikację albo zostały zatwierdzone.']);
        }
        $l = $this->svc->licenses->get((int)$a['license_id']);
        if (!$l || in_array($l['poolStatus'], [null, LicenseStatus::Expired, LicenseStatus::Suspended], true)) {
            return $this->view('message.php', ['title' => 'Licencja jest nieaktywna', 'tone' => 'danger', 'text' => 'Licencja ma status: ' . ($l && $l['poolStatus'] ? mb_strtolower($l['poolStatus']->label()) : 'nieudzielona') . '. Skontaktuj się z biurem ' . ldOrgName() . '.']);
        }
        if (!$old) {
            $hd = LicenseManagementService::holderData((string)$a['holder_kind'], $a['holder_id'] ? (int)$a['holder_id'] : null);
            $old = ['name' => $a['holder_name'], 'email' => $a['holder_email'], 'phone' => $a['holder_phone']] + ($hd['fields'] ?? []);
            if ($hd && empty($hd['fields']) && trim((string)$hd['address']) !== '') $old['addr_street'] = (string)$hd['address'];   // kursant: adres jednym wierszem
        }
        return $this->view('holder.php', ['a' => $a, 'l' => $l, 'token' => $token, 'errors' => $errors, 'old' => $old, 'docs' => $l['form']->declarationDocs()]);
    }

    public function holderSubmit(string $token): ?string
    {
        if ($this->throttled()) return $this->blocked();
        $a = $this->svc->assignments->byToken((string)$token);
        if (!$a) return $this->failToken();
        if (trim((string)($_POST['www'] ?? '')) !== '') return Router::redirect(route('ld.pub.done'));
        $dto = HolderDeclarationDto::fromArray($_POST);
        try {
            $r = $this->svc->submitDeclarations($a, $dto);
        } catch (DomainException $e) {
            return $this->view('message.php', ['title' => 'Nie można złożyć oświadczeń', 'tone' => 'danger', 'text' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('am5 portal: ' . $e);
            return $this->view('message.php', ['title' => 'Coś poszło nie tak', 'tone' => 'danger', 'text' => 'Nie zapisaliśmy oświadczeń. Spróbuj ponownie za chwilę — link nadal działa.']);
        }
        if ($r['errors']) return $this->holderForm($token, $r['errors'], $_POST);
        $_SESSION['ld_done'] = ['kind' => 'declarations', 'numbers' => $r['numbers']];
        return Router::redirect(route('ld.pub.done'));
    }

    /** QR z druku AMSK/1: status korekty po tokenie — bez danych osobowych (numer, status, daty). */
    public function amskVerify(string $token): string
    {
        $c = LicenseCorrection::byToken($token);
        if (!$c) { http_response_code(404); return $this->view('message.php', ['title' => 'Nie znaleziono korekty', 'tone' => 'danger', 'text' => 'Nie znaleziono korekty o tym kodzie. Sprawdź, czy kod QR jest czytelny.']); }
        $st = (string)$c['status'];
        $dt = fn(?string $v) => ldDate(substr((string)$v, 0, 10)) . ' ' . substr((string)$v, 11, 5);
        $text = 'Korekta ' . LicenseCorrection::code((int)$c['id']) . ' do sprawy ' . $c['target_number'] . ': ' . LicenseCorrection::statusLabel($st) . '. Wystawiona ' . $dt($c['created_at'])
              . ($st === LicenseCorrection::APPLIED ? ', zatwierdzona ' . $dt($c['applied_at']) . '.' : '.');
        return $this->view('message.php', ['title' => 'Status korekty AMSK', 'tone' => $st === LicenseCorrection::APPLIED ? 'success' : ($st === LicenseCorrection::CANCELLED ? 'danger' : 'info'), 'text' => $text,
            'numbers' => [LicenseCorrection::code((int)$c['id'])]]);
    }

    public function done(): string
    {
        $d = $_SESSION['ld_done'] ?? null;
        unset($_SESSION['ld_done']);
        if (!$d) return $this->view('message.php', ['title' => 'Dziękujemy', 'tone' => 'success', 'text' => 'Zgłoszenie przyjęte.']);
        if ($d['kind'] === 'payment') {
            return $this->view('message.php', ['title' => 'Wniosek potwierdzony', 'tone' => 'success', 'numbers' => $d['numbers'], 'sym' => 'AM20',
                'text' => 'Dziękujemy. Zmiana płatnika weszła w życie — potwierdzenie z dokumentem AM20 (PDF) wysłaliśmy e-mailem.']);
        }
        if ($d['kind'] === 'request') {
            return $this->view('message.php', ['title' => 'Wniosek podpisany', 'tone' => 'success', 'numbers' => $d['numbers'],
                'text' => 'Dziękujemy. Placówka rozpatrzy wniosek w ciągu ' . LD_REALIZATION_DAYS . ' dni — decyzję otrzymasz e-mailem w PDF.']);
        }
        if ($d['kind'] === 'decision') {
            return $this->view('message.php', ['title' => 'Decyzja zapisana', 'tone' => 'success', 'numbers' => $d['numbers'],
                'text' => ($d['grant'] ? 'Dziękujemy za udzielenie licencji.' : 'Dziękujemy — odmowę zapisaliśmy.') . ' Fundacja otrzymała powiadomienie. Kopię wniosku z decyzją prześlemy na życzenie.']);
        }
        return $this->view('message.php', ['title' => 'Oświadczenia złożone', 'tone' => 'success', 'numbers' => $d['numbers'],
            'text' => 'Dziękujemy. Potwierdzenie z dokumentami wysłaliśmy na podany adres e-mail (albo SMS-em). Weryfikacja trwa do ' . LD_REALIZATION_DAYS . ' dni.']);
    }
}
