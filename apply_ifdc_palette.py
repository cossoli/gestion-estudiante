#!/usr/bin/env python3
import shutil
import datetime

CSS_PATH = "./frontend/styles.css"

NEW_ROOT_BLOCK = """:root {
  --bg: #f3f8ee;
  --surface: #ffffff;
  --surface2: #eef6e6;
  --border: #dce8d0;
  --border-strong: #b8d4a0;
  --text: #1b2e10;
  --text-2: #3f5730;
  --text-3: #6b7a62;
  --primary: #4caf1a;
  --primary-dark: #3d8c15;
  --primary-light: #dff3d2;
  --accent: #f5a623;
  --ok: #2f7a1f;
  --ok-bg: #e6f5dc;
  --warn: #b5720e;
  --warn-bg: #fce9c6;
  --bad: #d14343;
  --bad-bg: #fbe2e2;
  --info: #2e6b5e;
  --info-bg: #e4f3ef;
  --shadow-sm: 0 1px 3px rgba(27,46,16,.06), 0 1px 2px rgba(27,46,16,.04);
  --shadow: 0 4px 16px rgba(27,46,16,.08), 0 1px 4px rgba(27,46,16,.04);
  --shadow-lg: 0 12px 40px rgba(27,46,16,.12), 0 4px 12px rgba(27,46,16,.06);
  --radius: 14px;
  --radius-sm: 8px;
  --radius-lg: 20px;
}"""

def main():
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = f"{CSS_PATH}.bak_{timestamp}"
    shutil.copy2(CSS_PATH, backup_path)
    print(f"Backup creado en: {backup_path}")

    with open(CSS_PATH, "r", encoding="utf-8") as f:
        lines = f.readlines()

    start_idx = None
    end_idx = None
    for i, line in enumerate(lines):
        if line.strip().startswith(":root"):
            start_idx = i
            break

    if start_idx is None:
        print("ERROR: no se encontró ':root'. No se modificó nada.")
        return

    for i in range(start_idx, len(lines)):
        if lines[i].strip() == "}":
            end_idx = i
            break

    if end_idx is None:
        print("ERROR: no se encontró el cierre del bloque :root. No se modificó nada.")
        return

    print(f"Bloque :root encontrado: líneas {start_idx+1} a {end_idx+1}")

    new_lines = lines[:start_idx] + [NEW_ROOT_BLOCK + "\n"] + lines[end_idx+1:]

    with open(CSS_PATH, "w", encoding="utf-8") as f:
        f.writelines(new_lines)

    print("¡Listo! Paleta IFDC aplicada a frontend/styles.css")
    print(f"Si algo se ve mal, restaurá con: cp {backup_path} {CSS_PATH}")

if __name__ == "__main__":
    main()
