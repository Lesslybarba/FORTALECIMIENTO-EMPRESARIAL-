<?php
/**
 * Ada - Identidad seudonima del usuario autenticado.
 *
 * Devuelve dos identificadores derivados (HMAC-SHA256) y NUNCA datos personales:
 *   uid : identifica a la cuenta de Moodle (estable entre sesiones). Se usa para
 *         separar el historial de conversaciones de cada usuario.
 *   sid : identifica la sesion actual (cambia en cada inicio de sesion). Se usa
 *         para saber cuando debe empezar un chat nuevo.
 * Solo responde si hay una sesion real de Moodle iniciada (no invitados).
 */
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isloggedin() || isguestuser()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'sin_sesion']);
    exit;
}

$secreto = $CFG->siteidentifier . (isset($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : '');
$uid = substr(hash_hmac('sha256', 'ada-uid:' . $USER->id, $secreto), 0, 32);
$sid = substr(hash_hmac('sha256', 'ada-sid:' . sesskey(), $secreto), 0, 32);

\core\session\manager::write_close();

echo json_encode(['ok' => true, 'uid' => $uid, 'sid' => $sid]);
