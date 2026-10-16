# Catálogo sintético de prueba (validación de escala)

Genera ~1.000 cursos de prueba en una categoría aparte del Moodle piloto, para validar
cómo responde Ada con un catálogo grande sin tocar los cursos reales.

1. `python gen_spec.py 1000` → crea `catalogo_spec.json` (17 materias × 10 temas, 4 unidades por curso, usando `vocab.py`).
2. Copiar `catalogo_spec.json` y `crear_catalogo.php` al contenedor de Moodle y ejecutar:
   `php /tmp/crear_catalogo.php /tmp/catalogo_spec.json` (≈3 min para 1.000 cursos).
3. Al terminar las pruebas: `php borrar_catalogo.php` elimina la categoría
   "ZZ Catálogo simulado" y todos sus cursos.

Hacer siempre un respaldo de la base de datos de Moodle antes de crear el catálogo.
Estos datos son simulados: no representan el catálogo real de la empresa.
