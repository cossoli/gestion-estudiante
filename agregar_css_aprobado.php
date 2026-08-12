<?php
$path = "/tmp/panel_index.html";
$lines = file($path);

$encontrado = false;
foreach ($lines as $i => $line) {
    if (strpos($line, '.materia-card.no-apto') !== false) {
        $indent = "    "; // 4 espacios, como el resto del bloque
        $nuevaLinea = $indent . ".materia-card.aprobado  { border-color: #a8d8f0; }\n";
        array_splice($lines, $i + 1, 0, [$nuevaLinea]);
        $encontrado = true;
        echo "OK: CSS aprobado insertado despues de la linea " . ($i + 1) . "\n";
        break;
    }
}
if (!$encontrado) {
    echo "ERROR: no se encontro la linea con .materia-card.no-apto\n";
}

file_put_contents($path, implode('', $lines));
echo "Listo.\n";
