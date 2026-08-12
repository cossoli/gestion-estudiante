<?php
$path = "/var/www/html/index.php";
$content = file_get_contents($path);
$marker = "if (\$path === '/tic/nuevas-materias'";

$nuevo_bloque = <<<PHP
if (\$path === '/docente/materias/alta' && \$_SERVER['REQUEST_METHOD'] === 'POST') {
    \$data       = json_input();
    \$idDocente  = (int)(\$data['id_docente'] ?? 0);
    \$idMateria  = (int)(\$data['id_materia'] ?? 0);

    if (\$idDocente === 0 || \$idMateria === 0) {
        respond(['ok' => false, 'error' => 'Faltan id_docente o id_materia.'], 400);
    }

    \$check = \$pdo->prepare("SELECT id_materia FROM materias WHERE id_materia = ?");
    \$check->execute([\$idMateria]);
    if (!\$check->fetch()) {
        respond(['ok' => false, 'error' => 'La materia indicada no existe.'], 404);
    }

    \$dup = \$pdo->prepare("SELECT 1 FROM docente_materias WHERE id_docente = ? AND id_materia = ?");
    \$dup->execute([\$idDocente, \$idMateria]);
    if (\$dup->fetch()) {
        respond(['ok' => false, 'error' => 'El docente ya tiene asignada esa materia.'], 409);
    }

    \$ins = \$pdo->prepare("INSERT INTO docente_materias (id_docente, id_materia) VALUES (?, ?)");
    \$ins->execute([\$idDocente, \$idMateria]);

    respond(['ok' => true, 'message' => 'Materia agregada correctamente.']);
}


PHP;

if (strpos($content, $marker) === false) {
    echo "ERROR: no se encontro el marcador, no se modifico nada.\n";
} else {
    $content = str_replace($marker, $nuevo_bloque . $marker, $content);
    file_put_contents($path, $content);
    echo "OK: bloque insertado correctamente.\n";
}
