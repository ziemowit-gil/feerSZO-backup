<?php
/**
 * modules/it_portal/lib/itPortalInstrukcjeSeed.php — artykuły w usługach Portalu Usług IT, które prowadzą do długich instrukcji
 * na osobnych stronach /uslugi-it/instrukcje/{slug} (public/instrukcja.php, treść w public/instrukcje/{slug}/).
 *
 * Polecenie 2026-11-24: instrukcja „Pliki w Nextcloud krok po kroku” (autor Ziemowit Gil) w SZO pod /uslugi-it, tak jak na
 * ziemowitgil.pl/misc/instrukcje/nextcloudhosted: strona ze spisem rozdziałów i PDF. Artykuł w „Chmurze plików” to wejście do niej
 * (przyciski „Otwórz instrukcję” i „Pobierz PDF”), dzięki niemu instrukcję znajduje też wyszukiwarka portalu.
 *
 * Ten sam adres artykułu = nadpisanie treści; przypięcie, publikacja i „Zobacz też” zostają (itPortalCloudArticleSave).
 * Usługi, której brakuje, nie zakłada (raport „brak usługi”). Bezpieczne do ponownego uruchomienia.
 */

/** @return array<string, array{cat:string, art:string, title:string, keywords:string, group:string, sort:int, html:string}> */
function itPortalInstrukcjeDefs(): array {
    return [
        'nextcloud-pliki' => [
            'cat' => 'chmura-plikow', 'art' => 'pliki-krok-po-kroku', 'group' => 'Pierwsze kroki', 'sort' => 14,
            'title' => 'Pliki w Nextcloud krok po kroku (instrukcja z PDF)',
            'keywords' => 'instrukcja, pdf, krok po kroku, dla początkujących, pliki, nextcloud, logowanie, dodawanie plików, wysyłanie, katalog, folder, przenoszenie, kopiowanie, zmiana nazwy, udostępnianie, grupa, zespół, link, usuwanie, kosz, przywracanie, wersje, synchronizacja, telefon, komputer, problemy',
            'html' => '<p>Pełna instrukcja dla początkujących w 12 rozdziałach: logowanie, dodawanie plików, katalogi, porządkowanie, '
                    . 'udostępnianie osobom, grupom i zespołom, lista udostępnień, usuwanie, kosz, wcześniejsze wersje, synchronizacja '
                    . 'z telefonem i komputerem oraz najczęstsze problemy. Ma zrzuty ekranu i boczny spis rozdziałów. Do druku jest wersja PDF.</p>'
                    . '<p>Zrzuty zrobiono w innej instalacji Nextcloud, więc kolory i logo mogą wyglądać inaczej niż w chmurze Fundacji. '
                    . 'Przyciski i menu nazywają się tak samo. Autor instrukcji: Ziemowit Gil.</p>',
        ],
    ];
}

/** Wgrywa artykuły-wejścia do instrukcji. $only = lista slugów instrukcji (pusta = wszystkie). @return list<string> raport */
function itPortalInstrukcjeApply(string $app, bool $dryRun = false, array $only = []): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    require_once __DIR__ . '/itPortalCloudSeed.php';   // itPortalCloudArticleSave: zapis artykułu z zachowaniem przypięcia i powiązań
    itPortalMigrate();
    $app = rtrim($app, '/');
    $pub = dirname(__DIR__) . '/public/instrukcje/';
    $rep = [];
    foreach (itPortalInstrukcjeDefs() as $slug => $d) {
        if ($only && !in_array($slug, $only, true)) continue;
        $cat = db_one("SELECT id FROM itp_categories WHERE slug = ?", [$d['cat']]);
        if (!$cat) { $rep[] = $slug . ': brak usługi ' . $d['cat']; continue; }
        $meta = require $pub . $slug . '/meta.php';
        $items = [['label' => 'Otwórz instrukcję', 'url' => $app . '/uslugi-it/instrukcje/' . $slug, 'note' => 'Strona ze spisem rozdziałów']];
        if (($meta['pdf'] ?? '') !== '' && is_file($pub . $slug . '/' . $meta['pdf'])) {
            $mb = number_format(filesize($pub . $slug . '/' . $meta['pdf']) / 1048576, 1, ',', '');
            $items[] = ['label' => 'Pobierz PDF', 'url' => $app . '/modules/it_portal/public/instrukcje/' . $slug . '/' . $meta['pdf'], 'note' => 'Do druku, ' . $mb . ' MB'];
        }
        $blocks = [['type' => 'text', 'html' => $d['html']], ['type' => 'buttons', 'title' => $meta['title'], 'items' => $items]];
        $rep[] = $slug . ': ' . itPortalCloudArticleSave((int)$cat['id'], $d['art'], [$d['title'], $d['keywords'], $blocks, false, $d['sort']], $dryRun, $d['cat']);
        if (!$dryRun) db_exec("UPDATE itp_articles SET menu_group = ? WHERE category_id = ? AND slug = ?", [$d['group'], (int)$cat['id'], $d['art']]);
    }
    return $rep;
}
