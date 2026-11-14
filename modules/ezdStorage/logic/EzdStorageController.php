<?php
declare(strict_types=1);

/**
 * modules/ezdStorage/logic/EzdStorageController.php — ekran „Kopie dokumentów EZD (FTP + Nextcloud)” dla administratora:
 * ustawienia FTP, konto ezdmigracja w chmurze, testy połączeń, dosłanie zaległych kopii.
 */
require_once dirname(__DIR__) . '/lib/EzdStorage.php';
require_once dirname(__DIR__) . '/lib/EzdSpMigrator.php';

final class EzdStorageController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_FLASH = 'ezd_store_flash';
    private const K_OLD = 'ezd_store_old';

    public function index(): string
    {
        if (function_exists('ika_require')) ika_require();
        $flash = $_SESSION[self::K_FLASH] ?? null;
        $old = $_SESSION[self::K_OLD] ?? null;
        unset($_SESSION[self::K_FLASH], $_SESSION[self::K_OLD]);
        return Router::view(self::VIEW . 'settings.php', [
            'flash'      => is_array($flash) ? $flash : null,
            'old'        => is_array($old) ? $old : null,
            'ftp'        => EzdStorage::ftpSettings(),
            'ftpReady'   => EzdStorage::ftpConfigured(),
            'ftpPaused'  => EzdStorage::paused('ftp') ? EzdStorage::pausedUntil('ftp') : 0,
            'ncReady'    => EzdStorage::ncConfigured(),
            'ncUser'     => EzdStorage::ncUser(),
            'ncRoot'     => EzdStorage::ncRoot(),
            'ncPaused'   => EzdStorage::paused('nc') ? EzdStorage::pausedUntil('nc') : 0,
            'cloudUrl'   => SsoConfig::cloudUrl(),
            'cloudReady' => SsoConfig::cloudConfigured(),
            'stats'      => EzdStorage::stats(),
            'sp'         => EzdSpMigrator::stats(),
            'spBase'     => EzdSpMigrator::base(),
        ]);
    }

    public function saveFtp(): ?string
    {
        try {
            EzdStorage::saveFtp($_POST);
            $this->flash(true, 'Zapisano ustawienia FTP.' . (EzdStorage::ftpConfigured() ? ' Kopie idą na FTP i do chmury — sprawdź połączenie przyciskiem „Sprawdź FTP”.' : ' Kopie na FTP są wyłączone.'));
        } catch (EzdStorageInvalid $e) {
            $_SESSION[self::K_OLD] = [
                'errors' => $e->errors,
                'values' => array_intersect_key($_POST, array_flip(['enabled', 'host', 'port', 'user', 'dir', 'tls', 'passive'])),   // bez hasła
            ];
            $this->flash(false, 'Nie zapisano ustawień FTP — popraw zaznaczone pola.');
        }
        return Router::redirect(route('ezdStore.index') . '#ftp');
    }

    public function test(): ?string
    {
        $target = ($_POST['target'] ?? '') === 'ftp' ? 'ftp' : 'nc';
        try {
            $this->flash(true, $target === 'ftp' ? EzdStorage::testFtp() : EzdStorage::testNc());
        } catch (\Throwable $e) {
            $this->flash(false, ($target === 'ftp' ? 'FTP: ' : 'Chmura: ') . $e->getMessage());
        }
        return Router::redirect(route('ezdStore.index') . '#' . ($target === 'ftp' ? 'ftp' : 'chmura'));
    }

    public function account(): ?string
    {
        if (($_POST['action'] ?? '') === 'forget') {
            EzdStorage::forgetAccount();
            $this->flash(true, 'Odłączono konto chmury. SZO nie wysyła już kopii do chmury; konto i pliki w chmurze zostały bez zmian.');
        } else {
            $r = EzdStorage::createAccount();
            $this->flash($r['ok'], $r['msg']);
        }
        return Router::redirect(route('ezdStore.index') . '#chmura');
    }

    public function sync(): ?string
    {
        @set_time_limit(180);
        $r = EzdStorage::syncBatch(50, 90, !empty($_POST['all']));
        $this->flash($r['errors'] === 0, 'Wysłano porcję: ' . $r['files'] . ' plików (do chmury ' . $r['nc'] . ', na FTP ' . $r['ftp'] . ', błędy ' . $r['errors']
            . '). Zostało: ' . $r['left'] . ($r['left'] ? ' — resztę dośle agent CRON co 10 minut.' : '.'));
        return Router::redirect(route('ezdStore.index') . '#stan');
    }

    /** Migracja z SharePointa: porcja teraz albo włączenie / wyłączenie. */
    public function sharepoint(): ?string
    {
        $action = (string)($_POST['action'] ?? 'run');
        if ($action === 'on' || $action === 'off') {
            EzdSpMigrator::setEnabled($action === 'on');
            $this->flash(true, $action === 'on' ? 'Migracja z SharePointa włączona.' : 'Migracja z SharePointa wyłączona — pliki idą zwykłą kopią z dysku serwera.');
        } else {
            @set_time_limit(180);
            $r = EzdSpMigrator::run(50, 90, !empty($_POST['all']));
            $this->flash($r['errors'] === 0 && $r['stopped'] === '', 'Przeniesiono z SharePointa: ' . $r['ok'] . ' z ' . $r['files'] . ' plików (starsze wersje: ' . $r['older']
                . ', brak na SharePoincie: ' . $r['missing'] . ', błędy: ' . $r['errors'] . '). Zostało: ' . $r['left'] . '.'
                . ($r['stopped'] !== '' ? ' Zatrzymano: ' . $r['stopped'] : ($r['left'] ? ' Resztę przeniesie agent CRON co 10 minut.' : '')));
        }
        return Router::redirect(route('ezdStore.index') . '#sharepoint');
    }

    private function flash(bool $ok, string $msg): void
    {
        $_SESSION[self::K_FLASH] = ['ok' => $ok, 'msg' => $msg];
    }
}
