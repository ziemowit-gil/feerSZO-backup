<?php
/**
 * cli/seedOfertaRedaktor.php — ogłoszenie wolontariatu „Redaktor/ka tekstów internetowych”.
 *
 *   php cli/seedOfertaRedaktor.php                # podgląd (nic nie zapisuje)
 *   php cli/seedOfertaRedaktor.php --apply        # zapis jako szkic
 *   php cli/seedOfertaRedaktor.php --apply --publish   # zapis i od razu aktywne (publiczny formularz)
 *
 * Trafia do volunteer_offers (Wolontariat → Zgłoszenia, contracts/rekrutacja/) — stamtąd zgłoszenia
 * kandydatów widać w karcie kontaktu CRM (Crm::getContactRecruitments, po e-mailu).
 * Klauzula RODO: nie ustawiamy — apply.php bierze domyślną klauzulę kontekstu 'volunteer_offer'.
 * Ponowne uruchomienie aktualizuje ogłoszenie o tym samym tytule (nie tworzy drugiego).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja_offers.php'; // VolunteerModuleManager + tabele

$opts    = getopt('', ['apply', 'publish']);
$apply   = array_key_exists('apply', $opts);
$publish = array_key_exists('publish', $opts);

$TITLE = 'Redaktor/ka tekstów internetowych (dziennikarstwo / komunikacja społeczna) – wolontariat w FEER';

$content = <<<'TXT'
FEER działa na rzecz osób z niepełnosprawnościami i chcemy, żeby nasze treści to pokazywały — jasno, angażująco i z sensem. Szukamy kogoś, kto pomoże nam przebudować komunikację na stronie od podstaw.

1. Cel wolontariatu
Strona FEER wymaga przemyślenia — nie tylko korekty, ale realnego przepisania treści tak, by skutecznie trafiały do różnych odbiorców: instytucji, darczyńców i osób z niepełnosprawnościami. Twoja praca przełoży się bezpośrednio na to, jak organizacja jest postrzegana i czy jej misja jest zrozumiała.

2. Na czym będzie polegał wolontariat?
• Analiza obecnych tekstów na feer.org.pl pod kątem komunikacyjnym
• Przebudowa struktury i języka wybranych podstron (zakładka „O nas”, opisy projektów, aktualności)
• Pisanie nowych treści w stylu dziennikarskim lub contentowym
• Wypracowanie spójnego tonu komunikacji dla organizacji
• Konsultacje z zespołem FEER w trakcie procesu

3. Kogo szukamy?
Preferujemy osoby z wykształceniem lub doświadczeniem w dziennikarstwie bądź komunikacji społecznej (także studentów tych kierunków). Liczy się lekkie pióro i chęć pisania o dostępności.

4. Kiedy i gdzie
Tryb: zdalnie
Harmonogram: elastyczny, ustalany indywidualnie

5. Ile czasu zajmuje zaangażowanie?
Ok. 3–5 godzin miesięcznie, w zależności od etapu prac.

6. Co zyskasz?
• Portfolio: realne teksty opublikowane na stronie aktywnej organizacji
• Doświadczenie w komunikacji NGO i contencie społecznym
• Zaświadczenie o wolontariacie
• Kontakt z tematyką dostępności i projektowania inkluzywnego
TXT;

$customFields = [
    ['label' => 'Wykształcenie lub doświadczenie w dziennikarstwie / komunikacji społecznej (kierunek, redakcja, projekty)', 'type' => 'textarea', 'required' => 1],
    ['label' => 'Link do portfolio lub przykładowych tekstów', 'type' => 'text', 'required' => 0],
    ['label' => 'Dlaczego chcesz pisać dla FEER?', 'type' => 'textarea', 'required' => 0],
    ['label' => 'Preferowany kontakt (e-mail / komunikator — jaki?)', 'type' => 'text', 'required' => 0],
];

$data = [
    'title'          => $TITLE,
    'content'        => $content,
    'status'         => $publish ? 'active' : 'draft',
    'custom_fields'  => $customFields,
    'max_candidates' => null,
];

$existing = db_one("SELECT id, status FROM volunteer_offers WHERE title = ? ORDER BY id LIMIT 1", [$TITLE]);

echo ($existing ? "Aktualizacja ogłoszenia #{$existing['id']}" : 'Nowe ogłoszenie') . ": {$TITLE}\n";
echo 'Status: ' . $data['status'] . ($existing && !$publish ? " (istniejące: zostaje {$existing['status']})" : '') . "\n";
echo 'Pola formularza: ' . count($customFields) . "\n";

if (!$apply) { echo "\nPodgląd — nic nie zapisano. Dodaj --apply.\n"; exit(0); }

$mgr = new VolunteerModuleManager();
if ($existing) {
    $id  = (int)$existing['id'];
    $upd = $data;
    if (!$publish) unset($upd['status']); // ponowny seed nie cofa opublikowanego ogłoszenia do szkicu
    $mgr->updateOffer($id, $upd);
} else {
    $id = $mgr->createOffer($data);
}

$row = db_one("SELECT id, slug, status FROM volunteer_offers WHERE id = ?", [$id]);
echo "\nZapisano #{$row['id']} ({$row['status']}).\n";
echo "Edycja:     /contracts/rekrutacja/add.php?id={$row['id']}\n";
echo "Formularz:  /contracts/rekrutacja/apply.php?id={$row['id']}\n";
