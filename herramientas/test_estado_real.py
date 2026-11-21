#!/usr/bin/env python3
"""docs/ESTADO-REAL.md y api/tests/fallos-conocidos.txt no pueden separarse.

Si se agrega o se quita un fallo conocido, el informe tiene que decirlo. Correr con:

    python3 -m unittest discover -s herramientas -p 'test_*.py'
"""

from __future__ import annotations

import re
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import comparar_resultados as cr  # noqa: E402

RAIZ = Path(__file__).resolve().parent.parent
INFORME = RAIZ / "docs" / "ESTADO-REAL.md"
LISTA = RAIZ / "api" / "tests" / "fallos-conocidos.txt"

SECCIONES = (
    "## Resumen",
    "## Qué hubo que corregir para que arrancara",
    "## Resultado de la suite",
    "## Fallos conocidos por causa",
    "## Lo que la suite no cubre",
    "## Riesgos de seguridad vistos al arrancar",
    "## Cómo reproducir",
)

RE_GRUPO = re.compile(r"^#\s*(G\d+)\.", re.MULTILINE)


class EstadoRealTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(INFORME.is_file(), "falta docs/ESTADO-REAL.md")
        self.assertTrue(LISTA.is_file(), "falta api/tests/fallos-conocidos.txt")
        self.informe = INFORME.read_text(encoding="utf-8")
        self.lista = LISTA.read_text(encoding="utf-8")
        self.conocidos = cr.leer_conocidos(LISTA)

    def test_el_informe_tiene_todas_las_secciones(self) -> None:
        for seccion in SECCIONES:
            self.assertIn(seccion, self.informe)

    def test_cada_fallo_conocido_esta_nombrado_en_el_informe(self) -> None:
        self.assertTrue(self.conocidos, "la lista de fallos conocidos esta vacia")
        for identificador in sorted(self.conocidos):
            metodo = identificador.split("::", 1)[1]
            self.assertIn(f"`{metodo}`", self.informe, f"el informe no nombra {identificador}")

    def test_cada_grupo_de_la_lista_esta_en_el_informe(self) -> None:
        grupos = RE_GRUPO.findall(self.lista)
        self.assertTrue(grupos, "la lista no agrupa los fallos (# G1. ...)")
        for grupo in grupos:
            self.assertIn(f"| {grupo} |", self.informe, f"el informe no tiene la fila de {grupo}")
            self.assertIn(f"**{grupo}:**", self.informe, f"el informe no lista las pruebas de {grupo}")

    def test_el_informe_declara_el_total_de_fallos_de_la_lista(self) -> None:
        total = re.search(r"\|\s*\*\*Total\*\*\s*\|\s*\*\*(\d+)\*\*\s*\|\s*\*\*(\d+)\*\*\s*\|\s*\*\*(\d+)\*\*\s*\|", self.informe)
        self.assertIsNotNone(total, "falta la fila **Total** de la tabla de resultados")
        pruebas, pasan, no_pasan = (int(total.group(i)) for i in (1, 2, 3))
        self.assertEqual(no_pasan, len(self.conocidos))
        self.assertEqual(pruebas, pasan + no_pasan)


if __name__ == "__main__":
    unittest.main()
