<?php
declare(strict_types=1);

/**
 * modules/ti_consents/logic/tiConsents.php — „Zgody” w panelu kursanta i rodzica (wzór: eduSky Panel — zgody per umowa, status przy każdej).
 *
 * Zbiera w JEDNO miejsce zgody i akceptacje kursanta, które system już przechowuje:
 *   1. Zapis na zajęcia (nabór): akceptacja regulaminu, zgoda na przetwarzanie danych, wizerunek, marketing, akceptacja warunków uczestnictwa — z daty zapisu,
 *   2. Umowa szkoleniowa TI: czy zawarta (status umowy) i kiedy,
 *   3. Regulaminy i dokumenty obowiązujące kursanta (k30_ti_terms): zaakceptowane (kto i kiedy) albo brak akceptacji aktualnej wersji,
 *   4. Dokumenty do akceptacji wystawione przez kadrę (k30_ti_srequest): zaakceptowane / odmowa / czeka.
 * Tylko ODCZYT — zmiana lub wycofanie zgody idzie przez kontakt z organizatorem (Wiadomości). Bez adresów IP i danych technicznych.
 *
 * Grupa: ['title','subtitle','icon','items'=>[['text','state'=>granted|none|declined|pending,'date','by','hint']]].
 */
final class TiConsents
{
    public const STATE_LABELS = ['granted' => 'Udzielona', 'none' => 'Brak zgody', 'declined' => 'Odmowa', 'pending' => 'Czeka na decyzję'];

    /** Domyślne treści zgód naboru (gdy konfiguracja formularza jest niedostępna). */
    private const NABOR_DEFAULT = [
        'terms'     => 'Akceptuję regulamin zajęć.',
        'gdpr'      => 'Wyrażam zgodę na przetwarzanie danych osobowych w celu rekrutacji i organizacji zajęć.',
        'image'     => 'Zgadzam się na nieodpłatne wykorzystanie wizerunku uczestnika w materiałach informacyjnych organizacji.',
        'marketing' => 'Chcę otrzymywać e-mailem informacje o kolejnych zajęciach i wydarzeniach.',
    ];

    private static function fmt(?string $d): string
    {
        return $d && strtotime($d) ? date('d.m.Y', strtotime($d)) : '';
    }

    /** @return array<int,array> grupy zgód kursanta (client_id z konta kursanta) */
    public static function forClient(int $clientId, ?int $accountId = null): array
    {
        $groups = [];
        foreach ([[self::class, 'nabor'], [self::class, 'contracts'], [self::class, 'terms'], [self::class, 'requests']] as $fn) {
            try {
                foreach ($fn($clientId, $accountId) as $g) if (!empty($g['items'])) $groups[] = $g;
            } catch (\Throwable $e) { error_log('[TiConsents] ' . $fn[1] . ': ' . $e->getMessage()); }
        }
        return $groups;
    }

