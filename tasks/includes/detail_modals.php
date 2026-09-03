<!--
 tasks/includes/detail_modals.php — wydzielone z tasks/detail.php.
 Modale: Zgłoś problem liderowi, Odrzuć wykonanie, Nowy obszar ze struktury. Wymaga: $task, $can_reject.
-->
<!-- ══ MODAL: Zgłoś problem liderowi ═══════════════════════════════════════ -->
<div id="td-notify-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="tdCloseNotifyModal()"></div>

<div id="td-notify-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="td-notify-modal-title"
     aria-describedby="td-notify-modal-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:9600;width:400px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.22);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #e2e8f0;background:#fffbeb">
    <h2 id="td-notify-modal-title"
        style="font-size:.93rem;font-weight:700;margin:0;
               display:flex;align-items:center;gap:.45rem;color:#92400e">
      <span style="width:28px;height:28px;background:#fef9c3;border-radius:50%;
                   display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"
            aria-hidden="true">
        <i class="bi bi-megaphone-fill" style="color:#d97706;font-size:.85rem"></i>
      </span>
      Zgłoś problem liderowi
    </h2>
    <button type="button"
            id="td-notify-close-btn"
            class="btn-close"
            onclick="tdCloseNotifyModal()"
            aria-label="Zamknij dialog zgłoszenia problemu"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.9rem 1.1rem">
    <p id="td-notify-modal-desc"
       style="font-size:.82rem;color:#64748b;margin-bottom:.75rem;line-height:1.5">
      Opisz problem z realizacją zadania
      <strong style="color:#0f172a"><?= h(mb_substr($task['title'],0,50)) ?><?= mb_strlen($task['title'])>50?'…':'' ?></strong>.
      Wiadomość trafi e-mailem i do skrzynki wewnętrznej lidera obszaru.
    </p>

    <div style="margin-bottom:.65rem">
      <label for="td-notify-msg"
             style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem">
        Opis problemu
        <span style="color:#dc2626" aria-hidden="true">*</span>
        <span class="visually-hidden">(wymagane)</span>
      </label>
      <textarea id="td-notify-msg"
                style="width:100%;border:1.5px solid #e2e8f0;border-radius:.45rem;
                       padding:.5rem .7rem;font-size:.85rem;resize:vertical;min-height:100px;
                       font-family:inherit;line-height:1.55;color:#0f172a;
                       transition:border-color .12s"
                maxlength="1000"
                placeholder="np. Brak dostępu do materiałów, niejasne instrukcje, termin niemożliwy do dotrzymania…"
                aria-required="true"
                aria-describedby="td-notify-hint td-notify-count-label"
                oninput="tdNotifyInput(this)"
                onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();tdNotifyLeader()}"></textarea>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.3rem">
        <p id="td-notify-hint" style="font-size:.72rem;color:#94a3b8;margin:0">
          <kbd style="font-size:.68rem;background:#f1f5f9;border:1px solid #e2e8f0;
                      border-radius:3px;padding:0 .25rem">Ctrl+Enter</kbd> wysyła
        </p>
        <span id="td-notify-count-label"
              style="font-size:.72rem;color:#94a3b8"
              aria-live="polite"
              aria-label="Liczba znaków">0 / 1000</span>
      </div>
    </div>

    <div id="td-notify-ok"  class="alert alert-success  small py-2 d-none" role="status"  aria-live="polite"></div>
    <div id="td-notify-err" class="alert alert-danger   small py-2 d-none" role="alert"   aria-live="assertive"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="tdCloseNotifyModal()"
            aria-label="Anuluj i zamknij dialog">
      Anuluj
    </button>
    <button type="button"
            class="btn btn-warning btn-sm"
            id="td-notify-btn"
            onclick="tdNotifyLeader()"
            aria-label="Wyślij zgłoszenie problemu do lidera obszaru">
      <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie
    </button>
  </div>

</div>

<!-- ══ MODAL: Odrzuć wykonanie ═══════════════════════════════════════════ -->
<div id="td-reject-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="tdCloseRejectModal()"></div>

