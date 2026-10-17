<?php
/**
 * modules/ti/logic/nabor/naborDelete.php — usuwanie zgłoszenia z naboru razem z tym, co po sobie zostawiło.
 *
 * Usuwane: zgłoszenie (kaskadą: zgody, historia, wiadomości, dokumenty), pliki z UPLOAD_DIR/ti_nabor/<id>,
 * aktywności CRM „Nabór TI <nr>”, konto kandydata bez innych zgłoszeń, a po przyjęciu — zapis do grupy oraz
 * uczestnik TI z kontem panelu, o ile założył go nabór i nie ma żadnych innych śladów (inne grupy, obecności,
 * rozliczenia, wpłaty, oceny). Uczestnik z historią zostaje — tylko zapis do tej grupy przechodzi w „inactive”.
 * Koszulka EZD NIE jest kasowana (rejestr kancelaryjny) — zostaje zamknięta i ukryta z adnotacją.
 * Kontakt CRM (opiekun) zostaje, chyba że założył go nabór i nie ma innych aktywności.
 * Każde usunięcie trafia do audit_logs (nabor.submission_deleted). Zwraca raport: lista wykonanych kroków.
 */
require_once __DIR__ . '/nabor.php';

function naborDeleteSubmission(int $sid, int $uid, string $byName, string $reason = ''): array {
    $s = naborSubmissionGet($sid);
    if (!$s) throw new InvalidArgumentException('Zgłoszenie nie istnieje.');
    $rep = [];
    $nr  = $s['registration_number'];

    // Uczestnik TI i zapis do grupy (tylko po przyjęciu)
    if ($s['client_id']) {
        $cid = (int)$s['client_id'];
        $mine = str_contains((string)(db_one("SELECT notes FROM k30_clients WHERE id=?", [$cid])['notes'] ?? ''), 'naboru ' . $nr);
        $deleted = false;
        if ($mine && naborClientIsClean($cid, (int)$s['course_id'])) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach (['k30_ti_enrollments', 'k30_ti_student_accounts'] as $t) db_exec("DELETE FROM $t WHERE client_id=?", [$cid]);
                try { db_exec("DELETE FROM payment_portal_users WHERE participant_id=?", [$cid]); } catch (\Throwable $e) {}
                db_exec("DELETE FROM k30_clients WHERE id=?", [$cid]);   // klucze obce z RESTRICT → wyjątek → zostawiamy uczestnika
                $pdo->commit();
                $deleted = true;
                $rep[] = 'usunięto uczestnika TI #' . $cid . ' (z kontem panelu i zapisem do grupy)';
            } catch (\Throwable $e) {
                $pdo->rollBack();
            }
        }
        if (!$deleted && $s['course_id']) {
            db_exec("UPDATE k30_ti_enrollments SET status='inactive', end_date=? WHERE client_id=? AND course_id=? AND status='active'", [date('Y-m-d'), $cid, $s['course_id']]);
            $rep[] = 'uczestnik TI #' . $cid . ' zostaje (ma inne dane) — zapis do grupy wycofany';
        }
    }

    // CRM: aktywności zgłoszenia; kontakt — tylko gdy założony przez nabór i bez innych aktywności
    if ($s['crm_contact_id']) {
        try {
            $cc = (int)$s['crm_contact_id'];
            db_exec("DELETE FROM crm_activities WHERE contact_id=? AND title LIKE ?", [$cc, 'Nabór TI ' . $nr . ' %']);
            $rep[] = 'usunięto aktywności CRM zgłoszenia';
            $other = (int)db_one("SELECT COUNT(*) n FROM crm_activities WHERE contact_id=?", [$cc])['n']
                   + (int)db_one("SELECT COUNT(*) n FROM ti_nabor_submissions WHERE crm_contact_id=? AND id<>?", [$cc, $sid])['n'];
            $src = (string)(db_one("SELECT source FROM crm_contacts WHERE id=?", [$cc])['source'] ?? '');
            if ($src === 'ti_nabor' && $other === 0) {
                foreach (['crm_tags', 'crm_group_members'] as $t) { try { db_exec("DELETE FROM $t WHERE contact_id=?", [$cc]); } catch (\Throwable $e) {} }
                db_exec("DELETE FROM crm_contacts WHERE id=?", [$cc]);
                $rep[] = 'usunięto kontakt CRM #' . $cc . ' (założony przez nabór)';
            }
        } catch (\Throwable $e) { $rep[] = 'CRM: ' . $e->getMessage(); }
    }

    // EZD: koszulka zostaje w rejestrze — zamknięta i ukryta
    if ($s['ezd_case_id']) {
        try {
            $note = 'Zgłoszenie ' . $nr . ' usunięte z naboru' . ($reason !== '' ? ': ' . $reason : '') . ' (' . $byName . ').';
            db_exec("UPDATE ezd_sprawy SET status='closed', closed_at=COALESCE(closed_at, datetime('now')), close_reason=?, hidden_at=datetime('now'),
                     hidden_by=?, updated_at=datetime('now') WHERE id=?", [$note, $uid ?: null, $s['ezd_case_id']]);
            if (function_exists('ezd_log') || is_file(dirname(__DIR__, 4) . '/includes/ezd.php')) {
                require_once dirname(__DIR__, 4) . '/includes/ezd.php';
                $logUid = $uid ?: (int)(db_one("SELECT created_by FROM ezd_sprawy WHERE id=?", [$s['ezd_case_id']])['created_by'] ?? 0);
                if ($logUid) ezd_log(null, (int)$s['ezd_case_id'], null, null, $logUid, 'sprawa_close', $note);
            }
            $rep[] = 'koszulka EZD #' . $s['ezd_case_id'] . ' zamknięta i ukryta (bez kasowania)';
        } catch (\Throwable $e) { $rep[] = 'EZD: ' . $e->getMessage(); }
    }

    // Pliki (PDF zgłoszenia, dokumenty kandydata)
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_nabor/' . $sid;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
        $rep[] = 'usunięto pliki zgłoszenia';
    }

    $accId = (int)($s['account_id'] ?? 0);
    db_exec("DELETE FROM ti_nabor_submissions WHERE id=?", [$sid]);   // kaskada: zgody, historia, wiadomości, dokumenty
    $rep[] = 'usunięto zgłoszenie ' . $nr;

    if ($accId && !db_one("SELECT 1 FROM ti_nabor_submissions WHERE account_id=?", [$accId])) {
        db_exec("DELETE FROM ti_nabor_accounts WHERE id=?", [$accId]);
        $rep[] = 'usunięto konto kandydata (nie miało innych zgłoszeń)';
    }

    try {
        require_once dirname(__DIR__, 4) . '/modules/audit_logs/logic/audit_logs.php';
        audit_log('nabor.submission_deleted', ['nr' => $nr, 'id' => $sid, 'by' => $byName, 'reason' => $reason, 'steps' => $rep], $uid ?: null);
    } catch (\Throwable $e) {}
    return $rep;
}

/** Uczestnik bez śladów poza zapisem do $courseId (inne grupy, obecności, rozliczenia, wpłaty, oceny, egzaminy). */
function naborClientIsClean(int $cid, int $courseId): bool {
    if (db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id<>?", [$cid, $courseId])) return false;
    foreach (['k30_ti_attendance', 'k30_ti_payments', 'k30_ti_billing', 'k30_ti_grades', 'k30_ti_test_attempts', 'k30_ti_exam_attempts', 'k30_ti_homework_submissions'] as $t) {
        try { if (db_one("SELECT 1 FROM $t WHERE client_id=? LIMIT 1", [$cid])) return false; } catch (\Throwable $e) {}
    }
    return true;
}
