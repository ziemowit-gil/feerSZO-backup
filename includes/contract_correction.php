<?php
/**
 * includes/contract_correction.php
 * Audyt wewnętrzny umów — flaga "do uzupełnienia" (needs_correction).
 *
 * Flaga jest ORTOGONALNA do STATUS_TRANSITIONS — nie zastępuje statusu umowy,
 * pozwala wracać do poprawki wielokrotnie bez gubienia pozycji w workflow.
 * Historia poprawek nie ma osobnej tabeli — korzysta z istniejącego
 * contract_audit_log / log_contract_action() (includes/approval.php).
 */

final class ContractCorrectionValidator
{
    /** @return string[] błędy walidacji, [] gdy dane poprawne */
    public static function validateMarkRequest(array $data): array
    {
        $errors = [];
        $reason = trim((string)($data['correction_reason'] ?? ''));
        if ($reason === '') {
            $errors[] = 'Powód korekty jest wymagany — opisz, co koordynator/audytor powinien uzupełnić.';
        } elseif (mb_strlen($reason) < 5) {
            $errors[] = 'Powód korekty jest zbyt krótki — podaj konkretny opis braku.';
        }
        return $errors;
    }
}

/**
 * Typy umów, dla których zgłoszenie "do poprawy" zapisuje dodatkowo wolną
 * notatkę roboczą (kolumna notatka_do_realizacji — istnieje tylko na tych
 * dwóch tabelach, patrz includes/zlecenie_schema.php, wolontariat_schema.php).
 */
const CORRECTION_NOTATKA_TYPES = ['zlecenie', 'wolontariat'];

final class ContractCorrectionService
{
    /**
     * Oznacza umowę jako wymagającą korekty. Rzuca InvalidArgumentException
     * gdy powód pusty — walidator w warstwie wywołującej powinien to przechwycić
     * wcześniej i wyświetlić błąd formularza; wyjątek tu jest ostatnią linią obrony
     * (broni przed obejściem walidatora z innej ścieżki wejścia, np. API).
     */
    public static function markForCorrection(string $type, int $contractId, string $reason, int $byUserId, ?string $notatkaRealizacji = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('correction_reason jest wymagany przy needs_correction=1.');
        }

        $table = table_for_type($type);
        $now = date('Y-m-d H:i:s');

        $update = [
            'needs_correction'        => 1,
            'correction_reason'       => $reason,
            'correction_requested_by' => $byUserId,
            'correction_requested_at' => $now,
            'correction_resolved_at'  => null,
        ];
        if (in_array($type, CORRECTION_NOTATKA_TYPES, true)) {
            $notatkaRealizacji = trim((string)$notatkaRealizacji);
            $update['notatka_do_realizacji'] = $notatkaRealizacji !== '' ? $notatkaRealizacji : null;
        }

        db_update($table, $update, $contractId);

        log_contract_action($type, $contractId, $byUserId, 'mark_correction', $reason);
    }

    /** Zdejmuje flagę po wprowadzeniu poprawek — historia zostaje w contract_audit_log. */
    public static function resolveCorrection(string $type, int $contractId, int $byUserId, string $note = ''): void
    {
        $table = table_for_type($type);

        $update = [
            'needs_correction'       => 0,
            'correction_resolved_at' => date('Y-m-d H:i:s'),
        ];
        if (in_array($type, CORRECTION_NOTATKA_TYPES, true)) {
            $update['notatka_do_realizacji'] = null;
        }

        db_update($table, $update, $contractId);

        log_contract_action($type, $contractId, $byUserId, 'resolve_correction', $note ?: 'Poprawki wprowadzone');
    }
}

/**
 * Znacznik audytowy "do uzupełnienia" — nakładka niezależna od status_badge()
 * (needs_correction nie jest wartością ze STATUS_LABELS, tylko flagą audytową).
 */
function needs_correction_badge(array $row): string
{
    if (empty($row['needs_correction'])) return '';
    return '<span class="badge bg-danger ms-1" title="' . h($row['correction_reason'] ?? '') . '">'
         . '<i class="bi bi-exclamation-triangle-fill"></i> Uzupełnij dokumenty lub dane</span>';
}

/**
 * Alert audytowy do widoku umowy: gdy needs_correction=1 — powód + przycisk
 * "Poprawki wprowadzone". Umieszczany na górze karty umowy (widoczny od razu).
 * Jedna definicja używana przez wszystkie 7 typów umów (includes/contract_view_header.php).
 */
