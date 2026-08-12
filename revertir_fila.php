<?php
$path = "/tmp/panel_index.html";
$lines = file($path);

$linea572 = $lines[571]; // indice 0, linea 572 es indice 571
if (strpos($linea572, "fila-\${c.resultado") !== false) {
    $indent = substr($linea572, 0, strpos($linea572, '<tr'));
    $lines[571] = $indent . "<tr>\n";
    echo "OK: linea 572 revertida a <tr> simple.\n";
} else {
    echo "AVISO: la linea 572 no contenia el patron esperado, contenido actual:\n";
    echo $linea572;
}

file_put_contents($path, implode('', $lines));
echo "Listo.\n";
