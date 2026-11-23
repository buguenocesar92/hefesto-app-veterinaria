#!/usr/bin/env python3
"""Ningun job de .github/workflows queda clavado a un runner hosted de GitHub.

Cesar pidio todo el CI en el Xeon: los runners de GitHub de esta cuenta fallan
por facturacion. Con la variable de repositorio RUNNER_CI=xeon los jobs van a
una VM efimera del Xeon; un `runs-on: ubuntu-...` fijo no obedece esa variable
y deja el CI parado. Correr con:

    python3 -m unittest discover -s herramientas -p 'test_*.py'
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
WORKFLOWS = RAIZ / ".github" / "workflows"

FIJO = re.compile(
    r"""^\s+runs-on:\s*\[?\s*['"]?ubuntu-(latest|[0-9]+\.[0-9]+)['"]?\s*\]?\s*(#.*)?$"""
)
JOB = re.compile(r"^  ([A-Za-z0-9_-]+):\s*(#.*)?$")

ESPERADO = (
    "    runs-on: ${{ (vars.RUNNER_CI == 'xeon' || github.actor == 'dependabot[bot]')"
    " && (github.event_name != 'pull_request'"
    " || github.event.pull_request.head.repo.full_name == github.repository)"
    " && fromJSON('[\"self-hosted\",\"xeon-ci\"]') || 'ubuntu-24.04' }}\n"
)


def clavados() -> list[str]:
    encontrados: list[str] = []
    archivos = sorted([*WORKFLOWS.glob("*.yml"), *WORKFLOWS.glob("*.yaml")])
    for archivo in archivos:
        en_jobs = False
        job: str | None = None
        for linea in archivo.read_text(encoding="utf-8").split("\n"):
            if re.match(r"^[A-Za-z]", linea):
                en_jobs = re.match(r"^jobs:\s*$", linea) is not None
                job = None
                continue
            m = JOB.match(linea)
            if en_jobs and m:
                job = m.group(1)
                continue
            if en_jobs and job is not None and FIJO.match(linea):
                encontrados.append(f"{archivo.name}:{job}")
    return encontrados


class PoliticaDeWorkflows(unittest.TestCase):
    def test_hay_workflows(self) -> None:
        self.assertTrue(list(WORKFLOWS.glob("*.yml")) or list(WORKFLOWS.glob("*.yaml")))

    def test_ningun_job_clavado_a_ubuntu_hosted(self) -> None:
        # 'archivo.yml:job' -> razon por la que ese job no puede ir al Xeon.
        # Vacia a proposito: hoy todos los jobs pueden ir a la VM del Xeon.
        excepciones: dict[str, str] = {}
        self.assertEqual([c for c in clavados() if c not in excepciones], [])

    def test_job_api_sigue_a_runner_ci_y_deja_forks_en_github(self) -> None:
        texto = (WORKFLOWS / "ci.yml").read_text(encoding="utf-8")
        self.assertIn(ESPERADO, texto)


if __name__ == "__main__":
    unittest.main()
