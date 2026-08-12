#!/usr/bin/env python3
import re
import shutil
import datetime

HTML_PATH = "./frontend/inicio/index.html"
CSS_PATH = "./frontend/styles.css"

CSS_ADDITION = """
/* --- Variante tierra (Docentes) --- */
.menu-card--tierra .menu-card-top{ background:#A9754A; }
.menu-card--tierra .menu-card-go{ color:#7A4E28; }
.menu-card--tierra .menu-card-arrow{ background:#A9754A; }
"""

def backup(path):
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = f"{path}.bak_{timestamp}"
    shutil.copy2(path, backup_path)
    print(f"Backup creado en: {backup_path}")

def main():
    backup(HTML_PATH)
    backup(CSS_PATH)

    with open(HTML_PATH, "r", encoding="utf-8") as f:
        html = f.read()

    new_html, n = re.subn(
        r'menu-card menu-card--rosa',
        'menu-card menu-card--tierra',
        html
    )
    print(f"Clase 'rosa' -> 'tierra' reemplazada: {n} ocurrencia(s)")

    if n == 0:
        print("AVISO: no se encontró la clase 'menu-card--rosa'. No se modificó el HTML.")
    else:
        with open(HTML_PATH, "w", encoding="utf-8") as f:
            f.write(new_html)

    with open(CSS_PATH, "a", encoding="utf-8") as f:
        f.write(CSS_ADDITION)
    print("CSS de la variante 'tierra' agregado.")

    print("¡Listo!")

if __name__ == "__main__":
    main()