    /** Zgody z formularza naboru — jedna grupa na zgłoszenie przypisane do kursanta. */
    private static function nabor(int $clientId, ?int $accountId): array
    {
        $subs = db_all("SELECT s.*, c.public_name AS course_name FROM ti_nabor_submissions s LEFT JOIN ti_nabor_courses c ON c.course_id = s.course_id
                        WHERE s.client_id = ? AND COALESCE(s.is_test, 0) = 0 ORDER BY s.created_at DESC", [$clientId]);
        if (!$subs) return [];
        $labels = self::NABOR_DEFAULT;
        $visible = ['terms' => true, 'gdpr' => true, 'image' => true, 'marketing' => true];
        try {
            $f = dirname(__DIR__, 2) . '/ti/logic/nabor/naborForm.php';
            if (is_file($f)) {
                require_once $f;
                if (function_exists('naborForm')) {
                    foreach ((array)(naborForm()['consents'] ?? []) as $k => $c) {
                        if (isset($labels[$k]) && !empty($c['label'])) $labels[$k] = (string)$c['label'];
                        $visible[$k] = !empty($c['visible']);
                    }
                }
            }
        } catch (\Throwable $e) {}
        $out = [];
        foreach ($subs as $s) {
            $cons = db_one("SELECT * FROM ti_nabor_consents WHERE submission_id = ? ORDER BY id DESC LIMIT 1", [(int)$s['id']]);
            $items = [];
            if ($cons) {
                $d = self::fmt((string)$cons['created_at']);
                foreach (['terms' => 'terms_accepted', 'gdpr' => 'gdpr_accepted', 'image' => 'image_consent', 'marketing' => 'marketing_consent'] as $k => $col) {
                    if (empty($visible[$k]) && empty($cons[$col])) continue;   // zgoda nie była pytana w formularzu
                    $on = !empty($cons[$col]);
                    $items[] = ['text' => $labels[$k], 'state' => $on ? 'granted' : 'none', 'date' => $on ? $d : '', 'by' => '', 'hint' => ''];
                }
            }
            if (!empty($s['agreement_accepted_at'])) $items[] = ['text' => 'Zapoznałem(-am) się z warunkami uczestnictwa i potwierdzam miejsce na zajęciach.', 'state' => 'granted', 'date' => self::fmt((string)$s['agreement_accepted_at']), 'by' => '', 'hint' => ''];
            $out[] = ['title' => 'Zapis ' . $s['registration_number'], 'subtitle' => trim((string)$s['participant_name'] . ' · ' . (string)($s['course_name'] ?: 'zajęcia'), ' ·'), 'icon' => 'clipboard-check', 'items' => $items];
        }
        return $out;
    }

    /** Umowy szkoleniowe TI kursanta (bez projektów i anulowanych). */
    private static function contracts(int $clientId, ?int $accountId): array
    {
        require_once dirname(__DIR__, 2) . '/ti_training_contract/logic/trainingContractStore.php';
        require_once dirname(__DIR__, 2) . '/ti_training_contract/logic/trainingContractConsents.php';
        TiTrainingContractStore::migrate();   // UMSZ → SZK: zmiana nazwy tabeli przy pierwszym użyciu
        $rows = db_all("SELECT * FROM umowy_szk WHERE client_id = ? AND COALESCE(is_test, 0) = 0 AND status NOT IN ('projekt', 'anulowana') ORDER BY id DESC", [$clientId]);
        $out = [];
        foreach ($rows as $r) {
            $signed = in_array((string)$r['status'], ['podpisana', 'w realizacji', 'zakończona'], true);
            $state = $signed ? 'granted' : ((string)$r['status'] === 'rozwiązana' ? 'declined' : 'pending');
            $hint = ['do podpisu' => 'Umowa czeka na podpis.', 'rozwiązana' => 'Umowa została rozwiązana.', 'zakończona' => 'Umowa zakończona.'][(string)$r['status']] ?? '';
            $items = [['text' => 'Zawarcie umowy o świadczenie usług szkoleniowych.', 'state' => $state, 'date' => self::fmt((string)($r['data_zawarcia'] ?: '')), 'by' => '', 'hint' => $hint]];
            // „Zgody i dokumenty” umowy SZK (lista prowadzona przez kadrę w karcie umowy) — stan każdej pozycji.
            foreach (TiSzkConsents::list((int)$r['id']) as $z) {
                $items[] = ['text' => (string)$z['title'] . ((int)$z['required'] ? '' : ' (dobrowolna)'), 'state' => $z['state'] === 'granted' ? 'granted' : ($z['state'] === 'declined' ? 'declined' : 'pending'),
                            'date' => self::fmt((string)($z['decided_at'] ?? '')), 'by' => $z['decided_at'] ? 'organizator' : '', 'hint' => (string)$z['note']];
            }
            $out[] = ['title' => 'Umowa ' . ($r['numer_umowy'] ?: ('#' . $r['id'])), 'subtitle' => trim((string)$r['imie_nazwisko'] . ' · ' . (string)$r['temat'], ' ·'), 'icon' => 'file-earmark-text', 'items' => $items];
        }
        return $out;
    }

    /** Regulaminy obowiązujące kursanta: zaakceptowana aktualna wersja albo brak. */
    private static function terms(int $clientId, ?int $accountId): array
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
        ti_terms_migrate();
        $items = [];
        foreach (db_all("SELECT * FROM k30_ti_terms WHERE is_active = 1 ORDER BY type") as $t) {
            $a = db_one("SELECT * FROM k30_ti_terms_accepts WHERE client_id = ? AND term_id = ? AND version >= ? ORDER BY accepted_at DESC LIMIT 1", [$clientId, (int)$t['id'], (int)$t['version']]);
            if (!$a && !ti_term_applies($t, $clientId)) continue;   // nie dotyczy kursanta i niezaakceptowany — pomijamy; zaakceptowany zawsze widać
            $items[] = ['text' => (string)$t['title'], 'state' => $a ? 'granted' : 'none', 'date' => $a ? self::fmt((string)$a['accepted_at']) : '',
                        'by' => $a ? ti_terms_role_label((string)$a['accepted_by_role']) : '', 'hint' => $a ? 'wersja ' . (int)$a['version'] : ''];
        }
        return $items ? [['title' => 'Regulaminy', 'subtitle' => 'Regulaminy usług, z którymi się zapoznałeś(-aś) i które zaakceptowałeś(-aś)', 'icon' => 'shield-check', 'items' => $items]] : [];
    }

    /** Dokumenty do akceptacji od kadry. */
    private static function requests(int $clientId, ?int $accountId): array
    {
        if (!$accountId) return [];
        require_once dirname(__DIR__, 2) . '/ti_student_folder/logic/studentFolder.php';
        TiStudentFolder::migrate();
        $items = [];
        foreach (TiStudentFolder::requests($accountId) as $r) {
            $st = (string)$r['status'];
            $items[] = ['text' => (string)$r['title'], 'state' => $st === 'accepted' ? 'granted' : ($st === 'declined' ? 'declined' : 'pending'), 'date' => self::fmt((string)($r['decided_at'] ?? '')),
                        'by' => '', 'hint' => $st === 'declined' && !empty($r['decline_reason']) ? 'Powód: ' . $r['decline_reason'] : ''];
        }
        return $items ? [['title' => 'Dokumenty do akceptacji', 'subtitle' => 'Dokumenty wystawione przez organizatora', 'icon' => 'folder-check', 'items' => $items]] : [];
    }
}
