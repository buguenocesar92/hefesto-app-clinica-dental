#!/usr/bin/env python3
"""
Revisa palabras pegadas en medio de una frase.

Se ejecuta sin dependencias externas y sin red:

    python herramientas/verificar_palabras_pegadas.py

Codigo de salida: 0 si no encontro nada, 1 si encontro.

POR QUE EXISTE, SEPARADO DEL BARRIDO DE CARACTERES
---------------------------------------------------
Un barrido de alfabetos NO detecta esto:

    El profesional puedeachable el dato.

El problema no es un caracter raro pegado dentro de una palabra: son dos
palabras latinas, correctas por separado, que quedaron pegadas. El barrido de
caracteres pasa por encima sin darse cuenta, y el lector tampoco: la palabra
pegada se lee casi bien y el cerebro completa el hueco solo.

Como este error se repitio varias veces al escribir la aplicacion, hay un
script que lo busca en vez de confiar en la revision a ojo.

QUE BUSCA
---------
1. Una palabra que tiene una minuscula pegada a una mayuscula dentro de ella,
   como "de"La" o "una)Pieza". En la prosa eso casi nunca es correcto.
2. Una lista corta de secuencias que, pegadas, casi siempre son un error:
   "delas", "puedecomprometer", "consultorioque", "sinospermitir".

QUE NO HACE
-----------
No corrige: marca y deja la correccion a quien lee. Decidir si "sobrela" es
un error o una palabra compuesta depende de la frase, y una correccion
automática haria mas dano que el error que intenta arreglar.

LIMITE HONESTO
-------------
Es un detector, no una prueba. Lo que no encuentra, no existe en la muestra
que reviso: no es una garantia de que el archivo este limpio. Y en las lineas
de codigo el camelCase es legitimo, asi que se saltan las que parecen
codigo, con el riesgo de que alguna se escape.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

EXTENSIONES = {".md", ".php", ".ts", ".vue"}

EXCLUIDOS = {
    ".git", "node_modules", "vendor", ".nuxt", ".output",
    ".phpunit.cache", "storage", "dist",
}

# Secuencias que, pegadas al lado de otra letra, casi siempre son un error.
# La lista es corta a proposito: es mas util detectar los veinte casos reales
# que generar cientos de falsos positivos que nadie va a mirar.
PEGADAS_REPARABLES = {
    "puedecomprometer": "puede comprometer",
    "puedellegar": "puede llegar",
    "sinospermitir": "si no se permite",
    "nadainventado": "nada inventado",
    "consultorioque": "consultorio que",
    "pacientequien": "paciente quien",
    "piezanunca": "pieza nunca",
    "seencontra": "se encuentra",
    "solose": "solo se",
    "deltodo": "de todo",
    "lacronologia": "la cronologia",
    "elodontograma": "el odontograma",
}

# Minuscula pegada a mayuscula dentro de una palabra.
PATRON_CAMEL = re.compile(r"[a-záéíóúñ](?=[A-ZÁÉÍÓÚÑ])")

# Extension de archivo que se revisa completa: markdown es casi toda prosa.
PROSA_COMPLETA = {".md"}

# En estos formatos solo se revisan los COMENTARIOS. El codigo usa camelCase a
# proposito (`ServicioOdontograma`, `estadoAnterior`) y revisarlo entero
# produciria cientos de falsos positivos que nadie miraria.
SOLO_COMENTARIOS = {".php", ".ts", ".vue"}

MARCAS_COMENTARIO = ("//", "*", "/*", "#")

# Codigo en linea dentro de markdown: se quita antes de revisar, porque un
# nombre de clase entre acentos graves es codigo y no prosa.
CODIGO_EN_LINEA = re.compile(r"`[^`]*`")

# Anotaciones de PHPDoc: llevan nombres de tipo en CamelCase (`@return
# BelongsTo<Paciente>`) y no son prosa. Se quitan antes de revisar.
ANOTACION = re.compile(r"@\w+")

# Caracteres de sintaxis que delatan una linea de codigo o una declaracion de
# tipos, donde el CamelCase es correcto y no hay prosa que revisar.
SINTAXIS = ("<", ">", "$", "{", "}", "(", ")", "=", "|", ";", "[", "]", "::", ":")

# Nombres propios y tecnologias cuyo CamelCase es correcto y conocido. Sin
# esta lista, "PostgreSQL" y "Authentik" se reportan como errores de tipeo.
PROPIOS = (
    "PostgreSQL", "Postgres", "MySQL", "Laravel", "Authentik", "Redis", "Nuxt",
    "Vue", "Docker", "Composer", "PHPUnit", "Collision", "HTML", "HTTP",
    "HTTPS", "JSON", "XML", "SQL", "CSS", "URL", "URI", "API", "FDI", "ISO",
    "Ley", "GitHub", "Caddy", "Nginx", "Linux", "Cookie", "Token", "PHP",
    "Node", "JavaScript", "TypeScript", "README", "Markdown", "PHPStan",
    "GiST", "btree", "RFC", "DNI", "RUT", "OIDC", "CSRF", "TLS", "NAT",
    # Excepciones de PHP, que aparecen en las anotaciones de tipo.
    "InvalidArgumentException", "RuntimeException", "LogicException",
    "DomainException", "Exception", "Throwable", "Error",
)


def es_texto(contenido: bytes) -> bool:
    if b"\x00" in contenido:
        return False

    try:
        contenido.decode("utf-8")
    except UnicodeDecodeError:
        return False

    return True


def es_comentario(linea: str) -> bool:
    """True si la linea es un comentario y no codigo."""
    recortada = linea.strip()

    return any(recortada.startswith(marca) for marca in MARCAS_COMENTARIO)


def revisar_linea(linea: str) -> list[tuple[str, str]]:
    """Devuelve [(tipo, fragmento)] con lo que hay que mirar."""
    hallazgos: list[tuple[str, str]] = []
    minusculas = linea.lower()

    for pegada in PEGADAS_REPARABLES:
        indice = minusculas.find(pegada)

        if indice < 0:
            continue

        # Solo cuenta si la secuencia esta pegada al resto de una palabra, es
        # decir, si no hay espacio ni puntuacion justo antes ni despues.
        antes = linea[indice - 1] if indice > 0 else " "
        posicion_despues = indice + len(pegada)
        despues = linea[posicion_despues] if posicion_despues < len(linea) else " "

        if not (antes.isalnum() or despues.isalnum()):
            continue

        fragmento = linea[max(0, indice - 20): posicion_despues + 20]

        hallazgos.append((
            "palabra pegada",
            f"{fragmento.strip()}  ->  probar: {PEGADAS_REPARABLES[pegada]}",
        ))

    for coincidencia in PATRON_CAMEL.finditer(linea):
        posicion = coincidencia.start()

        if any(marca in linea for marca in SINTAXIS):
            # Linea de codigo o declaracion de tipos: el CamelCase es correcto.
            continue

        # La palabra completa alrededor del empalme, sin puntos de la prosa.
        inicio = posicion

        while inicio > 0 and linea[inicio - 1].isalpha():
            inicio -= 1

        fin = posicion + 1

        while fin < len(linea) and linea[fin].isalpha():
            fin += 1

        if linea[inicio:fin] in PROPIOS:
            continue

        hallazgos.append((
            "mayuscula pegada",
            linea[max(0, posicion - 25): posicion + 25].strip(),
        ))

    return hallazgos


def revisar(raiz: Path) -> list[tuple[Path, int, str, str]]:
    hallazgos: list[tuple[Path, int, str, str]] = []

    for ruta in sorted(raiz.rglob("*")):
        if not ruta.is_file():
            continue

        if any(parte in EXCLUIDOS for parte in ruta.parts):
            continue

        extension = ruta.suffix.lower()

        if extension not in PROSA_COMPLETA and extension not in SOLO_COMENTARIOS:
            continue

        contenido = ruta.read_bytes()

        if not es_texto(contenido):
            continue

        en_bloque = False

        for numero, linea in enumerate(contenido.decode("utf-8").splitlines(), start=1):
            if linea.strip().startswith("```"):
                en_bloque = not en_bloque
                continue

            if en_bloque:
                continue

            if extension in SOLO_COMENTARIOS and not es_comentario(linea):
                continue

            # Se quitan las anotaciones de PHPDoc y el codigo en linea: son
            # declaraciones de tipos, no prosa, y su CamelCase es correcto.
            revisable = CODIGO_EN_LINEA.sub(" ", linea)
            revisable = ANOTACION.sub(" ", revisable)

            for tipo, fragmento in revisar_linea(revisable):
                hallazgos.append((ruta, numero, tipo, fragmento[:140]))

    return hallazgos


def main() -> int:
    raiz = Path(__file__).resolve().parent.parent

    if not raiz.is_dir():
        print(f"No se encontro la raiz de la aplicacion en {raiz}", file=sys.stderr)
        return 1

    hallazgos = revisar(raiz)

    if not hallazgos:
        print("Sin palabras pegadas detectadas.")
        return 0

    print(f"Se encontraron {len(hallazgos)} lineas por revisar:\n")

    for ruta, numero, tipo, fragmento in hallazgos:
        print(f"  {ruta.relative_to(raiz)}:{numero}  [{tipo}]")
        print(f"      {fragmento}")

    print("\nRevisa una por una: el camelCase dentro del codigo es correcto.")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())