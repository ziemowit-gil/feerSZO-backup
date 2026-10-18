<?php
/**
 * modules/ti_room_srs/logic/RoomSrsController.php — Formularze rezerwacji sal TI ↔ SRS.
 *
 * Panel dydaktyka (kierownik, middleware dyd.staff):
 *   index    — grupy z salą w okresie (tydzień / miesiąc / 3 miesiące) ze statusem formularza i rezerwacji SRS;
 *   form     — formularz wypełniony danymi z systemu, do poprawienia; PESEL i adres zamieszkania wpisywane ręcznie;
 *   generate — zapis formularza (bez PESEL/adresu), rezerwacja w SRS, pobranie DOCX ze wzoru operatora;
 *   rooms    — przypisanie sal TI do zasobów SRS i opcji „Sala” we wzorze.
 * SZO (sesja SZO): docx — formularz do wglądu z karty rezerwacji SRS (bez PESEL/adresu, nigdy niezapisane).
 */
require_once __DIR__ . '/roomSrs.php';

final class RoomSrsController
{
    private const VIEW = __DIR__ . '/../view/';

    private function me(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? '')];
    }

    /** Okres z parametrów (jak w „Wykazie sal do rezerwacji”). */
    private function range(): array
    {
        $range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'month';
        return ['range' => $range] + ti_room_reservation_range((string)($_GET['w'] ?? ''), $range);
    }

    public function index(): string
    {
        $rr = $this->range();
        $items = tiRoomSrsCandidates($rr['from'], $rr['to']);
        $resIds = array_filter(array_map(fn($i) => (int)($i['form']['srs_reservation_id'] ?? 0), $items));
        $res = [];
        if ($resIds) {
            foreach (db_all("SELECT id, status FROM resource_reservations WHERE id IN (" . implode(',', $resIds) . ")") as $r) $res[(int)$r['id']] = $r['status'];
        }
        $rooms = [];
        foreach (tiRoomSrsRooms() as $r) $rooms[(int)$r['id']] = $r;
        return Router::view(self::VIEW . 'index.php', ['me' => $this->me(), 'rr' => $rr, 'items' => $items, 'res' => $res, 'rooms' => $rooms]);
    }

    public function form(): string
    {
        [$courseId, $roomId, $from, $to] = $this->key($_GET);
        try {
            $d = tiRoomSrsDefaults($courseId, $roomId, $from, $to);
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            header('Location: ' . route('trs.index')); exit;
        }
        $resStatus = null;
        if ((int)($d['meta']['form']['srs_reservation_id'] ?? 0)) {
            $resStatus = db_one("SELECT status FROM resource_reservations WHERE id = ?", [(int)$d['meta']['form']['srs_reservation_id']])['status'] ?? null;
        }
        return Router::view(self::VIEW . 'form.php', [
            'me' => $this->me(), 'trData' => $d['data'], 'trMeta' => $d['meta'], 'trKey' => compact('courseId', 'roomId', 'from', 'to'),
            'resStatus' => $resStatus, 'back' => (string)($_GET['back'] ?? ''),
        ]);
    }

    public function generate(): ?string
    {
        $me = $this->me();
        [$courseId, $roomId, $from, $to] = $this->key($_POST, 'k_');
        $data = tiRoomSrsDataFromPost($_POST);
        $back = route('trs.form', ['course' => $courseId, 'room' => $roomId, 'from' => $from, 'to' => $to]);
        if ($data['pesel'] !== '' && !tiRoomSrsPeselValid($data['pesel'])) {
            flash_set('danger', 'Numer PESEL jest niepoprawny (suma kontrolna) — popraw go albo zostaw puste pole.');
            return Router::redirect($back);
        }
        try {
            $formId = tiRoomSrsSave($courseId, $roomId, $from, $to, $data, $me['id'], !empty($_POST['srs']));
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect($back);
        }
        if (($_POST['_op'] ?? '') === 'save') {
            flash_set('success', !empty($_POST['srs']) ? 'Formularz zapisany, rezerwacja w SRS zaktualizowana.' : 'Formularz zapisany.');
            return Router::redirect($back);
        }
        if (function_exists('ti_print_log_add')) {
            ti_print_log_add('sale_formularz', 'Formularz rezerwacji sali — grupa #' . $courseId . ', sala #' . $roomId . ', ' . $from . ' – ' . $to,
                $courseId, 0, ['form_id' => $formId], dyd_require());
        }
        return $this->sendDocx(tiRoomSrsDocxData($data), $courseId, $from);
    }

    public function rooms(): string
    {
        return Router::view(self::VIEW . 'rooms.php', ['me' => $this->me(), 'rooms' => tiRoomSrsRooms(), 'resources' => tiRoomSrsResources()]);
    }

    public function roomsSave(): ?string
    {
        $me = $this->me();
        try {
            if (($_POST['_op'] ?? '') === 'create') {
                $id = tiRoomSrsCreateResource((int)($_POST['room_id'] ?? 0), $me['id']);
                flash_set('success', 'Utworzono zasób SRS #' . $id . ' i przypisano go do sali.');
            } else {
                foreach ((array)($_POST['rooms'] ?? []) as $roomId => $r) {
                    tiRoomSrsSaveRoom((int)$roomId, (int)($r['resource_id'] ?? 0) ?: null, (string)($r['form_room'] ?? ''));
                }
                flash_set('success', 'Zapisano przypisania sal.');
            }
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('trs.rooms'));
    }

    /** SZO: formularz z karty rezerwacji SRS (wnioskodawca, administrator SRS, dysponent zasobu). */
    public function docx(int $id): ?string
    {
        $form = tiRoomSrsFormGet($id);
        $res = $form && (int)$form['srs_reservation_id'] ? res_reservation_get((int)$form['srs_reservation_id']) : null;
        $u = current_user();
        $ok = $res && (is_admin() || can_write('srs') || (int)$res['user_id'] === (int)$u['id'] || (int)($res['dysponent_user_id'] ?? 0) === (int)$u['id']);
        if (!$ok) { http_response_code(403); return 'Brak dostępu do formularza.'; }
        $data = tiRoomSrsDefaults((int)$form['course_id'], (int)$form['room_id'], (string)$form['date_from'], (string)$form['date_to'])['data'];
        return $this->sendDocx(tiRoomSrsDocxData($data), (int)$form['course_id'], (string)$form['date_from']);
    }

    private function sendDocx(array $data, int $courseId, string $from): ?string
    {
        $bin = RoomFormDocx::render($data);
        $course = db_one("SELECT group_code, name FROM k30_ti_courses WHERE id = ?", [$courseId]);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($course['group_code'] ?: $course['name'])) ?: 'grupa');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="Formularz_rezerwacji_sali_' . trim($slug, '_') . '_' . $from . '.docx"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: private, no-store');
        echo $bin;
        return null;
    }

    /** course/room/from/to z żądania (daty Y-m-d); w POST z prefiksem k_ — „room” to tam opcja sali we wzorze. */
    private function key(array $src, string $p = ''): array
    {
        $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : date('Y-m-d');
        return [(int)($src[$p . 'course'] ?? 0), (int)($src[$p . 'room'] ?? 0), $d($src[$p . 'from'] ?? ''), $d($src[$p . 'to'] ?? '')];
    }
}
