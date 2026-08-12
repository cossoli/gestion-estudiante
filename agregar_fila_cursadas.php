<?php
$path = "/tmp/panel_index.html";
$lines = file($path);

$idx = 653; // linea 654, indice 653
$linea = $lines[$idx];
if (trim($linea) === '<tr>') {
    $indent = substr($linea, 0, strpos($linea, '<tr>'));
    $lines[$idx] = $indent . "<tr class=\"fila-\${c.resultado || 'sinresultado'}\">\n";
    echo "OK: linea 654 actualizada con clase dinamica.\n";
} else {
    echo "AVISO: la linea 654 no es <tr> simple, contenido actual:\n";
    echo $linea;
}

file_put_contents($path, implode('', $lines));
echo "Listo.\n";
