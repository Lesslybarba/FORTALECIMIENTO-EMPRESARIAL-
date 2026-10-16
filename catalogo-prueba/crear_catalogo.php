<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
\core\session\manager::set_user(get_admin());
$spec = json_decode(file_get_contents($argv[1]), true);
$t0 = microtime(true);
$padre = core_course_category::create(['name' => $spec['padre'], 'parent' => 0]);
$cats = [];
foreach ($spec['categorias'] as $n) {
    $c = core_course_category::create(['name' => $n, 'parent' => $padre->id]);
    $cats[$n] = $c->id;
}
echo "categoria padre: {$padre->id}\n";
$i = 0;
foreach ($spec['cursos'] as $c) {
    $data = (object)[
        'fullname' => $c['fullname'], 'shortname' => $c['shortname'], 'idnumber' => $c['idnumber'],
        'category' => $cats[$c['categoria']], 'summary' => $c['summary'], 'summaryformat' => FORMAT_HTML,
        'format' => 'topics', 'visible' => 1, 'startdate' => time(),
    ];
    $course = create_course($data);
    course_create_sections_if_missing($course, [1, 2, 3, 4]);
    foreach ($c['sections'] as $k => $s) {
        $sec = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $k + 1]);
        $DB->update_record('course_sections', (object)['id' => $sec->id, 'name' => $s['name'], 'summary' => $s['summary'], 'summaryformat' => FORMAT_HTML]);
    }
    $i++;
    if ($i % 50 == 0) { echo "$i cursos (" . round(microtime(true) - $t0) . " s)\n"; }
}
rebuild_course_cache(0, true);
purge_all_caches();
echo "LISTO: $i cursos creados en " . round(microtime(true) - $t0) . " s\n";
