<?php
/**
 * modules/it_portal/lib/itPortalNoticeSeed.php — komunikat „Ważne zmiany: odchodzimy od Microsoft 365” w Portalu Usług IT
 * (/uslugi-it/komunikaty, trzy najnowsze widać też na stronie Start portalu). Polecenie 2026-11-13: „Przygotuj seed na komunikat
 * o ważnych zmianach”.
 *
 * Treść zgodna z artykułami portalu na 2026-11-13 (itPortalMigracjaSeed, itPortalFirstLoginSeed, itPortalGlossarySeed):
 * 14.11 nowa poczta, 20.11 wyłączenie Microsoft 365, 1.12 jedno hasło IAM do aplikacji Fundacji (poczta osobno).
 * Komunikat rozpoznajemy po tytule (ITP_NOTICE_SEED_TITLE): drugi raz nadpisuje treść, status i podpis, zostawia datę
 * i publikację (ręczne ukrycie w panelu portalu zostaje). Używa migracja '2026-11_it_portal_notice_zmiany'
 * i cli/it_portal_notice_seed.php.
 */
const ITP_NOTICE_SEED_TITLE = 'Ważne zmiany: odchodzimy od Microsoft 365';

/** @return array{status:string, notice:string} */
function itPortalNoticeSeedApply(string $app, bool $dryRun = false, string $date = '2026-11-13'): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $old = db_one("SELECT * FROM itp_notices WHERE title = ? ORDER BY id LIMIT 1", [ITP_NOTICE_SEED_TITLE]);
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'notice' => ITP_NOTICE_SEED_TITLE . ($old ? " (#{$old['id']})" : '')];
    if ($dryRun) return $rep;
    $id = itPortalNoticeSave([
        'title' => ITP_NOTICE_SEED_TITLE, 'body_html' => itPortalNoticeSeedBody($app), 'status' => 'info', 'signature' => 'Dział IT FEER',
        'notice_date' => $old ? $old['notice_date'] : $date, 'is_published' => $old ? (int)$old['is_published'] : 1,
    ], $old ? (int)$old['id'] : 0);
    $rep['notice'] = ITP_NOTICE_SEED_TITLE . " (#{$id})";
    return $rep;
}

/** Treść komunikatu (HTML przechodzi przez itPortalSanitizeHtml w itPortalNoticeSave). */
function itPortalNoticeSeedBody(string $app): string {
    $a = static fn(string $path, string $text): string => '<a href="' . $app . $path . '">' . $text . '</a>';
    return '<p>Fundacja przenosi pocztę, pliki i logowanie z Microsoft 365 do własnych narzędzi. Twój adres e-mail się nie zmienia. Zmieniają się miejsca logowania i hasła.</p>'
        . '<h3>Ważne daty</h3><ul>'
        . '<li><strong>14 listopada 2026 r.</strong> poczta zaczyna działać w nowej skrzynce na <a href="https://dpoczta.pl">dpoczta.pl</a>. Hasło do niej dostajesz SMS-em.</li>'
        . '<li><strong>20 listopada 2026 r.</strong> wyłączamy Microsoft 365. Do tego dnia załóż konto IAM i przenieś z OneDrive do chmury Fundacji pliki, których potrzebujesz.</li>'
        . '<li><strong>Od 1 grudnia 2026 r.</strong> jednym hasłem IAM zalogujesz się do wszystkich aplikacji Fundacji. Poczta ma osobne hasło.</li></ul>'
        . '<h3>Co masz zrobić</h3><ul>'
        . '<li>Przenieś pocztę na stronie ' . $a('/migracjapoczty', 'Migracja poczty') . ', jeśli jeszcze tego nie zrobiłeś(-aś).</li>'
        . '<li>Zaloguj się do SZO e-mailem i hasłem albo przyciskiem „Zaloguj przez logowanie jednokrotne”. Po zalogowaniu SZO zaproponuje konto IAM i konto w chmurze plików.</li>'
        . '<li>Ustaw dwa różne hasła: jedno do IAM i chmury, drugie do poczty. Oba zmienisz na stronie ' . $a('/nowehaslo', 'Nowe hasło') . '.</li></ul>'
        . '<h3>Gdzie przeczytać więcej</h3><ul>'
        . '<li>' . $a('/uslugi-it/uslugi/migracja/migracja', 'Migracja z Microsoft 365: co się zmienia i dlaczego') . '</li>'
        . '<li>' . $a('/uslugi-it/uslugi/moj-login/pierwsze-logowanie', 'Pierwsze logowanie krok po kroku') . '</li>'
        . '<li>' . $a('/uslugi-it/uslugi/migracja/iam-i-poczta', 'Czym jest IAM, a czym poczta') . '</li>'
        . '<li>' . $a('/uslugi-it/uslugi/migracja/terminologia', 'Terminologia: słowniczek pojęć') . '</li>'
        . '<li>Instrukcje poczty: ' . $a('/uslugi-it/uslugi/moja-poczta', 'Moja poczta') . '</li></ul>'
        . '<p><strong>Nikt z Fundacji nie poprosi Cię o hasło.</strong> Hasła wysyłamy tylko SMS-em. Coś nie działa albo budzi wątpliwości? '
        . $a('/helpdesk/new.php', 'Napisz do Helpdesku IT') . '.</p>';
}
