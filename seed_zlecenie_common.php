<?php
/**
 * seed_zlecenie_common.php — wspólny profil danych dla seedów umowy zlecenie.
 *
 * Używane przez:
 *   - seed_test_zlecenie.php        (tworzenie konta + umowy)
 *   - seed_zlecenie_usun.php        (usunięcie umowy i danych pochodnych)
 *
 * Oba skrypty MUSZĄ celować w ten sam rekord, dlatego rozstrzygnięcie „co jest
 * rekordem seeda" żyje tutaj, a nie w dwóch kopiach.
 *
 * Profile:
 *   - środowisko testowe  → testy@feer.org.pl,     umowa UZ/TEST/001
 *   - PRODUKCJA           → produkcja@feer.org.pl, umowa UZ/DEMO/001 (dane demo)
 *
 * Na produkcji seed jest DOZWOLONY, ale wyłącznie w profilu demo: żadne inne
 * konto ani numer umowy nie zostanie utworzony, a rachunki są zawsze testowe
 * (test_mode=1), więc nic nie wchodzi do obiegu księgowego.
 */

if (!function_exists('seed_zl_is_production')) {

    function seed_zl_is_production(): bool {
        return defined('APP_ENV') && APP_ENV === 'production';
    }

    /**
     * @return array{email:string, numer:string, name:string, demo:bool, env:string}
     */
    function seed_zl_profile(): array {
        return seed_zl_is_production()
            ? [
                'email' => 'produkcja@feer.org.pl',
                'numer' => 'UZ/DEMO/001',
                'name'  => 'Demo Produkcja',
                'demo'  => true,
                'env'   => 'produkcja',
            ]
            : [
                'email' => 'testy@feer.org.pl',
                'numer' => 'UZ/TEST/001',
                'name'  => 'Testowy Zleceniobiorca',
                'demo'  => false,
                'env'   => 'testowe',
            ];
    }

    /** Nagłówek wypisywany przez oba skrypty — od razu widać, gdzie działamy. */
    function seed_zl_banner(string $tytul): void {
        $p = seed_zl_profile();
        echo "\n";
        if ($p['demo']) {
            $line = str_repeat('═', 64);
            echo "{$line}\n";
            echo "  ŚRODOWISKO PRODUKCYJNE — profil DEMO\n";
            echo "  Dotyczy wyłącznie konta {$p['email']}\n";
            echo "  oraz umowy {$p['numer']}. Nic innego nie zostanie ruszone.\n";
            echo "{$line}\n";
        }
        echo "{$tytul} (środowisko {$p['env']}: {$p['email']} / {$p['numer']})\n\n";
    }
}
