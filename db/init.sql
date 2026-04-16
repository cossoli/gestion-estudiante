-- ─────────────────────────────────────────────────────────────────
-- SISTEMA DE GESTIÓN ESTUDIANTIL — IFDC Río Colorado
-- init.sql — Estructura completa de base de datos
-- ─────────────────────────────────────────────────────────────────

-- ── USUARIOS ─────────────────────────────────────────────────────
CREATE TABLE usuarios (
    id_usuario SERIAL PRIMARY KEY,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    email_validado BOOLEAN NOT NULL DEFAULT FALSE,
    token_validacion VARCHAR(255),
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── CARRERAS ──────────────────────────────────────────────────────
CREATE TABLE carreras (
    id_carrera SERIAL PRIMARY KEY,
    nombre_carrera VARCHAR(150) NOT NULL,
    titulo_otorgado VARCHAR(150),
    duracion_anios INT NOT NULL,
    modalidad VARCHAR(30) CHECK (modalidad IN ('presencial', 'semipresencial', 'virtual')),
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    numero_resolucion VARCHAR(100)
);

-- ── MATERIAS ──────────────────────────────────────────────────────
CREATE TABLE materias (
    id_materia SERIAL PRIMARY KEY,
    id_carrera INT NOT NULL,
    nombre_materia VARCHAR(150) NOT NULL,
    codigo_materia VARCHAR(50),
    anio_plan INT NOT NULL CHECK (anio_plan >= 1),
    formato VARCHAR(20) NOT NULL CHECK (formato IN ('anual', 'cuatrimestral')),
    cuatrimestre INT CHECK (cuatrimestre IN (1, 2) OR cuatrimestre IS NULL),
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT fk_materia_carrera FOREIGN KEY (id_carrera) REFERENCES carreras(id_carrera)
);

-- ── CORRELATIVAS ─────────────────────────────────────────────────
-- Una materia puede requerir tener aprobada/cursada otra materia.
-- tipo: 'aprobada' = el estudiante debe haber aprobado la cursada
--       'cursada'  = alcanza con haberla cursado (aunque no aprobada)
CREATE TABLE correlativas (
    id_correlativa SERIAL PRIMARY KEY,
    id_materia INT NOT NULL,           -- materia que tiene el requisito
    id_materia_requerida INT NOT NULL, -- materia que se debe tener previa
    tipo VARCHAR(10) NOT NULL DEFAULT 'aprobada'
        CHECK (tipo IN ('aprobada', 'cursada')),
    CONSTRAINT fk_corr_materia    FOREIGN KEY (id_materia)          REFERENCES materias(id_materia) ON DELETE CASCADE,
    CONSTRAINT fk_corr_requerida  FOREIGN KEY (id_materia_requerida) REFERENCES materias(id_materia) ON DELETE CASCADE,
    CONSTRAINT uq_correlativa     UNIQUE (id_materia, id_materia_requerida)
);

-- ── ESTUDIANTES ───────────────────────────────────────────────────
CREATE TABLE estudiantes (
    id_estudiante SERIAL PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    apellido VARCHAR(100) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL UNIQUE,
    correo VARCHAR(150) UNIQUE,
    telefono VARCHAR(50),
    fecha_nacimiento DATE,
    domicilio VARCHAR(200),
    localidad VARCHAR(100),
    id_carrera INT NOT NULL,
    anio_actual INT NOT NULL CHECK (anio_actual >= 1),
    anio_cohorte INT,
    estado_general VARCHAR(40) NOT NULL DEFAULT 'pendiente_secretaria'
        CHECK (estado_general IN (
            'pendiente_validacion_email',
            'pendiente_secretaria',
            'observado_secretaria',
            'rechazado_secretaria',
            'aprobado_secretaria',
            'cargado_plataforma',
            'inactivo'
        )),
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_estudiante_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
    CONSTRAINT fk_estudiante_carrera FOREIGN KEY (id_carrera) REFERENCES carreras(id_carrera)
);

-- ── DOCUMENTOS DEL ESTUDIANTE ─────────────────────────────────────
CREATE TABLE documentos_estudiante (
    id_documento SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL UNIQUE,
    pdf_dni VARCHAR(255),
    pdf_titulo_secundario VARCHAR(255),
    fecha_subida TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observaciones_documentos TEXT,
    CONSTRAINT fk_documento_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE
);

-- ── REVISIÓN SECRETARÍA (ingresantes) ────────────────────────────
CREATE TABLE revision_secretaria (
    id_revision SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL UNIQUE,
    documentacion_completa BOOLEAN NOT NULL DEFAULT FALSE,
    datos_correctos BOOLEAN NOT NULL DEFAULT FALSE,
    estado_secretaria VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_secretaria IN ('pendiente', 'observado', 'aprobado', 'rechazado')),
    observaciones_secretaria TEXT,
    fecha_revision TIMESTAMP,
    usuario_secretaria VARCHAR(150),
    CONSTRAINT fk_revision_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE
);

