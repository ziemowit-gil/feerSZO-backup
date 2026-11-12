<?php
/**
 * modules/user_merge/view/mergeResult.php — ramka z wynikiem scalenia nad listą kont (jednorazowo, z sesji).
 * Pokazuje osobno: scalenie lokalne, SMS z hasłem, Microsoft 365 (błąd Graph wyraźnie, scalenie zostaje), e-maile.
 * role="status" — czytnik ekranu odczyta wynik po przeładowaniu strony. Hasła tu nie ma (nie ma go w sesji).
 */

function userMergeResultBox(?array $r): string {
    if (!$r || empty($r['ok'])) return '';
    $warn = false;
    $line = function (string $icon, string $tone, string $html): string {
        $c = ['ok' => 'tw-text-[#0e700e]', 'warn' => 'tw-text-[#8a3707]', 'err' => 'tw-text-[#b10e1c]', 'info' => 'tw-text-[#424242]'][$tone];
        return '<li class="tw-flex tw-gap-2"><i class="bi ' . $icon . ' ' . $c . '" aria-hidden="true"></i><span>' . $html . '</span></li>';
    };
    $items = [];
    $merged = implode(', ', array_map(fn($m) => '#' . (int)$m['id'] . ' ' . h($m['email']), $r['merged'] ?? []));
    $items[] = $line('bi-check-circle-fill', 'ok', 'Scalono: ' . $merged . ' → <strong>#' . (int)$r['primary']['id'] . ' ' . h($r['primary']['name'])
        . '</strong> (' . h($r['primary']['email']) . '). Przepięte powiązania: ' . (int)$r['moved']
        . ($r['contracts'] ? ', umowy z nowym adresem e-mail: ' . (int)$r['contracts'] : '')
        . ($r['filled'] ? ', uzupełnione pola: ' . h(implode(', ', $r['filled'])) : '') . '.'
        . ($r['left'] ? ' Rekordy pozostawione przy archiwum (były już na koncie głównym): ' . (int)$r['left'] . '.' : ''));
    if (!empty($r['ms']['clash'])) {
        $items[] = $line('bi-exclamation-triangle-fill', 'warn', 'Konto zarchiwizowane miało inne konto Microsoft — zostało przy archiwum (bez zmian w Entra ID).');
    }
    $okSms = array_filter($r['sms'] ?? [], fn($s) => $s['ok']);
    $badSms = array_filter($r['sms'] ?? [], fn($s) => !$s['ok']);
    if ($okSms) $items[] = $line('bi-chat-dots-fill', 'ok', 'Hasło tymczasowe wysłano SMS-em na: ' . h(implode(', ', array_column($okSms, 'to'))) . '.');
    foreach ($badSms as $s) { $warn = true; $items[] = $line('bi-x-circle-fill', 'err', 'SMS na ' . h($s['to']) . ' nie wyszedł: ' . h($s['info'] ?? '') . '.'); }
    if (!$r['sms']) { $warn = true; $items[] = $line('bi-exclamation-triangle-fill', 'warn', 'Brak numeru telefonu — hasła nie wysłano. Ustaw hasło przyciskiem „Resetuj hasło”.'); }
    elseif (!$okSms) { $items[] = $line('bi-exclamation-triangle-fill', 'warn', 'Hasło nie dotarło do użytkownika — ustaw je przyciskiem „Resetuj hasło”.'); }

    $m = $r['m365'] ?? ['status' => 'none'];
    if ($m['status'] === 'ok') {
        $items[] = $line('bi-microsoft', 'ok', 'Microsoft 365: hasło zsynchronizowane, zmiana wymuszona przy pierwszym logowaniu. Logowanie Microsoft pozostaje aktywne.');
    } elseif ($m['status'] === 'error') {
        $warn = true;
        $items[] = $line('bi-x-circle-fill', 'err', '<strong>Microsoft 365: nie udało się ustawić hasła</strong>' . (!empty($m['http']) ? ' (HTTP ' . (int)$m['http'] . ')' : '')
            . ' — ' . h($m['info'] ?? '') . '. Scalenie lokalne zostało zachowane; logowanie Microsoft działa ze starym hasłem.');
    } elseif ($m['status'] === 'skipped') {
        $items[] = $line('bi-info-circle-fill', 'info', 'Microsoft 365: ' . h($m['info'] ?? 'pominięto') . ' Logowanie Microsoft pozostaje aktywne.');
    }
    $okMail = array_filter($r['email'] ?? [], fn($e) => $e['ok']);
    $badMail = array_filter($r['email'] ?? [], fn($e) => !$e['ok']);
    if ($okMail) $items[] = $line('bi-envelope-check-fill', 'ok', 'Potwierdzenie e-mail (bez hasła) wysłano na: ' . h(implode(', ', array_column($okMail, 'to'))) . '.');
    foreach ($badMail as $e) { $warn = true; $items[] = $line('bi-envelope-x-fill', 'err', 'E-mail na ' . h($e['to']) . ' nie wyszedł' . (!empty($e['info']) ? ': ' . h($e['info']) : '') . '.'); }

    $frame = $warn ? 'tw-border-[#bc4b09] tw-bg-[#fff9f5]' : 'tw-border-[#107c10] tw-bg-[#f1faf1]';
    return '<section role="status" aria-labelledby="um-res-h" class="tw-mb-3 tw-rounded-lg tw-border tw-border-solid ' . $frame . ' tw-px-4 tw-py-3 tw-text-sm tw-text-[#242424]">'
         . '<h2 id="um-res-h" class="tw-m-0 tw-mb-2 tw-text-base tw-font-semibold">' . ($warn ? 'Konta scalone — sprawdź uwagi' : 'Konta scalone') . '</h2>'
         . '<ul class="tw-m-0 tw-flex tw-list-none tw-flex-col tw-gap-1 tw-p-0">' . implode('', $items) . '</ul></section>';
}
