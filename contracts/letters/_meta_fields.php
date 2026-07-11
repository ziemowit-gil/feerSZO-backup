<?php
/**
 * Partial: sekcja „Dane rejestrowe" (metryka pisma / dziennik podawczy).
 * Wspólna dla contracts/letters/add.php i edit.php.
 *
 * Oczekuje w zasięgu:
 *   $L        — tablica bieżących wartości pisma (dla add: [] lub z domyślnymi),
 *   $signers  — wynik letter_signers().
 * Po POST wartości nadpisywane są z $_POST (sticky form).
 */
$mv = function (string $k, string $default = '') use ($L) {
    return h($_POST[$k] ?? $L[$k] ?? $default);
};
$sel_delivery = $_POST['sposob_doreczenia'] ?? $L['sposob_doreczenia'] ?? 'email';
$sel_urgency  = $_POST['pilnosc']           ?? $L['pilnosc']           ?? 'zwykłe';
$sel_signer   = (string)($_POST['podpisujacy_id'] ?? $L['podpisujacy_id'] ?? '');
?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center" role="button"
         data-bs-toggle="collapse" data-bs-target="#metaFields" aria-expanded="false">
        <i class="bi bi-card-list me-2 text-primary"></i>
        <span class="fw-bold">Dane rejestrowe</span>
        <span class="text-muted small ms-2">(sygnatura, doręczenie, podpisujący, podstawa prawna…)</span>
        <i class="bi bi-chevron-down ms-auto"></i>
    </div>
    <div class="collapse" id="metaFields">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">SYGNATURA / ZNAK PISMA</label>
                    <input type="text" name="sygnatura" class="form-control" value="<?= $mv('sygnatura') ?>"
                           placeholder="np. FEER/2026/012">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">MIEJSCOWOŚĆ</label>
                    <input type="text" name="miejsce" class="form-control" value="<?= $mv('miejsce') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">PODPISUJĄCY</label>
                    <select name="podpisujacy_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($signers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $sel_signer === (string)$s['id'] ? 'selected' : '' ?>>
                            <?= h($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-bold">SPOSÓB DORĘCZENIA</label>
                    <select name="sposob_doreczenia" class="form-select">
                        <?php foreach (LETTER_DELIVERY_METHODS as $k => $lbl): ?>
                        <option value="<?= h($k) ?>" <?= $sel_delivery === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">PILNOŚĆ</label>
                    <select name="pilnosc" class="form-select">
                        <?php foreach (LETTER_URGENCY as $k => $u): ?>
                        <option value="<?= h($k) ?>" <?= $sel_urgency === $k ? 'selected' : '' ?>><?= h($u['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">TERMIN ODPOWIEDZI</label>
                    <input type="date" name="termin_odpowiedzi" class="form-control" value="<?= $mv('termin_odpowiedzi') ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label small fw-bold">PODSTAWA PRAWNA</label>
                    <input type="text" name="podstawa_prawna" class="form-control" value="<?= $mv('podstawa_prawna') ?>"
                           placeholder="np. art. 746 § 1 Kodeksu cywilnego">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">NR NADANIA (R)</label>
                    <input type="text" name="nr_nadania" class="form-control" value="<?= $mv('nr_nadania') ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label small fw-bold">DO WIADOMOŚCI (DW)</label>
                    <input type="text" name="kopia_do" class="form-control" value="<?= $mv('kopia_do') ?>"
                           placeholder="osoby/podmioty otrzymujące kopię">
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-bold">ADRES DO e-DORĘCZEŃ (ADE)</label>
                    <input type="text" name="adres_edoreczenia" class="form-control" value="<?= $mv('adres_edoreczenia') ?>"
                           placeholder="AE:PL-…">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">REFERENCJA e-DORĘCZEŃ</label>
                    <input type="text" name="edoreczenia_ref" class="form-control" value="<?= $mv('edoreczenia_ref') ?>">
                </div>
            </div>
        </div>
    </div>
</div>