-- ── CARGA TIC ─────────────────────────────────────────────────────
CREATE TABLE carga_tic (
    id_carga SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL UNIQUE,
    estado_tic VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_tic IN ('pendiente', 'cargado')),
    fecha_carga_plataforma TIMESTAMP,
    observaciones_tic TEXT,
    usuario_tic VARCHAR(150),
    CONSTRAINT fk_tic_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE
);

-- ── INSCRIPCIONES A MATERIAS (alta inicial) ───────────────────────
CREATE TABLE inscripciones_materias (
    id_inscripcion_materia SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_materia INT NOT NULL,
    anio_lectivo INT NOT NULL,
    tipo_inscripcion VARCHAR(20) NOT NULL DEFAULT 'automatica'
        CHECK (tipo_inscripcion IN ('automatica', 'manual')),
    estado_secretaria VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_secretaria IN ('pendiente', 'habilitado', 'observado', 'rechazado')),
    habilitada BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observaciones TEXT,
    CONSTRAINT fk_inscripcion_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_inscripcion_materia    FOREIGN KEY (id_materia)    REFERENCES materias(id_materia),
    CONSTRAINT uq_estudiante_materia_anio UNIQUE (id_estudiante, id_materia, anio_lectivo)
);

-- ── CURSADAS ──────────────────────────────────────────────────────
-- Registra cada materia que un alumno cursa en un período.
-- condicion_verificada: Secretaría confirmó que el alumno cumple correlativas.
-- resultado: se completa al cierre del período.
CREATE TABLE cursadas (
    id_cursada SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_materia INT NOT NULL,
    anio_cursada INT NOT NULL,
    cuatrimestre_cursada INT CHECK (cuatrimestre_cursada IN (1, 2) OR cuatrimestre_cursada IS NULL),
    -- Verificación de condición por Secretaría
    condicion_verificada BOOLEAN NOT NULL DEFAULT FALSE,
    estado_condicion VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_condicion IN ('pendiente', 'apto', 'no_apto')),
    observaciones_condicion TEXT,
    fecha_verificacion TIMESTAMP,
    -- Carga en plataforma
    ingresado_plataforma BOOLEAN NOT NULL DEFAULT FALSE,
    -- Resultado al cierre
    resultado VARCHAR(20)
        CHECK (resultado IN ('aprobado', 'desaprobado', 'promocion', 'ausente', 'abandono') OR resultado IS NULL),
    fecha_resultado DATE,
    -- Observaciones generales
    obs_cursada TEXT,
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cursada_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_cursada_materia    FOREIGN KEY (id_materia)    REFERENCES materias(id_materia),
    CONSTRAINT uq_cursada            UNIQUE (id_estudiante, id_materia, anio_cursada, cuatrimestre_cursada)
);

-- ── MESAS DE EXAMEN FINAL ─────────────────────────────────────────
-- Secretaría crea las mesas. Los alumnos se anotan desde su panel.
CREATE TABLE mesas_finales (
    id_mesa SERIAL PRIMARY KEY,
    id_materia INT NOT NULL,
    anio INT NOT NULL,
    turno VARCHAR(20) NOT NULL
        CHECK (turno IN ('feb_mar', 'julio', 'nov_dic')),
    fecha_mesa DATE,
    aula VARCHAR(100),
    docente_titular VARCHAR(150),
    activa BOOLEAN NOT NULL DEFAULT TRUE,  -- TRUE = abierta para inscripción
    CONSTRAINT fk_mesa_materia FOREIGN KEY (id_materia) REFERENCES materias(id_materia)
);

