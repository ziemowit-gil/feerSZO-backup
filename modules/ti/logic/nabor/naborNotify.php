<?php
/**
 * modules/ti/logic/nabor/naborNotify.php — wiadomości do rodzica/opiekuna.
 *
 *   naborNotifyConfirmEmail — link potwierdzający adres e-mail (bez niego zgłoszenie nie wchodzi do obiegu),
 *   naborNotifyReceived     — po potwierdzeniu: numer zgłoszenia + PDF w załączniku,
 *   naborNotifyStatus       — zmiana statusu: e-mail zawsze, SMS gdy kanał SMS skonfigurowany (rodzaj ti_nabor_status).
 * Błąd wysyłki nigdy nie przerywa obiegu — trafia do historii zgłoszenia.
 */
require_once __DIR__ . '/nabor.php';
require_once dirname(__DIR__, 4) . '/includes/mail_queue.php';

function naborOrgName(): string {
    return org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER');
}

function naborMailHtml(string $title, string $body): string {
    return '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#111827">'
         . '<div style="background:#1d68f2;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;font-size:18px;font-weight:bold">' . h($title) . '</div>'
         . '<div style="border:1px solid #d8e2f3;border-top:0;padding:20px;border-radius:0 0 8px 8px;line-height:1.55">' . $body . '</div>'
         . '<p style="font-size:12px;color:#5b6472;margin-top:12px">' . h(naborOrgName()) . ' · wiadomość wysłana automatycznie z systemu zapisów.</p></div>';
}

function naborNotifyConfirmEmail(?array $s, string $token): void {
    if (!$s) return;
    $url = naborUrl('/potwierdz', ['t' => $token]);
    $body = '<p>Dzień dobry,</p><p>otrzymaliśmy zgłoszenie <strong>' . h($s['participant_name']) . '</strong> na zajęcia '
          . '(numer <strong>' . h($s['registration_number']) . '</strong>).</p>'
          . '<p><strong>Aby zgłoszenie trafiło do rozpatrzenia, potwierdź adres e-mail w ciągu ' . NABOR_CONFIRM_HOURS . ' godzin:</strong></p>'
          . '<p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#1d68f2;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Potwierdzam zgłoszenie</a></p>'
          . '<p style="font-size:13px;color:#5b6472">Jeśli przycisk nie działa, skopiuj adres do przeglądarki:<br>' . h($url) . '</p>'
          . '<p style="font-size:13px;color:#5b6472">Jeśli to nie Ty wysłałeś(-aś) zgłoszenie, zignoruj tę wiadomość — bez potwierdzenia zostanie usunięte z obiegu.</p>';
    try {
        mail_queue_add($s['guardian_email'], $s['guardian_name'], 'Potwierdź zgłoszenie ' . $s['registration_number'],
                       naborMailHtml('Potwierdź zgłoszenie', $body), '', 'ti_nabor', (int)$s['id'], '', true);
    } catch (\Throwable $e) {
        naborHistoryNote((int)$s['id'], 'Błąd wysyłki e-maila potwierdzającego: ' . $e->getMessage());
    }
}

/** PDF zgłoszenia zapisany trwale (kolejka poczty może wysyłać później). */
function naborStorePdf(array $s): ?array {
    try {
        require_once dirname(__DIR__, 2) . '/lib/naborPdf.php';
        $rel  = 'ti_nabor/' . (int)$s['id'] . '/';            // kolejka poczty składa UPLOAD_DIR . path
        $dir  = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = naborSubmissionPdfFilename($s);
        file_put_contents($dir . $name, naborSubmissionPdf((int)$s['id']));
        return ['path' => $rel . $name, 'name' => $name, 'mime' => 'application/pdf', 'size' => filesize($dir . $name)];
    } catch (\Throwable $e) {
        error_log('[ti_nabor] PDF #' . $s['id'] . ': ' . $e->getMessage());
        return null;
    }
}

