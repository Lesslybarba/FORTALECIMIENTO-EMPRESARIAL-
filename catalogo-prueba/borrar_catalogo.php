<?php
// Elimina por completo el catalogo sintetico de prueba (categoria padre y sus cursos).
// Uso: php borrar_catalogo.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
\core\session\manager::set_user(get_admin());
global $DB;
$padre = $DB->get_record_select('course_categories', "name LIKE 'ZZ Catálogo simulado%'");
if (!$padre) { echo "No hay catalogo simulado.\n"; exit(0); }
$t0 = microtime(true);
core_course_category::get($padre->id)->delete_full(false);
purge_all_caches();
echo "Catalogo simulado eliminado en " . round(microtime(true) - $t0) . " s. Cursos restantes: " . $DB->count_records_select('course', 'id <> 1') . "\n";
