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
    return '<span class="badge bg-danger ms-1 align-middle" style="font-size:.78rem;padding:.35rem .65rem" '
         . 'title="' . h($row['correction_reason'] ?? '') . '">'
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
 * Przycisk "Złóż wniosek o edycję" + modal (Alpine.js), zastępuje dawny
 * pełnoekranowy link do contracts/approvals/changes_request.php — ten sam
 * endpoint jest teraz wołany przez fetch() (patrz assets/js/app.js:
 * Alpine.data('editRequestModal', ...)), więc logika walidacji/zapisu w
 * changes_request.php jest nienaruszona, zmienia się tylko sposób jej wywołania.
 * Bez JS działa dalej jak dawniej — <noscript> pokazuje zwykły link.
 * Wołany przez wszystkie 7 typów umów z ich bloku "Wnioski o edycję", tylko gdy
 * can_edit() && !$has_pending_edit (warunek zostaje w każdym view.php jak dotąd).
 */
function edit_request_trigger_html(string $type, int $id, string $numerUmowy = ''): string
{
    $endpoint = APP_URL . '/contracts/approvals/changes_request.php';
    $fallbackUrl = $endpoint . '?type=' . rawurlencode($type) . '&id=' . (int)$id;
    $cfg = [
        'endpoint' => $endpoint,
        'type'     => $type,
        'id'       => (int)$id,
        'csrf'     => csrf_token(),
    ];

    ob_start();
    ?>
    <span x-data="editRequestModal(<?= h(json_encode($cfg)) ?>)">
      <noscript>
        <a href="<?= h($fallbackUrl) ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-pencil"></i> Złóż wniosek o edycję
        </a>
      </noscript>
      <button type="button" x-cloak class="btn btn-sm btn-outline-secondary" @click="openModal()">
        <i class="bi bi-pencil"></i> Złóż wniosek o edycję
      </button>

      <div x-show="open" x-cloak x-trap.noscroll="open" @keydown.escape.window="close()"
           class="modal d-block" style="background:rgba(0,0,0,.5)" role="dialog" aria-modal="true"
           :aria-labelledby="labelId" @click.self="close()">
        <div class="modal-dialog modal-dialog-centered" style="max-width:640px" @click.stop>
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" :id="labelId">
                <i class="bi bi-pencil-square"></i> Wniosek o edycję: <?= h($numerUmowy) ?>
              </h5>
              <button type="button" class="btn-close" @click="close()" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
              <div x-show="errors.length" x-cloak class="alert alert-danger py-2" role="alert" aria-live="assertive">
                <ul class="mb-0"><template x-for="e in errors" :key="e"><li x-text="e"></li></template></ul>
              </div>
              <p class="text-muted small mb-3">Opisz jakie zmiany chcesz wprowadzić do umowy. Administrator otrzyma powiadomienie i zatwierdzi lub odrzuci wniosek.</p>
              <label class="form-label fw-semibold" :for="textareaId">Co chcesz zmienić? <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(wymagane)</span></label>
              <textarea :id="textareaId" x-ref="textarea" x-model="opis" class="form-control" rows="5" required
                @keydown.enter.meta="submitForm()" @keydown.enter.ctrl="submitForm()"
                placeholder="Np. Zmiana daty zakończenia z 31.12.2025 na 28.02.2026, powód: przedłużenie projektu..."></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" @click="close()" :disabled="submitting">Anuluj</button>
              <button type="button" class="btn btn-primary" @click="submitForm()" :disabled="submitting">
                <span x-show="submitting" x-cloak class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i x-show="!submitting" x-cloak class="bi bi-send"></i> Złóż wniosek
              </button>
            </div>
          </div>
        </div>
      </div>
    </span>
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
      <i class="bi bi-flag-fill"></i> <span>Oznacz: do uzupełnienia</span>
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
