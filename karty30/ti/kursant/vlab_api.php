<?php
/**
 * karty30/ti/kursant/vlab_api.php — endpoint AJAX VLAB dla kursanta.
 * Akcje: list | create | start | stop | restart | remove | refresh | order_dedicated_ip | cancel_dedicated_ip.
 * Każda operacja ograniczona do kontenerów zalogowanego kursanta.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

karty30_migrate();
$student = student_current();
if (!$student) { http_response_code(401); echo json_encode(['ok' => false, 'msg' => 'Sesja wygasła.']); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

/** Buduje payload listy maszyn + szablonów dla front-endu. */
function vlab_payload(array $student): array {
    $cfg = vlab_config();
    $templates = $cfg['is_enabled']
        ? db_all("SELECT id,name,description,default_ports FROM k30_ti_vlab_templates WHERE is_active=1 ORDER BY sort,name")
        : [];
    $rows = db_all(
        "SELECT * FROM k30_ti_vlab_containers WHERE student_id=? AND status!='removed' ORDER BY created_at DESC",
        [$student['id']]
    );
    $dedip_pricing = vlab_dedicated_ip_pricing();
    $machines = [];
    foreach ($rows as $r) {
        $dedip = vlab_dedicated_ip_for_container((int)$r['id']);
        $machines[] = [
            'id'         => (int)$r['id'],
            'label'      => $r['label'],
            'status'     => $r['status'],
            'error'      => $r['error_msg'],
            'ssh_host'   => $cfg['public_host'] ?? '',
            // Logowanie przez konto hosta (wpuszcza do kontenera); port = SSH hosta.
            'host_user'  => $r['host_user'] ?? '',
            'host_port'  => (int)($cfg['ssh_port'] ?: 22),
            // Fallback: bezpośredni port kontenera (gdy nie utworzono konta host_user).
            'ssh_user'   => $r['ssh_user'] ?? '',
            'ssh_port'   => (int)($r['ssh_port'] ?? 0),
            'ttyd_url'   => vlab_ttyd_url($r),
            'force_pw'   => (int)($r['force_pw_pending'] ?? 0) === 1,
            'created_at' => $r['created_at'],
            'dedicated_ip' => $dedip ? [
                'order_id'   => (int)$dedip['id'],
                'status'     => $dedip['status'],
                'ip_address' => $dedip['ip_address'],
            ] : null,
        ];
    }
    return [
        'enabled'         => (bool)$cfg['is_enabled'],
        'disabled'        => vlab_is_disabled(),
        'notice'          => vlab_is_disabled() ? vlab_disabled_notice() : '',
        'max'             => (int)$cfg['max_per_student'],
        'count'           => vlab_student_count($student['id']),
        'ports_self'      => vlab_student_can_ports(),
        'templates'       => $templates,
        'machines'        => $machines,
        'dedicated_ip'    => $dedip_pricing,
    ];
}

// Operacje modyfikujące wymagają tokenu i metody POST
$modifying = in_array($action, ['create', 'start', 'stop', 'restart', 'remove', 'port_open', 'port_close', 'port_request_open', 'port_request_close', 'order_dedicated_ip', 'cancel_dedicated_ip'], true);
if ($modifying) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'msg' => 'Metoda niedozwolona.']); exit; }
    student_token_check();
}

try {
    switch ($action) {
        case 'list':
            echo json_encode(['ok' => true] + vlab_payload($student));
            break;

        case 'create': {
            $tpl   = (int)($_POST['template_id'] ?? 0);
            $label = (string)($_POST['label'] ?? '');
            $ports = (string)($_POST['ports'] ?? '');
            $res   = vlab_provision($student['id'], (int)$student['client_id'], $tpl, $label, $ports);
            echo json_encode($res + ['data' => vlab_payload($student)]);
            break;
        }

        case 'start':
        case 'stop':
        case 'restart':
        case 'remove': {
            $id  = (int)($_POST['id'] ?? 0);
            $res = vlab_action($id, $student['id'], $action);
            echo json_encode($res + ['data' => vlab_payload($student)]);
            break;
        }

        case 'refresh': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id) vlab_refresh($id, $student['id']);
            echo json_encode(['ok' => true] + vlab_payload($student));
            break;
        }

        case 'ports': {
            if (!vlab_student_can_ports()) { echo json_encode(['ok' => false, 'msg' => 'Zarządzanie portami jest wyłączone.']); break; }
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            echo json_encode(vlab_student_ports_data($id, $student['id']));
            break;
        }

        case 'port_open':
        case 'port_request_open': {
            $rl = vlab_port_rate_check($student['id']);
            if (!$rl['ok']) { echo json_encode($rl); break; }
            $id    = (int)($_POST['id'] ?? 0);
            $port  = (int)($_POST['host_port'] ?? 0);
            $proto = (string)($_POST['proto'] ?? 'tcp');
            $note  = (string)($_POST['note'] ?? '');
            $res   = vlab_port_request_open($id, $port, $proto, $note, null, $student['id']);
            echo json_encode($res);
            break;
        }

        case 'port_close':
        case 'port_request_close': {
            $rl = vlab_port_rate_check($student['id']);
            if (!$rl['ok']) { echo json_encode($rl); break; }
            $pid = (int)($_POST['port_id'] ?? 0);
            $res = vlab_port_request_close($pid, 'kursant', null, $student['id']);
            echo json_encode($res);
            break;
        }

        case 'order_dedicated_ip': {
            $id  = (int)($_POST['id'] ?? 0);
            $res = vlab_dedicated_ip_request($id, $student['id']);
            echo json_encode($res + ['data' => vlab_payload($student)]);
            break;
        }

        case 'cancel_dedicated_ip': {
            $id  = (int)($_POST['id'] ?? 0);
            $res = vlab_dedicated_ip_self_cancel($id, $student['id']);
            echo json_encode($res + ['data' => vlab_payload($student)]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Nieznana akcja.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Błąd serwera: ' . $e->getMessage()]);
}