<div id="td-reject-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="td-reject-modal-title"
     aria-describedby="td-reject-modal-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:9600;width:400px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.22);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #e2e8f0;background:#fef2f2">
    <h2 id="td-reject-modal-title"
        style="font-size:.93rem;font-weight:700;margin:0;
               display:flex;align-items:center;gap:.45rem;color:#991b1b">
      <span style="width:28px;height:28px;background:#fee2e2;border-radius:50%;
                   display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"
            aria-hidden="true">
        <i class="bi bi-x-octagon-fill" style="color:#dc2626;font-size:.85rem"></i>
      </span>
      Odrzuć wykonanie
    </h2>
    <button type="button"
            id="td-reject-close-btn"
            class="btn-close"
            onclick="tdCloseRejectModal()"
            aria-label="Zamknij dialog odrzucenia wykonania"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.9rem 1.1rem">
    <p id="td-reject-modal-desc"
       style="font-size:.82rem;color:#64748b;margin-bottom:.75rem;line-height:1.5">
      Podaj powód odrzucenia wykonania zadania
      <strong style="color:#0f172a"><?= h(mb_substr($task['title'],0,50)) ?><?= mb_strlen($task['title'])>50?'…':'' ?></strong>.
      Wykonawca otrzyma powiadomienie z tym powodem.
    </p>

    <div style="margin-bottom:.65rem">
      <label for="td-reject-msg"
             style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem">
        Powód odrzucenia
        <span style="color:#dc2626" aria-hidden="true">*</span>
        <span class="visually-hidden">(wymagane)</span>
      </label>
      <textarea id="td-reject-msg"
                style="width:100%;border:1.5px solid #e2e8f0;border-radius:.45rem;
                       padding:.5rem .7rem;font-size:.85rem;resize:vertical;min-height:100px;
                       font-family:inherit;line-height:1.55;color:#0f172a;
                       transition:border-color .12s"
                maxlength="1000"
                placeholder="np. Brakuje wymaganych danych, wynik niezgodny z opisem zadania…"
                aria-required="true"
                aria-describedby="td-reject-hint td-reject-count-label"
                oninput="tdRejectInput(this)"
                onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();tdReject()}"></textarea>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.3rem">
        <p id="td-reject-hint" style="font-size:.72rem;color:#94a3b8;margin:0">
          <kbd style="font-size:.68rem;background:#f1f5f9;border:1px solid #e2e8f0;
                      border-radius:3px;padding:0 .25rem">Ctrl+Enter</kbd> wysyła
        </p>
        <span id="td-reject-count-label"
              style="font-size:.72rem;color:#94a3b8"
              aria-live="polite"
              aria-label="Liczba znaków">0 / 1000</span>
      </div>
    </div>

    <div id="td-reject-ok"  class="alert alert-success  small py-2 d-none" role="status"  aria-live="polite"></div>
    <div id="td-reject-err" class="alert alert-danger   small py-2 d-none" role="alert"   aria-live="assertive"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="tdCloseRejectModal()"
            aria-label="Anuluj i zamknij dialog">
      Anuluj
    </button>
    <button type="button"
            class="btn btn-danger btn-sm"
            id="td-reject-btn"
            onclick="tdReject()"
            aria-label="Odrzuć wykonanie zadania z podanym powodem">
      <i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Odrzuć wykonanie
    </button>
  </div>

</div>

<!-- ══ MODAL: Nowy obszar ══════════════════════════════════════════════════ -->
<div id="td-ws-modal-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9500"
     onclick="tdCloseNewWsModal()"
     aria-hidden="true"></div>

<div id="td-ws-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="td-ws-modal-title"
     style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:9600;width:340px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;box-shadow:0 16px 40px rgba(0,0,0,.22);
            overflow:hidden">

  <div style="padding:.8rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
              display:flex;align-items:center;justify-content:space-between">
    <h2 id="td-ws-modal-title" style="font-size:.9rem;font-weight:700;margin:0;color:#0f172a">
      <i class="bi bi-grid-plus me-1 text-primary" aria-hidden="true"></i>Nowy obszar roboczy
    </h2>
    <button type="button" class="btn-close" onclick="tdCloseNewWsModal()"
            aria-label="Zamknij" style="font-size:.75rem"></button>
  </div>

  <div style="padding:.9rem 1rem">
    <p style="font-size:.8rem;color:#64748b;margin-bottom:.8rem">
      Utwórz obszar roboczy na podstawie tego zadania.
      Zadanie zostanie przeniesione do nowego obszaru.
    </p>

    <div style="margin-bottom:.65rem">
      <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
             for="td-ws-name">
        Nazwa obszaru <span style="color:#dc2626" aria-hidden="true">*</span>
      </label>
      <input type="text" id="td-ws-name" class="form-control form-control-sm"
             maxlength="120" placeholder="np. Projekt FEER 2026"
             aria-required="true">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-bottom:.65rem">
      <div>
        <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
               for="td-ws-color">Kolor</label>
        <input type="color" id="td-ws-color" class="form-control form-control-sm form-control-color"
               value="#2563eb" style="height:34px;width:100%">
      </div>
      <div>
        <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
               for="td-ws-icon">Ikona <span style="color:#94a3b8;font-weight:400">(bez bi-)</span></label>
        <input type="text" id="td-ws-icon" class="form-control form-control-sm"
               value="kanban" placeholder="kanban">
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .6rem;
                background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;
                font-size:.78rem;color:#92400e;margin-bottom:.7rem">
      <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
      Zadanie <strong id="td-ws-task-label" style="max-width:160px;white-space:nowrap;
               overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:bottom">
        <?= h(mb_substr($task['title'],0,40)) ?>
      </strong> zostanie przeniesione do nowego obszaru.
    </div>

    <div id="td-ws-err" class="alert alert-danger small py-2 d-none" role="alert"></div>
  </div>

  <div style="padding:.65rem 1rem;border-top:1px solid #e2e8f0;display:flex;gap:.5rem;justify-content:flex-end">
    <button type="button" class="btn btn-outline-secondary btn-sm"
            onclick="tdCloseNewWsModal()">Anuluj</button>
    <button type="button" class="btn btn-primary btn-sm" id="td-ws-submit"
            onclick="tdSubmitNewWs()">
      <i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar
    </button>
  </div>
</div>

<!-- Strefa niebezpieczna przeniesiona do belki przycisków w nagłówku -->

