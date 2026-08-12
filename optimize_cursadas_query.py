#!/usr/bin/env python3
import re
import shutil
import datetime

PHP_PATH = "./backend/index.php"

NEW_BLOCK = '''if ($path === '/secretaria/cursadas' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT e.id_estudiante, e.apellido, e.nombre, e.dni, e.correo, e.anio_actual,
                c.nombre_carrera
         FROM estudiantes e
         JOIN carreras c ON c.id_carrera = e.id_carrera
         WHERE e.estado_general IN ('cargado_plataforma','aprobado_secretaria')
         ORDER BY e.apellido, e.nombre"
    );
    $alumnos = $stmt->fetchAll();

    $idsEstudiantes = array_column($alumnos, 'id_estudiante');

    if (!$idsEstudiantes) {
        respond($alumnos);
    }

    $placeholders = implode(',', array_fill(0, count($idsEstudiantes), '?'));
    $anioActual   = (int) date('Y');

    // --- 1 sola consulta: todas las materias inscriptas de todos los alumnos ---
    $mStmt = $pdo->prepare(
        "SELECT im.id_estudiante, im.id_inscripcion_materia, im.estado_secretaria AS estado_condicion,
                im.observaciones, m.id_materia, m.nombre_materia, m.codigo_materia,
                m.formato, m.cuatrimestre, m.anio_plan,
                c2.nombre_carrera AS carrera_materia,
                cu.resultado AS resultado_cursada
         FROM inscripciones_materias im
         JOIN materias m ON m.id_materia = im.id_materia
         JOIN carreras c2 ON c2.id_carrera = m.id_carrera
         LEFT JOIN cursadas cu ON cu.id_estudiante = im.id_estudiante
           AND cu.id_materia = im.id_materia
           AND cu.anio_cursada = im.anio_lectivo
         WHERE im.id_estudiante IN ($placeholders) AND im.anio_lectivo = ?"
    );
    $mStmt->execute([...$idsEstudiantes, $anioActual]);
    $todasMaterias = $mStmt->fetchAll();

    $materiasPorAlumno = [];
    $idsMateriasUnicas = [];
    foreach ($todasMaterias as $fila) {
        $materiasPorAlumno[$fila['id_estudiante']][] = $fila;
        $idsMateriasUnicas[$fila['id_materia']] = true;
    }
    $idsMateriasUnicas = array_keys($idsMateriasUnicas);

    // --- 1 sola consulta: todas las correlativas de esas materias ---
    $correlativasPorMateria = [];
    if ($idsMateriasUnicas) {
        $ph2 = implode(',', array_fill(0, count($idsMateriasUnicas), '?'));
        $cStmt = $pdo->prepare(
            "SELECT cor.id_materia, cor.tipo, cor.id_materia_requerida, m2.nombre_materia
             FROM correlativas cor
             JOIN materias m2 ON m2.id_materia = cor.id_materia_requerida
             WHERE cor.id_materia IN ($ph2)"
        );
        $cStmt->execute($idsMateriasUnicas);
        foreach ($cStmt->fetchAll() as $c) {
            $correlativasPorMateria[$c['id_materia']][] = $c;
        }
    }

    // --- 1 sola consulta: todas las cursadas (con resultado) de estos alumnos ---
    $resultadosPorAlumnoMateria = [];
    $cuStmt = $pdo->prepare(
        "SELECT id_estudiante, id_materia, resultado
         FROM cursadas
         WHERE id_estudiante IN ($placeholders) AND resultado IS NOT NULL"
    );
    $cuStmt->execute($idsEstudiantes);
    foreach ($cuStmt->fetchAll() as $r) {
        $resultadosPorAlumnoMateria[$r['id_estudiante']][$r['id_materia']][] = $r['resultado'];
    }

    // --- Armar la respuesta final en PHP, sin más consultas a la base ---
    foreach ($alumnos as &$alumno) {
        $materias = $materiasPorAlumno[$alumno['id_estudiante']] ?? [];

        foreach ($materias as &$materia) {
            $materia['correlativas_ok']   = [];
            $materia['correlativas_fail'] = [];

            $reqs = $correlativasPorMateria[$materia['id_materia']] ?? [];
            foreach ($reqs as $req) {
                $resultados = $resultadosPorAlumnoMateria[$alumno['id_estudiante']][$req['id_materia_requerida']] ?? [];

                if ($req['tipo'] === 'aprobada') {
                    $cumple = !empty(array_intersect($resultados, ['aprobado', 'promocion']));
                } else {
                    $cumple = !empty($resultados);
                }

                $label = $req['nombre_materia'] . ($req['tipo'] === 'cursada' ? ' (cursada)' : ' (aprobada)');
                if ($cumple) {
                    $materia['correlativas_ok'][] = $label;
                } else {
                    $materia['correlativas_fail'][] = $label;
                }
            }
        }
        unset($materia);

        $alumno['materias'] = $materias;
    }
    unset($alumno);

    respond($alumnos);
}'''

def main():
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = f"{PHP_PATH}.bak_{timestamp}"
    shutil.copy2(PHP_PATH, backup_path)
    print(f"Backup creado en: {backup_path}")

    with open(PHP_PATH, "r", encoding="utf-8") as f:
        php = f.read()

    pattern = re.compile(
        r"if \(\$path === '/secretaria/cursadas' && \$_SERVER\['REQUEST_METHOD'\] === 'GET'\) \{"
        r".*?"
        r"respond\(\$alumnos\);\s*\}",
        re.DOTALL
    )

    matches = list(pattern.finditer(php))
    if len(matches) != 1:
        print(f"AVISO: se esperaba encontrar 1 bloque, se encontraron {len(matches)}. No se modificó nada.")
        return

    m = matches[0]
    php_new = php[:m.start()] + NEW_BLOCK + php[m.end():]

    with open(PHP_PATH, "w", encoding="utf-8") as f:
        f.write(php_new)

    print("¡Listo! Consulta de /secretaria/cursadas optimizada (de N+1 queries a 3 consultas totales).")
    print(f"Si algo se ve mal, restaurá con: cp {backup_path} {PHP_PATH}")

if __name__ == "__main__":
    main()
