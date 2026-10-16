<?php
/**
 * Ada - Catalogo de cursos con busqueda en el servidor.
 *
 * Reemplaza la descarga del catalogo completo (get_courses_full del bridge, que hace
 * varias peticiones HTTP por curso) por consultas directas a la base de datos que
 * devuelven SOLO lo necesario. Expone metadatos publicos de cursos visibles
 * (nombre, categoria, unidades, enlace); nunca datos personales.
 *
 *   action=resumen                     total de cursos y categorias
 *   action=buscar&q=texto[&limite=5]   cursos/unidades que tratan un tema, con enlace
 *   action=curso&q=texto | id=N        un curso con todas sus unidades
 *   action=contexto&q=texto            formato del bridge para el prompt del modelo (solo cursos relevantes)
 */
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$t0 = microtime(true);

function out($arr) {
    global $t0;
    $arr['ms'] = round((microtime(true) - $t0) * 1000);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function norm($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D);
        $s = preg_replace('/\p{Mn}+/u', '', $s);
    }
    return $s;
}

function limpiar($h) {
    $h = preg_replace('/<style[^>]*>.*?<\/style>/is', '', (string)$h);
    $h = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $h);
    $h = str_replace(['</p>', '<br>', '<br/>', '<br />', '</div>', '</li>'], ' ', (string)$h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}

function peso($txt) {
    return preg_match('/(\d+)\s*%/', (string)$txt, $m) ? (int)$m[1] : null;
}

function url_curso($id) {
    global $CFG;
    return $CFG->wwwroot . '/course/view.php?id=' . (int)$id;
}

$STOP = explode(' ', 'que cual cuales como donde cuando cuanto cuantos cuantas con sin por para sobre entre desde hasta del las los una uno unos unas '
    . 'mas muy pero tambien este esta estos estas ese esa eso aqui alli ahi mis tus sus nos ' . 'ofreces ofrece ofrecen ofrecer tienen tiene tienes tengan tengas '
    . 'hay haya hayan dame dar dime darme podrias puedes puede podria quiero quisiera quisiese necesito busco buscar buscando saber conocer ver '
    . 'aprender estudiar estudio estudiando repasar encontrar enfoque enfocado enfocados enfocada curso cursos tema temas unidad unidades materia '
    . 'materias clase clases link enlace url pagina favor gracias hola quiero algun alguna algunos algunas existe existen disponible disponibles '
    . 'esta estan estoy soy ser fue pedi pido pedir plataforma escuela rural academico academicos ofrecen ofreces');

function tokens($q) {
    global $STOP;
    preg_match_all('/[a-z0-9]{3,}/', norm($q), $m);
    $out = [];
    foreach ($m[0] as $t) {
        if (in_array($t, $STOP, true)) continue;
        if (strlen($t) > 5 && substr($t, -2) === 'es') $t = substr($t, 0, -2);
        elseif (strlen($t) > 4 && substr($t, -1) === 's') $t = substr($t, 0, -1);
        $out[$t] = true;
    }
    return array_keys($out);
}

function contiene($texto, $tok) {
    return $texto !== '' && strpos(norm($texto), $tok) !== false;
}

