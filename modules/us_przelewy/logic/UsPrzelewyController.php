<?php
/**
 * modules/us_przelewy/logic/UsPrzelewyController.php — akcje modułu Przelewy do US (trasy w router.php).
 */

require_once __DIR__ . '/usPrzelewy.php';

final class UsPrzelewyController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string {
        uspMigrate();
        $f = ['status' => (string)($_GET['status'] ?? ''), 'rok' => (int)($_GET['rok'] ?? 0), 'formularz' => (string)($_GET['formularz'] ?? '')];
        return Router::view(self::VIEW . 'index.php', [
            'rows' => uspList($f), 'f' => $f, 'forms' => uspFormularze(), 'konta' => edok_rachunki_list(),
            'rachunek' => uspRachunek(), 'nip' => uspNip(),
        ]);
    }

    public function create(): string {
        uspMigrate();
        $forms = uspFormularze();
        $code = (string)($_GET['formularz'] ?? array_key_first($forms));
        $form = $forms[$code] ?? reset($forms);
        [$rok, $nr] = uspOkresDomyslny($form['okres']);
        $z = ['id' => 0, 'formularz' => $code, 'okres_rok' => $rok, 'okres_nr' => $nr, 'kwota' => '', 'termin' => uspTerminDomyslny($form, $rok, $nr),
              'rachunek' => uspRachunek(), 'txt' => '', 'edok_doc_id' => null, 'uwagi' => '', 'status' => 'do_zaplaty'];
        return $this->form($z, []);
    }

    public function store(): ?string {
        uspMigrate();
        [$d, $errors, $dup] = uspValidate($_POST);
        $up = $errors ? [] : uspUpload();
        if (is_string($up)) $errors[] = $up;
        if ($errors) {
            flash_set('warning', implode(' ', $errors));
            return $this->form(['id' => 0, 'status' => 'do_zaplaty'] + $_POST, $dup);
        }
        $d += $up;
        $id = uspCreate($d, current_user() ?: []);
        flash_set('success', 'Dodano zobowiązanie ' . $d['sfp'] . ' za ' . uspOkresLabel($d['okres_typ'], $d['okres_rok'], $d['okres_nr']) . '.');
        return Router::redirect(route('usp.show', ['id' => $id]));
    }

    public function show(int $id): string {
        uspMigrate();
        $z = uspGet($id);
        if (!$z) { http_response_code(404); return 'Nie znaleziono zobowiązania.'; }
        return $this->form($z, []);
    }

    public function update(int $id): ?string {
        uspMigrate();
        $z = uspGet($id);
        if (!$z) { http_response_code(404); return 'Nie znaleziono zobowiązania.'; }
        if ($z['status'] !== 'do_zaplaty') {
            flash_set('warning', 'Zobowiązanie jest już zlecone, zapłacone albo anulowane — dane można zmienić dopiero po cofnięciu do statusu „Do zapłaty”.');
            return Router::redirect(route('usp.show', ['id' => $id]));
        }
        [$d, $errors, $dup] = uspValidate($_POST, $id);
        $up = $errors ? [] : uspUpload();
        if (is_string($up)) $errors[] = $up;
        if ($errors) {
            flash_set('warning', implode(' ', $errors));
            return $this->form(array_merge($z, $_POST, ['id' => $id]), $dup);
        }
        $d += $up;
        uspUpdate($id, $d);
        flash_set('success', 'Zapisano zobowiązanie.');
        return Router::redirect(route('usp.show', ['id' => $id]));
    }

    public function status(int $id): ?string {
        uspMigrate();
        $err = uspSetStatus($id, (string)($_POST['status'] ?? ''), (string)($_POST['data_zaplaty'] ?? ''));
        flash_set($err ? 'warning' : 'success', $err ?? 'Zmieniono status.');
        return Router::redirect((string)($_POST['back'] ?? '') === 'lista' ? route('usp.index') : route('usp.show', ['id' => $id]));
    }

    public function generuj(): ?string {
        uspMigrate();
        $res = uspGenerujPlik((array)($_POST['zid'] ?? []), (string)($_POST['rachunek_z'] ?? ''), (string)($_POST['format'] ?? 'auto'), !empty($_POST['potwierdz_ponowne']));
        if (is_string($res)) {
            flash_set('warning', $res);
            return Router::redirect(route('usp.index'));
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $res['nazwa'] . '"');
        header('Content-Length: ' . strlen($res['content']));
        echo $res['content'];
        return null;
    }

    /** Pobranie zapisanego pliku przelewu albo załącznika (?co=plik|zalacznik|wyliczenie). */
    public function plik(int $id): ?string {
        uspMigrate();
        $z = uspGet($id);
        $co  = (string)($_GET['co'] ?? '');
        $zal = isset(USP_ZALACZNIKI[$co]);
        $rel = $z ? (string)($zal ? $z[$co . '_path'] : $z['plik_path']) : '';
        $abs = $rel !== '' ? rtrim(UPLOAD_DIR, '/') . '/' . $rel : '';
        if ($abs === '' || !is_file($abs)) {
            flash_set('warning', 'Nie ma zapisanego pliku.');
            return Router::redirect($z ? route('usp.show', ['id' => $id]) : route('usp.index'));
        }
        $nazwa = $zal ? ($z[$co . '_nazwa'] ?: basename($rel)) : $z['plik_nazwa'];
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nazwa) . '"');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        return null;
    }

    public function settings(): ?string {
        uspMigrate();
        $errors = uspSaveSettings($_POST);
        flash_set($errors ? 'warning' : 'success', $errors ? implode(' ', $errors) : 'Zapisano ustawienia przelewów do US.');
        return Router::redirect(route('usp.index') . '#ustawienia');
    }

    private function form(array $z, array $dup): string {
        $id = (int)($z['id'] ?? 0);
        return Router::view(self::VIEW . 'form.php', [
            'z' => $z, 'dup' => $dup, 'forms' => uspFormularze(), 'historia' => $id ? uspHistoria($id) : [],
            'edokDocs' => uspEdokDocs(), 'rachunek' => uspRachunek(),
            'edokDoc' => !empty($z['edok_doc_id']) ? db_one("SELECT id, number, title FROM edok_documents WHERE id = ?", [(int)$z['edok_doc_id']]) : null,
        ]);
    }
}
