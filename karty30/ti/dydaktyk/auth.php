<?php
/**
 * Panel dydaktyka TI — autoryzacja.
 *
 * Dydaktyk = zalogowany użytkownik SZO będący doradcą TyfloKonsultacji
 * (k30_consultant=1) lub mający dostęp do modułu Karty 30 / administrator.
 * Logowanie odbywa się jak do całego SZO (wspólna sesja, wspólny CSRF) —
 * brak osobnego ekranu logowania.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

/**
 * Wymaga zalogowanego doradcy TI. Przy braku logowania przekierowuje do
 * logowania SZO; przy braku roli doradcy zwraca 403. Zwraca rekord użytkownika.
 */
function dyd_require(): array {
    require_login();                       // logowanie jak do SZO
    if (!k30_is_consultant()) {            // doradca / dostęp K30 / admin
        http_response_code(403);
        $KP_TITLE = 'Brak dostępu — Panel dydaktyka';
        include __DIR__ . '/../kursant/_layout_head.php';
        echo '<main id="main" class="container py-5" style="max-width:560px">'
           . '<div class="card border-0 shadow-sm"><div class="card-body p-4 text-center">'
           . '<i class="bi bi-shield-lock fs-1 text-warning"></i>'
           . '<h1 class="h4 fw-bold mt-3">Brak dostępu</h1>'
           . '<p class="text-body-secondary mb-3">Panel dydaktyka jest dostępny wyłącznie dla doradców TyfloKonsultacji.</p>'
           . '<a href="' . h(APP_URL) . '" class="btn btn-primary"><i class="bi bi-house me-1"></i>Wróć do SZO</a>'
           . '</div></div></main>';
        include __DIR__ . '/../kursant/_layout_foot.php';
        exit;
    }
    return current_user();
}

/** Czy użytkownik jest pracownikiem K30 (admin / zapis Karty 30) — widzi wszystkie kursy. */
function dyd_is_staff(): bool {
    return is_admin() || can_write('karty30');
}

/** Czy dany dydaktyk może zarządzać kursem (własny kurs lub pracownik K30). */
function dyd_owns_course(int $uid, int $course_id): bool {
    if (!$course_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_course($uid, $course_id);
}

/** Czy dany dydaktyk może zarządzać lekcją (jej kurs jest jego — lub pracownik K30). */
function dyd_owns_session(int $uid, int $session_id): bool {
    if (!$session_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_session($uid, $session_id);
}

/** Kursy, którymi dydaktyk może zarządzać (własne; pracownik K30 — wszystkie). */
function dyd_courses(int $uid): array {
    return dyd_is_staff() ? k30_ti_courses(false) : k30_ti_instructor_courses($uid, false);
}
