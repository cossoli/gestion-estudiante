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

    // Solo se inscriben automáticamente las materias del 1° cuatrimestre del 1° año.
    // Alumnos de años superiores deben solicitar inscripción a Secretaría.
    if ((int) $student['anio_actual'] !== 1) return;

    $anioLectivo = (int) date('Y');
    $subjectStmt = $pdo->prepare(
        "SELECT id_materia
         FROM materias
         WHERE id_carrera = ?
           AND anio_plan = 1
           AND activa = TRUE
           AND formato = 'cuatrimestral'
           AND cuatrimestre = 1"
    );
    $subjectStmt->execute([$student['id_carrera']]);
    $subjects = $subjectStmt->fetchAll();

    $insert = $pdo->prepare(
        "INSERT INTO inscripciones_materias
            (id_estudiante, id_materia, anio_lectivo, tipo_inscripcion, estado_secretaria, habilitada, observaciones)
         VALUES (?, ?, ?, 'automatica', 'habilitado', TRUE, 'Alta inicial — 1° cuatrimestre 1° año')
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

        // Traer carreras del alumno
        $cStmt = $pdo->prepare(
            "SELECT ec.id_carrera, ec.anio_actual, ec.anio_cohorte, c.nombre_carrera
             FROM estudiante_carreras ec
             JOIN carreras c ON c.id_carrera = ec.id_carrera
             WHERE ec.id_estudiante = ?"
        );
        $cStmt->execute([$student['id_estudiante']]);
        $carreras = $cStmt->fetchAll();

        respond(['ok' => true, 'inscripto' => true, 'data' => $student, 'carreras' => $carreras]);
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
        $anioCohorte     = (int) ($_POST['anio_cohorte'] ?? 0);

        // Carreras múltiples desde JSON
        $carrerasJson = trim($_POST['carreras_json'] ?? '');
        $carreras     = $carrerasJson ? json_decode($carrerasJson, true) : [];
        $idCarrera    = !empty($carreras) ? (int) $carreras[0] : 0; // carrera principal

        // Validaciones básicas
        if ($apellido === '' || $nombre === '' || $dni === '' || empty($carreras)) {
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
                    id_carrera = ?, anio_actual = 1, anio_cohorte = ?,
                    estado_general = 'pendiente_secretaria'
                 WHERE id_estudiante = ?"
            );
            $update->execute([
                $apellido, $nombre, $correo ?: null, $telefono ?: null,
                $fechaNacimiento ?: null, $domicilio ?: null, $localidad ?: null,
                $idCarrera, $anioCohorte ?: null,
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'pendiente_secretaria')"
            );
            $insEst->execute([
                $userId, $apellido, $nombre, $dni, $correo ?: null, $telefono ?: null,
                $fechaNacimiento ?: null, $domicilio ?: null, $localidad ?: null,
                $idCarrera, $anioCohorte ?: null
            ]);
            $studentId = (int) $pdo->lastInsertId();
        }

        // Registrar todas las carreras en estudiante_carreras
        $insCarrera = $pdo->prepare(
            "INSERT INTO estudiante_carreras (id_estudiante, id_carrera, anio_actual, anio_cohorte)
             VALUES (?, ?, 1, ?)
             ON CONFLICT (id_estudiante, id_carrera) DO UPDATE SET anio_cohorte = EXCLUDED.anio_cohorte"
        );
        foreach ($carreras as $cid) {
            $insCarrera->execute([$studentId, (int) $cid, $anioCohorte ?: null]);
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
        $estudiantes = $stmt->fetchAll();

        // Agregar carreras y materias a cada estudiante
        foreach ($estudiantes as &$est) {
            // Carreras
            $cStmt = $pdo->prepare(
                "SELECT ec.id_carrera, c.nombre_carrera
                 FROM estudiante_carreras ec
                 JOIN carreras c ON c.id_carrera = ec.id_carrera
                 WHERE ec.id_estudiante = ?"
            );
            $cStmt->execute([$est['id_estudiante']]);
            $carreras = $cStmt->fetchAll();
            $est['carreras'] = !empty($carreras) ? $carreras : [['id_carrera' => $est['id_carrera'], 'nombre_carrera' => $est['nombre_carrera']]];

            // Materias inscriptas del año lectivo actual
            $mStmt = $pdo->prepare(
                "SELECT m.nombre_materia, m.codigo_materia
                 FROM inscripciones_materias im
                 JOIN materias m ON m.id_materia = im.id_materia
                 WHERE im.id_estudiante = ? AND im.anio_lectivo = ?
                 ORDER BY m.nombre_materia"
            );
            $mStmt->execute([$est['id_estudiante'], (int) date('Y')]);
            $est['materias'] = $mStmt->fetchAll();
        }
        unset($est);

        respond($estudiantes);
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

    // ── Panel alumno: materias inscriptas agrupadas por carrera ─────────────
    if ($path === '/alumno/materias' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $dni = trim($_GET['dni'] ?? '');
        if ($dni === '') respond(['ok' => false, 'error' => 'Falta el DNI.'], 400);

        $estStmt = $pdo->prepare("SELECT id_estudiante FROM estudiantes WHERE dni = ?");
        $estStmt->execute([$dni]);
        $est = $estStmt->fetch();
        if (!$est) respond(['ok' => false, 'error' => 'Estudiante no encontrado.'], 404);

        // Traer materias agrupadas por carrera
        $stmt = $pdo->prepare(
            "SELECT im.id_inscripcion_materia, im.estado_secretaria, im.observaciones,
                    m.id_materia, m.nombre_materia, m.codigo_materia, m.formato, m.cuatrimestre, m.anio_plan,
                    m.id_carrera
             FROM inscripciones_materias im
             JOIN materias m ON m.id_materia = im.id_materia
             WHERE im.id_estudiante = ? AND im.anio_lectivo = ?
             ORDER BY m.id_carrera, m.anio_plan, m.cuatrimestre, m.nombre_materia"
        );
        $stmt->execute([$est['id_estudiante'], (int) date('Y')]);
        $rows = $stmt->fetchAll();

        // Agrupar por id_carrera
        $porCarrera = [];
        foreach ($rows as $row) {
            $cid = $row['id_carrera'];
            if (!isset($porCarrera[$cid])) $porCarrera[$cid] = [];
            $porCarrera[$cid][] = $row;
        }

        respond(['ok' => true, 'porCarrera' => $porCarrera]);
    }

    // ── Panel alumno: materias disponibles para inscribirse (por carrera) ──────
    if ($path === '/alumno/materias-disponibles' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $dni       = trim($_GET['dni'] ?? '');
        $idCarrera = (int) ($_GET['id_carrera'] ?? 0);
        if ($dni === '') respond(['ok' => false, 'error' => 'Falta el DNI.'], 400);
        if ($idCarrera === 0) respond(['ok' => false, 'error' => 'Falta id_carrera.'], 400);

        $estStmt = $pdo->prepare("SELECT id_estudiante FROM estudiantes WHERE dni = ?");
        $estStmt->execute([$dni]);
        $est = $estStmt->fetch();
        if (!$est) respond(['ok' => false, 'error' => 'Estudiante no encontrado.'], 404);

        $stmt = $pdo->prepare(
            "SELECT m.id_materia, m.nombre_materia, m.codigo_materia, m.formato, m.cuatrimestre, m.anio_plan
             FROM materias m
             WHERE m.id_carrera = ?
               AND m.activa = TRUE
               AND m.id_materia NOT IN (
                   SELECT id_materia FROM inscripciones_materias
                   WHERE id_estudiante = ? AND anio_lectivo = ?
               )
             ORDER BY m.anio_plan, m.cuatrimestre, m.nombre_materia"
        );
        $stmt->execute([$idCarrera, $est['id_estudiante'], (int) date('Y')]);
        respond(['ok' => true, 'materias' => $stmt->fetchAll()]);
    }

    // ── Panel alumno: inscribirse a una materia ───────────────────────────────
    if ($path === '/alumno/inscribir-materia' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data      = json_input();
        $dni       = trim($data['dni'] ?? '');
        $idMateria = (int) ($data['id_materia'] ?? 0);

        if ($dni === '' || $idMateria === 0) {
            respond(['ok' => false, 'error' => 'Datos incompletos.'], 400);
        }

        $estStmt = $pdo->prepare(
            "SELECT id_estudiante, estado_general FROM estudiantes WHERE dni = ?"
        );
        $estStmt->execute([$dni]);
        $est = $estStmt->fetch();
        if (!$est) respond(['ok' => false, 'error' => 'Estudiante no encontrado.'], 404);

        if (!in_array($est['estado_general'], ['aprobado_secretaria', 'cargado_plataforma'])) {
            respond(['ok' => false, 'error' => 'Tu inscripción todavía no fue aprobada por Secretaría.'], 403);
        }

        $anioLectivo = (int) date('Y');

        // Verificar que no esté ya inscripto
        $check = $pdo->prepare(
            "SELECT id_inscripcion_materia FROM inscripciones_materias
             WHERE id_estudiante = ? AND id_materia = ? AND anio_lectivo = ?"
        );
        $check->execute([$est['id_estudiante'], $idMateria, $anioLectivo]);
        if ($check->fetch()) {
            respond(['ok' => false, 'error' => 'Ya estás inscripto a esa materia.'], 409);
        }

        $pdo->prepare(
            "INSERT INTO inscripciones_materias
             (id_estudiante, id_materia, anio_lectivo, tipo_inscripcion, estado_secretaria, habilitada)
             VALUES (?, ?, ?, 'manual', 'pendiente', FALSE)"
        )->execute([$est['id_estudiante'], $idMateria, $anioLectivo]);

        respond(['ok' => true, 'message' => 'Inscripción registrada. Quedará pendiente de verificación por Secretaría.']);
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

    // ── Materias por carrera ──────────────────────────────────────────────────
    if ($path === '/materias' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idCarrera = (int) ($_GET['id_carrera'] ?? 0);
        if ($idCarrera === 0) respond(['ok' => false, 'error' => 'Falta id_carrera.'], 400);
        $stmt = $pdo->prepare("SELECT id_materia, nombre_materia, codigo_materia, formato, cuatrimestre, anio_plan FROM materias WHERE id_carrera = ? AND activa = TRUE ORDER BY anio_plan, nombre_materia");
        $stmt->execute([$idCarrera]);
        respond($stmt->fetchAll());
    }

    // ── Secretaría: listado de docentes ───────────────────────────────────────
    if ($path === '/secretaria/docentes' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT id_docente, apellido, nombre, email FROM docentes WHERE activo = TRUE ORDER BY apellido, nombre");
        respond($stmt->fetchAll());
    }

    // ── Secretaría: mesas — listado ───────────────────────────────────────────
    if ($path === '/secretaria/mesas' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT mf.id_mesa, mf.id_materia, mf.id_docente, mf.anio, mf.turno,
                    mf.fecha_mesa, mf.aula, mf.activa,
                    m.nombre_materia, m.id_carrera,
                    c.nombre_carrera,
                    d.apellido || ', ' || d.nombre AS docente_nombre,
                    COUNT(im.id_inscripcion) AS total_inscriptos
             FROM mesas_finales mf
             JOIN materias m ON m.id_materia = mf.id_materia
             JOIN carreras c ON c.id_carrera = m.id_carrera
             LEFT JOIN docentes d ON d.id_docente = mf.id_docente
             LEFT JOIN inscripciones_mesas im ON im.id_mesa = mf.id_mesa
             GROUP BY mf.id_mesa, m.nombre_materia, m.id_carrera, c.nombre_carrera, d.apellido, d.nombre
             ORDER BY mf.anio DESC, mf.fecha_mesa DESC NULLS LAST"
        );
        respond($stmt->fetchAll());
    }

    // ── Secretaría: mesas — crear ─────────────────────────────────────────────
    if ($path === '/secretaria/mesas' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data       = json_input();
        $idMateria  = (int) ($data['id_materia'] ?? 0);
        $turno      = trim($data['turno'] ?? '');
        $anio       = (int) ($data['anio'] ?? 0);
        $fechaMesa  = trim($data['fecha_mesa'] ?? '') ?: null;
        $aula       = trim($data['aula'] ?? '') ?: null;
        $idDocente  = (int) ($data['id_docente'] ?? 0) ?: null;
        $activa     = (bool) ($data['activa'] ?? true);

        if ($idMateria === 0 || $turno === '' || $anio === 0) {
            respond(['ok' => false, 'error' => 'Faltan campos obligatorios.'], 400);
        }
        if (!in_array($turno, ['feb_mar','julio','nov_dic'], true)) {
            respond(['ok' => false, 'error' => 'Turno inválido.'], 400);
        }

        $pdo->prepare(
            "INSERT INTO mesas_finales (id_materia, id_docente, anio, turno, fecha_mesa, aula, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$idMateria, $idDocente, $anio, $turno, $fechaMesa, $aula, $activa]);

        respond(['ok' => true, 'message' => 'Mesa creada correctamente.']);
    }

    // ── Secretaría: mesas — editar ────────────────────────────────────────────
    if (preg_match('#^/secretaria/mesas/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $idMesa    = (int) $m[1];
        $data      = json_input();

        // Puede venir solo 'activa' (toggle) o todos los campos
        if (isset($data['activa']) && count($data) === 1) {
            $pdo->prepare("UPDATE mesas_finales SET activa = ? WHERE id_mesa = ?")
                ->execute([$data['activa'], $idMesa]);
        } else {
            $idMateria = (int) ($data['id_materia'] ?? 0);
            $turno     = trim($data['turno'] ?? '');
            $anio      = (int) ($data['anio'] ?? 0);
            $fechaMesa = trim($data['fecha_mesa'] ?? '') ?: null;
            $aula      = trim($data['aula'] ?? '') ?: null;
            $idDocente = (int) ($data['id_docente'] ?? 0) ?: null;
            $activa    = (bool) ($data['activa'] ?? true);

            if ($idMateria === 0 || $turno === '' || $anio === 0) {
                respond(['ok' => false, 'error' => 'Faltan campos obligatorios.'], 400);
            }
            $pdo->prepare(
                "UPDATE mesas_finales SET id_materia=?, id_docente=?, anio=?, turno=?, fecha_mesa=?, aula=?, activa=? WHERE id_mesa=?"
            )->execute([$idMateria, $idDocente, $anio, $turno, $fechaMesa, $aula, $activa, $idMesa]);
        }
        respond(['ok' => true]);
    }

    // ── Secretaría: mesas — inscriptos ────────────────────────────────────────
    if (preg_match('#^/secretaria/mesas/(\d+)/inscriptos$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idMesa = (int) $m[1];
        $stmt   = $pdo->prepare(
            "SELECT im.id_inscripcion, im.estado_condicion, im.resultado, im.nota_obtenida, im.obs_mesa,
                    e.apellido, e.nombre, e.dni, e.correo
             FROM inscripciones_mesas im
             JOIN estudiantes e ON e.id_estudiante = im.id_estudiante
             WHERE im.id_mesa = ?
             ORDER BY e.apellido, e.nombre"
        );
        $stmt->execute([$idMesa]);
        respond(['ok' => true, 'inscriptos' => $stmt->fetchAll()]);
    }

    // ── Secretaría: mesas — verificar condición ───────────────────────────────
    if ($path === '/secretaria/mesas/verificar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data   = json_input();
        $idInsc = (int) ($data['id_inscripcion'] ?? 0);
        $estado = trim($data['estado_condicion'] ?? '');
        if ($idInsc === 0 || !in_array($estado, ['pendiente','apto','no_apto'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }
        $pdo->prepare(
            "UPDATE inscripciones_mesas SET estado_condicion = ?, condicion_verificada = ? WHERE id_inscripcion = ?"
        )->execute([$estado, $estado !== 'pendiente', $idInsc]);
        respond(['ok' => true]);
    }

    // ── Secretaría: mesas — cargar resultado ──────────────────────────────────
    if ($path === '/secretaria/mesas/resultado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data      = json_input();
        $idInsc    = (int) ($data['id_inscripcion'] ?? 0);
        $resultado = trim($data['resultado'] ?? '');
        $nota      = isset($data['nota_obtenida']) && $data['nota_obtenida'] !== null ? (float) $data['nota_obtenida'] : null;
        $obs       = trim($data['obs_mesa'] ?? '') ?: null;

        if ($idInsc === 0 || !in_array($resultado, ['aprobado','reprobado','ausente'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        $pdo->prepare(
            "UPDATE inscripciones_mesas SET resultado = ?, nota_obtenida = ?, obs_mesa = ? WHERE id_inscripcion = ?"
        )->execute([$resultado, $nota, $obs, $idInsc]);

        respond(['ok' => true, 'message' => 'Resultado cargado correctamente.']);
    }

    // ── Docente: registro ─────────────────────────────────────────────────────
    if ($path === '/docente/registro' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data     = json_input();
        $apellido = trim($data['apellido'] ?? '');
        $nombre   = trim($data['nombre']   ?? '');
        $dni      = trim($data['dni']      ?? '');
        $email    = trim($data['email']    ?? '') ?: null;
        $materias = $data['materias']      ?? [];

        if ($apellido === '' || $nombre === '' || $dni === '') {
            respond(['ok' => false, 'error' => 'Apellido, nombre y DNI son obligatorios.'], 400);
        }
        if (!preg_match('/^\d{7,8}$/', $dni)) {
            respond(['ok' => false, 'error' => 'El DNI debe tener 7 u 8 dígitos numéricos.'], 400);
        }
        if (!is_array($materias) || count($materias) === 0) {
            respond(['ok' => false, 'error' => 'Seleccioná al menos una materia.'], 400);
        }

        // Verificar DNI único
        $check = $pdo->prepare("SELECT id_docente FROM docentes WHERE dni = ?");
        $check->execute([$dni]);
        if ($check->fetch()) {
            respond(['ok' => false, 'error' => 'Ya existe un docente con ese DNI.'], 409);
        }

        // Contraseña inicial = DNI
        $hash = password_hash($dni, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        // Insertar docente
        $pdo->prepare(
            "INSERT INTO docentes (apellido, nombre, dni, email, password_hash) VALUES (?, ?, ?, ?, ?)"
        )->execute([$apellido, $nombre, $dni, $email, $hash]);
        $idDocente = (int) $pdo->lastInsertId();

        // Asignar solo las materias seleccionadas por el docente
        $insMat = $pdo->prepare(
            "INSERT INTO docente_materias (id_docente, id_materia)
             VALUES (?, ?)
             ON CONFLICT DO NOTHING"
        );
        foreach ($materias as $idMateria) {
            $insMat->execute([$idDocente, (int) $idMateria]);
        }

        $pdo->commit();

        respond(['ok' => true, 'docente' => [
            'id_docente' => $idDocente,
            'apellido'   => $apellido,
            'nombre'     => $nombre,
            'dni'        => $dni,
            'email'      => $email,
        ]]);
    }

    // ── Docente: login por DNI ────────────────────────────────────────────────
    if ($path === '/docente/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data     = json_input();
        $dni      = trim($data['dni'] ?? '');
        $password = trim($data['password'] ?? '');

        if ($dni === '' || $password === '') {
            respond(['ok' => false, 'error' => 'DNI y contraseña son obligatorios.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM docentes WHERE dni = ? AND activo = TRUE");
        $stmt->execute([$dni]);
        $docente = $stmt->fetch();

        if (!$docente || !password_verify($password, $docente['password_hash'])) {
            respond(['ok' => false, 'error' => 'DNI o contraseña incorrectos.'], 401);
        }

        respond(['ok' => true, 'docente' => [
            'id_docente' => $docente['id_docente'],
            'apellido'   => $docente['apellido'],
            'nombre'     => $docente['nombre'],
            'dni'        => $docente['dni'],
            'email'      => $docente['email'],
        ]]);
    }

    // ── Docente: materias asignadas ───────────────────────────────────────────
    if ($path === '/docente/materias' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idDocente = (int) ($_GET['id_docente'] ?? 0);
        if ($idDocente === 0) respond(['ok' => false, 'error' => 'Falta id_docente.'], 400);

        $stmt = $pdo->prepare(
            "SELECT m.id_materia, m.nombre_materia, m.codigo_materia, m.formato, m.cuatrimestre, m.anio_plan,
                    c.nombre_carrera
             FROM docente_materias dm
             JOIN materias m ON m.id_materia = dm.id_materia
             JOIN carreras c ON c.id_carrera = m.id_carrera
             WHERE dm.id_docente = ? AND m.activa = TRUE
             ORDER BY c.nombre_carrera, m.nombre_materia"
        );
        $stmt->execute([$idDocente]);
        respond(['ok' => true, 'materias' => $stmt->fetchAll()]);
    }

    // ── Docente: alumnos de una cursada ───────────────────────────────────────
    if ($path === '/docente/cursadas' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idMateria    = (int) ($_GET['id_materia'] ?? 0);
        $anioCursada  = (int) ($_GET['anio_cursada'] ?? date('Y'));
        $cuatrimestre = $_GET['cuatrimestre_cursada'] ?? '';

        if ($idMateria === 0) respond(['ok' => false, 'error' => 'Falta id_materia.'], 400);

        // Si es anual, cuatrimestre puede ser NULL
        if ($cuatrimestre === 'anual' || $cuatrimestre === '') {
            $stmt = $pdo->prepare(
                "SELECT cu.id_cursada, cu.resultado, cu.obs_cursada,
                        e.apellido, e.nombre, e.dni, e.correo
                 FROM cursadas cu
                 JOIN estudiantes e ON e.id_estudiante = cu.id_estudiante
                 WHERE cu.id_materia = ? AND cu.anio_cursada = ?
                   AND cu.cuatrimestre_cursada IS NULL
                 ORDER BY e.apellido, e.nombre"
            );
            $stmt->execute([$idMateria, $anioCursada]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT cu.id_cursada, cu.resultado, cu.obs_cursada,
                        e.apellido, e.nombre, e.dni, e.correo
                 FROM cursadas cu
                 JOIN estudiantes e ON e.id_estudiante = cu.id_estudiante
                 WHERE cu.id_materia = ? AND cu.anio_cursada = ?
                   AND cu.cuatrimestre_cursada = ?
                 ORDER BY e.apellido, e.nombre"
            );
            $stmt->execute([$idMateria, $anioCursada, (int) $cuatrimestre]);
        }
        respond(['ok' => true, 'alumnos' => $stmt->fetchAll()]);
    }

    // ── Docente: guardar resultado de cursada ─────────────────────────────────
    if ($path === '/docente/cursadas/resultado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data      = json_input();
        $idCursada = (int) ($data['id_cursada'] ?? 0);
        $resultado = trim($data['resultado'] ?? '');

        if ($idCursada === 0 || !in_array($resultado, ['aprobado','desaprobado','promocion','ausente','abandono'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        $pdo->prepare(
            "UPDATE cursadas SET resultado = ?, fecha_resultado = CURRENT_DATE WHERE id_cursada = ?"
        )->execute([$resultado, $idCursada]);

        respond(['ok' => true]);
    }

    // ── Docente: mesas asignadas ──────────────────────────────────────────────
    if ($path === '/docente/mesas' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idDocente = (int) ($_GET['id_docente'] ?? 0);
        if ($idDocente === 0) respond(['ok' => false, 'error' => 'Falta id_docente.'], 400);

        $stmt = $pdo->prepare(
            "SELECT mf.id_mesa, mf.anio, mf.turno, mf.fecha_mesa, mf.aula, mf.activa,
                    m.nombre_materia, c.nombre_carrera,
                    COUNT(im.id_inscripcion) AS total_inscriptos
             FROM mesas_finales mf
             JOIN materias m ON m.id_materia = mf.id_materia
             JOIN carreras c ON c.id_carrera = m.id_carrera
             LEFT JOIN inscripciones_mesas im ON im.id_mesa = mf.id_mesa
             WHERE mf.id_docente = ?
             GROUP BY mf.id_mesa, m.nombre_materia, c.nombre_carrera
             ORDER BY mf.anio DESC, mf.fecha_mesa DESC NULLS LAST"
        );
        $stmt->execute([$idDocente]);
        respond(['ok' => true, 'mesas' => $stmt->fetchAll()]);
    }

    // ── Docente: inscriptos de una mesa ───────────────────────────────────────
    if (preg_match('#^/docente/mesas/(\d+)/inscriptos$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $idMesa = (int) $m[1];
        $stmt   = $pdo->prepare(
            "SELECT im.id_inscripcion, im.estado_condicion, im.resultado, im.nota_obtenida,
                    e.apellido, e.nombre, e.dni
             FROM inscripciones_mesas im
             JOIN estudiantes e ON e.id_estudiante = im.id_estudiante
             WHERE im.id_mesa = ?
             ORDER BY e.apellido, e.nombre"
        );
        $stmt->execute([$idMesa]);
        respond(['ok' => true, 'inscriptos' => $stmt->fetchAll()]);
    }

    // ── Docente: guardar nota de examen final ─────────────────────────────────
    if ($path === '/docente/mesas/resultado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data      = json_input();
        $idInsc    = (int) ($data['id_inscripcion'] ?? 0);
        $resultado = trim($data['resultado'] ?? '');
        $nota      = isset($data['nota_obtenida']) && $data['nota_obtenida'] !== null ? (float) $data['nota_obtenida'] : null;

        if ($idInsc === 0 || !in_array($resultado, ['aprobado','reprobado','ausente'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        $pdo->prepare(
            "UPDATE inscripciones_mesas SET resultado = ?, nota_obtenida = ? WHERE id_inscripcion = ?"
        )->execute([$resultado, $nota, $idInsc]);

        respond(['ok' => true]);
    }

    // ── TIC: nuevas materias pendientes de carga ─────────────────────────────
    if ($path === '/tic/nuevas-materias' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT im.id_inscripcion_materia, im.tipo_inscripcion,
                    e.id_estudiante, e.apellido, e.nombre, e.dni, e.correo,
                    m.nombre_materia, c.nombre_carrera
             FROM inscripciones_materias im
             JOIN estudiantes e ON e.id_estudiante = im.id_estudiante
             JOIN materias m ON m.id_materia = im.id_materia
             JOIN carreras c ON c.id_carrera = m.id_carrera
             WHERE im.tipo_inscripcion = 'manual'
               AND im.habilitada = TRUE
               AND im.ingresado_plataforma = FALSE
               AND e.estado_general IN ('aprobado_secretaria','cargado_plataforma')
             ORDER BY e.apellido, e.nombre, m.nombre_materia"
        );
        respond(['ok' => true, 'materias' => $stmt->fetchAll()]);
    }

    // ── TIC: marcar materia como cargada en plataforma ────────────────────────
    if ($path === '/tic/cargar-materia' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data  = json_input();
        $idInsc = (int) ($data['id_inscripcion_materia'] ?? 0);
        if ($idInsc === 0) respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);

        $pdo->prepare(
            "UPDATE inscripciones_materias SET ingresado_plataforma = TRUE WHERE id_inscripcion_materia = ?"
        )->execute([$idInsc]);

        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Ruta no encontrada.'], 404);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // En producción no exponer el mensaje real
    respond(['ok' => false, 'error' => 'Error interno del servidor.'], 500);
}