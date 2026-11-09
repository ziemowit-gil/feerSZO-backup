<?php
/**
 * modules/edok_bulk/logic/EdokBulkController.php — akcje zbiorcze na liście EODoK (trasy w router.php).
 * Każda akcja: POST + CSRF (router), weryfikacja danych, wykonanie przez edokBulk*(), podsumowanie
 * zapamiętane w sesji i powrót na listę z tymi samymi filtrami. ZIP wysyłany jest od razu jako plik.
 */

require_once __DIR__ . '/edokBulk.php';

final class EdokBulkController
{
    private function userId(): int { return (int)(current_user()['id'] ?? 0); }

    private function back(): ?string { return Router::redirect(edokBulkBackUrl((string)($_POST['back'] ?? ''))); }

    /** Zaznaczone identyfikatory albo komunikat i null. */
    private function ids(): ?array {
        $ids = edokBulkIds($_POST['ids'] ?? []);
        if (!$ids) { flash_set('warning', 'Zaznacz przynajmniej jeden dokument.'); return null; }
        return $ids;
    }

    /** Akceptacja zaznaczonych jednym PIN-em (etapy, do których użytkownik ma rolę). */
    public function sign(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        if (empty($_POST['confirm'])) {
            flash_set('danger', 'Potwierdź, że zweryfikowałeś(-aś) każdy z zaznaczonych dokumentów.');
            return $this->back();
        }
        @set_time_limit(300);
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
        $res = edokBulkSign($ids, $this->userId(), trim((string)($_POST['pin'] ?? '')), $notes);
        if ($res['error'] !== null) {
            flash_set('danger', 'Nic nie zaakceptowano: ' . $res['error']);
            return $this->back();
        }
        unset($res['error']);
        edokBulkRemember('Akceptacja zbiorcza jednym PIN-em', $res);
        return $this->back();
    }

    /** PDF-y zaznaczonych w jednym ZIP — plik do pobrania; lista zostaje (podsumowanie tylko przy braku pliku). */
    public function zip(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        $res = edokBulkZip($ids);
        if ($res['zip'] === null) {
            unset($res['zip']);
            edokBulkRemember('Pobranie PDF-ów (ZIP)', $res);
            return $this->back();
        }
        $name = 'EODoK_dokumenty_' . date('Y-m-d_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($res['zip']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($res['zip']);
        @unlink($res['zip']);
        return null;
    }

    /** Oznaczenie zaznaczonych zaakceptowanych wydatków jako „Opłacony”. */
    public function paid(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        if (!edokBulkCanPay()) {
            flash_set('danger', 'Status płatności zmienia administrator albo osoba zatwierdzająca do zapłaty.');
            return $this->back();
        }
        $on = trim((string)($_POST['paid_on'] ?? ''));
        $d  = DateTime::createFromFormat('!Y-m-d', $on);
        if (!$d || $d->format('Y-m-d') !== $on || $on > date('Y-m-d')) {
            flash_set('danger', 'Data zapłaty musi być poprawną datą, nie późniejszą niż dziś.');
            return $this->back();
        }
        $forma = (string)($_POST['forma'] ?? '');
        if (!isset(EDOK_FORMY_ZAPLATY[$forma])) {
            flash_set('danger', 'Wybierz formę zapłaty.');
            return $this->back();
        }
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
        edokBulkRemember('Oznaczenie jako opłacone', edokBulkMarkPaid($ids, $on, $forma, $notes));
        return $this->back();
    }
}
