# -*- coding: utf-8 -*-
"""Convierte el dataset oficial CIE-10 Chile (MINSAL/DEIS) a JSON compacto."""

from __future__ import annotations

import json
import re
from pathlib import Path

import pyreadr

ROOT = Path(__file__).resolve().parent
RDA = ROOT / "cie10_cl.rda"
OUT = ROOT / "cie10.json"

USO_CLINICO = {"principal", "causa_externa", "etiologico", "causa_externa | principal"}


def pretty_label(value: str) -> str:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    if not text:
        return ""
    text = text[:1].upper() + text[1:].lower()
    return re.sub(r"\(([a-z0-9.\-]+)\)", lambda match: f"({match.group(1).upper()})", text)


def categoria_codigo(value: str, codigo: str) -> str:
    token = str(value or "").strip().split(" ")[0]
    if re.match(r"^[A-Z][0-9A-Z]{2}", token, re.I):
        return token.upper()
    compact = codigo.replace(".", "")
    return compact[:3].upper() if len(compact) >= 3 else codigo.upper()


def es_categoria(codigo: str) -> bool:
    return bool(re.fullmatch(r"[A-Z][0-9]{2}", codigo.replace(".", ""), re.I))


def seccion_rango(value: str) -> str:
    text = str(value or "").strip()
    match = re.match(r"^([A-Z][0-9A-Z.]*\s*-\s*[A-Z][0-9A-Z.]*)", text, re.I)
    return match.group(1).replace(" ", "") if match else pretty_label(text)


def capitulo_info(value: str) -> tuple[str, str]:
    text = pretty_label(value)
    match = re.match(r"cap\.?\s*(\d+)\s+(.*)", text, re.I)
    if match:
        nombre = match.group(2)
        nombre = nombre[:1].upper() + nombre[1:] if nombre else nombre
        return f"Cap. {int(match.group(1)):02d}", nombre
    return "", text


def incluir(codigo: str, capitulo_nombre: str, uso: str) -> bool:
    if str(capitulo_nombre or "").upper().startswith("TAB M"):
        return False
    if not codigo:
        return False
    if uso in USO_CLINICO:
        return True
    return es_categoria(codigo)


def main() -> None:
    frame = pyreadr.read_r(str(RDA))["cie10_cl"]
    items = []
    seen = set()

    for row in frame.itertuples(index=False):
        codigo = str(row.codigo or "").strip().upper()
        uso = str(row.uso_cl or "").strip()
        if not incluir(codigo, row.capitulo_nombre, uso):
            continue
        if codigo in seen:
            continue
        seen.add(codigo)
        cap_num, cap_nombre = capitulo_info(row.capitulo_nombre)
        items.append(
            {
                "codigo": codigo,
                "descripcion": str(row.descripcion or "").strip(),
                "categoria": categoria_codigo(row.categoria, codigo),
                "seccion": seccion_rango(row.seccion),
                "capitulo": cap_num,
                "capitulo_nombre": cap_nombre,
                "uso": uso or "legado",
            }
        )

    extras = [
        {
            "codigo": "U07.1",
            "descripcion": "COVID-19, virus identificado",
            "categoria": "U07",
            "seccion": "U00-U49",
            "capitulo": "Cap. 22",
            "capitulo_nombre": "Códigos para situaciones especiales (u00-u99)",
            "uso": "principal",
        },
        {
            "codigo": "U07.2",
            "descripcion": "COVID-19, virus no identificado",
            "categoria": "U07",
            "seccion": "U00-U49",
            "capitulo": "Cap. 22",
            "capitulo_nombre": "Códigos para situaciones especiales (u00-u99)",
            "uso": "principal",
        },
        {
            "codigo": "U09.9",
            "descripcion": "Condición de salud posterior a COVID-19, no especificada",
            "categoria": "U09",
            "seccion": "U00-U49",
            "capitulo": "Cap. 22",
            "capitulo_nombre": "Códigos para situaciones especiales (u00-u99)",
            "uso": "principal",
        },
    ]
    for extra in extras:
        if extra["codigo"] not in seen:
            items.append(extra)
            seen.add(extra["codigo"])

    items.sort(key=lambda item: item["codigo"])
    payload = {
        "meta": {
            "nombre": "CIE-10 Chile",
            "fuente": "MINSAL / DEIS, catálogo CIE-10 Chile v2018",
            "origen": "https://deis.minsal.cl/centrofic/",
            "total": len(items),
            "licencia": "Datos de clasificación de uso público para consulta clínica y estadística",
        },
        "items": items,
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"OK {len(items)} códigos -> {OUT}")


if __name__ == "__main__":
    main()
