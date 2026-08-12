#!/usr/bin/env python3
import re
import shutil
import datetime

HTML_PATH = "./frontend/inscripcion/index.html"

def backup(path):
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = f"{path}.bak_{timestamp}"
    shutil.copy2(path, backup_path)
    print(f"Backup creado en: {backup_path}")

def main():
    backup(HTML_PATH)

    with open(HTML_PATH, "r", encoding="utf-8") as f:
        html = f.read()

    original_len = len(html)

    html, n1 = re.subn(
        r'\s*<div class="field">\s*'
        r'<label class="field-label">Copia del DNI \(PDF\).*?</label>\s*'
        r'<div class="file-field" id="fieldDni">.*?</div>\s*'
        r'</div>',
        '',
        html, count=1, flags=re.DOTALL
    )
    print(f"Bloque 'Copia del DNI' eliminado: {'sí' if n1 else 'NO ENCONTRADO'}")

    html, n2 = re.subn(
        r'\s*<div class="field">\s*'
        r'<label class="field-label">Título secundario \(PDF\).*?</label>\s*'
        r'<div class="file-field" id="fieldTitulo">.*?</div>\s*'
        r'</div>',
        '',
        html, count=1, flags=re.DOTALL
    )
    print(f"Bloque 'Título secundario' eliminado: {'sí' if n2 else 'NO ENCONTRADO'}")

    html, n3 = re.subn(
        r'[ \t]*const pdfDni\s*=.*?\.files\[0\];\s*\n', '', html
    )
    html, n4 = re.subn(
        r'[ \t]*const pdfTitulo\s*=.*?\.files\[0\];\s*\n', '', html
    )
    print(f"Declaraciones pdfDni/pdfTitulo eliminadas: {n3 + n4} ocurrencia(s)")

    html, n5 = re.subn(
        r'[ \t]*if \(!pdfDni\)\s*return setStatus\(status,[^;]*;\s*\n', '', html
    )
    html, n6 = re.subn(
        r'[ \t]*if \(!pdfTitulo\)\s*return setStatus\(status,[^;]*;\s*\n', '', html
    )
    print(f"Validaciones pdfDni/pdfTitulo eliminadas: {n5 + n6} ocurrencia(s)")

    if len(html) == original_len:
        print("AVISO: no se modificó nada. Revisá los patrones antes de continuar.")
        return

    with open(HTML_PATH, "w", encoding="utf-8") as f:
        f.write(html)

    print("¡Listo! Campos de DNI y título sacados del formulario de inscripción.")

if __name__ == "__main__":
    main()