function contract_correction_alert(string $type, int $id, array $row): string
{
    if (empty($row['needs_correction'])) return '';

    ob_start();
    ?>
    <div class="alert alert-danger d-flex justify-content-between align-items-start flex-wrap gap-2 no-print mb-2 py-2">
      <div class="small">
        <strong><i class="bi bi-exclamation-triangle-fill"></i> Umowa wymaga uzupełnienia.</strong><br>
        <?= nl2br(h($row['correction_reason'] ?? '')) ?>
        <div class="text-muted mt-1">
          Zgłoszono: <?= date_pl($row['correction_requested_at'] ?? null) ?>
        </div>
      </div>
      <?php if (can_edit()): ?>
      <form method="post" action="<?= APP_URL ?>/contracts/mark_correction.php" class="flex-shrink-0">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="resolve">
        <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i> Poprawki wprowadzone</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Duży modal ostrzegawczy, otwierany AUTOMATYCZNIE przy każdym wejściu w widok
 * umowy z needs_correction=1 — żeby powodu korekty nie dało się przeoczyć.
 * Alert w contract_correction_alert() zostaje jako trwałe przypomnienie na
 * stronie po zamknięciu modala.
 */
function contract_correction_notice_modal(string $type, int $id, array $row): string
{
    if (empty($row['needs_correction'])) return '';

    $modalId = 'correctionNoticeModal-' . h($type) . '-' . $id;
    ob_start();
    ?>
    <div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-label" aria-modal="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title" id="<?= $modalId ?>-label">
              <i class="bi bi-exclamation-triangle-fill"></i> Umowa wymaga uzupełnienia
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="mb-2 fs-5"><?= nl2br(h($row['correction_reason'] ?? '')) ?></p>
            <p class="text-muted small mb-0">
              Zgłoszono: <?= date_pl($row['correction_requested_at'] ?? null) ?>
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            <?php if (can_edit()): ?>
            <form method="post" action="<?= APP_URL ?>/contracts/mark_correction.php" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="type" value="<?= h($type) ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="action" value="resolve">
              <button class="btn btn-success"><i class="bi bi-check-lg"></i> Poprawki wprowadzone</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById(<?= json_encode($modalId) ?>);
        if (el) new bootstrap.Modal(el).show();
      });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Przycisk "Oznacz: do uzupełnienia" (+ modal z wymaganym powodem), widoczny
 * dla edytorów tylko gdy umowa nie jest już oznaczona. Umieszczany w pasku akcji
 * karty umowy, obok wydruków/dokumentów (includes/contract_view_header.php).
 */
function contract_correction_button(string $type, int $id, array $row): string
{
    if (!empty($row['needs_correction']) || !can_edit()) return '';

    $showNotatka = in_array($type, CORRECTION_NOTATKA_TYPES, true);

    ob_start();
    ?>
    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
            data-bs-target="#markCorrectionModal-<?= h($type) ?>-<?= $id ?>">
      <i class="bi bi-flag-fill"></i> <span class="d-none d-sm-inline">Oznacz: do uzupełnienia</span>
    </button>
    <div class="modal fade" id="markCorrectionModal-<?= h($type) ?>-<?= $id ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" action="<?= APP_URL ?>/contracts/mark_correction.php">
            <div class="modal-header bg-danger text-white">
              <h5 class="modal-title"><i class="bi bi-flag-fill"></i> Oznacz umowę do uzupełnienia</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <?= csrf_field() ?>
              <input type="hidden" name="type" value="<?= h($type) ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="action" value="mark">
              <label class="form-label">Powód (widoczny dla osoby odpowiedzialnej)</label>
              <textarea name="correction_reason" class="form-control" rows="3" required minlength="5"></textarea>
              <?php if ($showNotatka): ?>
              <div class="mt-3">
                <label class="form-label">Notatka potrzebna do realizacji</label>
                <textarea name="notatka_do_realizacji" class="form-control" rows="2"
                          placeholder="Co jeszcze trzeba zrobić, żeby umowę zrealizować/zamknąć…"></textarea>
                <div class="form-text">Opcjonalna — widoczna jako ikonka z podpowiedzią na liście umów.</div>
              </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
              <button class="btn btn-danger"><i class="bi bi-flag-fill"></i> Oznacz</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
