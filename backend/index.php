<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'db.php';

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

function get_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    return rtrim($path, '/') ?: '/';
}

function assign_initial_subjects(PDO $pdo, int $studentId): void {
    $studentStmt = $pdo->prepare("SELECT id_carrera, anio_actual FROM estudiantes WHERE id_estudiante = ?");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();
    if (!$student) return;

    $anioLectivo = (int) date('Y');
    $subjectStmt = $pdo->prepare(
        "SELECT id_materia
         FROM materias
         WHERE id_carrera = ?
           AND anio_plan = ?
           AND activa = TRUE
           AND (formato = 'anual' OR (formato = 'cuatrimestral' AND cuatrimestre = 1))"
    );
    $subjectStmt->execute([$student['id_carrera'], $student['anio_actual']]);
    $subjects = $subjectStmt->fetchAll();

    $insert = $pdo->prepare(
        "INSERT INTO inscripciones_materias
            (id_estudiante, id_materia, anio_lectivo, tipo_inscripcion, estado_secretaria, habilitada, observaciones)
         VALUES (?, ?, ?, 'automatica', 'habilitado', TRUE, 'Alta inicial aprobada por Secretaría')
         ON CONFLICT (id_estudiante, id_materia, anio_lectivo) DO NOTHING"
    );
    foreach ($subjects as $subject) {
        $insert->execute([$studentId, $subject['id_materia'], $anioLectivo]);
    }
}

