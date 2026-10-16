# -*- coding: utf-8 -*-
import json, random, sys
from vocab import MATERIAS, NIVELES
N = int(sys.argv[1]) if len(sys.argv) > 1 else 1000
rnd = random.Random(2026)
mats = list(MATERIAS.keys())
PCTS = [(35,30,20,15),(30,30,20,20),(40,30,20,10),(25,25,25,25),(35,25,25,15)]
vistos = {}
cursos = []
for i in range(N):
    mat = mats[i % len(mats)]
    temas = rnd.sample(MATERIAS[mat], 4)
    nivel = NIVELES[(i // len(mats)) % len(NIVELES)]
    nombre = f"{mat} — {temas[0][0]} y {temas[1][0]} ({nivel})"
    vistos[nombre] = vistos.get(nombre, 0) + 1
    if vistos[nombre] > 1:
        nombre += f" · Grupo {vistos[nombre]}"
    pct = rnd.choice(PCTS)
    secs = []
    for k, (tema, claves) in enumerate(temas):
        secs.append({"name": f"Unidad {k+1} — {tema}", "summary": f"{claves[0].upper()+claves[1:]}. Representa el {pct[k]}% del curso."})
    cursos.append({
        "shortname": f"SIM-{i+1:04d}", "idnumber": f"SIM-{i+1:04d}", "fullname": nombre, "categoria": mat,
        "summary": f"Curso de {mat.lower()} que cubre {temas[0][0].lower()}, {temas[1][0].lower()}, {temas[2][0].lower()} y {temas[3][0].lower()}. Nivel {nivel.lower()}.",
        "sections": secs})
json.dump({"padre": "ZZ Catálogo simulado (borrar al terminar las pruebas)", "categorias": mats, "cursos": cursos},
          open("catalogo_spec.json", "w", encoding="utf-8"), ensure_ascii=False)
print("cursos:", len(cursos), "| materias:", len(mats), "| nombres unicos:", len({c['fullname'] for c in cursos}))