-- ── INSCRIPCIONES A MESAS ─────────────────────────────────────────
-- Vincula alumnos con mesas. Secretaría verifica condición.
-- El resultado se carga al terminar el examen.
CREATE TABLE inscripciones_mesas (
    id_inscripcion SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_mesa INT NOT NULL,
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Verificación de condición
    condicion_verificada BOOLEAN NOT NULL DEFAULT FALSE,
    estado_condicion VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_condicion IN ('pendiente', 'apto', 'no_apto')),
    -- Resultado del examen
    nota_obtenida DECIMAL(4,2) CHECK (nota_obtenida >= 1 AND nota_obtenida <= 10 OR nota_obtenida IS NULL),
    resultado VARCHAR(20)
        CHECK (resultado IN ('aprobado', 'reprobado', 'ausente') OR resultado IS NULL),
    obs_mesa TEXT,
    CONSTRAINT fk_insc_mesa_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_insc_mesa_mesa       FOREIGN KEY (id_mesa)       REFERENCES mesas_finales(id_mesa),
    CONSTRAINT uq_estudiante_mesa      UNIQUE (id_estudiante, id_mesa)
);
-- ─────────────────────────────────────────────────────────────────
-- MIGRACIÓN: Módulo docentes + mesas de examen
-- Ejecutar sobre la base existente
-- ─────────────────────────────────────────────────────────────────

-- ── DOCENTES ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS docentes (
    id_docente SERIAL PRIMARY KEY,
    apellido VARCHAR(100) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── DOCENTE - MATERIAS (qué materias dicta cada docente) ──────────
CREATE TABLE IF NOT EXISTS docente_materias (
    id_docente INT NOT NULL,
    id_materia INT NOT NULL,
    PRIMARY KEY (id_docente, id_materia),
    CONSTRAINT fk_dm_docente FOREIGN KEY (id_docente) REFERENCES docentes(id_docente) ON DELETE CASCADE,
    CONSTRAINT fk_dm_materia FOREIGN KEY (id_materia) REFERENCES materias(id_materia) ON DELETE CASCADE
);

-- ── MESAS FINALES ─────────────────────────────────────────────────
-- Ya existe en init.sql, pero por si no fue creada aún:
CREATE TABLE IF NOT EXISTS mesas_finales (
    id_mesa SERIAL PRIMARY KEY,
    id_materia INT NOT NULL,
    id_docente INT,
    anio INT NOT NULL,
    turno VARCHAR(20) NOT NULL
        CHECK (turno IN ('feb_mar', 'julio', 'nov_dic')),
    fecha_mesa DATE,
    aula VARCHAR(100),
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT fk_mesa_materia FOREIGN KEY (id_materia) REFERENCES materias(id_materia),
    CONSTRAINT fk_mesa_docente FOREIGN KEY (id_docente) REFERENCES docentes(id_docente)
);

-- ── INSCRIPCIONES A MESAS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inscripciones_mesas (
    id_inscripcion SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_mesa INT NOT NULL,
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    condicion_verificada BOOLEAN NOT NULL DEFAULT FALSE,
    estado_condicion VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_condicion IN ('pendiente', 'apto', 'no_apto')),
    nota_obtenida DECIMAL(4,2) CHECK (nota_obtenida >= 1 AND nota_obtenida <= 10 OR nota_obtenida IS NULL),
    resultado VARCHAR(20)
        CHECK (resultado IN ('aprobado', 'reprobado', 'ausente') OR resultado IS NULL),
    obs_mesa TEXT,
    CONSTRAINT fk_insc_mesa_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_insc_mesa_mesa       FOREIGN KEY (id_mesa)       REFERENCES mesas_finales(id_mesa),
    CONSTRAINT uq_estudiante_mesa      UNIQUE (id_estudiante, id_mesa)
);



-- ─────────────────────────────────────────────────────────────────
-- DATOS INICIALES
-- ─────────────────────────────────────────────────────────────────

