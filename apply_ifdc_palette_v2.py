#!/usr/bin/env python3
import shutil
import datetime

CSS_PATH = "./frontend/styles.css"

NEW_ROOT_BLOCK = """:root {
  --bg: #f5faee;
  --surface: #ffffff;
  --surface2: #eaf7dc;
  --border: #d8ecc4;
  --border-strong: #b0d98c;
  --text: #1b2e10;
  --text-2: #3f5730;
  --text-3: #7a8a68;
  --primary: #7ed321;
  --primary-dark: #5aa816;
  --primary-light: #e0f5ce;
  --accent: #f5a623;
  --ok: #2f7a1f;
  --ok-bg: #e6f5dc;
  --warn: #c97a0e;
  --warn-bg: #ffe3b0;
  --bad: #d14343;
  --bad-bg: #fbe2e2;
  --info: #2e6b5e;
  --info-bg: #e4f3ef;
  --shadow-sm: 0 1px 2px rgba(27,46,16,.08);
  --shadow: 0 4px 12px rgba(27,46,16,.10);
  --shadow-lg: 0 10px 30px rgba(27,46,16,.14);
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

    print("¡Listo! Paleta IFDC 'alegre y dinámico' aplicada a frontend/styles.css")
    print(f"Si algo se ve mal, restaurá con: cp {backup_path} {CSS_PATH}")

if __name__ == "__main__":
    main()
