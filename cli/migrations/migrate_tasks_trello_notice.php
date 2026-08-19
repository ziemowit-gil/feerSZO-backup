<?php
/**
 * Migracja: ogłoszenie o tymczasowym przejściu na Trello (moduł Zadania).
 *
 * Dodaje przypięte ogłoszenie informujące wolontariuszy i współpracowników
 * o czasowym wyłączeniu modułu Zadania i przejściu na tablicę Trello
 * dla ciągłości operacyjnej.
 *
 * Idempotentna — ogłoszenie dodawane tylko jeśli nie istnieje.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

notif_migrate();

$marker_title = '[TRELLO-NOTICE-2024] Zadania — przejście na Trello';

$exists = db_one("SELECT id FROM announcements WHERE title=? LIMIT 1", [$marker_title]);
if ($exists) {
    echo "Ogłoszenie już istnieje (id={$exists['id']}) — pomijam.\n";
    exit(0);
}

$body = '<p>Szanowni Wolontariusze i Współpracownicy,</p>'
    . '<p>Informujemy, że moduł <strong>Zadania</strong> w systemie FEER jest '
    . '<strong>tymczasowo wyłączony</strong> w związku z przygotowywaną aktualizacją.</p>'
    . '<p>W celu zapewnienia <strong>ciągłości operacyjnej</strong> prosimy o korzystanie '
    . 'z naszej tablicy Trello jako zamiennika:</p>'
    . '<p><a href="https://trello.com/b/VDjMNkbr/feer-wsp%C3%B3%C5%82praca-zespo%C5%82u" '
    . 'target="_blank" rel="noopener"><strong>🔗 Tablica Trello — FEER Współpraca Zespołu</strong></a></p>'
    . '<p>O przywróceniu modułu poinformujemy osobno. Dziękujemy za wyrozumiałość.</p>';

$id = db_insert('announcements', [
    'title'        => $marker_title,
    'body'         => $body,
    'audience'     => 'all',
    'kategoria'    => 'administracyjne',
    'author_id'    => null,
    'author_name'  => 'Administrator',
    'is_pinned'    => 1,
    'is_active'    => 1,
    'send_email'   => 0,
    'display_mode' => 'banner',
    'created_at'   => date('Y-m-d H:i:s'),
    'updated_at'   => date('Y-m-d H:i:s'),
]);

echo "Dodano ogłoszenie (id={$id}).\n";
