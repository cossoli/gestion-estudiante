<?php
$path = "/tmp/directivos_index.html";
$content = file_get_contents($path);

// 1) Botón Exportar - Alumnos
$markerAlu = '<div id="resultadoAlumnosVacio" class="text-sm text-muted" style="display:none">No se encontraron alumnos con ese criterio.</div>';
$botonAlu = '<div style="margin:10px 0"><button class="btn btn-secondary" onclick="exportarCSV(\'alumnos\', \'alumnos_ifdc.csv\')">Exportar CSV</button></div>' . "\n  " . $markerAlu;
if (strpos($content, $markerAlu) !== false) {
    $content = str_replace($markerAlu, $botonAlu, $content);
    echo "OK: boton alumnos insertado.\n";
} else {
    echo "ERROR: no se encontro marcador de alumnos.\n";
}

// 2) Botón Exportar - Docentes
$markerDoc = '<div id="resultadoDocentesVacio" class="text-sm text-muted" style="display:none">No se encontraron docentes con ese criterio.</div>';
$botonDoc = '<div style="margin:10px 0"><button class="btn btn-secondary" onclick="exportarCSV(\'docentes\', \'docentes_ifdc.csv\')">Exportar CSV</button></div>' . "\n  " . $markerDoc;
if (strpos($content, $markerDoc) !== false) {
    $content = str_replace($markerDoc, $botonDoc, $content);
    echo "OK: boton docentes insertado.\n";
} else {
    echo "ERROR: no se encontro marcador de docentes.\n";
}

// 3) Modificar renderTabla para guardar headers/rows globales
$markerFn = "function renderTabla(el, headers, rows) {";
$nuevaFn = <<<JS
let __ultimaTabla = {};
function renderTabla(el, headers, rows) {
  __ultimaTabla[el.id] = { headers, rows };
JS;
if (strpos($content, $markerFn) !== false) {
    $content = str_replace($markerFn, $nuevaFn, $content);
    echo "OK: renderTabla modificada para guardar datos.\n";
} else {
    echo "ERROR: no se encontro renderTabla.\n";
}

// 4) Agregar función exportarCSV antes de cargarReportes
$markerCarga = "async function cargarReportes() {";
$funcExport = <<<JS
function exportarCSV(tipo, nombreArchivo) {
  const idTabla = tipo === 'alumnos' ? 'tablaAlumnosBusqueda' : 'tablaDocentesBusqueda';
  const datos = __ultimaTabla[idTabla];
  if (!datos || !datos.rows.length) {
    alert('No hay resultados para exportar. Primero realiza una busqueda.');
    return;
  }
  const escCSV = v => '"' + String(v).replace(/"/g, '""') + '"';
  let csv = datos.headers.map(escCSV).join(',') + '\\n';
  datos.rows.forEach(row => { csv += row.map(escCSV).join(',') + '\\n'; });
  const blob = new Blob(['\\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nombreArchivo;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

async function cargarReportes() {
JS;
if (strpos($content, $markerCarga) !== false) {
    $content = str_replace($markerCarga, $funcExport, $content);
    echo "OK: funcion exportarCSV agregada.\n";
} else {
    echo "ERROR: no se encontro cargarReportes.\n";
}

file_put_contents($path, $content);
echo "Listo.\n";