INSERT INTO carreras (nombre_carrera, titulo_otorgado, duracion_anios, modalidad, numero_resolucion) VALUES
('Profesorado de Matemática',        'Profesor/a de Matemática',        4, 'presencial', 'Res. 100/20'),
('Profesorado de Física',            'Profesor/a de Física',            4, 'presencial', 'Res. 101/20'),
('Profesorado de Lengua y Literatura','Profesor/a de Lengua y Literatura',4,'presencial', 'Res. 102/20'),
('Profesorado de Teatro',            'Profesor/a de Teatro',            4, 'presencial', 'Res. 103/20');

INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
-- Profesorado de Matemática
(1, 'Pedagogía',             'MAT-101', 1, 'anual',         NULL),
(1, 'Álgebra I',             'MAT-102', 1, 'cuatrimestral', 1),
(1, 'Análisis Matemático I', 'MAT-103', 1, 'cuatrimestral', 1),
(1, 'Didáctica General',     'MAT-104', 1, 'cuatrimestral', 2),
-- Profesorado de Física
(2, 'Pedagogía',             'FIS-101', 1, 'anual',         NULL),
(2, 'Física General I',      'FIS-102', 1, 'cuatrimestral', 1),
(2, 'Matemática Aplicada',   'FIS-103', 1, 'cuatrimestral', 1),
(2, 'Didáctica General',     'FIS-104', 1, 'cuatrimestral', 2),
-- Profesorado de Lengua y Literatura
(3, 'Pedagogía',                    'LYL-101', 1, 'anual',         NULL),
(3, 'Introducción a la Literatura', 'LYL-102', 1, 'cuatrimestral', 1),
(3, 'Gramática I',                  'LYL-103', 1, 'cuatrimestral', 1),
(3, 'Didáctica General',            'LYL-104', 1, 'cuatrimestral', 2),
-- Profesorado de Teatro
(4, 'Pedagogía',           'TEA-101', 1, 'anual',         NULL),
(4, 'Actuación I',         'TEA-102', 1, 'cuatrimestral', 1),
(4, 'Expresión Corporal',  'TEA-103', 1, 'cuatrimestral', 1),
(4, 'Didáctica General',   'TEA-104', 1, 'cuatrimestral', 2);-

- ─────────────────────────────────────────────────────────────────
-- Docentes de prueba — contraseña inicial: "docente123"
-- Cambiar el hash si se desea otra contraseña
-- ─────────────────────────────────────────────────────────────────

-- Insertar docentes (contraseña: docente123)
INSERT INTO docentes (apellido, nombre, email, password_hash) VALUES
('García', 'Juan Carlos', 'jgarcia@ifdc.edu.ar', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('López', 'María Elena', 'mlopez@ifdc.edu.ar',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Martínez', 'Roberto', 'rmartinez@ifdc.edu.ar','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Asignar materias a docentes (ajustar id_materia según tu base)
-- García dicta Álgebra I y Análisis Matemático I (Profesorado de Matemática)
INSERT INTO docente_materias (id_docente, id_materia)
SELECT d.id_docente, m.id_materia
FROM docentes d, materias m
WHERE d.email = 'jgarcia@ifdc.edu.ar'
  AND m.codigo_materia IN ('MAT-102', 'MAT-103')
ON CONFLICT DO NOTHING;

-- López dicta Pedagogía y Didáctica General (todas las carreras)
INSERT INTO docente_materias (id_docente, id_materia)
SELECT d.id_docente, m.id_materia
FROM docentes d, materias m
WHERE d.email = 'mlopez@ifdc.edu.ar'
  AND m.codigo_materia IN ('MAT-101','MAT-104','FIS-101','FIS-104','LYL-101','LYL-104','TEA-101','TEA-104')
ON CONFLICT DO NOTHING;

-- Martínez dicta Física General I y Matemática Aplicada
INSERT INTO docente_materias (id_docente, id_materia)
SELECT d.id_docente, m.id_materia
FROM docentes d, materias m
WHERE d.email = 'rmartinez@ifdc.edu.ar'
  AND m.codigo_materia IN ('FIS-102', 'FIS-103')
ON CONFLICT DO NOTHING;