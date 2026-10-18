#!/usr/bin/env python3
"""
Revisa que no queden caracteres CJK ni cirilicos en el codigo.

Se ejecuta sin dependencias externas y sin red:

    python herramientas/verificar_caracteres.py

Codigo de salida: 0 si no encontro nada, 1 si encontro.

POR QUE EXISTE
--------------
Un editor de texto con el teclado en otro idioma puede dejar caracteres CJK o
cirilicos pegados en un comentario, en un mensaje de error o en una etiqueta.
En el codigo se ven como ruido y, peor, pueden terminar dentro de un mensaje que
un paciente o una recepcion leen en pantalla.

Los identificadores de PHP, los nombres de archivo y las claves de configuracion
son ASCII a proposito: se pueden escribir en cualquier teclado, buscar con
expresiones regulares simples y comparar entre sistemas.

QUE REVISA
----------
Todos los archivos de texto del proyecto, menos `node_modules`, `vendor` y las
carpetas de compilacion. El codigo operativo de otras aplicaciones vive fuera
de esta carpeta, asi que no se toca.
"""

from __future__ import annotations

import sys
import unicodedata
from pathlib import Path

# (categoria, inicio, fin) en puntos de codigo Unicode.
RANGOS = (
    ("CJK (kanji)", 0x3400, 0x9FFF),
    ("CJK (hiragana)", 0x3040, 0x30FF),
    ("Cirilico", 0x0400, 0x04FF),
    ("Cirilico (suplemento)", 0x0500, 0x052F),
    ("Hebreo", 0x0590, 0x05FF),
    ("Arabe", 0x0600, 0x06FF),
    ("Arabe (suplemento)", 0x0750, 0x077F),
    ("Griego", 0x0370, 0x03FF),
    ("Tamil", 0x0B80, 0x0BFF),
    ("Devanagari", 0x0900, 0x097F),
    ("Tailandes", 0x0E00, 0x0E7F),
)

# Emojis y simbolos de uso decorativo: no deberian aparecer en codigo ni en
# mensajes de la aplicacion.
RANGOS_EMOJI = ((0x1F300, 0x1FAFF), (0x2600, 0x27BF), (0xFE0F, 0xFE0F))

# Extensiones que se revisan.
EXTENSIONES = {
    ".php", ".ts", ".vue", ".js", ".json", ".yml", ".yaml", ".md",
    ".sh", ".ini", ".conf", ".xml", ".css", ".py", ".example", ".txt",
}

EXCLUIDOS = {
    ".git", "node_modules", "vendor", ".nuxt", ".output", ".phpunit.cache",
    "storage", "dist",
}

# Archivos sin extension util que tambien se revisan.
ARCHIVOS = {".env.example", ".gitignore", ".editorconfig"}

# Alfabetos permitidos ademas de los latinos y los signos de puntuacion.
PERMITIDOS = set(
    "áéíóúüñÁÉÍÓÚÜÑ"
    "àèìòùâêîôûãõç"
    "äëïöüÄËÏÖÜß"
    "¡¿«»“”‘’–—·°"
)


def categoria_de(caracter: str) -> str | None:
    """Nombre de la categoria sospechosa, o None si el caracter esta bien."""
    punto = ord(caracter)

    for nombre, inicio, fin in RANGOS:
        if inicio <= punto <= fin:
            return nombre

    for inicio, fin in RANGOS_EMOJI:
        if inicio <= punto <= fin:
            return "Emoji o simbolo decorativo"

    # Latin UNICODE capital (latines extendidos que aparecen en nombres
    # proprios) y combinantes se aceptan.
    nombre = unicodedata.name(caracter, "")

    if nombre.startswith("LATIN") or nombre.startswith("COMBINING"):
        return None

    return None


def es_texto(contenido: bytes) -> bool:
    """True si el archivo parece texto y no binario."""
    if b"\x00" in contenido:
        return False

    try:
        contenido.decode("utf-8")
    except UnicodeDecodeError:
        return False

    return True


def revisar(raiz: Path) -> list[tuple[Path, int, str, str]]:
    """
    Devuelve [(archivo, linea, categoria, fragmento)] con lo que hay que mirar.
    """
    hallazgos: list[tuple[Path, int, str, str]] = []

    for ruta in sorted(raiz.rglob("*")):
        if not ruta.is_file():
            continue

        if any(parte in EXCLUIDOS for parte in ruta.parts):
            continue

        if ruta.suffix.lower() not in EXTENSIONES and ruta.name.lower() not in ARCHIVOS:
            continue

        contenido = ruta.read_bytes()

        if not es_texto(contenido):
            continue

        for numero, linea in enumerate(contenido.decode("utf-8").splitlines(), start=1):
            for caracter in linea:
                if caracter in PERMITIDOS or caracter.isascii():
                    continue

                categoria = categoria_de(caracter)

                if categoria is not None:
                    hallazgos.append(
                        (ruta, numero, categoria, linea.strip()[:120])
                    )
                    break

    return hallazgos


def escapa(texto: str) -> str:
    """
    Vuelve imprimible un fragmento que puede traer caracteres no soportados por
    la consola (en Windows la codificacion suele ser cp1252).

    Se muestra como secuencia de escape (`\\u4f60`): el objetivo es que el
    operador vea QUE caracter es, no que la consola lo muestre bonito.
    """
    return texto.encode("ascii", "backslashreplace").decode("ascii")


def main() -> int:
    raiz = Path(__file__).resolve().parent.parent

    if not raiz.is_dir():
        print(f"No se encontro la raiz de la aplicacion en {raiz}", file=sys.stderr)
        return 1

    hallazgos = revisar(raiz)

    if not hallazgos:
        print("Sin caracteres CJK, cirilicos, hebreos, arabes, griegos ni emojis.")
        return 0

    print(f"Se encontraron {len(hallazgos)} lineas con caracteres fuera del alfabeto esperado:\n")

    for ruta, numero, categoria, trozo in hallazgos:
        print(f"  {ruta.relative_to(raiz)}:{numero}  [{categoria}]")
        print(f"      {escapa(trozo)}")

    print("\nCorrige las lineas marcadas y vuelve a ejecutar este script.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())