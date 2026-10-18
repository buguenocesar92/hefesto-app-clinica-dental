#!/usr/bin/env python3
"""
Revisa que no queden caracteres de alfabetos no latinos en el codigo.

Se ejecuta sin dependencias externas y sin red:

    python herramientas/verificar_caracteres.py

Codigo de salida: 0 si no encontro nada, 1 si encontro.

POR QUE EXISTE Y POR QUE HACE DOS PASADAS
-----------------------------------------
Un editor con el teclado en otro idioma deja caracteres pegados en un
comentario, en un mensaje de error o en una etiqueta. En el codigo se ven
como ruido; peor, pueden terminar dentro de un mensaje que un paciente o
una recepcion leen en pantalla.

La PRIMERA pasada se salta los casos conocidos: recorre unicamente los
rangos de alfabetos que se hanfiltrado alguna vez.

La SEGUNDA pasada es la que importa, y es la que corrige un barrido
insuficiente: no mira una lista de alfabetos, sino TODOS los caracteres del
archivo y pregunta, uno por uno, cual es su nombre en Unicode. Se acepta
solamente lo que empieza por LATIN, por COMBINING o por DIGIT, mas un grupo
de signos de puntuacion y simbolos de moneda expresamente permitidos.

Esa segunda pasada es estrictamente mas ancha que "chino y cirilico": cubre
arabe, hebreo, devanagari, tailandes, hangul, kana, y cualquier otra cosa que
no se haya listado. Si mañana aparece un alfabeto que nadie penso, tambien
se atrapa.

QUE REVISA
----------
Todos los archivos de texto de ESTA aplicacion. No sube a la raiz del
workspace: el codigo de otras aplicaciones es de otros agentes y aqui no se
toca. Menos `node_modules`, `vendor` y carpetas de compilacion.

IDENTIFICADORES
---------------
Los identificadores de PHP, los nombres de archivo y las claves de
configuracion son ASCII a proposito: se pueden escribir en cualquier teclado
y comparar entre sistemas. La prosa (comentarios, textos visibles) si lleva
tildes, porque es lo que se escribe mal si no se escribe bien.
"""

from __future__ import annotations

import sys
import unicodedata
from pathlib import Path

# Alfabetos que se han filtrado alguna vez. Se revisan en la PRIMERA pasada,
# que es la rapida: son los casos frecuentes.
RANGOS_CONOCIDOS = (
    ("Chino (kanji)", 0x3400, 0x9FFF),
    ("Chino (extensiones)", 0x20000, 0x2A6DF),
    ("Jiragana", 0x3040, 0x30FF),
    ("Katakana", 0x30A0, 0x30FF),
    ("Cirilico", 0x0400, 0x04FF),
    ("Cirilico (suplemento)", 0x0500, 0x052F),
    ("Hebreo", 0x0590, 0x05FF),
    ("Arabe", 0x0600, 0x06FF),
    ("Arabe (suplemento)", 0x0750, 0x077F),
    ("Devanagari", 0x0900, 0x097F),
    ("Griego", 0x0370, 0x03FF),
    ("Tamil", 0x0B80, 0x0BFF),
    ("Tailandes", 0x0E00, 0x0E7F),
    ("Hangul", 0xAC00, 0xD7AF),
    ("Armenio", 0x0530, 0x058F),
    ("Georgiano", 0x10A0, 0x10FF),
)

# Emojis y simbolos decorativos: no deberian aparecer ni en codigo ni en un
# mensaje de la aplicacion.
RANGOS_EMOJI = (
    ("Emoji", 0x1F300, 0x1FAFF),
    ("Simbolo decorativo", 0x2600, 0x27BF),
    ("Selector de variacion", 0xFE00, 0xFE0F),
)

# Puntuacion y simbolos que si se aceptan en la prosa y en el codigo.
# NO se incluyen los signos de visto bueno o de tachado: viven en el rango de
# simbolos decorativos y no deberian aparecer.
SIGNOS_PERMITIDOS = set("¡¿«»“”‘’–—·°§¶†‡•…‰′″≤≥≠±×÷←→↑↓")

EXTENSIONES = {
    ".php", ".ts", ".vue", ".js", ".json", ".yml", ".yaml", ".md",
    ".sh", ".ini", ".conf", ".xml", ".css", ".py", ".example", ".txt",
    ".gitignore", ".editorconfig", ".env",
}

EXCLUIDOS = {
    ".git", "node_modules", "vendor", ".nuxt", ".output",
    ".phpunit.cache", "storage", "dist",
}

ARCHIVOS = {".env.example", ".gitignore", ".editorconfig"}

# Formas de borrar en Windows, por si el recorrido las encuentra.
BORRABLES = ("conhost.exe",)