function buscar_cursos($toks, $limite) {
    global $DB;
    if (!$toks) return [0, []];
    $params = [];
    $conds = [];
    foreach ($toks as $i => $t) {
        foreach (['c.fullname', 'c.summary', 's.name', 's.summary'] as $j => $f) {
            $p = "t{$i}_{$j}";
            $conds[] = $DB->sql_like($f, ":$p", false, false);
            $params[$p] = '%' . $DB->sql_like_escape($t) . '%';
        }
    }
    $sql = "SELECT s.id AS sid, c.id, c.fullname, c.shortname, c.summary AS csum, cc.name AS catname,
                   s.section, s.name AS sname, s.summary AS ssum
              FROM {course} c
              JOIN {course_categories} cc ON cc.id = c.category
         LEFT JOIN {course_sections} s ON s.course = c.id AND s.section > 0
             WHERE c.id <> 1 AND c.visible = 1 AND cc.visible = 1 AND (" . implode(' OR ', $conds) . ")";
    $res = [];
    foreach ($DB->get_recordset_sql($sql, $params) as $r) {
        $id = (int)$r->id;
        if (!isset($res[$id])) {
            $res[$id] = ['id' => $id, 'nombre' => $r->fullname, 'categoria' => $r->catname, 'url' => url_curso($id),
                         'mejor' => [], 'unidades' => []];
        }
        $unidadCoincide = false;
        foreach ($toks as $t) {
            $w = 0;
            if (contiene($r->fullname, $t)) $w = max($w, 5);
            if (contiene($r->sname, $t)) { $w = max($w, 4); $unidadCoincide = true; }
            if (contiene($r->ssum, $t)) { $w = max($w, 3); $unidadCoincide = true; }
            if (contiene($r->csum, $t)) $w = max($w, 1);
            if ($w > 0) {
                $res[$id]['mejor'][$t] = max($res[$id]['mejor'][$t] ?? 0, $w);
            }
        }
        if ($unidadCoincide && $r->sname !== null) {
            $res[$id]['unidades'][$r->sid] = ['nombre' => $r->sname, 'peso' => peso($r->ssum)];
        }
    }
    $lista = [];
    foreach ($res as $c) {
        if (!$c['mejor']) continue;
        $c['tokens_coinciden'] = count($c['mejor']);
        $c['puntaje'] = array_sum($c['mejor']);
        $c['unidades'] = array_values($c['unidades']);
        unset($c['mejor']);
        $lista[] = $c;
    }
    usort($lista, function ($a, $b) {
        return [$b['tokens_coinciden'], $b['puntaje'], $a['id']] <=> [$a['tokens_coinciden'], $a['puntaje'], $b['id']];
    });
    return [count($lista), array_slice($lista, 0, $limite)];
}

