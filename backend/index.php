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
    if (!$student) {
        return;
    }

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

    if ($path === '/health') {
        respond(['ok' => true, 'service' => 'backend']);
    }

    if ($path === '/carreras' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras WHERE activa = TRUE ORDER BY nombre_carrera");
        respond($stmt->fetchAll());
    }

    if ($path === '/registro' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');

        if ($email === '' || $password === '') {
            respond(['ok' => false, 'error' => 'Correo y contraseña son obligatorios.'], 400);
        }

        $check = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            respond(['ok' => false, 'error' => 'Ese correo ya está registrado.'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, password_hash, email_validado, token_validacion) VALUES (?, ?, TRUE, ?)");
        $stmt->execute([$email, $hash, $token]);
        respond(['ok' => true, 'message' => 'Cuenta creada correctamente.']);
    }

    if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = TRUE");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(['ok' => false, 'error' => 'Correo o contraseña incorrectos.'], 401);
        }

        respond(['ok' => true, 'email' => $email]);
    }

    if ($path === '/estudiante' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $email = trim($_GET['email'] ?? '');
        if ($email === '') {
            respond(['ok' => false, 'error' => 'Falta el correo.'], 400);
        }

        $stmt = $pdo->prepare(
            "SELECT e.*, c.nombre_carrera
             FROM estudiantes e
             JOIN carreras c ON c.id_carrera = e.id_carrera
             WHERE e.correo = ?"
        );
        $stmt->execute([$email]);
        $student = $stmt->fetch();

        if (!$student) {
            respond(['ok' => true, 'inscripto' => false]);
        }

        respond(['ok' => true, 'inscripto' => true, 'data' => $student]);
    }

    if ($path === '/inscripcion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        $domicilio = trim($_POST['domicilio'] ?? '');
        $localidad = trim($_POST['localidad'] ?? '');
        $idCarrera = (int) ($_POST['id_carrera'] ?? 0);
        $anioActual = (int) ($_POST['anio_actual'] ?? 0);
        $anioCohorte = (int) ($_POST['anio_cohorte'] ?? 0);

        if ($email === '' || $apellido === '' || $nombre === '' || $dni === '' || $idCarrera === 0 || $anioActual === 0) {
            respond(['ok' => false, 'error' => 'Faltan campos obligatorios.'], 400);
        }

        $userStmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();
        if (!$user) {
            respond(['ok' => false, 'error' => 'Primero debés crear tu cuenta.'], 400);
        }

        $checkDni = $pdo->prepare("SELECT id_estudiante FROM estudiantes WHERE dni = ? AND correo <> ?");
        $checkDni->execute([$dni, $email]);
        if ($checkDni->fetch()) {
            respond(['ok' => false, 'error' => 'Ese DNI ya está asociado a otro estudiante.'], 409);
        }

        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        $savePdf = function (string $field, string $prefix) use ($uploadsDir): ?string {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                return null;
            }
            $tmp = $_FILES[$field]['tmp_name'];
            $name = $_FILES[$field]['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                respond(['ok' => false, 'error' => 'Los archivos deben ser PDF.'], 400);
            }
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $dest = $uploadsDir . '/' . $filename;
            move_uploaded_file($tmp, $dest);
            return 'uploads/' . $filename;
        };

        $pdfDni = $savePdf('pdf_dni', 'dni');
        $pdfTitulo = $savePdf('pdf_titulo_secundario', 'titulo');

        $pdo->beginTransaction();

        $studentStmt = $pdo->prepare("SELECT id_estudiante FROM estudiantes WHERE correo = ?");
        $studentStmt->execute([$email]);
        $existing = $studentStmt->fetch();

        if ($existing) {
            $studentId = (int) $existing['id_estudiante'];
            $update = $pdo->prepare(
                "UPDATE estudiantes SET
                    apellido = ?, nombre = ?, dni = ?, telefono = ?, fecha_nacimiento = ?,
                    domicilio = ?, localidad = ?, id_carrera = ?, anio_actual = ?, anio_cohorte = ?,
                    estado_general = 'pendiente_secretaria'
                 WHERE id_estudiante = ?"
            );
            $update->execute([
                $apellido, $nombre, $dni, $telefono ?: null, $fechaNacimiento ?: null,
                $domicilio ?: null, $localidad ?: null, $idCarrera, $anioActual, $anioCohorte ?: null,
                $studentId
            ]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO estudiantes
                (id_usuario, apellido, nombre, dni, correo, telefono, fecha_nacimiento, domicilio, localidad, id_carrera, anio_actual, anio_cohorte, estado_general)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente_secretaria')"
            );
            $insert->execute([
                $user['id_usuario'], $apellido, $nombre, $dni, $email, $telefono ?: null, $fechaNacimiento ?: null,
                $domicilio ?: null, $localidad ?: null, $idCarrera, $anioActual, $anioCohorte ?: null
            ]);
            $studentId = (int) $pdo->lastInsertId();
        }

        $docStmt = $pdo->prepare("SELECT id_documento, pdf_dni, pdf_titulo_secundario FROM documentos_estudiante WHERE id_estudiante = ?");
        $docStmt->execute([$studentId]);
        $doc = $docStmt->fetch();
        if ($doc) {
            $updDoc = $pdo->prepare(
                "UPDATE documentos_estudiante SET pdf_dni = ?, pdf_titulo_secundario = ?, fecha_subida = CURRENT_TIMESTAMP WHERE id_estudiante = ?"
            );
            $updDoc->execute([$pdfDni ?: $doc['pdf_dni'], $pdfTitulo ?: $doc['pdf_titulo_secundario'], $studentId]);
        } else {
            $insDoc = $pdo->prepare(
                "INSERT INTO documentos_estudiante (id_estudiante, pdf_dni, pdf_titulo_secundario) VALUES (?, ?, ?)"
            );
            $insDoc->execute([$studentId, $pdfDni, $pdfTitulo]);
        }

        $revStmt = $pdo->prepare("SELECT id_revision FROM revision_secretaria WHERE id_estudiante = ?");
        $revStmt->execute([$studentId]);
        if ($revStmt->fetch()) {
            $updRev = $pdo->prepare(
                "UPDATE revision_secretaria SET documentacion_completa = FALSE, datos_correctos = FALSE, estado_secretaria = 'pendiente', observaciones_secretaria = NULL, fecha_revision = NULL WHERE id_estudiante = ?"
            );
            $updRev->execute([$studentId]);
        } else {
            $insRev = $pdo->prepare(
                "INSERT INTO revision_secretaria (id_estudiante) VALUES (?)"
            );
            $insRev->execute([$studentId]);
        }

        $pdo->commit();
        respond(['ok' => true, 'message' => 'Inscripción enviada correctamente. Quedó pendiente de revisión por Secretaría.']);
    }

    if ($path === '/secretaria/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $password = trim($data['password'] ?? '');
        if ($password === getenv('SECRETARIA_PASSWORD')) {
            respond(['ok' => true]);
        }
        respond(['ok' => false, 'error' => 'Clave incorrecta.'], 401);
    }

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

    if ($path === '/secretaria/accion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $studentId = (int) ($data['id_estudiante'] ?? 0);
        $accion = trim($data['accion'] ?? '');
        $observaciones = trim($data['observaciones'] ?? '');

        if ($studentId === 0 || !in_array($accion, ['aprobar', 'observar', 'rechazar'], true)) {
            respond(['ok' => false, 'error' => 'Datos inválidos.'], 400);
        }

        $pdo->beginTransaction();

        $map = [
            'aprobar' => ['estado_general' => 'aprobado_secretaria', 'estado_secretaria' => 'aprobado', 'documentacion' => true, 'datos' => true],
            'observar' => ['estado_general' => 'observado_secretaria', 'estado_secretaria' => 'observado', 'documentacion' => false, 'datos' => false],
            'rechazar' => ['estado_general' => 'rechazado_secretaria', 'estado_secretaria' => 'rechazado', 'documentacion' => false, 'datos' => false],
        ][$accion];

        $updStudent = $pdo->prepare("UPDATE estudiantes SET estado_general = ? WHERE id_estudiante = ?");
        $updStudent->execute([$map['estado_general'], $studentId]);

        $updRev = $pdo->prepare(
            "UPDATE revision_secretaria
             SET documentacion_completa = ?, datos_correctos = ?, estado_secretaria = ?, observaciones_secretaria = ?, fecha_revision = CURRENT_TIMESTAMP, usuario_secretaria = 'Secretaría'
             WHERE id_estudiante = ?"
        );
        $updRev->execute([$map['documentacion'], $map['datos'], $map['estado_secretaria'], $observaciones ?: null, $studentId]);

        if ($accion === 'aprobar') {
            $ticCheck = $pdo->prepare("SELECT id_carga FROM carga_tic WHERE id_estudiante = ?");
            $ticCheck->execute([$studentId]);
            if ($ticCheck->fetch()) {
                $updTic = $pdo->prepare("UPDATE carga_tic SET estado_tic = 'pendiente', observaciones_tic = NULL, fecha_carga_plataforma = NULL, usuario_tic = NULL WHERE id_estudiante = ?");
                $updTic->execute([$studentId]);
            } else {
                $insTic = $pdo->prepare("INSERT INTO carga_tic (id_estudiante) VALUES (?)");
                $insTic->execute([$studentId]);
            }
            assign_initial_subjects($pdo, $studentId);
        }

        $pdo->commit();
        respond(['ok' => true, 'message' => 'Acción registrada correctamente.']);
    }

    if ($path === '/tic/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $password = trim($data['password'] ?? '');
        if ($password === getenv('TIC_PASSWORD')) {
            respond(['ok' => true]);
        }
        respond(['ok' => false, 'error' => 'Clave incorrecta.'], 401);
    }

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

    if ($path === '/tic/cargar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_input();
        $studentId = (int) ($data['id_estudiante'] ?? 0);
        $observaciones = trim($data['observaciones'] ?? '');
        if ($studentId === 0) {
            respond(['ok' => false, 'error' => 'Falta el estudiante.'], 400);
        }

        $pdo->beginTransaction();
        $updStudent = $pdo->prepare("UPDATE estudiantes SET estado_general = 'cargado_plataforma' WHERE id_estudiante = ?");
        $updStudent->execute([$studentId]);
        $updTic = $pdo->prepare(
            "UPDATE carga_tic
             SET estado_tic = 'cargado', fecha_carga_plataforma = CURRENT_TIMESTAMP, observaciones_tic = ?, usuario_tic = 'Área TIC'
             WHERE id_estudiante = ?"
        );
        $updTic->execute([$observaciones ?: null, $studentId]);
        $pdo->commit();
        respond(['ok' => true, 'message' => 'Estudiante marcado como cargado en plataforma.']);
    }

    respond(['ok' => false, 'error' => 'Ruta no encontrada.'], 404);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
