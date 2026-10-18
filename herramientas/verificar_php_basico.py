#!/usr/bin/env python3
"""Revisa que las llaves y parentesis de los archivos PHP esten balanceados.

Herramienta de desarrollo, no de produccion: no reemplaza a `php -l`, que si
entiende PHP. Sirve para detectar de temprano un archivo al que le quedo una
llave, sin necesidad de tener PHP instalado.

    python herramientas/verificar_php_basico.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent


def sin_comentarios_ni_cadenas(texto: str) -> str:
    """
    Deja solo el codigo: saca heredocs, cadenas y comentarios.

    EL ORDEN IMPORTA. Primero el heredoc (su cuerpo puede traer comillas y
    llaves de CSS), despues las cadenas (para que un `//` dentro de una cadena
    no se lea como comentario), y al final los comentarios. En PHP no existe
    el comentario con `#`, y tratar el numeral como si lo fuera rompe la
    revision con cadenas como `'##.###.###-#'`.
    """
    texto = re.sub(r"<<<\s*'?\"?[A-Z_]+\s*\n.*?\n\s*[A-Z_]+\s*;", " ", texto, flags=re.S)
    texto = re.sub(r"'(?:\\.|[^'\\])*'", " '' ", texto)
    texto = re.sub(r'"(?:\\.|[^"\\])*"', ' "" ', texto)
    texto = re.sub(r"/\*.*?\*/", " ", texto, flags=re.S)
    texto = re.sub(r"//[^\n]*", " ", texto)
    return texto


def revisar(raiz: Path) -> list[str]:
    hallazgos: list[str] = []
    archivos = 0

    for carpeta in ("api/app", "api/database", "api/routes", "api/config", "api/tests", "api/bootstrap"):
        base = raiz / carpeta

        if not base.is_dir():
            continue

        for ruta in sorted(base.rglob("*.php")):
            archivos += 1
            codigo = sin_comentarios_ni_cadenas(ruta.read_text(encoding="utf-8"))

            llaves = (codigo.count("{"), codigo.count("}"))
            parentesis = (codigo.count("("), codigo.count(")"))

            if llaves[0] != llaves[1] or parentesis[0] != parentesis[1]:
                hallazgos.append(
                    f"{ruta.relative_to(raiz)}: llaves {llaves[0]}/{llaves[1]}, "
                    f"parentesis {parentesis[0]}/{parentesis[1]}"
                )

    print(f"Archivos PHP revisados: {archivos}")

    return hallazgos


def main() -> int:
    raiz = RAIZ

    if not (raiz / "api").is_dir():
        print(f"No se encontro la API en {raiz / 'api'}", file=sys.stderr)
        return 1

    hallazgos = revisar(raiz)

    if not hallazgos:
        print("Llaves y parentesis balanceados en todo el codigo PHP.")
        return 0

    print("\nArchivos desbalanceados:")

    for hallazgo in hallazgos:
        print(f"  - {hallazgo}")

    return 1


if __name__ == "__main__":
    raise SystemExit(main())