#!/usr/bin/env python3
"""Pruebas de comparar_resultados.py. Correr con:

    python3 -m unittest discover -s herramientas -p 'test_*.py'
"""

from __future__ import annotations

import io
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import comparar_resultados as cr  # noqa: E402

JUNIT = """<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="raiz" tests="4">
    <testsuite name="Tests\\Unit\\ATest" tests="3">
      <testcase name="test_pasa" class="Tests\\Unit\\ATest" time="0.01"/>
      <testcase name="test_falla" class="Tests\\Unit\\ATest" time="0.01">
        <failure type="X">no</failure>
      </testcase>
      <testcase name="test_error" class="Tests\\Unit\\ATest" time="0.01">
        <error type="Y">pum</error>
      </testcase>
    </testsuite>
    <testsuite name="Tests\\Unit\\BTest" tests="1">
      <testcase name="test_omitida" class="Tests\\Unit\\BTest" time="0.0">
        <skipped/>
      </testcase>
    </testsuite>
  </testsuite>
</testsuites>
"""

FALLA = "Tests\\Unit\\ATest::test_falla"
ERROR = "Tests\\Unit\\ATest::test_error"
PASA = "Tests\\Unit\\ATest::test_pasa"


class CompararResultadosTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self._tmp.name)
        self.junit = self.dir / "junit.xml"
        self.junit.write_text(JUNIT, encoding="utf-8")
        self.lista = self.dir / "fallos-conocidos.txt"

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def correr(self, conocidos: str, junit: Path | None = None) -> tuple[int, str, str]:
        self.lista.write_text(conocidos, encoding="utf-8")
        salida, errores = io.StringIO(), io.StringIO()
        with redirect_stdout(salida), redirect_stderr(errores):
            codigo = cr.main(["comparar", str(junit or self.junit), str(self.lista)])
        return codigo, salida.getvalue(), errores.getvalue()

    def test_lee_fallos_errores_y_omitidas(self) -> None:
        todas, fallidas, omitidas = cr.leer_junit(self.junit)
        self.assertEqual(len(todas), 4)
        self.assertEqual(fallidas, {FALLA, ERROR})
        self.assertEqual(omitidas, {"Tests\\Unit\\BTest::test_omitida"})

    def test_la_lista_ignora_comentarios_y_lineas_vacias(self) -> None:
        self.lista.write_text(f"# motivo\n\n  {FALLA}  \n", encoding="utf-8")
        self.assertEqual(cr.leer_conocidos(self.lista), {FALLA})

    def test_sale_0_si_los_fallos_son_exactamente_los_conocidos(self) -> None:
        codigo, salida, _ = self.correr(f"# dos conocidos\n{FALLA}\n{ERROR}\n")
        self.assertEqual(codigo, 0)
        self.assertIn("pruebas: 4 | pasan: 1 | no pasan: 2 | omitidas: 1 | conocidos: 2", salida)

    def test_sale_1_si_hay_un_fallo_nuevo(self) -> None:
        codigo, _, errores = self.correr(f"{FALLA}\n")
        self.assertEqual(codigo, 1)
        self.assertIn(f"FALLO NUEVO: {ERROR}", errores)

    def test_sale_1_si_un_conocido_ya_pasa(self) -> None:
        codigo, _, errores = self.correr(f"{FALLA}\n{ERROR}\n{PASA}\n")
        self.assertEqual(codigo, 1)
        self.assertIn(f"SOBRA EN LA LISTA (ya pasa): {PASA}", errores)

    def test_sale_1_si_un_conocido_ya_no_existe(self) -> None:
        codigo, _, errores = self.correr(f"{FALLA}\n{ERROR}\nTests\\Unit\\ZTest::test_x\n")
        self.assertEqual(codigo, 1)
        self.assertIn("SOBRA EN LA LISTA (ya no existe): Tests\\Unit\\ZTest::test_x", errores)

    def test_sale_1_si_el_informe_no_trae_pruebas(self) -> None:
        vacio = self.dir / "vacio.xml"
        vacio.write_text("<testsuites></testsuites>", encoding="utf-8")
        codigo, _, errores = self.correr("", vacio)
        self.assertEqual(codigo, 1)
        self.assertIn("no trae ninguna prueba", errores)

    def test_sale_1_si_el_informe_no_existe_o_esta_roto(self) -> None:
        codigo, _, _ = self.correr("", self.dir / "no-existe.xml")
        self.assertEqual(codigo, 1)
        roto = self.dir / "roto.xml"
        roto.write_text("<testsuites>", encoding="utf-8")
        codigo, _, _ = self.correr("", roto)
        self.assertEqual(codigo, 1)

    def test_sale_1_con_argumentos_de_menos(self) -> None:
        with redirect_stderr(io.StringIO()):
            self.assertEqual(cr.main(["comparar"]), 1)


if __name__ == "__main__":
    unittest.main()
