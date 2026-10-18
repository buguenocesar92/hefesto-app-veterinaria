#!/usr/bin/env python3
r"""
Revisa que el proyecto este estructuralmente sano, sin necesidad de PHP.

Este script NO reemplaza a PHP. Revisa tres cosas que se pueden comprobar
leyendo texto, y que son las que mas se rompen cuando se escribe codigo en
muchos archivos:

  1. INTEGRIDAD DE PHP: llaves balanceadas, archivo terminado, `<?php` al
     principio.
  2. REFERENCIAS INTERNAS: que todo `use App\...`, el `extends` de un
     controlador y los controladores de `routes/web.php` apunten a una clase
     que exista de verdad.
  3. CLASES DECLARADAS: que cada clase publicamente nombrada tenga su archivo.

El punto 2 es el que mas importa: un `use` a una clase que no existe no
produce ningun aviso hasta que PHP carga el archivo, y si es una ruta, hasta
que alguien entra por esa ruta.

    python herramientas/verificar_estructura.py

Codigo de salida: 0 si no encontro nada, 1 si encontro.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

EXCLUIDOS = {
    "vendor", "node_modules", ".nuxt", ".output", ".phpunit.cache", "storage", "dist",
}


def archivos_php() -> list[Path]:
    return sorted(
        p for p in RAIZ.rglob("*.php")
        if not any(parte in EXCLUIDOS for parte in p.parts)
    )


def php_integridad(ruta: Path) -> list[str]:
    """Llaves balanceadas y estructura basica."""
    problemas: list[str] = []
    texto = ruta.read_text(encoding="utf-8")

    if not texto.startswith("<?php"):
        problemas.append(f"{ruta.name}: no empieza con <?php")

    # Se quitan cadenas y comentarios para no contar llaves que estan dentro.
    limpio = re.sub(r"/\*.*?\*/", "", texto, flags=re.S)
    limpio = re.sub(r"//[^\n]*", "", limpio)
    limpio = re.sub(r"'(?:\\.|[^'\\])*'", "''", limpio)
    limpio = re.sub(r'"(?:\\.|[^"\\])*"', '""', limpio)

    llaves = limpio.count("{") - limpio.count("}")
    parentesis = limpio.count("(") - limpio.count(")")
    corchetes = limpio.count("[") - limpio.count("]")

    if llaves != 0:
        problemas.append(f"{ruta.name}: llaves desbalanceadas ({llaves:+d})")
    if parentesis != 0:
        problemas.append(f"{ruta.name}: parentesis desbalanceados ({parentesis:+d})")
    if corchetes != 0:
        problemas.append(f"{ruta.name}: corchetes desbalanceados ({corchetes:+d})")

    return problemas


def clases_declaradas() -> dict[str, Path]:
    """Mapa de FQCN a archivo, para resolver referencias."""
    mapa: dict[str, Path] = {}

    for ruta in archivos_php():
        texto = ruta.read_text(encoding="utf-8")

        namespace = re.search(r"^namespace\s+([A-Za-z0-9_\\]+)\s*;", texto, flags=re.M)
        if namespace is None:
            continue

        prefijo = namespace.group(1) + "\\"

        for coincidencia in re.finditer(
            r"^(?:final\s+|abstract\s+)?(?:class|enum|interface|trait)\s+([A-Za-z0-9_]+)",
            texto,
            flags=re.M,
        ):
            mapa[prefijo + coincidencia.group(1)] = ruta

    return mapa


def referencias_rotas(mapa: dict[str, Path]) -> list[str]:
    """`use App\\...` que apuntan a clases que no existen."""
    problemas: list[str] = []
    # Clases que vienen de Laravel, no del proyecto.
    externos = re.compile(r"^App\\(Http|Models|Policies|Providers|Console|Support)\\")

    for ruta in archivos_php():
        texto = ruta.read_text(encoding="utf-8")
        namespace = re.search(r"^namespace\s+([A-Za-z0-9_\\]+)\s*;", texto, flags=re.M)
        propio = namespace.group(1) + "\\" if namespace else ""

        for coincidencia in re.finditer(r"^use\s+(App\\[A-Za-z0-9_\\]+)\s*;", texto, flags=re.M):
            fqcn = coincidencia.group(1)

            # `App\Http\Controllers\X` y `App\Enums\Y` SI deben existir.
            # Se ignoran los que solo reexportan algo de otro namespace.
            if fqcn not in mapa:
                problemas.append(f"{ruta.relative_to(RAIZ)}: `use {fqcn}` no existe")

    return problemas


def controladores_de_rutas(mapa: dict[str, Path]) -> list[str]:
    """Los controladores usados en routes/web.php tienen que existir."""
    problemas: list[str] = []
    rutas = RAIZ / "api" / "routes" / "web.php"

    if not rutas.exists():
        return [f"falta {rutas.relative_to(RAIZ)}"]

    texto = rutas.read_text(encoding="utf-8")

    # Se resuelve cada `use` de la cabecera de rutas.
    imports = dict(re.findall(r"use\s+(App\\Http\\Controllers\\[^;]+?)\s+as\s+([^;]+);", texto))
    imports.update({m: m for m in re.findall(r"use\s+(App\\Http\\Controllers\\[A-Za-z0-9_\\]+);", texto)})

    for referencia in re.findall(r"\[([A-Za-z0-9_]+)::class\]", texto):
        # Se busca en el mapa de imports por el alias.
        candidato = imports.get(referencia)
        if candidato is None:
            problemas.append(f"routes/web.php: no se resuelve el alias «{referencia}»")
            continue
        if candidato not in mapa:
            problemas.append(f"routes/web.php: el controlador «{candidato}» no existe")

    return problemas


def main() -> int:
    problemas: list[str] = []

    for ruta in archivos_php():
        problemas.extend(php_integridad(ruta))

    mapa = clases_declaradas()

    if not mapa:
        problemas.append("No se encontro ninguna clase App\\. Revisar que exista api/app.")

    problemas.extend(referencias_rotas(mapa))
    problemas.extend(controladores_de_rutas(mapa))

    if problemas:
        print(f"Se encontraron {len(problemas)} problemas estructurales:\n")
        for p in sorted(set(problemas)):
            print(f"  {p}")
        print("\nCorrige y vuelve a ejecutar este script.")
        return 1

    print(f"Estructura correcta: {len(mapa)} clases declaradas, "
          f"{len(archivos_php())} archivos PHP sin errores de sintaxis evidente.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
