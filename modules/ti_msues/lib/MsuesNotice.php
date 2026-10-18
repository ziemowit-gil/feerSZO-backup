<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesNotice.php — komunikat o certyfikacji MSUES na Pulpicie panelu dydaktyka (pierwszy ekran po zalogowaniu).
 *
 * Pokazywany dopiero po uruchomieniu certyfikacji przez kierownika (msues_launched_at). Treść zależy od stanu osoby:
 *   prowadzący bez zatwierdzonego oświadczenia — co to jest MSUES, co zrobić, termin; robocze / odrzucone (z powodem) / czeka na weryfikację;
 *   kierownik — liczba oświadczeń czekających na weryfikację.
 * Osoba z zatwierdzonym oświadczeniem (i kierownik bez kolejki) nie widzi nic. „Ukryj” chowa komunikat do końca sesji przeglądarki
 * (sessionStorage) — po następnym zalogowaniu wraca, dopóki sprawa nie jest załatwiona. Nigdy nie rzuca — błąd modułu nie psuje Pulpitu.
 */
require_once __DIR__ . '/MsuesLaunch.php';

function msuesLoginNotice(int $uid, bool $staff): string
{
    try {
        $launched = MsuesLaunch::launched();
        if (!$launched || $uid <= 0) return '';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $url = fn(string $route) => 'msues.php' . ($route !== '' ? '?_route=' . rawurlencode($route) : '');
        $deadline = $launched['deadline'] !== '' ? ' Termin: <b>' . $e(DeclarationForm::dateLong($launched['deadline'])) . '</b>.' : '';
        $req = MsuesComplianceService::declarationRequired()
            ? ' Bez zatwierdzonego oświadczenia nie można zostać przypisanym do nowej grupy.' : '';
        $box = function (string $tone, string $icon, string $title, string $body, string $href, string $cta) use ($e, $uid): string {
            $key = 'msuesNotice:' . $uid . ':' . md5($title . $body);
            return '<section class="alert alert-' . $tone . ' d-flex gap-3 align-items-start rounded-0 mb-3" role="region" aria-label="Certyfikacja MSUES" data-msues-notice="' . $e($key) . '">'
                . '<i class="bi bi-' . $icon . ' fs-3" aria-hidden="true"></i><div class="flex-grow-1">'
                . '<h2 class="h6 mb-1">' . $title . '</h2><div class="small">' . $body . '</div>'
                . '<div class="mt-2 d-flex gap-2 flex-wrap"><a class="btn btn-sm btn-primary rounded-0" href="' . $e($href) . '">' . $e($cta) . '</a>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary rounded-0" data-msues-hide>Ukryj do następnego logowania</button></div></div></section>'
                . '<script>(function(){var b=document.querySelector(\'[data-msues-notice="' . $e($key) . '"]\');if(!b)return;var k=b.getAttribute("data-msues-notice");'
                . 'try{if(sessionStorage.getItem(k)){b.remove();return;}}catch(x){}'
                . 'b.querySelector("[data-msues-hide]").addEventListener("click",function(){try{sessionStorage.setItem(k,"1");}catch(x){}b.remove();});})();</script>';
        };

        $out = '';
        if ($staff) {
            $n = (int)(db_one("SELECT COUNT(*) AS c FROM trainer_declarations WHERE status = 'pending'")['c'] ?? 0);
            if ($n > 0) {
                $out .= $box('info', 'award', 'Certyfikacja MSUES: ' . $n . ' ' . ($n === 1 ? 'oświadczenie czeka'
                    : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 'oświadczenia czekają' : 'oświadczeń czeka')) . ' na weryfikację',
                    'Sprawdź treść i skan podpisu, potem zatwierdź pod audyt albo odrzuć z uzasadnieniem.', $url('/oswiadczenia') . '&status=pending', 'Weryfikuj oświadczenia');
            }
        }

        // Prowadzący: tylko kadra (prowadzi lub prowadził zajęcia albo ma już oświadczenie / dokumenty MSUES)
        if (!in_array($uid, array_map(fn($t) => (int)$t['id'], MsuesSzoData::trainers()), true)) return $out;
        if (db_one("SELECT 1 FROM trainer_declarations WHERE trainer_id = ? AND status = 'approved'", [$uid])) return $out;
        $last = db_one("SELECT id, status, decision_note, decided_name FROM trainer_declarations WHERE trainer_id = ? AND status <> 'superseded' ORDER BY id DESC LIMIT 1", [$uid]);
        $status = (string)($last['status'] ?? '');
        $what = 'Fundacja przygotowuje się do znaku jakości <b>MSUES</b> (Małopolskie Standardy Usług Edukacyjno-Szkoleniowych, WUP Kraków). '
              . 'Audyt sprawdza kwalifikacje kadry — potrzebujemy Twojego CV, dyplomów lub certyfikatów i podpisanego oświadczenia o kwalifikacjach.';
        $out .= match ($status) {
            'pending'  => $box('success', 'hourglass-split', 'Certyfikacja MSUES: Twoje oświadczenie czeka na weryfikację',
                              'Dziękujemy! Kierownik sprawdzi oświadczenie i skan podpisu. Możesz jeszcze dodać CV i dyplomy, jeśli ich brakuje.', $url('/moje'), 'Moje dokumenty MSUES'),
            'rejected' => $box('danger', 'exclamation-octagon', 'Certyfikacja MSUES: oświadczenie wymaga poprawy',
                              'Odrzucił(a) ' . $e($last['decided_name']) . ': <i>' . $e($last['decision_note']) . '</i>. Popraw treść, wgraj nowy skan i wyślij ponownie.' . $deadline,
                              $url('/oswiadczenia/' . (int)$last['id']), 'Popraw oświadczenie'),
            'draft'    => $box('warning', 'award', 'Certyfikacja MSUES: dokończ oświadczenie o kwalifikacjach',
                              $what . ' Oświadczenie jest już wstępnie wypełnione danymi z systemu — sprawdź je, podpisz PDF, wgraj skan i wyślij do weryfikacji.' . $deadline . $req,
                              $url('/oswiadczenia/' . (int)$last['id']), 'Uzupełnij oświadczenie'),
            default    => $box('warning', 'award', 'Certyfikacja MSUES: prosimy o oświadczenie o kwalifikacjach',
                              $what . ' Zajmie to kilka minut.' . $deadline . $req, $url('/moje'), 'Przejdź do Certyfikacji MSUES'),
        };
        return $out;
    } catch (\Throwable $ex) {
        error_log('msuesLoginNotice: ' . $ex->getMessage());
        return '';
    }
}
