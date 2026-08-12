#!/usr/bin/env python3
import re
import shutil
import datetime

HTML_PATH = "./frontend/inicio/index.html"
CSS_PATH = "./frontend/styles.css"

CSS_ADDITION = """
/* --- BOLD REDESIGN: INICIO --- */
header{ background:#1b2e10; border-bottom:none; }
.header-title{ color:#ffffff; }
.header-subtitle{ color:#c9d9bc; }

.page-banner{
  background: var(--primary);
  color:#1b2e10;
  padding:18px 24px;
  display:flex; align-items:center; justify-content:center; gap:14px;
  flex-wrap:wrap;
}
.page-banner strong{
  font-size:20px; font-weight:900; letter-spacing:-0.3px; text-transform:uppercase;
}
.page-banner .banner-pill{
  background:#1b2e10; color:#fff;
  padding:6px 16px; border-radius:20px; font-size:12.5px; font-weight:700;
}

.menu-card{
  display:flex; flex-direction:column; overflow:hidden; padding:0 !important;
  border-radius:18px; box-shadow:0 6px 20px rgba(23,36,13,0.08);
  transition:transform .2s, box-shadow .2s; text-decoration:none;
}
.menu-card:hover{ transform:translateY(-6px); box-shadow:0 16px 34px rgba(23,36,13,0.16); }

.menu-card-top{
  height:80px; display:flex; align-items:center; justify-content:center;
  font-size:34px; position:relative; overflow:hidden;
}
.menu-card--lima .menu-card-top{ background:#6FC721; }
.menu-card--naranja .menu-card-top{ background:#F5A623; }
.menu-card--celeste .menu-card-top{ background:#2E9EE0; }
.menu-card--rosa .menu-card-top{ background:#E0559C; }

.menu-card-body{ padding:20px; }
.menu-card-body h3{ margin-bottom:8px; }
.menu-card-body p{ margin-bottom:16px; }

.menu-card-footer{ display:flex; align-items:center; justify-content:space-between; }
.menu-card-go{ font-size:12.5px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; }
.menu-card--lima .menu-card-go{ color:#4A9014; }
.menu-card--naranja .menu-card-go{ color:#B5720E; }
.menu-card--celeste .menu-card-go{ color:#1D6FA3; }
.menu-card--rosa .menu-card-go{ color:#B03D7A; }

.menu-card-footer .menu-card-arrow{
  width:32px; height:32px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  font-weight:900; color:#fff; font-size:15px;
  transition:transform .2s;
}
.menu-card--lima .menu-card-arrow{ background:#6FC721; }
.menu-card--naranja .menu-card-arrow{ background:#F5A623; }
.menu-card--celeste .menu-card-arrow{ background:#2E9EE0; }
.menu-card--rosa .menu-card-arrow{ background:#E0559C; }
.menu-card:hover .menu-card-arrow{ transform:translateX(4px); }
"""

CARDS = [
    ("/acceso/", "lima", "🎓"),
    ("/secretaria/", "naranja", "📋"),
    ("/tic/", "celeste", "💻"),
    ("/docente/", "rosa", "🧑‍🏫"),
]

def backup(path):
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = f"{path}.bak_{timestamp}"
    shutil.copy2(path, backup_path)
    print(f"Backup creado en: {backup_path}")
    return backup_path

def rebuild_card(match, color, emoji):
    href = match.group("href")
    h3 = match.group("h3")
    p = match.group("p")
    return (
        f'<a class="menu-card menu-card--{color}" href="{href}">\n'
        f'      <div class="menu-card-top">{emoji}</div>\n'
        f'      <div class="menu-card-body">\n'
        f'        <h3>{h3}</h3>\n'
        f'        <p>{p}</p>\n'
        f'        <div class="menu-card-footer">\n'
        f'          <span class="menu-card-go">Ingresar</span>\n'
        f'          <span class="menu-card-arrow">→</span>\n'
        f'        </div>\n'
        f'      </div>\n'
        f'    </a>'
    )

def main():
    backup(HTML_PATH)
    backup(CSS_PATH)

    with open(HTML_PATH, "r", encoding="utf-8") as f:
        html = f.read()

    for href, color, emoji in CARDS:
        pattern = re.compile(
            r'<a class="menu-card" href="' + re.escape(href) + r'">\s*'
            r'<div class="menu-card-icon[^"]*">.*?</div>\s*'
            r'<h3>(?P<h3>.*?)</h3>\s*'
            r'<p>(?P<p>.*?)</p>\s*'
            r'<span class="menu-card-arrow">.*?</span>\s*'
            r'</a>',
            re.DOTALL
        )
        m = pattern.search(html)
        if not m:
            print(f"AVISO: no encontré la card para {href}, se deja sin cambios.")
            continue
        m2 = re.match(
            r'<a class="menu-card" href="(?P<href>' + re.escape(href) + r')">\s*'
            r'<div class="menu-card-icon[^"]*">.*?</div>\s*'
            r'<h3>(?P<h3>.*?)</h3>\s*'
            r'<p>(?P<p>.*?)</p>\s*'
            r'<span class="menu-card-arrow">.*?</span>\s*'
            r'</a>',
            m.group(0), re.DOTALL
        )
        new_block = rebuild_card(m2, color, emoji)
        html = html[:m.start()] + new_block + html[m.end():]
        print(f"Card actualizada: {href} -> {color}")

    banner_html = (
        '<div class="page-banner">\n'
        '    <strong>Ciclo lectivo 2026</strong>\n'
        '    <span class="banner-pill">Inscripciones abiertas</span>\n'
        '  </div>\n\n  '
    )
    if '<div class="page-banner">' not in html:
        html, n = re.subn(
            r'(<div class="page-hero)',
            banner_html + r'\1',
            html, count=1
        )
        if n:
            print("Banner insertado antes de .page-hero")
        else:
            print("AVISO: no encontré .page-hero para insertar el banner.")
    else:
        print("El banner ya existía, no se duplicó.")

    with open(HTML_PATH, "w", encoding="utf-8") as f:
        f.write(html)

    with open(CSS_PATH, "a", encoding="utf-8") as f:
        f.write(CSS_ADDITION)

    print("¡Listo! Rediseño bold aplicado.")

if __name__ == "__main__":
    main()