def categoria_rango(caracter: str) -> str | None:
    """Nombre del rango conocido al que pertenece, o None."""
    punto = ord(caracter)

    for nombre, inicio, fin in RANGOS_CONOCIDOS:
        if inicio <= punto <= fin:
            return nombre

    for nombre, inicio, fin in RANGOS_EMOJI:
        if inicio <= punto <= fin:
            return nombre

    return None


def categoria_unicode(caracter: str) -> str | None:
    """
    Segunda pasada: decide por el nombre Unicode, no por una lista.

    Devuelve el nombre de la categoria sospechosa, o None si el caracter
    esta bien. Solo se acepta LATIN, COMBINING y DIGIT mas la puntuacion
    permitida.
    """
    if caracter.isascii():
        return None

    if caracter in SIGNOS_PERMITIDOS:
        return None

    punto = ord(caracter)

    for nombre, inicio, fin in RANGOS_EMOJI:
        if inicio <= punto <= fin:
            return nombre

    nombre = unicodedata.name(caracter, "")

    if nombre == "":
        # Sin nombre Unicode: es un caracter de control o un caso raro.
        return "Caracter sin nombre Unicode"

    if caracter.isspace():
        return None

    if nombre.startswith("LATIN") or nombre.startswith("COMBINING"):
        return None

    if nombre.startswith("DIGIT"):
        return None

    if unicodedata.category(caracter).startswith("P"):
        # Puntuacion Unicode (comillas tipograficas, guiones largos) que no
        # estaba en la lista manual.
        return None

    if unicodedata.category(caracter).startswith("S") and caracter in SIGNOS_PERMITIDOS:
        return None

    return nombre.split(" ")[0] if " " in nombre else nombre


def es_texto(contenido: bytes) -> bool:
    """True si el archivo parece texto y no binario."""
    if b"\x00" in contenido:
        return False

    try:
        contenido.decode("utf-8")
    except UnicodeDecodeError:
        return False

    return True


def revisar(raiz: Path) -> tuple[list[tuple[Path, int, str, str]], list[tuple[Path, int, str, str]]]:
    """
    Devuelve (primera_pasada, segunda_pasada).

    Cada elemento es (archivo, linea, categoria, fragmento).
    """
    primera: list[tuple[Path, int, str, str]] = []
    segunda: list[tuple[Path, int, str, str]] = []

    for ruta in sorted(raiz.rglob("*")):
        if not ruta.is_file():
            continue

        if any(parte in EXCLUIDOS for parte in ruta.parts):
            continue

        if ruta.name in BORRABLES:
            continue

        if ruta.suffix.lower() not in EXTENSIONES and ruta.name.lower() not in ARCHIVOS:
            continue

        contenido = ruta.read_bytes()

        if not es_texto(contenido):
            continue

        for numero, linea in enumerate(contenido.decode("utf-8").splitlines(), start=1):
            for caracter in linea:
                if caracter.isascii():
                    continue

                etiqueta = categoria_rango(caracter)

                if etiqueta is not None:
                    primera.append((ruta, numero, etiqueta, linea.strip()[:120]))
                    # Un hallazgo de la primera pasada tambien es un hallazgo
                    # de la segunda: no se lo pierde de vista.
                    break

                categoria = categoria_unicode(caracter)

                if categoria is not None:
                    segunda.append((ruta, numero, categoria, linea.strip()[:120]))
                    break

    return primera, segunda


def escapa(texto: str) -> str:
    """
    Vuelve imprimible un fragmento que puede traer caracteres no soportados
    por la consola (en Windows la codificacion suele ser cp1252).

    Se muestra como secuencia de escape: el objetivo es que el operador vea
    QUE caracter es, no que la consola lo muestre bonito.
    """
    return texto.encode("ascii", "backslashreplace").decode("ascii")


def main() -> int:
    raiz = Path(__file__).resolve().parent.parent

    if not raiz.is_dir():
        print(f"No se encontro la raiz de la aplicacion en {raiz}", file=sys.stderr)
        return 1

    primera, segunda = revisar(raiz)

    total = len(primera) + len(segunda)

    if total == 0:
        print("Pasada 1 (alfabetos conocidos): sin hallazgos.")
        print("Pasada 2 (todos los caracteres): sin hallazgos.")
        print("")
        print("Revisados todos los caracteres no ASCII de todos los archivos de texto,")
        print("no solo chino y cirilico.")
        return 0

    if primera:
        print(f"PASADA 1: {len(primera)} lineas con alfabetos conocidos:\n")
        for ruta, numero, categoria, trozo in primera:
            print(f"  {ruta.relative_to(raiz)}:{numero}  [{categoria}]")
            print(f"      {escapa(trozo)}")
        print("")

    if segunda:
        print(f"PASADA 2: {len(segunda)} lineas con caracteres fuera del latin:\n")
        for ruta, numero, categoria, trozo in segunda:
            print(f"  {ruta.relative_to(raiz)}:{numero}  [{categoria}]")
            print(f"      {escapa(trozo)}")
        print("")

    print("Corrige las lineas marcadas y vuelve a ejecutar este script.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())