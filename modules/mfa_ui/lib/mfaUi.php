<?php
/**
 * modules/mfa_ui/lib/mfaUi.php — wspólna szata Metro ekranów MFA (ustawienia 2FA, klucze WebAuthn, kod przy logowaniu).
 * Klasy mfa-*; na stronach panelu wołać po panelMetroAssets() i owijać treść w <div class="pvm">.
 * Kolory i wielkości liter pochodzą z zmiennych Metro (--m-*, --pvm-*) z fallbackiem — ekran logowania ich nie ma.
 */
function mfaUiStyles(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<style>
.mfa{--mfa-acc:var(--pvm-acc,#0f6fa8);--mfa-ink:var(--pvm-ink,#111827);--mfa-mut:var(--pvm-muted,#5f5f5f);--mfa-bg:var(--pvm-bg,#fff);
  --mfa-ok:var(--m-green,#007a00);--mfa-red:var(--m-red,#a20025);--mfa-warn:var(--m-orange,#c25100);--mfa-grey:var(--m-grey,#5f5f5f)}
.mfa *{box-sizing:border-box}
.mfa-cols{display:grid;gap:8px;grid-template-columns:1fr;margin-bottom:1.5rem}
@media(min-width:900px){.mfa-cols{grid-template-columns:1fr 1fr}}
.mfa-cols .mt-group{margin:0;display:flex;flex-direction:column}
.mfa-state{display:flex;align-items:center;gap:.9rem;margin-bottom:1rem}
.mfa-state .sq{width:52px;height:52px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;background:var(--c)}
.mfa-state b{display:block;font-size:1.1rem;font-weight:600}
.mfa-state small{color:var(--mfa-mut)}
.mfa-badge{display:inline-block;padding:.1rem .5rem;background:var(--mfa-ok);color:#fff;font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-left:.5rem;vertical-align:middle}
.mfa-lead{margin:0 0 1rem;font-weight:300;max-width:70ch}
.mfa-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto}
.mfa-hint{font-size:.8rem;color:var(--mfa-mut);margin:.35rem 0 0}
.mfa-steps{list-style:none;margin:0 0 1rem;padding:0;display:flex;flex-wrap:wrap;gap:.35rem}
.mfa-steps li{display:flex;align-items:center;gap:.5rem;padding:.35rem .75rem .35rem .35rem;background:var(--pvm-light,#f2f2f2);font-size:.85rem;border-bottom:3px solid transparent}
.mfa-steps .n{width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;background:var(--mfa-grey);color:#fff;font-weight:700;font-size:.78rem}
.mfa-steps li.is-done .n{background:var(--mfa-ok)} .mfa-steps li.is-cur{border-bottom-color:var(--mfa-acc);font-weight:600} .mfa-steps li.is-cur .n{background:var(--mfa-acc)}
.mfa label.lbl{display:block;font-weight:600;font-size:.88rem;margin-bottom:.3rem}
.mfa-in{border:2px solid #8a8a8a;border-radius:0;padding:.55rem .75rem;font:inherit;font-size:1rem;background:var(--mfa-bg);color:var(--mfa-ink);min-width:0;width:100%}
.mfa-in:focus{border-color:var(--mfa-acc);outline:0}
.mfa-in--code{font-size:1.8rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-variant-numeric:tabular-nums}
.mfa-phone{display:flex;max-width:360px}.mfa-phone .pre{display:flex;align-items:center;padding:0 .75rem;background:var(--pvm-light,#f2f2f2);border:2px solid #8a8a8a;border-right:0;font-weight:600}
.mfa-qr{display:flex;justify-content:center;background:#fff;padding:.75rem;margin:0 0 1rem;border:1px solid var(--m-line,#e1e1e1)}
.mfa-secret{display:flex;gap:8px;max-width:520px}.mfa-secret .mt-btn{white-space:nowrap;flex-shrink:0}.mfa-secret .mfa-in{font-family:ui-monospace,Menlo,Consolas,monospace}
.mfa-codes{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px;margin:.6rem 0}
.mfa-codes code{display:block;text-align:center;padding:.55rem;background:var(--mfa-bg);font-weight:700;font-size:1rem;letter-spacing:.06em}
.mfa-danger{border-left:6px solid var(--mfa-red);background:var(--mfa-bg);padding:1rem;margin-top:.5rem}
.mfa-danger h3{font-size:1rem;font-weight:600;color:var(--mfa-red);margin:0 0 .4rem}
.mfa-tbl{width:100%;border-collapse:collapse;background:var(--mfa-bg)}
.mfa-tbl th{text-align:left;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:var(--mfa-mut);font-weight:600;padding:.6rem .75rem;border-bottom:2px solid var(--mfa-ink)}
.mfa-tbl td{padding:.7rem .75rem;border-bottom:1px solid var(--m-line,#e1e1e1);vertical-align:middle}
.mfa-tbl .end{text-align:right}
@media(max-width:640px){.mfa-tbl thead{position:absolute;left:-9999px}.mfa-tbl tr{display:block;border-bottom:2px solid var(--m-line,#e1e1e1);padding:.5rem 0}.mfa-tbl td{display:block;border:0;padding:.2rem .75rem}.mfa-tbl .end{text-align:left}}
.mfa-status{margin-top:1rem}
.mfa-status .mfa-msg{padding:.7rem 1rem;color:#fff;background:var(--mfa-grey);font-size:.92rem}
.mfa-status .mfa-msg.ok{background:var(--mfa-ok)}.mfa-status .mfa-msg.err{background:var(--mfa-red)}
</style>
<?php
}
