<?php
declare(strict_types=1);

/**
 * modules/license_pool/logic/LpController.php — Pula licencji Fundacji (wspólna dla TI i IT SZO).
 * Kontekst wejścia: LP_CONTEXT = 'ti' (panel dydaktyka, tylko kadra) | 'it' (IT SZO, admin/editor).
 * Wyniki: string = HTML, null = przekierowanie.
 */
require_once dirname(__DIR__) . '/lib/LpPool.php';

final class LpController
{
    private const VIEW = __DIR__ . '/../view/';

    private function actor(): array
    {
        if (LP_CONTEXT === 'ti') { $me = dyd_require(); return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')]; }
        $u = current_user();
        return ['id' => (int)($u['id'] ?? 0), 'name' => (string)($u['name'] ?? '')];
    }

    private function view(string $file, array $data = []): string
    {
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'ctx' => LP_CONTEXT]);
    }

    private function ctx(): string { return LP_CONTEXT; }

    public function index(): string
    {
        return $this->view('index.php', ['products' => LpPool::products(), 'imports' => LpPool::imports(5)]);
    }

    public function saveProduct(): ?string
    {
        $id = (int)($_POST['id'] ?? 0);
        $r = LpPool::saveProduct($_POST, $id, $this->actor()['id']);
        if (!$r['ok']) { flash_set('danger', implode(' ', $r['errors'])); return Router::redirect($id ? route('lp.product', ['id' => $id]) : route('lp.index')); }
        flash_set('success', 'Produkt zapisany.');
        return Router::redirect(route('lp.product', ['id' => $r['id']]));
    }

    public function product(int $id, ?string $reveal = null, ?int $revealId = null): string
    {
        $p = LpPool::product((int)$id);
        if (!$p) { http_response_code(404); return 'Nie znaleziono produktu.'; }
        return $this->view('product.php', [
            'p' => $p, 'licenses' => LpPool::licenses((int)$id), 'assignments' => LpPool::productAssignments((int)$id),
            'users' => LpPool::holders('user'), 'clients' => LpPool::holders('client'),
            'free' => LpPool::pickFree((int)$id), 'reveal' => $reveal, 'revealId' => $revealId,
        ]);
    }

    public function assign(): ?string
    {
        $in = $_POST + ['context' => $this->ctx()];
        $r = LpPool::assign($in, $this->actor()['id']);
        $msg = 'Licencja przydzielona.';
        if ($r['ok'] && !empty($_POST['notify'])) {
            $m = $r['mail'];
            $msg .= $m['status'] === 'sent' ? ' E-mail z kluczem wysłano na ' . $m['to'] . '.' : ' E-mail NIE został wysłany: ' . $m['detail'] . '.';
        }
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? $msg : implode(' ', $r['errors']));
        return Router::redirect(route('lp.product', ['id' => (int)($_POST['product_id'] ?? 0)]));
    }

    public function revoke(int $id): ?string
    {
        $a = db_one("SELECT l.product_id FROM lp_assignments a JOIN lp_licenses l ON l.id = a.license_id WHERE a.id = ?", [(int)$id]);
        $r = LpPool::revoke((int)$id, (string)($_POST['reason'] ?? ''), $this->actor()['id']);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Przydział odebrany.' : $r['error']);
        return Router::redirect($a ? route('lp.product', ['id' => (int)$a['product_id']]) : route('lp.index'));
    }

    public function resend(int $id): ?string
    {
        $a = db_one("SELECT l.product_id FROM lp_assignments a JOIN lp_licenses l ON l.id = a.license_id WHERE a.id = ? AND a.revoked_at IS NULL", [$id]);
        if (!$a) { flash_set('danger', 'Nie znaleziono aktywnego przydziału.'); return Router::redirect(route('lp.index')); }
        $m = LpPool::sendKeyMail($id, trim((string)($_POST['holder_email'] ?? '')), $this->actor()['id']);
        flash_set($m['status'] === 'sent' ? 'success' : 'danger', $m['status'] === 'sent' ? 'E-mail z kluczem wysłano na ' . $m['to'] . '.' : 'E-mail nie został wysłany: ' . $m['detail'] . '.');
        return Router::redirect(route('lp.product', ['id' => (int)$a['product_id']]));
    }

    public function reveal(int $id): string
    {
        $lic = LpPool::license((int)$id);
        if (!$lic) { http_response_code(404); return 'Nie znaleziono licencji.'; }
        return $this->product((int)$lic["product_id"], (string)LpPool::revealCode((int)$id, $this->actor()['id']), (int)$id);
    }

    public function retire(int $id): ?string
    {
        $lic = LpPool::license((int)$id);
        if (!$lic) { http_response_code(404); return 'Nie znaleziono licencji.'; }
        LpPool::retire((int)$id, $this->actor()['id']);
        flash_set('success', 'Licencja wycofana z puli (przydziały zostają w historii).');
        return Router::redirect(route('lp.product', ['id' => (int)$lic['product_id']]));
    }

    // ── Import TXT ──────────────────────────────────────────────────────────

    public function importForm(): string
    {
        return $this->view('import.php', ['products' => LpPool::products(true), 'preview' => null]);
    }

    public function importPreview(): ?string
    {
        $f = $_FILES['txt'] ?? null;
        $raw = '';
        if ($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
            if ($f['size'] > LpImport::MAX_BYTES) { flash_set('danger', 'Plik jest za duży (maks. 2 MB).'); return Router::redirect(route('lp.import')); }
            $raw = (string)file_get_contents($f['tmp_name']);
        } elseif (trim((string)($_POST['paste'] ?? '')) !== '') {
            $raw = (string)$_POST['paste'];
        } else { flash_set('danger', 'Wybierz plik TXT albo wklej listę.'); return Router::redirect(route('lp.import')); }

        $def = '';
        if ((int)($_POST['product_id'] ?? 0) > 0) { $p = LpPool::product((int)$_POST['product_id']); $def = $p['name'] ?? ''; }
        $prev = LpPool::previewImport($raw, $def);
        $token = bin2hex(random_bytes(16));
        $_SESSION['lp_import'] = ['token' => $token, 'preview' => $prev, 'file' => $f['name'] ?? 'wklejone', 'at' => time()];
        return $this->view('import.php', ['products' => LpPool::products(true), 'preview' => $prev, 'token' => $token]);
    }

    public function importCommit(): ?string
    {
        $s = $_SESSION['lp_import'] ?? null;
        if (!$s || !hash_equals((string)$s['token'], (string)($_POST['token'] ?? '')) || time() - (int)$s['at'] > 1800) {
            flash_set('danger', 'Podgląd importu wygasł — wczytaj plik ponownie.');
            return Router::redirect(route('lp.import'));
        }
        unset($_SESSION['lp_import']);
        $r = LpPool::commitImport($s['preview'], (string)$s['file'], $this->actor()['id']);
        flash_set('success', "Import: dodano {$r['added']}, duplikaty pominięte {$r['duplicates']}, błędne linie {$r['invalid']}"
            . ($r['products_created'] ? ", nowe produkty {$r['products_created']}" : '') . '.');
        return Router::redirect(route('lp.index'));
    }
}
