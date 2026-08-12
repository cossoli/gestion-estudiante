<?php
$path = "/tmp/panel_index.html";
$lines = file($path);

$encontrado = false;
foreach ($lines as $i => $line) {
    if (strpos($line, 'nombre_materia') !== false && strpos($line, 'strong') !== false) {
        // La linea anterior deberia ser el <tr>
        if (trim($lines[$i - 1]) === '<tr>') {
            $indent = substr($lines[$i-1], 0, strpos($lines[$i-1], '<tr>'));
            $lines[$i - 1] = $indent . "<tr class=\"fila-\${c.resultado || 'sinresultado'}\">\n";
            $encontrado = true;
            echo "OK: clase de fila agregada en linea " . $i . "\n";
            break;
        }
    }
}
if (!$encontrado) {
    echo "ERROR: no se encontro el patron esperado.\n";
}

file_put_contents($path, implode('', $lines));
echo "Listo.\n";
