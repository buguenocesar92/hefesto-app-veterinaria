#!/usr/bin/env python3
"""Compara el resultado de PHPUnit con la lista de fallos conocidos.

Uso:
    python3 herramientas/comparar_resultados.py <junit.xml> <fallos-conocidos.txt>

El archivo de fallos conocidos tiene un identificador por linea, con la forma
`Clase\\Completa::metodo`. Las lineas vacias y las que empiezan con `#` se
ignoran.

Sale con 0 solo si el conjunto de pruebas que no pasan es EXACTAMENTE el de la
lista. Sale con 1 si hay un fallo nuevo, si una prueba de la lista ya pasa o
ya no existe (hay que quitarla), si el informe no trae pruebas o si no se puede
leer.

Solo biblioteca estandar. El informe lo escribe nuestra propia corrida de
PHPUnit; aun asi no se resuelven entidades externas (ElementTree no lo hace).
"""

from __future__ import annotations

import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ETIQUETAS_DE_FALLO = ("failure", "error")


def leer_conocidos(ruta: Path) -> set[str]:
    conocidos: set[str] = set()
    for linea in ruta.read_text(encoding="utf-8").splitlines():
        linea = linea.strip()
        if linea and not linea.startswith("#"):
            conocidos.add(linea)
    return conocidos


def leer_junit(ruta: Path) -> tuple[set[str], set[str], set[str]]:
    """Devuelve (todas, fallidas, omitidas) como identificadores Clase::metodo."""
    todas: set[str] = set()
    fallidas: set[str] = set()
    omitidas: set[str] = set()
    for caso in ET.parse(ruta).getroot().iter("testcase"):
        clase = caso.get("class") or caso.get("classname") or ""
        nombre = caso.get("name") or ""
        identificador = f"{clase}::{nombre}"
        todas.add(identificador)
        if any(caso.find(etiqueta) is not None for etiqueta in ETIQUETAS_DE_FALLO):
            fallidas.add(identificador)
        elif caso.find("skipped") is not None:
            omitidas.add(identificador)
    return todas, fallidas, omitidas


def comparar(todas: set[str], fallidas: set[str], conocidos: set[str]) -> tuple[list[str], list[str]]:
    """Devuelve (fallos nuevos, entradas de la lista que sobran)."""
    nuevos = sorted(fallidas - conocidos)
    sobran = sorted(conocidos - fallidas)
    return nuevos, sobran


def main(argumentos: list[str]) -> int:
    if len(argumentos) != 3:
        print(f"uso: {argumentos[0]} <junit.xml> <fallos-conocidos.txt>", file=sys.stderr)
        return 1

    try:
        todas, fallidas, omitidas = leer_junit(Path(argumentos[1]))
        conocidos = leer_conocidos(Path(argumentos[2]))
    except (OSError, ET.ParseError) as error:
        print(f"[comparar] no se pudo leer: {error}", file=sys.stderr)
        return 1

    if not todas:
        print("[comparar] el informe no trae ninguna prueba: la suite no cargo", file=sys.stderr)
        return 1

    nuevos, sobran = comparar(todas, fallidas, conocidos)
    pasan = len(todas) - len(fallidas) - len(omitidas)
    print(
        f"[comparar] pruebas: {len(todas)} | pasan: {pasan} | "
        f"no pasan: {len(fallidas)} | omitidas: {len(omitidas)} | "
        f"conocidos: {len(conocidos)}"
    )

    for identificador in nuevos:
        print(f"[comparar] FALLO NUEVO: {identificador}", file=sys.stderr)
    for identificador in sobran:
        motivo = "ya pasa" if identificador in todas else "ya no existe"
        print(f"[comparar] SOBRA EN LA LISTA ({motivo}): {identificador}", file=sys.stderr)

    if nuevos or sobran:
        return 1

    print("[comparar] sin fallos nuevos: el resultado coincide con la lista de conocidos")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
