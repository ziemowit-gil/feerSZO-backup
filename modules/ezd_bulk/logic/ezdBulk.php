<?php
/**
 * modules/ezd_bulk/logic/ezdBulk.php — akcje masowe na koszulkach zaznaczonych na liście /ezd/sprawy/index.php.
 *
 * Każda akcja przechodzi koszulkę po koszulce przez TE SAME funkcje co akcja pojedyncza (includes/ezd.php):
 * uprawnienia (ezd_sprawa_access), blokady (zamknięta, ciągła) i dziennik ezd_log są wspólne — tu nie ma
 * własnych reguł. Wynik każdej akcji: lista zrobionych + lista pominiętych z powodem, pokazywana raz
 * nad listą (view/result.php).
 */
require_once dirname(__DIR__, 3) . '/includes/ezd.php';

/** Adres trasy modułu (lista koszulek nie jest wywoływana przez router, więc bez route()). */
function ezdBulkUrl(string $path): string {
    return rtrim(APP_URL, '/') . '/modules/ezd_bulk/router.php?_route=' . rawurlencode('/' . ltrim($path, '/'));
}

/** Identyfikatory z formularza: liczby dodatnie, bez powtórzeń. */
function ezdBulkIds($raw): array {
    return array_values(array_unique(array_filter(array_map('intval', (array)$raw), fn($v) => $v > 0)));
}

/** Powrót na listę z tymi samymi filtrami — tylko do /ezd/sprawy/index.php (bez otwartego przekierowania). */
function ezdBulkBackUrl(string $back): string {
    $list  = rtrim(APP_URL, '/') . '/ezd/sprawy/index.php';
    $path  = (string)parse_url($back, PHP_URL_PATH);
    $query = (string)parse_url($back, PHP_URL_QUERY);
    if (!str_ends_with($path, '/ezd/sprawy/index.php')) return $list;
    return $list . ($query !== '' ? '?' . $query : '');
}

/**
 * Wykonuje $fn dla każdej koszulki, do której użytkownik ma jakikolwiek dostęp.
 * $fn zwraca null (wykonano) albo powód pominięcia; RuntimeException z funkcji EZD to też pominięcie.
 *
 * @param callable(array):?string $fn
 * @return array{done: list<string>, skipped: list<array{label:string,title:string,reason:string}>}
 */
function ezdBulkEach(array $ids, int $userId, callable $fn): array {
    $res = ['done' => [], 'skipped' => []];
    foreach ($ids as $sid) {
        $sp = ezd_sprawa_get((int)$sid);
        if (!$sp) { $res['skipped'][] = ['label' => '#' . $sid, 'title' => '', 'reason' => 'koszulka nie istnieje']; continue; }
        $label = (string)($sp['znak_sprawy'] ?: '#' . $sid);
        if (!ezd_sprawa_access($sp, $userId)) { $res['skipped'][] = ['label' => $label, 'title' => (string)$sp['title'], 'reason' => 'brak dostępu']; continue; }
        try {
            $why = $fn($sp);
        } catch (\RuntimeException $e) {
            $why = rtrim(mb_strtolower(mb_substr($e->getMessage(), 0, 1)) . mb_substr($e->getMessage(), 1), '.');
        }
        if ($why === null) $res['done'][] = $label;
        else $res['skipped'][] = ['label' => $label, 'title' => (string)$sp['title'], 'reason' => $why];
    }
    return $res;
}

/** Zapamiętuje podsumowanie do pokazania raz nad listą. */
function ezdBulkRemember(string $verb, array $res): void {
    auth_start();
    $_SESSION['ezd_bulk_result'] = ['verb' => $verb] + $res;
}

/** Odbiera (i czyści) podsumowanie ostatniej akcji masowej. */
function ezdBulkTakeResult(): ?array {
    auth_start();
    $r = $_SESSION['ezd_bulk_result'] ?? null;
    unset($_SESSION['ezd_bulk_result']);
    return is_array($r) ? $r : null;
}

/**
 * Zamknięcie zbiorcze: ten sam warunek co pojedynczy przycisk „Zamknij koszulkę” (ezd_sprawa_close_blocker)
 * i ta sama funkcja zapisu (ezd_sprawa_close — status, closed_at, dekretacje, dziennik).
 */
function ezdBulkClose(array $ids, int $userId, string $note, string $closedOn): array {
    $reason = trim($note) !== '' ? trim($note) : 'Zamknięcie zbiorcze z listy koszulek.';
    return ezdBulkEach($ids, $userId, function (array $sp) use ($userId, $reason, $closedOn) {
        if ($why = ezd_sprawa_close_blocker($sp, $userId)) return $why;
        ezd_sprawa_close((int)$sp['id'], $userId, $reason, $closedOn);
        return null;
    });
}
