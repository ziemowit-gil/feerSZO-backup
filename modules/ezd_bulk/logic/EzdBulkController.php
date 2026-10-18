<?php
/**
 * modules/ezd_bulk/logic/EzdBulkController.php — akcje masowe na liście koszulek (trasy w router.php).
 * Każda akcja: POST + CSRF (router), weryfikacja danych, wykonanie przez ezdBulk*(), podsumowanie
 * zapamiętane w sesji i powrót na listę z tymi samymi filtrami.
 */

require_once __DIR__ . '/ezdBulk.php';

final class EzdBulkController
{
    private function userId(): int { return (int)(current_user()['id'] ?? 0); }

    private function back(): ?string { return Router::redirect(ezdBulkBackUrl((string)($_POST['back'] ?? ''))); }

    /** Zaznaczone identyfikatory albo komunikat i powrót. */
    private function ids(): ?array {
        $ids = ezdBulkIds($_POST['ids'] ?? []);
        if (!$ids) { flash_set('warning', 'Zaznacz przynajmniej jedną koszulkę.'); return null; }
        return $ids;
    }

    public function close(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        $on = trim((string)($_POST['closed_on'] ?? ''));
        if ($on === '') $on = date('Y-m-d');
        $d = DateTime::createFromFormat('!Y-m-d', $on);
        if (!$d || $d->format('Y-m-d') !== $on || $on > date('Y-m-d')) {
            flash_set('danger', 'Data zamknięcia musi być poprawną datą, nie późniejszą niż dziś.');
            return $this->back();
        }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        ezdBulkRemember('Zamknięcie zbiorcze', ezdBulkClose($ids, $this->userId(), $note, $on));
        return $this->back();
    }

    public function owner(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        $raw = (string)($_POST['owner_id'] ?? '');
        $ownerId = $raw === 'none' ? null : (int)$raw;
        if ($ownerId !== null && !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [$ownerId])) {
            flash_set('danger', 'Wybierz prowadzącego z listy aktywnych użytkowników.');
            return $this->back();
        }
        ezdBulkRemember('Zmiana prowadzącego', ezdBulkOwner($ids, $this->userId(), $ownerId));
        return $this->back();
    }

    public function deadline(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        $deadline = !empty($_POST['clear']) ? '' : trim((string)($_POST['deadline'] ?? ''));
        if ($deadline !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $deadline);
            if (!$d || $d->format('Y-m-d') !== $deadline) { flash_set('danger', 'Podaj poprawną datę terminu.'); return $this->back(); }
        } elseif (empty($_POST['clear'])) {
            flash_set('danger', 'Podaj datę terminu albo zaznacz „Usuń termin”.'); return $this->back();
        }
        ezdBulkRemember($deadline === '' ? 'Usunięcie terminu' : 'Ustawienie terminu ' . $deadline, ezdBulkDeadline($ids, $this->userId(), $deadline));
        return $this->back();
    }

    public function urgent(): ?string {
        if (!($ids = $this->ids())) return $this->back();
        $on = ($_POST['mode'] ?? '') === 'on';
        ezdBulkRemember($on ? 'Oznaczenie jako pilne' : 'Zdjęcie oznaczenia pilne', ezdBulkUrgent($ids, $this->userId(), $on));
        return $this->back();
    }
}