try {
    $path = get_path();

    // ── Health check ──────────────────────────────────────────────────────────
    if ($path === '/health') {
        respond(['ok' => true, 'service' => 'backend']);
    }

    // ── Carreras ──────────────────────────────────────────────────────────────
    if ($path === '/carreras' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras WHERE activa = TRUE ORDER BY nombre_carrera");
        respond($stmt->fetchAll());
    }

    // ── Login estudiante por DNI ───────────────────────────────────────────────
    // El estudiante ingresa con DNI + contraseña (inicialmente su mismo DNI).
    // Se busca el usuario vinculado al estudiante cuyo dni coincide.
    if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data     = json_input();
        $dni      = trim($data['dni'] ?? '');
        $password = trim($data['password'] ?? '');

        if ($dni === '' || $password === '') {
            respond(['ok' => false, 'error' => 'DNI y contraseña son obligatorios.'], 400);
        }

        // Buscar el usuario a través del estudiante
        $stmt = $pdo->prepare(
            "SELECT u.id_usuario, u.password_hash, u.activo, e.dni
             FROM usuarios u
             JOIN estudiantes e ON e.id_usuario = u.id_usuario
             WHERE e.dni = ?"
        );
        $stmt->execute([$dni]);
        $user = $stmt->fetch();

        if (!$user || !$user['activo'] || !password_verify($password, $user['password_hash'])) {
            respond(['ok' => false, 'error' => 'DNI o contraseña incorrectos.'], 401);
        }

        respond(['ok' => true, 'dni' => $dni]);
    }

    // ── Perfil del estudiante por DNI ─────────────────────────────────────────
    if ($path === '/estudiante' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $dni = trim($_GET['dni'] ?? '');
        if ($dni === '') {
            respond(['ok' => false, 'error' => 'Falta el DNI.'], 400);
        }

        $stmt = $pdo->prepare(
            "SELECT e.*, c.nombre_carrera
             FROM estudiantes e
             JOIN carreras c ON c.id_carrera = e.id_carrera
             WHERE e.dni = ?"
        );
        $stmt->execute([$dni]);
        $student = $stmt->fetch();

        if (!$student) {
            respond(['ok' => true, 'inscripto' => false]);
        }

        respond(['ok' => true, 'inscripto' => true, 'data' => $student]);
    }

    // ── Inscripción (primer registro + actualizaciones) ───────────────────────
    // Al inscribirse por primera vez:
    //   1. Se crea el usuario con password = hash(DNI)
    //   2. Se crea el estudiante vinculado
    //   3. Se genera revision_secretaria en 'pendiente'
    // Si ya existe el estudiante (reenvío), solo se actualizan sus datos.
    if ($path === '/inscripcion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $apellido        = trim($_POST['apellido'] ?? '');
        $nombre          = trim($_POST['nombre'] ?? '');
        $dni             = trim($_POST['dni'] ?? '');
        $correo          = trim($_POST['correo'] ?? '');
        $telefono        = trim($_POST['telefono'] ?? '');
        $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        $domicilio       = trim($_POST['domicilio'] ?? '');
        $localidad       = trim($_POST['localidad'] ?? '');
        $idCarrera       = (int) ($_POST['id_carrera'] ?? 0);
        $anioActual      = (int) ($_POST['anio_actual'] ?? 0);
        $anioCohorte     = (int) ($_POST['anio_cohorte'] ?? 0);

        // Validaciones básicas
        if ($apellido === '' || $nombre === '' || $dni === '' || $idCarrera === 0 || $anioActual === 0) {
            respond(['ok' => false, 'error' => 'Faltan campos obligatorios.'], 400);
        }

        if (!preg_match('/^\d{7,8}$/', $dni)) {
            respond(['ok' => false, 'error' => 'El DNI debe tener 7 u 8 dígitos numéricos.'], 400);
        }

        // Guardar PDFs
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

        $savePdf = function (string $field, string $prefix) use ($uploadsDir): ?string {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
            $tmp  = $_FILES[$field]['tmp_name'];
            $name = $_FILES[$field]['name'];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                respond(['ok' => false, 'error' => 'Los archivos deben ser PDF.'], 400);
            }
            // Validar MIME real
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if ($mime !== 'application/pdf') {
                respond(['ok' => false, 'error' => 'El archivo no es un PDF válido.'], 400);
            }
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            move_uploaded_file($tmp, $uploadsDir . '/' . $filename);
            return 'uploads/' . $filename;
        };

        $pdfDni    = $savePdf('pdf_dni', 'dni');
        $pdfTitulo = $savePdf('pdf_titulo_secundario', 'titulo');

        $pdo->beginTransaction();

        // ¿Ya existe el estudiante con este DNI?
        $studentStmt = $pdo->prepare("SELECT id_estudiante, id_usuario FROM estudiantes WHERE dni = ?");
        $studentStmt->execute([$dni]);
        $existing = $studentStmt->fetch();

        if ($existing) {
            // ── Actualización ──
            $studentId = (int) $existing['id_estudiante'];
            $update = $pdo->prepare(
                "UPDATE estudiantes SET
                    apellido = ?, nombre = ?, correo = ?, telefono = ?,
                    fecha_nacimiento = ?, domicilio = ?, localidad = ?,
                    id_carrera = ?, anio_actual = ?, anio_cohorte = ?,
                    estado_general = 'pendiente_secretaria'
                 WHERE id_estudiante = ?"
            );
            $update->execute([
                $apellido, $nombre, $correo ?: null, $telefono ?: null,
                $fechaNacimiento ?: null, $domicilio ?: null, $localidad ?: null,
                $idCarrera, $anioActual, $anioCohorte ?: null,
                $studentId
            ]);
        } else {
            // ── Primer registro ──

            // Verificar que el correo no esté en uso (si se proveyó)
            if ($correo !== '') {
                $checkCorreo = $pdo->prepare("SELECT id_estudiante FROM estudiantes WHERE correo = ?");
                $checkCorreo->execute([$correo]);
                if ($checkCorreo->fetch()) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'Ese correo ya está registrado con otro estudiante.'], 409);
                }
            }

            // Crear usuario: email = dni@ifdc.local si no se proveyó correo real
            $emailUsuario = $correo !== '' ? $correo : ($dni . '@ifdc.local');
            $hash  = password_hash($dni, PASSWORD_DEFAULT); // contraseña inicial = DNI
            $token = bin2hex(random_bytes(16));

            $insUser = $pdo->prepare(
                "INSERT INTO usuarios (email, password_hash, email_validado, token_validacion)
                 VALUES (?, ?, TRUE, ?)"
            );
            $insUser->execute([$emailUsuario, $hash, $token]);
            $userId = (int) $pdo->lastInsertId();

            // Crear estudiante
            $insEst = $pdo->prepare(
                "INSERT INTO estudiantes
                 (id_usuario, apellido, nombre, dni, correo, telefono, fecha_nacimiento,
                  domicilio, localidad, id_carrera, anio_actual, anio_cohorte, estado_general)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente_secretaria')"
            );
            $insEst->execute([
                $userId, $apellido, $nombre, $dni, $correo ?: null, $telefono ?: null,
                $fechaNacimiento ?: null, $domicilio ?: null, $localidad ?: null,
                $idCarrera, $anioActual, $anioCohorte ?: null
            ]);
            $studentId = (int) $pdo->lastInsertId();
        }

        // Documentos
        $docStmt = $pdo->prepare("SELECT id_documento, pdf_dni, pdf_titulo_secundario FROM documentos_estudiante WHERE id_estudiante = ?");
        $docStmt->execute([$studentId]);
        $doc = $docStmt->fetch();
        if ($doc) {
            $updDoc = $pdo->prepare(
                "UPDATE documentos_estudiante SET pdf_dni = ?, pdf_titulo_secundario = ?, fecha_subida = CURRENT_TIMESTAMP WHERE id_estudiante = ?"
            );
            $updDoc->execute([$pdfDni ?: $doc['pdf_dni'], $pdfTitulo ?: $doc['pdf_titulo_secundario'], $studentId]);
        } else {
            $insDoc = $pdo->prepare("INSERT INTO documentos_estudiante (id_estudiante, pdf_dni, pdf_titulo_secundario) VALUES (?, ?, ?)");
            $insDoc->execute([$studentId, $pdfDni, $pdfTitulo]);
        }

        // Revisión secretaría
        $revStmt = $pdo->prepare("SELECT id_revision FROM revision_secretaria WHERE id_estudiante = ?");
        $revStmt->execute([$studentId]);
        if ($revStmt->fetch()) {
            $pdo->prepare(
                "UPDATE revision_secretaria SET documentacion_completa = FALSE, datos_correctos = FALSE,
                  estado_secretaria = 'pendiente', observaciones_secretaria = NULL, fecha_revision = NULL
                 WHERE id_estudiante = ?"
            )->execute([$studentId]);
        } else {
            $pdo->prepare("INSERT INTO revision_secretaria (id_estudiante) VALUES (?)")->execute([$studentId]);
        }

        $pdo->commit();
        respond(['ok' => true, 'dni' => $dni, 'message' => '¡Inscripción enviada! Ya podés ingresar al sistema con tu DNI. Tus datos quedan pendientes de revisión por Secretaría.']);
    }

    // ── Secretaría: login ──────────────────────────────────────────────────────
    if ($path === '/secretaria/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $password = trim($data['password'] ?? '');
        if ($password === getenv('SECRETARIA_PASSWORD')) {
            respond(['ok' => true]);
        }
        respond(['ok' => false, 'error' => 'Clave incorrecta.'], 401);
    }

    // ── Secretaría: listado ────────────────────────────────────────────────────
    if ($path === '/secretaria/inscriptos' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT e.id_estudiante, e.apellido, e.nombre, e.dni, e.correo, e.anio_actual, e.estado_general,
                    c.nombre_carrera, r.estado_secretaria, r.observaciones_secretaria,
                    d.pdf_dni, d.pdf_titulo_secundario
             FROM estudiantes e
             JOIN carreras c ON c.id_carrera = e.id_carrera
             LEFT JOIN revision_secretaria r ON r.id_estudiante = e.id_estudiante
             LEFT JOIN documentos_estudiante d ON d.id_estudiante = e.id_estudiante
             ORDER BY e.fecha_registro DESC"
        );
        respond($stmt->fetchAll());
    }

    // ── Secretaría: acción ────────────────────────────────────────────────────
    if ($path === '/secretaria/accion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data        = json_input();
        $studentId   = (int) ($data['id_estudiante'] ?? 0);
        $accion      = trim($data['accion'] ?? '');
        $observaciones = trim($data['observaciones'] ?? '');

        if ($studentId === 0 || !in_array($accion, ['aprobar', 'observar', 'rechazar'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        $pdo->beginTransaction();

        $map = [
            'aprobar' => ['estado_general' => 'aprobado_secretaria', 'estado_secretaria' => 'aprobado', 'documentacion' => true,  'datos' => true],
            'observar' => ['estado_general' => 'observado_secretaria','estado_secretaria' => 'observado','documentacion' => false, 'datos' => false],
            'rechazar' => ['estado_general' => 'rechazado_secretaria','estado_secretaria' => 'rechazado','documentacion' => false, 'datos' => false],
        ][$accion];

        $pdo->prepare("UPDATE estudiantes SET estado_general = ? WHERE id_estudiante = ?")
            ->execute([$map['estado_general'], $studentId]);

        $pdo->prepare(
            "UPDATE revision_secretaria
             SET documentacion_completa = ?, datos_correctos = ?, estado_secretaria = ?,
                 observaciones_secretaria = ?, fecha_revision = CURRENT_TIMESTAMP, usuario_secretaria = 'Secretaría'
             WHERE id_estudiante = ?"
        )->execute([$map['documentacion'], $map['datos'], $map['estado_secretaria'], $observaciones ?: null, $studentId]);

        if ($accion === 'aprobar') {
            $ticCheck = $pdo->prepare("SELECT id_carga FROM carga_tic WHERE id_estudiante = ?");
            $ticCheck->execute([$studentId]);
            if ($ticCheck->fetch()) {
                $pdo->prepare("UPDATE carga_tic SET estado_tic = 'pendiente', observaciones_tic = NULL, fecha_carga_plataforma = NULL, usuario_tic = NULL WHERE id_estudiante = ?")
                    ->execute([$studentId]);
            } else {
                $pdo->prepare("INSERT INTO carga_tic (id_estudiante) VALUES (?)")->execute([$studentId]);
            }
            assign_initial_subjects($pdo, $studentId);
        }

        $pdo->commit();
        respond(['ok' => true, 'message' => 'Acción registrada correctamente.']);
    }

    // ── TIC: login ────────────────────────────────────────────────────────────
    if ($path === '/tic/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $password = trim($data['password'] ?? '');
        if ($password === getenv('TIC_PASSWORD')) {
            respond(['ok' => true]);
        }
        respond(['ok' => false, 'error' => 'Clave incorrecta.'], 401);
    }

    // ── TIC: listado ──────────────────────────────────────────────────────────
    if ($path === '/tic/estudiantes' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT e.id_estudiante, e.apellido, e.nombre, e.dni, e.correo, e.anio_actual, e.estado_general,
                    c.nombre_carrera, t.estado_tic, t.fecha_carga_plataforma, t.observaciones_tic
             FROM estudiantes e
             JOIN carreras c ON c.id_carrera = e.id_carrera
             LEFT JOIN carga_tic t ON t.id_estudiante = e.id_estudiante
             WHERE e.estado_general = 'aprobado_secretaria' OR e.estado_general = 'cargado_plataforma'
             ORDER BY e.apellido, e.nombre"
        );
        respond($stmt->fetchAll());
    }

    // ── TIC: marcar cargado ───────────────────────────────────────────────────
    if ($path === '/tic/cargar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data        = json_input();
        $studentId   = (int) ($data['id_estudiante'] ?? 0);
        $observaciones = trim($data['observaciones'] ?? '');
        if ($studentId === 0) {
            respond(['ok' => false, 'error' => 'Falta el estudiante.'], 400);
        }

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE estudiantes SET estado_general = 'cargado_plataforma' WHERE id_estudiante = ?")
            ->execute([$studentId]);
        $pdo->prepare(
            "UPDATE carga_tic
             SET estado_tic = 'cargado', fecha_carga_plataforma = CURRENT_TIMESTAMP,
                 observaciones_tic = ?, usuario_tic = 'Área TIC'
             WHERE id_estudiante = ?"
        )->execute([$observaciones ?: null, $studentId]);
        $pdo->commit();
        respond(['ok' => true, 'message' => 'Estudiante marcado como cargado en plataforma.']);
    }

    // ── Secretaría: listado de alumnos cursando con correlativas ─────────────
    // Devuelve todos los alumnos activos con sus inscripciones a materias
    // y el estado de cada correlativa requerida.
    if ($path === '/secretaria/cursadas' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT e.id_estudiante, e.apellido, e.nombre, e.dni, e.correo, e.anio_actual,
                    c.nombre_carrera
             FROM estudiantes e
             JOIN carreras c ON c.id_carrera = e.id_carrera
             WHERE e.estado_general IN ('cargado_plataforma','aprobado_secretaria')
             ORDER BY e.apellido, e.nombre"
        );
        $alumnos = $stmt->fetchAll();

        foreach ($alumnos as &$alumno) {
            // Inscripciones a materias del año lectivo actual
            $mStmt = $pdo->prepare(
                "SELECT im.id_inscripcion_materia, im.estado_secretaria AS estado_condicion,
                        im.observaciones, m.id_materia, m.nombre_materia, m.codigo_materia, m.formato
                 FROM inscripciones_materias im
                 JOIN materias m ON m.id_materia = im.id_materia
                 WHERE im.id_estudiante = ? AND im.anio_lectivo = ?
                 ORDER BY m.anio_plan, m.nombre_materia"
            );
            $mStmt->execute([$alumno['id_estudiante'], (int) date('Y')]);
            $materias = $mStmt->fetchAll();

            foreach ($materias as &$materia) {
                // Correlativas requeridas para esta materia
                $cStmt = $pdo->prepare(
                    "SELECT cor.tipo, m2.nombre_materia,
                            -- Verificar si el alumno aprobó/cursó la materia requerida
                            EXISTS (
                                SELECT 1 FROM cursadas cu
                                WHERE cu.id_estudiante = ?
                                  AND cu.id_materia = cor.id_materia_requerida
                                  AND (
                                    (cor.tipo = 'aprobada' AND cu.resultado IN ('aprobado','promocion'))
                                    OR
                                    (cor.tipo = 'cursada'  AND cu.resultado IS NOT NULL)
                                  )
                            ) AS cumple
                     FROM correlativas cor
                     JOIN materias m2 ON m2.id_materia = cor.id_materia_requerida
                     WHERE cor.id_materia = ?"
                );
                $cStmt->execute([$alumno['id_estudiante'], $materia['id_materia']]);
                $correlativas = $cStmt->fetchAll();

                $materia['correlativas_ok']   = [];
                $materia['correlativas_fail'] = [];
                foreach ($correlativas as $cor) {
                    $label = $cor['nombre_materia'] . ($cor['tipo'] === 'cursada' ? ' (cursada)' : ' (aprobada)');
                    if ($cor['cumple']) {
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
    }

    // ── Secretaría: cursadas pendientes (para badge del menú) ─────────────────
    if ($path === '/secretaria/cursadas/pendientes' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare(
            "SELECT im.id_inscripcion_materia
             FROM inscripciones_materias im
             JOIN estudiantes e ON e.id_estudiante = im.id_estudiante
             WHERE im.estado_secretaria = 'pendiente'
               AND e.estado_general IN ('cargado_plataforma','aprobado_secretaria')
               AND im.anio_lectivo = ?"
        );
        $stmt->execute([(int) date('Y')]);
        respond($stmt->fetchAll());
    }

    // ── Secretaría: verificar condición de cursada ────────────────────────────
    if ($path === '/secretaria/cursadas/verificar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data       = json_input();
        $idInsc     = (int) ($data['id_inscripcion_materia'] ?? 0);
        $estado     = trim($data['estado'] ?? '');
        $obs        = trim($data['observaciones'] ?? '');

        if ($idInsc === 0 || !in_array($estado, ['pendiente', 'apto', 'no_apto', 'habilitado', 'rechazado'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        // Mapear estado UI → estado DB
        $estadoDB  = match($estado) {
            'apto'     => 'habilitado',
            'no_apto'  => 'rechazado',
            default    => 'pendiente'
        };
        $habilitada = $estado === 'apto';

        $pdo->prepare(
            "UPDATE inscripciones_materias
             SET estado_secretaria = ?, habilitada = ?, observaciones = ?
             WHERE id_inscripcion_materia = ?"
        )->execute([$estadoDB, $habilitada, $obs ?: null, $idInsc]);

        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Ruta no encontrada.'], 404);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // En producción no exponer el mensaje real
    respond(['ok' => false, 'error' => 'Error interno del servidor.'], 500);
}
