<?php
/**
 * modules/noclegi/lib/noclegiPdf.php — Rooming List w PDF na wspólnej bazie TI (TiPdf: DejaVu, paleta METRO).
 * Lista dla obiektu NIE zawiera kodów dostępu ani uwag o zdrowiu — tylko flagę „pokój dostępny”.
 */
require_once dirname(__DIR__, 2) . '/ti_pdf/logic/TiPdf.php';
require_once __DIR__ . '/noclegiRooming.php';

/** Zwraca binarny PDF (A4 poziomo). */
function noclRoomingPdf(int $facilityId, string $from, string $to): string {
    $fac = noclFacilityGet($facilityId) ?: ['name' => '—', 'address' => '', 'city' => '', 'phone' => ''];
    $data = noclRoomingData($facilityId, $from, $to);
    $roles = noclRoleOsoby();
    $pdf = new TiPdf('L', 'mm', 'A4');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $W = 273;
    $pdf->SetFillColor(...TiPdf::NAVY);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 15);
    $pdf->Cell($W, 11, TiPdf::pl('  Rooming List — ' . $fac['name']), 0, 1, 'L', true);
    $pdf->SetTextColor(...TiPdf::TEXT);
    $pdf->Ln(2);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->Cell($W, 5, TiPdf::pl('Okres: ' . date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)) . '   ·   ' . trim($fac['address'] . ', ' . $fac['city'], ', ') . ($fac['phone'] ? '   ·   tel. ' . $fac['phone'] : '')), 0, 1);
    $pdf->SetTextColor(...TiPdf::MUTED);
    $pdf->Cell($W, 5, TiPdf::pl('Wygenerowano ' . date('d.m.Y H:i') . '. Lista nie zawiera kodów dostępu ani danych o zdrowiu.'), 0, 1);
    $pdf->Ln(2);
    $cols = [['Pokój', 28], ['Typ', 38], ['Miejsca', 17], ['Osoba', 62], ['Rola', 32], ['Przyjazd', 24], ['Wyjazd', 24], ['Noce', 13], ['Dostępny', 17], ['Status', 18]];
    $head = function () use ($pdf, $cols) {
        $pdf->SetFillColor(...TiPdf::BAR); $pdf->SetTextColor(...TiPdf::TEXT); $pdf->SetFont('DejaVu', 'B', 8.5);
        foreach ($cols as [$t, $w]) $pdf->Cell($w, 7, TiPdf::pl($t), 1, 0, 'L', true);
        $pdf->Ln();
        $pdf->SetFont('DejaVu', '', 8.5);
    };
    $head();
    $zebra = false; $people = 0; $empty = 0;
    foreach ($data as $g) {
        $rm = $g['room'];
        $rows = $g['people'] ?: [null];
        if (!$g['people']) $empty++;
        foreach ($rows as $i => $p) {
            if ($pdf->GetY() > 180) { $pdf->AddPage(); $head(); }
            $zebra = !$zebra;
            $pdf->SetFillColor(...($zebra ? TiPdf::ZEBRA : [255, 255, 255]));
            $cells = [
                $i === 0 ? $rm['name'] : '', $i === 0 ? (noclTypyPokoi()[$rm['room_type']] ?? $rm['room_type']) : '', $i === 0 ? (string)$rm['beds'] : '',
                $p ? $p['person_name'] : '— wolny —', $p ? ($roles[$p['person_role']] ?? '') : '',
                $p ? date('d.m.Y', strtotime($p['date_from'])) : '', $p ? date('d.m.Y', strtotime($p['date_to'])) : '',
                $p ? (string)noclNights($p['date_from'], $p['date_to']) : '', $i === 0 && (int)$rm['accessible'] ? 'tak' : '', $p ? noclStatusLabel($p['status']) : '',
            ];
            $pdf->SetTextColor(...($p ? TiPdf::TEXT : TiPdf::MUTED));
            foreach ($cols as $k => [$t, $w]) $pdf->Cell($w, 6.5, TiPdf::pl(mb_strimwidth($cells[$k], 0, (int)($w * 0.62), '…')), 1, 0, 'L', true);
            $pdf->Ln();
            if ($p) $people++;
        }
    }
    $pdf->Ln(3);
    $pdf->SetTextColor(...TiPdf::TEXT); $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->Cell($W, 6, TiPdf::pl('Razem osób: ' . $people . '   ·   pokoi bez gości w okresie: ' . $empty), 0, 1);
    return $pdf->Output('S');
}
