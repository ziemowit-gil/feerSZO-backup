<?php
/**
 * modules/user_merge/lib/userMergeResolve.php — konto zarchiwizowane przy scalaniu → konto główne.
 *
 * Dla logowania (np. SSO Microsoft szuka konta po e-mailu LUB microsoft_id): gdy trafi w archiwum
 * (users.merged_into_id), przechodzi do konta głównego. Bez odwołań do merged_into_id w SQL — działa także na bazie,
 * na której samonaprawa schematu jeszcze nie dodała kolumny (wtedy klucza po prostu nie ma w wierszu).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

function userMergeFollow(?array $u): ?array {
    for ($hop = 0; $u && !empty($u['merged_into_id']) && $hop < 5; $hop++) {
        $next = db_one("SELECT * FROM users WHERE id = ?", [(int)$u['merged_into_id']]);
        if (!$next) break;
        $u = $next;
    }
    return $u;
}