function unidades_curso($id) {
    global $DB;
    $acts = $DB->get_records_sql("SELECT cm.section, COUNT(1) AS n FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name <> 'label' WHERE cm.course = ? AND cm.deletioninprogress = 0 GROUP BY cm.section", [$id]);
    $secs = $DB->get_records('course_sections', ['course' => $id], 'section');
    // El texto de las etiquetas (labels) de cada seccion tambien describe la unidad
    // (p. ej. el porcentaje); el bridge anterior lo agregaba a la descripcion.
    $labels = [];
    foreach ($DB->get_records_sql("SELECT cm.id, cm.section, l.intro FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module AND m.name = 'label' JOIN {label} l ON l.id = cm.instance
            WHERE cm.course = ? AND cm.deletioninprogress = 0 ORDER BY cm.id", [$id]) as $l) {
        $txt = limpiar($l->intro);
        if ($txt !== '') $labels[$l->section][] = mb_substr($txt, 0, 600);
    }
    $out = [];
    foreach ($secs as $s) {
        if ((int)$s->section === 0) continue;
        $n = isset($acts[$s->id]) ? (int)$acts[$s->id]->n : 0;
        $desc = limpiar($s->summary);
        if (!empty($labels[$s->id])) $desc .= "\n\n" . implode("\n\n", $labels[$s->id]);
        $out[] = ['nombre' => $s->name, 'descripcion' => $desc, 'peso' => peso($desc),
                  'actividades' => array_fill(0, $n, ['tipo' => 'actividad'])];
    }
    return $out;
}

function curso_completo($c) {
    return ['id' => (int)$c->id, 'nombre' => $c->fullname, 'nombre_corto' => $c->shortname, 'descripcion' => limpiar($c->summary),
            'url' => url_curso($c->id), 'secciones' => unidades_curso($c->id)];
}

$action = $_GET['action'] ?? '';
$q = $_GET['q'] ?? '';
$limite = max(1, min(10, (int)($_GET['limite'] ?? 5)));

if ($action === 'resumen') {
    $total = $DB->count_records_select('course', 'id <> 1 AND visible = 1');
    $cats = $DB->get_records_sql("SELECT cc.id, cc.name, COUNT(c.id) AS n FROM {course_categories} cc JOIN {course} c ON c.category = cc.id
                                  WHERE c.visible = 1 AND cc.visible = 1 AND c.id <> 1 GROUP BY cc.id, cc.name ORDER BY n DESC, cc.name");
    $lista = [];
    foreach ($cats as $c) $lista[] = ['nombre' => $c->name, 'cursos' => (int)$c->n];
    $ej = $DB->get_records_select('course', 'id <> 1 AND visible = 1', null, 'id', 'id, fullname', 0, 6);
    $ejemplos = [];
    foreach ($ej as $c) $ejemplos[] = ['id' => (int)$c->id, 'nombre' => $c->fullname, 'url' => url_curso($c->id)];
    out(['success' => true, 'total' => (int)$total, 'categorias' => $lista, 'ejemplos' => $ejemplos]);
}

if ($action === 'buscar') {
    $toks = tokens($q);
    list($total, $cursos) = buscar_cursos($toks, $limite);
    out(['success' => true, 'tokens' => $toks, 'total_coincidencias' => $total, 'cursos' => $cursos]);
}

if ($action === 'curso') {
    if (!empty($_GET['id'])) {
        $c = $DB->get_record('course', ['id' => (int)$_GET['id'], 'visible' => 1]);
        if (!$c) out(['success' => true, 'encontrado' => false]);
        out(['success' => true, 'encontrado' => true, 'ambiguo' => false, 'curso' => curso_completo($c)]);
    }
    $toks = tokens($q);
    if (!$toks) out(['success' => true, 'encontrado' => false]);
    $params = [];
    $conds = [];
    foreach ($toks as $i => $t) {
        foreach (['fullname', 'shortname'] as $j => $f) {
            $conds[] = $DB->sql_like($f, ":t{$i}_{$j}", false, false);
            $params["t{$i}_{$j}"] = '%' . $DB->sql_like_escape($t) . '%';
        }
    }
    $cands = [];
    foreach ($DB->get_records_select('course', 'id <> 1 AND visible = 1 AND (' . implode(' OR ', $conds) . ')', $params, 'id', '*', 0, 200) as $c) {
        $n = 0;
        foreach ($toks as $t) if (contiene($c->fullname . ' ' . $c->shortname, $t)) $n++;
        if ($n > 0) $cands[] = ['n' => $n, 'c' => $c];
    }
    if (!$cands) out(['success' => true, 'encontrado' => false]);
    usort($cands, function ($a, $b) { return [$b['n'], $a['c']->id] <=> [$a['n'], $b['c']->id]; });
    $mejor = $cands[0]['n'];
    $empatados = array_filter($cands, function ($x) use ($mejor) { return $x['n'] === $mejor; });
    $ambiguo = count($empatados) > 1;
    $otros = [];
    foreach (array_slice(array_values($empatados), 0, 5) as $x) $otros[] = ['id' => (int)$x['c']->id, 'nombre' => $x['c']->fullname, 'url' => url_curso($x['c']->id)];
    out(['success' => true, 'encontrado' => true, 'ambiguo' => $ambiguo, 'candidatos' => $otros, 'curso' => curso_completo($cands[0]['c'])]);
}

if ($action === 'contexto') {
    $total = (int)$DB->count_records_select('course', 'id <> 1 AND visible = 1');
    $cursos = [];
    if ($total <= 6) {
        foreach ($DB->get_records_select('course', 'id <> 1 AND visible = 1', null, 'id') as $c) $cursos[] = curso_completo($c);
        out(['success' => true, 'total_cursos' => $total, 'parcial' => false, 'cursos' => $cursos]);
    }
    list($n, $top) = buscar_cursos(tokens($q), 3);
    foreach ($top as $t) $cursos[] = curso_completo($DB->get_record('course', ['id' => $t['id']]));
    out(['success' => true, 'total_cursos' => $total, 'parcial' => true, 'coincidencias' => $n, 'cursos' => $cursos]);
}

http_response_code(400);
out(['success' => false, 'error' => 'accion_desconocida']);