function naborNotifyReceived(?array $s): void {
    if (!$s) return;
    $att = naborStorePdf($s);
    $what = $s['is_custom_schedule'] ? 'termin do ustalenia — skontaktujemy się, gdy zbierzemy grupę'
                                     : h((string)$s['course_name']);
    $extra = $s['status'] === 'waitlist'
        ? '<p><strong>W tej chwili w grupie nie ma wolnych miejsc</strong> — zgłoszenie jest na liście rezerwowej. Damy znać, gdy zwolni się miejsce.</p>'
        : '<p>Zgłoszenie zostało zarejestrowane i czeka na rozpatrzenie. O decyzji poinformujemy e-mailem i SMS-em.</p>';
    $body = '<p>Dzień dobry,</p><p>dziękujemy za potwierdzenie adresu e-mail. Zgłoszenie <strong>' . h($s['registration_number']) . '</strong> '
          . 'dla <strong>' . h($s['participant_name']) . '</strong> (' . $what . ') zostało przyjęte do obiegu.</p>' . $extra
          . '<p>W załączniku przesyłamy PDF z kompletem danych i udzielonymi zgodami.</p>';
    try {
        mail_queue_add($s['guardian_email'], $s['guardian_name'], 'Zgłoszenie ' . $s['registration_number'] . ' zarejestrowane',
                       naborMailHtml('Zgłoszenie zarejestrowane', $body), '', 'ti_nabor', (int)$s['id'], '', true, $att ? [$att] : []);
    } catch (\Throwable $e) {
        naborHistoryNote((int)$s['id'], 'Błąd wysyłki potwierdzenia rejestracji: ' . $e->getMessage());
    }
}

/** Treść powiadomienia o statusie: [temat, akapit]. */
function naborStatusMessage(array $s): array {
    $co = $s['course_name'] ? '„' . $s['course_name'] . '”' : 'zajęcia';
    return match ($s['status']) {
        'accepted'        => ['przyjęte', $s['participant_name'] . ' został(a) przyjęty(-a) na ' . $co . '. Szczegóły organizacyjne prześlemy przed pierwszymi zajęciami.'],
        'waitlist'        => ['lista rezerwowa', 'Zgłoszenie jest na liście rezerwowej grupy ' . $co . '. Damy znać, gdy zwolni się miejsce.'],
        'rejected'        => ['odrzucone', 'Niestety nie możemy przyjąć zgłoszenia na ' . $co . '.'],
        'cancelled'       => ['anulowane', 'Zgłoszenie zostało anulowane.'],
        'pending_review'  => ['w weryfikacji', 'Zgłoszenie jest weryfikowane. Możemy poprosić o uzupełnienie informacji.'],
        'custom_schedule' => ['termin do ustalenia', 'Zgłoszenie czeka na ustalenie terminu — skontaktujemy się z propozycją.'],
        'new'             => ['przywrócone', 'Zgłoszenie wróciło do rozpatrzenia.'],
        default           => [naborStatusLabel($s['status']), 'Status zgłoszenia: ' . naborStatusLabel($s['status']) . '.'],
    };
}

function naborNotifyStatus(?array $s, string $comment = ''): void {
    if (!$s || $s['status'] === 'archived') return;
    [$short, $text] = naborStatusMessage($s);
    $body = '<p>Dzień dobry,</p><p>' . h($text) . '</p>'
          . ($comment !== '' ? '<p style="background:#edf3fe;padding:10px 14px;border-radius:6px"><strong>Informacja od organizatora:</strong><br>' . nl2br(h($comment)) . '</p>' : '')
          . '<p style="font-size:13px;color:#5b6472">Numer zgłoszenia: ' . h($s['registration_number']) . '</p>';
    $log = [];
    try {
        mail_queue_add($s['guardian_email'], $s['guardian_name'], 'Zgłoszenie ' . $s['registration_number'] . ': ' . $short,
                       naborMailHtml('Zmiana statusu zgłoszenia', $body), '', 'ti_nabor', (int)$s['id']);
        $log[] = 'e-mail';
    } catch (\Throwable $e) {
        $log[] = 'e-mail: błąd (' . $e->getMessage() . ')';
    }
    try {
        require_once dirname(__DIR__, 4) . '/includes/sms.php';
        require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
        if (smsPreferredReady(naborSmsProvider())) {
            $via = naborSms($s['guardian_phone'], naborOrgName() . ': zgloszenie ' . $s['registration_number'] . ' - ' . sms_ascii($short) . '. Szczegoly w e-mailu.', 'ti_nabor_status');
            $log[] = 'SMS (' . sms_provider_label($via ?: '—') . ')';
        } else {
            $log[] = 'SMS: brak skonfigurowanego dostawcy';
        }
    } catch (\Throwable $e) {
        $log[] = 'SMS: błąd (' . $e->getMessage() . ')';
    }
    naborHistoryNote((int)$s['id'], 'Powiadomienie o statusie: ' . implode(', ', $log));
}
