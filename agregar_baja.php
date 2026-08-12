<?php
$path = "/var/www/html/index.php";
$content = file_get_contents($path);
$marker = "if (\$path === '/docente/materias/alta'";

$nuevo_bloque = <<<PHP
if (\$path === '/docente/materias/baja' && \$_SERVER['REQUEST_METHOD'] === 'POST') {
    \$data       = json_input();
    \$idDocente  = (int)(\$data['id_docente'] ?? 0);
    \$idMateria  = (int)(\$data['id_materia'] ?? 0);

    if (\$idDocente === 0 || \$idMateria === 0) {
        respond(['ok' => false, 'error' => 'Faltan id_docente o id_materia.'], 400);
    }

    \$del = \$pdo->prepare("DELETE FROM docente_materias WHERE id_docente = ? AND id_materia = ?");
    \$del->execute([\$idDocente, \$idMateria]);

    if (\$del->rowCount() === 0) {
        respond(['ok' => false, 'error' => 'No se encontro esa materia asignada al docente.'], 404);
    }

    respond(['ok' => true, 'message' => 'Materia dada de baja correctamente.']);
}


PHP;

if (strpos($content, $marker) === false) {
    echo "ERROR: no se encontro el marcador, no se modifico nada.\n";
} else {
    $content = str_replace($marker, $nuevo_bloque . $marker, $content);
    file_put_contents($path, $content);
    echo "OK: bloque insertado correctamente.\n";
}
