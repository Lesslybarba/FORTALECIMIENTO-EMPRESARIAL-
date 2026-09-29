<?php
/**
 * Bridge v2 - Middleware inteligente Moodle -> n8n -> Ollama
 * Agrega y limpia datos de multiples endpoints de la API REST de Moodle
 * y los devuelve en una estructura unificada lista para consumir por un LLM.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ============================================================
// CONFIGURACION
// ============================================================
$token = getenv('MOODLE_WS_TOKEN');
$moodle_base = 'http://localhost/webservice/rest/server.php';
$host_header = 'Host: localhost:8080';

// ============================================================
// FUNCION: Llamar a la API de Moodle
// ============================================================
function callMoodle($function, $params = []) {
    global $token, $moodle_base, $host_header;

    $url = $moodle_base . '?wstoken=' . $token
         . '&wsfunction=' . $function
         . '&moodlewsrestformat=json';

    foreach ($params as $key => $value) {
        $url .= '&' . urlencode($key) . '=' . urlencode($value);
    }

    $context = stream_context_create([
        'http' => [
            'header' => $host_header,
            'timeout' => 15
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

// ============================================================
// FUNCION: Limpiar HTML a texto plano legible
// ============================================================
function limpiarHtml($html) {
    if (empty($html)) return '';

    // Eliminar bloques <style> y <script> completos (incluyendo su contenido)
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

    // Convertir entidades HTML (&amp;, &#x1F9EA;, etc.) a caracteres reales
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Convertir tags de bloque en saltos de linea antes de eliminarlos
    $html = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)[^>]*>/i', "\n", $html);

    // Eliminar el resto de tags HTML
    $texto = strip_tags($html);

    // Colapsar espacios multiples y limpiar saltos de linea excesivos
    $texto = preg_replace('/[ \t]+/', ' ', $texto);
    $texto = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $texto);
    $texto = trim($texto);

    return $texto;
}

// ============================================================
// FUNCION: Formatear timestamp Unix a fecha legible
// ============================================================
function formatearFecha($timestamp) {
    if (empty($timestamp) || $timestamp == 0) return null;
    return date('Y-m-d', $timestamp);
}

// ============================================================
// FUNCION: Mapear tipo de modulo a etiqueta amigable
// ============================================================
function tipoModulo($modname) {
    $tipos = [
        'forum'    => 'foro',
        'assign'   => 'tarea',
        'quiz'     => 'cuestionario',
        'resource' => 'recurso',
        'url'      => 'enlace',
        'page'     => 'pagina',
        'label'    => 'etiqueta',
        'folder'   => 'carpeta',
        'book'     => 'libro',
        'choice'   => 'encuesta',
        'feedback' => 'retroalimentacion',
        'lesson'   => 'leccion',
        'workshop' => 'taller',
    ];
    return isset($tipos[$modname]) ? $tipos[$modname] : $modname;
}

// ============================================================
// ROUTER: Decidir que endpoint ejecutar
// ============================================================
$action = isset($_GET['action']) ? $_GET['action'] : null;

// ---- Modo compatibilidad: comportamiento del bridge v1 ----
if ($action === null) {
    $function = isset($_GET['wsfunction']) ? $_GET['wsfunction'] : 'core_course_get_courses';
    $params = $_GET;
    unset($params['wsfunction']);
    $result = callMoodle($function, $params);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Nuevo endpoint: get_courses_full ----
if ($action === 'get_courses_full') {

    // 1. Obtener todos los cursos
    $cursos_raw = callMoodle('core_course_get_courses');
    if ($cursos_raw === null) {
        echo json_encode(['success' => false, 'error' => 'No se pudo consultar cursos']);
        exit;
    }

    // 2. Obtener todas las categorias
    $categorias_raw = callMoodle('core_course_get_categories');
    $categorias_map = [];
    if (is_array($categorias_raw)) {
        foreach ($categorias_raw as $cat) {
            $categorias_map[$cat['id']] = $cat['name'];
        }
    }

    // 3. Procesar cada curso
    $cursos_final = [];
    foreach ($cursos_raw as $curso) {
        // Ignorar el curso "front page" (id=1)
        if ($curso['id'] == 1) continue;

        // Datos basicos
        $curso_info = [
            'id' => $curso['id'],
            'nombre' => $curso['fullname'],
            'nombre_corto' => $curso['shortname'],
            'descripcion' => limpiarHtml($curso['summary']),
            'categoria' => isset($categorias_map[$curso['categoryid']])
                ? $categorias_map[$curso['categoryid']]
                : null,
            'fecha_inicio' => formatearFecha($curso['startdate']),
            'fecha_fin' => formatearFecha($curso['enddate']),
            'url' => 'http://localhost:8080/course/view.php?id=' . $curso['id'],
            'profesores' => [],
            'num_estudiantes' => 0,
            'secciones' => []
        ];

        // 4. Obtener usuarios matriculados
        $usuarios = callMoodle('core_enrol_get_enrolled_users', ['courseid' => $curso['id']]);
        if (is_array($usuarios)) {
            foreach ($usuarios as $usuario) {
                $es_profesor = false;
                if (isset($usuario['roles']) && is_array($usuario['roles'])) {
                    foreach ($usuario['roles'] as $rol) {
                        // Roles de Moodle: 3=editingteacher, 4=teacher
                        if (in_array($rol['roleid'], [3, 4])) {
                            $es_profesor = true;
                            break;
                        }
                    }
                }
                if ($es_profesor) {
                    $curso_info['profesores'][] = [
                        'nombre' => $usuario['fullname'],
                        'email' => isset($usuario['email']) ? $usuario['email'] : null
                    ];
                } else {
                    $curso_info['num_estudiantes']++;
                }
            }
        }

        // 5. Obtener contenidos (secciones y actividades)
        $secciones = callMoodle('core_course_get_contents', ['courseid' => $curso['id']]);
        if (is_array($secciones)) {
            foreach ($secciones as $sec) {
                // Saltar la seccion 0 (general/avisos) si no tiene contenido util
                if ($sec['section'] == 0 && empty($sec['summary'])) continue;

                $seccion_info = [
                    'nombre' => $sec['name'],
                    'descripcion' => limpiarHtml($sec['summary']),
                    'actividades' => []
                ];

                if (isset($sec['modules']) && is_array($sec['modules'])) {
                    foreach ($sec['modules'] as $mod) {
                        // Las "labels" con contenido rico se agregan a la descripcion de la seccion
                        if ($mod['modname'] === 'label' && isset($mod['description'])) {
                            $texto_label = limpiarHtml($mod['description']);
                            if (!empty($texto_label)) {
                                $seccion_info['descripcion'] .= "\n\n" . $texto_label;
                            }
                            continue;
                        }

                        // El resto de modulos son actividades reales
                        $actividad = [
                            'tipo' => tipoModulo($mod['modname']),
                            'nombre' => html_entity_decode($mod['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        ];
                        if (!empty($mod['url'])) {
                            $actividad['url'] = $mod['url'];
                        }
                        $seccion_info['actividades'][] = $actividad;
                    }
                }

                $seccion_info['descripcion'] = trim($seccion_info['descripcion']);
                $curso_info['secciones'][] = $seccion_info;
            }
        }

        $cursos_final[] = $curso_info;
    }

    // 6. Respuesta final
    echo json_encode([
        'success' => true,
        'total_cursos' => count($cursos_final),
        'cursos' => $cursos_final
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- Accion desconocida ----
echo json_encode([
    'success' => false,
    'error' => 'Accion no reconocida. Usa ?action=get_courses_full o ?wsfunction=...'
]);
