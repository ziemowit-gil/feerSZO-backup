<?php
/**
 * modules/it_portal/lib/itPortalNoticeStopkaSeed.php — komunikat „Obowiązkowa stopka w wiadomościach e-mail” w Portalu Usług IT
 * (/uslugi-it/komunikaty). Polecenie 2026-11-13: „przygotuj wpis na pomoc-it i komunikat” + „że generowanie stopek obowiązkowe”.
 * Komunikat rozpoznajemy po tytule (ITP_NOTICE_STOPKA_TITLE): drugi raz nadpisuje treść, status i podpis, zostawia datę
 * i publikację. Brak daty wejścia w życie jest celowy — komunikat mówi „od teraz”; konkretny termin dopisz w panelu portalu.
 * Używa migracja '2026-11_it_portal_stopka' i cli/it_portal_stopka_seed.php.
 */
const ITP_NOTICE_STOPKA_TITLE = 'Obowiązkowa stopka w wiadomościach e-mail';

/** @return array{status:string, notice:string} */
function itPortalNoticeStopkaApply(string $app, bool $dryRun = false, string $date = '2026-11-13'): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $old = db_one("SELECT * FROM itp_notices WHERE title = ? ORDER BY id LIMIT 1", [ITP_NOTICE_STOPKA_TITLE]);
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'notice' => ITP_NOTICE_STOPKA_TITLE . ($old ? " (#{$old['id']})" : '')];
    if ($dryRun) return $rep;
    $id = itPortalNoticeSave([
        'title' => ITP_NOTICE_STOPKA_TITLE, 'body_html' => itPortalNoticeStopkaBody($app), 'status' => 'info', 'signature' => 'Dział IT FEER',
        'notice_date' => $old ? $old['notice_date'] : $date, 'is_published' => $old ? (int)$old['is_published'] : 1,
    ], $old ? (int)$old['id'] : 0);
    $rep['notice'] = ITP_NOTICE_STOPKA_TITLE . " (#{$id})";
    return $rep;
}

/** Treść komunikatu (HTML przechodzi przez itPortalSanitizeHtml w itPortalNoticeSave). */
function itPortalNoticeStopkaBody(string $app): string {
    $a = static fn(string $path, string $text): string => '<a href="' . $app . $path . '">' . $text . '</a>';
    return '<p>Od teraz każda wiadomość ze służbowej skrzynki musi mieć stopkę z generatora Fundacji. Stopka jest elementem tożsamości Fundacji, dlatego wszyscy używamy tego samego wzoru i korzystanie z generatora jest obowiązkowe.</p>'
        . '<h3>Co masz zrobić</h3><ol>'
        . '<li>Otwórz ' . $a('/pomocne/generatorstopki', 'generator stopki') . ' (nie wymaga logowania).</li>'
        . '<li>Wpisz imię, nazwisko, stanowisko, telefon i adres e-mail. Dane Fundacji i linki do Facebooka, Instagrama i LinkedIna są w stopce od razu.</li>'
        . '<li>Kliknij „Kopiuj stopkę do schowka” i wklej ją w ustawieniach podpisu swojej poczty.</li></ol>'
        . '<h3>Do zapamiętania</h3><ul>'
        . '<li>Stopka jest elementem tożsamości Fundacji, więc nie zmieniamy jej wyglądu ręcznie.</li>'
        . '<li>Gdy zmieni się telefon albo stanowisko, wygeneruj stopkę jeszcze raz.</li>'
        . '<li>Dane wpisane w generatorze zostają w Twojej przeglądarce i nie trafiają na serwer.</li></ul>'
        . '<h3>Gdzie przeczytać więcej</h3><ul>'
        . '<li>' . $a('/uslugi-it/uslugi/moja-poczta/generator-stopki', 'Obowiązkowa stopka: jak ją wygenerować') . '</li>'
        . '<li>' . $a('/uslugi-it/uslugi/moja-poczta/podpis-w-poczcie', 'Jak ustawić podpis (stopkę) w poczcie') . '</li></ul>'
        . '<p>Coś nie działa? ' . $a('/helpdesk/new.php', 'Napisz do Helpdesku IT') . '.</p>';
}
