<?php
/**
 * modules/it_portal/lib/itPortalEzdKopieSeed.php — artykuł „Kopie dokumentów EZD w chmurze (zamiast SharePointa)” w usłudze „EZD”
 * (/uslugi-it/uslugi/ezd/kopie-dokumentow-w-chmurze). Polecenie 2026-11-14: „wypchnij to do pomocy IT” — po zmianie
 * „kopie dokumentów EZD na FTP i w Nextcloud (konto ezdmigracja) zamiast SharePointa” i migracji z SharePointa
 * (commity c32e08ad3, ac0409ba2, 3948ca899; modules/ezdStorage).
 * Brak usługi „EZD” → artykuł trafia do „Chmury plików”. Uruchamia migracja '2026-11_it_portal_ezd_kopie_article'.
 */

const ITP_EZDKOPIE_RELATED = [['chmura-plikow', 'nextcloud'], ['chmura-plikow', 'lista-plikow']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalEzdKopieApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];

    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'ezd'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'chmura-plikow'");
    if (!$cat) { $rep['category'] = 'brak usługi „EZD” i „Chmura plików”'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];

    $rel = [];
    foreach (ITP_EZDKOPIE_RELATED as [$cs, $as]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $slug = 'kopie-dokumentow-w-chmurze';
    [$title, $keywords, $blocks] = itPortalEzdKopieArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}";
    if ($dryRun) return $rep;

    $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
    $related = array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), array_keys($rel))));
    $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0,
        'sort_order' => $sort, 'related' => $related], $old ? (int)$old['id'] : 0);

    foreach ($rel as $rid => $r) {
        $ids = itPortalIds($r['related']);
        if (in_array($id, $ids, true)) continue;
        $ids[] = $id;
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($ids))), $rid]);
        $rep['linked'][] = (string)$r['title'];
    }
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalEzdKopieArticle(string $app): array {
    $ezd = $app . '/ezd/index.php';
    $hd  = $app . '/helpdesk/new.php';
    $adm = $app . '/modules/ezdStorage/router.php?_route=/ezdkopie';
    return ['Kopie dokumentów EZD w chmurze (zamiast SharePointa)',
        'ezd, wirtualne biurko, kopie dokumentów, kopia, archiwum, nextcloud, chmura, folder ezd, sharepoint, ftp, serwer archiwum, '
        . 'migracja, office online, konwertuj na pdf, word online, gdzie są dokumenty, ezdmigracja, koszulka, jrwa',
        [
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Od 14 listopada 2026 kopie dokumentów z EZD trafiają do chmury Fundacji (Nextcloud) '
                . 'i na serwer archiwum, a nie na SharePoint.</strong> Oryginały zostają w SZO. W <a href="' . $ezd . '">Wirtualnym biurku</a> pracujesz tak jak dotąd.</p>'],

            ['type' => 'text', 'html' => '<h2>Co się zmienia w codziennej pracy</h2><ul>'
                . '<li>Wgrywanie, pobieranie, podpisywanie, druk koszulki i Spinacz działają bez zmian.</li>'
                . '<li>Kopia każdego zapisanego dokumentu powstaje sama. Nic nie musisz klikać.</li>'
                . '<li>Gdy chmura albo serwer archiwum chwilowo nie działa, dokument i tak zapisze się w EZD. Kopię system dośle później.</li>'
                . '</ul>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>„Otwórz w Office Online” i „Konwertuj na PDF” korzystają jeszcze z Microsoft 365.</strong> '
                . 'Po wyłączeniu Microsoft 365 (20 listopada 2026) przestaną działać. Wtedy pobierz dokument Word lub Excel, popraw go na komputerze '
                . 'i wgraj do koszulki jako nową wersję.</p>'],

            ['type' => 'text', 'html' => '<h2>Gdzie są kopie</h2>'
                . '<p>W chmurze Fundacji, w folderze <strong>EZD</strong> konta technicznego. Układ folderów jest taki sam jak dawniej na SharePoincie: '
                . '<code>cases</code> → symbol JRWA → koszulka (tytuł, data i znak) → pliki.</p>'
                . '<p>Gdy w koszulce jest kilka plików o tej samej nazwie (kolejne wersje), najnowszy ma zwykłą nazwę, a starsze mają dopisany numer '
                . 'w nawiasie kwadratowym, na przykład <code>Pismo [123].pdf</code>.</p>'
                . '<p>Folder EZD widzą tylko osoby, którym udostępnił go administrator. Jeśli potrzebujesz dostępu, napisz do <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

            ['type' => 'callout', 'kind' => 'important', 'html' => '<p><strong>Pracuj zawsze na dokumentach w EZD, nie w folderze EZD w chmurze.</strong> '
                . 'Zmiany zrobione w chmurze nie wracają do EZD, a nowa wersja dokumentu z EZD nadpisze kopię w chmurze. '
                . 'Nie usuwaj plików z tego folderu: to kopia zapasowa, z której EZD odtwarza brakujące pliki.</p>'],

            ['type' => 'text', 'html' => '<h2>Dokumenty z SharePointa</h2>'
                . '<p>Pliki, które EZD wysłało wcześniej na SharePoint, przenosimy do chmury automatycznie, pod tę samą ścieżkę, jaką miały na SharePoincie. '
                . 'Przeniesienie trwa w tle. Nic nie musisz robić.</p>'],

            ['type' => 'text', 'html' => '<h2>Dla administratora</h2><ul>'
                . '<li>Ustawienia są w <a href="' . $adm . '">Kopiach dokumentów EZD</a> (Ustawienia modułu Wirtualne biurko → „Ustawienia kopii”): '
                . 'stan kopii i ostatnie błędy, konto w chmurze, migracja z SharePointa i dane serwera FTP z przyciskiem „Sprawdź FTP”.</li>'
                . '<li>Bez serwera FTP kopie idą tylko do chmury.</li>'
                . '<li>Niedostępny serwer i odtworzenie pliku zapisują się w dzienniku zdarzeń (wpisy <code>ezd.store.*</code>).</li>'
                . '</ul>'],
        ]];
}
