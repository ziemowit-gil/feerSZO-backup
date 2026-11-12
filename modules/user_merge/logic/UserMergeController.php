<?php
/**
 * modules/user_merge/logic/UserMergeController.php — akcje routera scalania kont.
 *
 * POST /scal — formularz z okna porównania (CSRF sprawdza Router). Wymaga administratora i ważnej sesji IKA — tak jak
 * lista kont (admin/users.php). Wynik (bez hasła) trafia jednorazowo do sesji i jest pokazywany nad listą kont.
 */

require_once __DIR__ . '/UserMergeService.php';

final class UserMergeController
{
    private const RESULT_KEY = 'user_merge_result';

    private static function back(string $query = ''): string
    {
        return APP_URL . '/tozsamosc/uzytkownicy.php' . ($query !== '' ? '?' . $query : '');
    }

    public function merge(): ?string
    {
        if (!is_admin()) { http_response_code(403); return 'Brak dostępu.'; }
        if (!ika_ok(self::back(), 3600)) {
            flash_set('warning', 'Sesja kodu IKA wygasła — scalenie NIE zostało wykonane. Potwierdź kod IKA i powtórz operację.');
            header('Location: ' . self::back()); return null;
        }
        $members = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['members'] ?? '')))));
        $primary = (int)($_POST['primary'] ?? 0);
        // Grupa > 2: konta do scalenia zaznacza admin (dups_explicit); para: drugie konto wynika z wyboru głównego.
        $dups = !empty($_POST['dups_explicit'])
            ? array_map('intval', (array)($_POST['dups'] ?? []))
            : (count($members) === 2 ? array_values(array_diff($members, [$primary])) : []);

        $res = (new UserMergeService())->merge($primary, $dups, [
            'members'      => $members,
            'sync_m365'    => !empty($_POST['sync_m365']),
            'confirm_same' => !empty($_POST['confirm_same']),
            'phone'        => trim((string)($_POST['phone'] ?? '')),
        ], (int)current_user()['id']);

        auth_start();
        if (!$res['ok']) {
            flash_set('danger', $res['error']);
            header('Location: ' . self::back('duplikaty=1' . ($primary ? '&scal=' . $primary : ''))); return null;
        }
        $_SESSION[self::RESULT_KEY] = $res;
        header('Location: ' . self::back(http_build_query(['q' => $res['primary']['email']]))); return null;
    }

    /** Wynik ostatniego scalenia (jednorazowo) — dla listy kont. */
    public static function takeResult(): ?array
    {
        auth_start();
        $r = $_SESSION[self::RESULT_KEY] ?? null;
        unset($_SESSION[self::RESULT_KEY]);
        return is_array($r) ? $r : null;
    }
}
