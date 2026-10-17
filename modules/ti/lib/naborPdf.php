<?php
/**
 * modules/ti/lib/naborPdf.php — PDF zgłoszenia z naboru do grup TI.
 *
 * Komplet danych zgłoszenia, zasady odbioru, zgody RODO z adresem IP i znacznikiem czasu
 * oraz ślad weryfikacji (SMS / e-mail). Dokument główny pisma w EZD i załącznik e-maila do opiekuna.
 * Baza: TiPdf (DejaVu, polskie znaki, stopka z numeracją stron).
 */
require_once dirname(__DIR__) . '/logic/nabor/nabor.php';
require_once dirname(__DIR__, 2) . '/ti_pdf/logic/TiPdf.php';

function naborSubmissionPdfFilename(array $s): string {
    return 'zgloszenie-' . str_replace('/', '-', $s['registration_number']) . '.pdf';
}

/** Treść PDF (string). */
function naborSubmissionPdf(int $sid): string {
    $s = naborSubmissionGet($sid);
    if (!$s) throw new RuntimeException('Zgłoszenie nie istnieje.');
    $c = naborConsents($sid) ?: [];
    $p = fn($t) => TiPdf::pl($t);

    $pdf = new TiPdf('P', 'mm', 'A4');
    $pdf->SetTitle($p('Zgłoszenie ' . $s['registration_number']), false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pdf->SetFillColor(15, 80, 150);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell(0, 10, $p('Zgłoszenie do grupy TI'), 0, 1, 'L', true);
    $pdf->Ln(3);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('DejaVu', 'B', 11);
    $pdf->Cell(0, 6, $p('Numer zgłoszenia: ' . $s['registration_number']), 0, 1);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, $p('Złożono: ' . naborTime($s['created_at']) . ' · status: ' . naborStatusLabel($s['status'])), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    $section = function (string $title) use ($pdf, $p) {
        $pdf->Ln(3);
        $pdf->SetFillColor(224, 232, 244);
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell(0, 7, $p($title), 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 9.5);
    };
    $row = function (string $label, string $value) use ($pdf, $p) {
        $y = $pdf->GetY();
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->MultiCell(55, 5.5, $p($label), 0, 'L');
        $h1 = $pdf->GetY();
        $pdf->SetXY(70, $y);
        $pdf->SetFont('DejaVu', '', 9.5);
        $pdf->MultiCell(0, 5.5, $p($value !== '' ? $value : '—'), 0, 'L');
        $pdf->SetY(max($h1, $pdf->GetY()));
    };
    $list = fn(string $json, array $map) => implode(', ', array_map(fn($k) => $map[$k] ?? $k, json_decode($json ?: '[]', true) ?: []));
    $yn = fn($v) => !empty($v) ? 'TAK' : 'NIE';

    $section('1. Zajęcia');
    if ($s['is_custom_schedule']) {
        $row('Termin', 'Do ustalenia');
        $row('Preferowane dni', $list($s['preferred_days'], NABOR_DAYS));
        $row('Preferowane pory', $list($s['preferred_time_slots'], NABOR_SLOTS));
        $row('Dyspozycyjność', (string)$s['notes_availability']);
    } else {
        $row('Grupa', (string)$s['course_name']);
        $row('Forma', 'zajęcia online');
        $row('Harmonogram', (string)$s['course_schedule']);
        $row('Płatność (deklaracja)', NABOR_PAYMENT[$s['payment_method']] ?? '');
    }

    $section('2. Uczestnik');
    $row('Imię i nazwisko', $s['participant_name']);
    $row('PESEL / data urodzenia', $s['participant_birth_date_or_pesel']);
    $row('Szkoła / klasa', $s['participant_school_class']);
    $row('Uwagi medyczne i dostępnościowe', $s['participant_medical_notes']);

    $section('3. Rodzic / opiekun prawny');
    $row('Imię i nazwisko', $s['guardian_name']);
    $row('Telefon', '+' . $s['guardian_phone'] . ($s['phone_verified_at'] ? '  (potwierdzony kodem SMS ' . naborTime($s['phone_verified_at']) . ')' : ''));
    $row('E-mail', $s['guardian_email'] . ($s['email_confirmed_at'] ? '  (potwierdzony linkiem ' . naborTime($s['email_confirmed_at']) . ')' : '  (niepotwierdzony)'));
    $row('Adres zamieszkania', $s['guardian_street'] . ', ' . $s['guardian_postal_code'] . ' ' . $s['guardian_city']);
    if (($s['pickup_type'] ?? '') !== '') $row('Odbiór uczestnika', NABOR_PICKUP[$s['pickup_type']] ?? '');   // zgłoszenia sprzed przejścia na online
    $row('Sprzęt do zajęć online', naborEquipmentLabel($s['equipment'] ?? '[]'));
    $row('Uwagi o sprzęcie', (string)($s['equipment_notes'] ?? ''));

    $section('4. Zgody i oświadczenia');
    $row('Regulamin zajęć', $yn($c['terms_accepted'] ?? 0));
    $row('Przetwarzanie danych osobowych (RODO)', $yn($c['gdpr_accepted'] ?? 0));
    $row('Wykorzystanie wizerunku', $yn($c['image_consent'] ?? 0));
    $row('Informacje marketingowe', $yn($c['marketing_consent'] ?? 0));
    $row('Adres IP', (string)($c['ip_address'] ?? $s['ip_address']));
    $row('Znacznik czasu zgód', naborTime($c['created_at'] ?? null, true));
    $row('Przeglądarka', (string)($c['user_agent'] ?? ''));

    $pdf->Ln(4);
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4.2, $p('Dokument wygenerowany automatycznie ' . date('Y-m-d H:i') . ' z formularza internetowego. '
        . 'Tożsamość wnioskodawcy potwierdzona kodem SMS wysłanym na podany numer telefonu oraz linkiem wysłanym na podany adres e-mail. '
        . 'Zgody można wycofać w każdej chwili, kontaktując się z administratorem danych.'), 0, 'L');
    return $pdf->Output('S');
}
