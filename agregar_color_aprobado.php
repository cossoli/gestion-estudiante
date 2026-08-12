<?php
$path = "/tmp/panel_index.html";
$content = file_get_contents($path);

// 1) Agregar clase CSS para aprobado (buscamos solo el fragmento único, sin depender de indentación)
$markerCSS = ".materia-card.no-apto   { border-color: #f5c2c2; }";
if (strpos($content, $markerCSS) !== false) {
    $content = str_replace($markerCSS, $markerCSS . "\n    .materia-card.aprobado  { border-color: #a8d8f0; }", $content);
    echo "OK: CSS aprobado agregado.\n";
} else {
    echo "ERROR: no se encontro marcador CSS.\n";
}

// 2) Cambiar solo el fragmento puntual del ternario (sin depender de saltos de linea)
$markerJS = "? 'apto' : 'no-apto')";
if (strpos($content, $markerJS) !== false) {
    $content = str_replace($markerJS, "? 'aprobado' : 'no-apto')", $content);
    echo "OK: logica claseCardFinal actualizada.\n";
} else {
    echo "ERROR: no se encontro marcador JS.\n";
}

file_put_contents($path, $content);
echo "Listo.\n";
