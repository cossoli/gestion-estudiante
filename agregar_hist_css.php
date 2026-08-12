<?php
$path = "/tmp/panel_index.html";
$lines = file($path);

$encontrado = false;
foreach ($lines as $i => $line) {
    if (strpos($line, '.materia-card.aprobado') !== false) {
        $indent = "    ";
        $nuevasLineas = [
            $indent . ".fila-aprobado    td { background: #e8f4fb; }\n",
            $indent . ".fila-promocion   td { background: #e8f4fb; }\n",
            $indent . ".fila-desaprobado td { background: #fdeeee; }\n",
            $indent . ".fila-abandono    td { background: #fdeeee; }\n",
        ];
        array_splice($lines, $i + 1, 0, $nuevasLineas);
        $encontrado = true;
        echo "OK: CSS de filas de historial insertado despues de la linea " . ($i + 1) . "\n";
        break;
    }
}
if (!$encontrado) {
    echo "ERROR: no se encontro la linea .materia-card.aprobado\n";
}

file_put_contents($path, implode('', $lines));
echo "Listo.\n";
