#!/usr/bin/env python3
"""
Revisa que las clases internas que se usan existan de verdad.

Se ejecuta sin PHP ni Node:

    python herramientas/verificar_referencias.py

Salida: 0 si no hay referencias rotas, 1 si hay.

QUE REVISA
----------
1. Que cada clase, interfaz, enum o trait declarado en `api/app`,
   `api/database` y `api/tests` este en la ruta que corresponde a su nombre.
2. Que cada `use Clase\\Ruta\\Interna;` de un archivo del proyecto apunte a una
   clase declarada aqui. Los namespaces externos (Illuminate, Laravel,
   Firebase...) se saltan: no son codigo de esta aplicacion.
3. Que cada controlador usado en `api/routes/web.php` exista.

POR QUE EXISTE
--------------
En PHP, un `use` a una clase que no existe no falla al cargar el archivo: falla
despues, en el momento en que se usa esa clase, en produccion y con datos de
pacientes. Este script acorta esa distancia a un comando de un segundo.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

PREFIJOS_PROPIOS = ("App\\", "Database\\", "Tests\\")

# `namespace App\Models;` y `final class X extends Y implements Z`
RE_NAMESPACE = re.compile(r"^namespace\s+([A-Za-z0-9_\\]+)\s*;", re.MULTILINE)
# El nombre de una clase acepta tambien letras acentuadas: en PHP un
# identificador UTF-8 es valido. Este proyecto mantiene los identificadores en
# ASCII (ver docs/01), pero el verificador no debe dar falsos positivos si
# alguno se escapa con tilde.
RE_DECLARACION = re.compile(
    r"^(?:final\s+|abstract\s+)?(class|interface|trait|enum)\s+([A-Za-zÀ-ÿ0-9_]+)",
    re.MULTILINE,
)
RE_USE = re.compile(r"^use\s+([A-Za-zÀ-ÿ0-9_\\]+)\s*;", re.MULTILINE)
RE_CLASE_CONTROLADOR = re.compile(r"([A-Za-zÀ-ÿ0-9_\\]+Controller)::class")

DECLARATIVO = RE_DECLARACION


def declaran_clases() -> dict[str, Path]:
    """Mapa clase completa (con namespace) -> archivo donde se declara."""
    clases: dict[str, Path] = {}

    for carpeta in ("api/app", "api/database", "api/tests"):
        base = RAIZ / carpeta

        if not base.is_dir():
            continue

        for ruta in base.rglob("*.php"):
            texto = ruta.read_text(encoding="utf-8", errors="replace")

            namespace = RE_NAMESPACE.search(texto)

            if namespace is None:
                continue

            for _, nombre in DECLARATIVO.findall(texto):
                clases[f"{namespace.group(1)}\\{nombre}"] = ruta

    return clases


def rutas_de_api() -> list[Path]:
    """Archivos PHP del proyecto donde puede haber referencias."""
    return sorted((RAIZ / "api").rglob("*.php"))


def main() -> int:
    clases = declaran_clases()

    if not clases:
        print("No se encontro ninguna clase. Revise que esta ejecutandolo desde la aplicacion.")
        return 1

    faltantes: list[str] = []
    rotas: list[str] = []

    # 1. Ruta correcta para cada clase.
    for nombre, ruta in clases.items():
        esperado = ruta.stem

        if not nombre.endswith("\\" + esperado):
            faltantes.append(f"{nombre} deberia estar en {esperado}.php (esta en {ruta.name})")

    # 2. Cada `use` propio debe apuntar a una clase declarada.
    for ruta in rutas_de_api():
        texto = ruta.read_text(encoding="utf-8", errors="replace")

        for importado in RE_USE.findall(texto):
            if not importado.startswith(PREFIJOS_PROPIOS):
                continue

            if importado not in clases:
                rotas.append(f"{ruta.relative_to(RAIZ)}: use {importado}; no existe")

    # 3. Los controladores de las rutas tienen que existir.
    archivo_rutas = RAIZ / "api" / "routes" / "web.php"
    texto_rutas = archivo_rutas.read_text(encoding="utf-8", errors="replace")

    for controlador in RE_CLASE_CONTROLADOR.findall(texto_rutas):
        if not controlador.startswith("App\\"):
            continue

        if controlador not in clases:
            rotas.append(f"routes/web.php usa {controlador}::class y no existe")

    print(f"Clases declaradas: {len(clases)}")

    if not faltantes and not rotas:
        print("Todas las referencias internas resuelven.")
        return 0

    if faltantes:
        print(f"\nClases fuera de su ruta ({len(faltantes)}):")

        for hallazgo in faltantes:
            print(f"  - {hallazgo}")

    if rotas:
        print(f"\nReferencias sin resolver ({len(rotas)}):")

        for hallazgo in rotas:
            print(f"  - {hallazgo}")

    return 1


if __name__ == "__main__":
    raise SystemExit(main())