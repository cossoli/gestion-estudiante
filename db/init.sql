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
CREATE TABLE correlativas (
    id_correlativa SERIAL PRIMARY KEY,
    id_materia INT NOT NULL,
    id_materia_requerida INT NOT NULL,
    tipo VARCHAR(10) NOT NULL DEFAULT 'aprobada'
        CHECK (tipo IN ('aprobada', 'cursada')),
    CONSTRAINT fk_corr_materia   FOREIGN KEY (id_materia)          REFERENCES materias(id_materia) ON DELETE CASCADE,
    CONSTRAINT fk_corr_requerida FOREIGN KEY (id_materia_requerida) REFERENCES materias(id_materia) ON DELETE CASCADE,
    CONSTRAINT uq_correlativa    UNIQUE (id_materia, id_materia_requerida)
);

-- ── DOCENTES ─────────────────────────────────────────────────────
CREATE TABLE docentes (
    id_docente SERIAL PRIMARY KEY,
    apellido VARCHAR(100) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(150) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── DOCENTE - MATERIAS ────────────────────────────────────────────
CREATE TABLE docente_materias (
    id_docente INT NOT NULL,
    id_materia INT NOT NULL,
    PRIMARY KEY (id_docente, id_materia),
    CONSTRAINT fk_dm_docente FOREIGN KEY (id_docente) REFERENCES docentes(id_docente) ON DELETE CASCADE,
    CONSTRAINT fk_dm_materia FOREIGN KEY (id_materia) REFERENCES materias(id_materia) ON DELETE CASCADE
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

-- ── REVISIÓN SECRETARÍA ───────────────────────────────────────────
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

-- ── INSCRIPCIONES A MATERIAS ──────────────────────────────────────
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
CREATE TABLE cursadas (
    id_cursada SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL,
    id_materia INT NOT NULL,
    anio_cursada INT NOT NULL,
    cuatrimestre_cursada INT CHECK (cuatrimestre_cursada IN (1, 2) OR cuatrimestre_cursada IS NULL),
    condicion_verificada BOOLEAN NOT NULL DEFAULT FALSE,
    estado_condicion VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_condicion IN ('pendiente', 'apto', 'no_apto')),
    observaciones_condicion TEXT,
    fecha_verificacion TIMESTAMP,
    ingresado_plataforma BOOLEAN NOT NULL DEFAULT FALSE,
    resultado VARCHAR(20)
        CHECK (resultado IN ('aprobado', 'desaprobado', 'promocion', 'ausente', 'abandono') OR resultado IS NULL),
    fecha_resultado DATE,
    obs_cursada TEXT,
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cursada_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_cursada_materia    FOREIGN KEY (id_materia)    REFERENCES materias(id_materia),
    CONSTRAINT uq_cursada            UNIQUE (id_estudiante, id_materia, anio_cursada, cuatrimestre_cursada)
);

-- ── MESAS DE EXAMEN FINAL ─────────────────────────────────────────
CREATE TABLE mesas_finales (
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
CREATE TABLE inscripciones_mesas (
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
('Profesorado de Matemática',         'Profesor/a de Matemática',         4, 'presencial', 'Res. 100/20'),
('Profesorado de Física',             'Profesor/a de Física',             4, 'presencial', 'Res. 101/20'),
('Profesorado de Lengua y Literatura','Profesor/a de Lengua y Literatura', 4, 'presencial', 'Res. 102/20'),
('Profesorado de Teatro',             'Profesor/a de Teatro',             4, 'presencial', 'Res. 103/20');

-- ─────────────────────────────────────────────────────────────────
-- MATERIAS — PROFESORADO DE MATEMÁTICA (id_carrera = 1)
-- ─────────────────────────────────────────────────────────────────
INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
-- 1° Año — Formación General
(1, 'Alfabetización Académica',          'MAT-101', 1, 'cuatrimestral', 1),
(1, 'Antropología',                      'MAT-102', 1, 'cuatrimestral', 1),
(1, 'Filosofía',                         'MAT-103', 1, 'cuatrimestral', 2),
(1, 'Pedagogía',                         'MAT-104', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Específica
(1, 'Geometría I',                       'MAT-105', 1, 'cuatrimestral', 1),
(1, 'Matemática General',                'MAT-106', 1, 'cuatrimestral', 1),
(1, 'Introducción al Algebra',           'MAT-107', 1, 'cuatrimestral', 2),
(1, 'Análisis Matemático I',             'MAT-108', 1, 'cuatrimestral', 2),
(1, 'Introducción a la Física',          'MAT-109', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Práctica Profesional
(1, 'Práctica Docente I',                'MAT-110', 1, 'anual',         NULL),
-- 2° Año — Formación General
(1, 'Didáctica General',                 'MAT-201', 2, 'cuatrimestral', 1),
(1, 'Psicología Educacional',            'MAT-202', 2, 'cuatrimestral', 1),
(1, 'Sociología de la Educación',        'MAT-203', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Específica
(1, 'Didáctica de la Matemática I',      'MAT-204', 2, 'cuatrimestral', 1),
(1, 'Geometría II',                      'MAT-205', 2, 'cuatrimestral', 1),
(1, 'Algebra I',                         'MAT-206', 2, 'cuatrimestral', 1),
(1, 'Didáctica de la Matemática II',     'MAT-207', 2, 'cuatrimestral', 2),
(1, 'Análisis Matemático II',            'MAT-208', 2, 'cuatrimestral', 2),
(1, 'Sujetos de la Educación Secundaria','MAT-209', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Práctica Profesional
(1, 'Práctica Docente II',               'MAT-210', 2, 'anual',         NULL),
-- 3° Año — Formación General
(1, 'Historia de la Educación Argentina','MAT-301', 3, 'cuatrimestral', 1),
(1, 'Educación y TIC',                   'MAT-302', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Específica
(1, 'Didáctica de la Matemática III',    'MAT-303', 3, 'cuatrimestral', 1),
(1, 'Geometría III',                     'MAT-304', 3, 'cuatrimestral', 1),
(1, 'Algebra II',                        'MAT-305', 3, 'cuatrimestral', 1),
(1, 'Epistemología e Historia de las Matemáticas', 'MAT-306', 3, 'cuatrimestral', 2),
(1, 'Análisis Matemático III',           'MAT-307', 3, 'cuatrimestral', 2),
(1, 'Probabilidad y Estadística',        'MAT-308', 3, 'cuatrimestral', 2),
(1, 'Taller Interdisciplinario de Modelización Matemática', 'MAT-309', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Práctica Profesional
(1, 'Práctica Docente III',              'MAT-310', 3, 'anual',         NULL),
-- 4° Año — Formación General
(1, 'Educación Sexual Integral',         'MAT-401', 4, 'cuatrimestral', 1),
(1, 'Política Educativa y Legislación',  'MAT-402', 4, 'cuatrimestral', 2),
-- 4° Año — Formación Específica
(1, 'Algebra III',                       'MAT-403', 4, 'cuatrimestral', 1),
(1, 'Análisis Matemático III',           'MAT-404', 4, 'cuatrimestral', 2),
(1, 'Problemáticas Socioeducativas en la Educación Secundaria', 'MAT-405', 4, 'cuatrimestral', 1),
-- 4° Año — Formación Práctica Profesional
(1, 'Práctica Docente IV y Residencia Pedagógica', 'MAT-410', 4, 'anual', NULL);

-- ─────────────────────────────────────────────────────────────────
-- MATERIAS — PROFESORADO DE FÍSICA (id_carrera = 2)
-- ─────────────────────────────────────────────────────────────────
INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
-- 1° Año — Formación General
(2, 'Alfabetización Académica',          'FIS-101', 1, 'cuatrimestral', 1),
(2, 'Antropología',                      'FIS-102', 1, 'cuatrimestral', 1),
(2, 'Filosofía',                         'FIS-103', 1, 'cuatrimestral', 2),
(2, 'Pedagogía',                         'FIS-104', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Específica
(2, 'Introducción a la Física',          'FIS-105', 1, 'cuatrimestral', 1),
(2, 'Matemática General',                'FIS-106', 1, 'cuatrimestral', 1),
(2, 'Física Clásica Experimental',       'FIS-107', 1, 'cuatrimestral', 2),
(2, 'Análisis Matemático I',             'FIS-108', 1, 'cuatrimestral', 2),
(2, 'Elementos de Geometría',            'FIS-109', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Práctica Profesional
(2, 'Práctica Docente I',                'FIS-110', 1, 'anual',         NULL),
-- 2° Año — Formación General
(2, 'Didáctica General',                 'FIS-201', 2, 'cuatrimestral', 1),
(2, 'Psicología Educacional',            'FIS-202', 2, 'cuatrimestral', 1),
(2, 'Sociología de la Educación',        'FIS-203', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Específica
(2, 'Química General',                   'FIS-204', 2, 'cuatrimestral', 1),
(2, 'Física de los Medios Continuos',    'FIS-205', 2, 'cuatrimestral', 1),
(2, 'Epistemología e Historia de las Ciencias Naturales', 'FIS-206', 2, 'cuatrimestral', 1),
(2, 'Sujetos de la Educación Secundaria','FIS-207', 2, 'cuatrimestral', 2),
(2, 'Física de la Luz',                  'FIS-208', 2, 'cuatrimestral', 2),
(2, 'Análisis Matemático II',            'FIS-209', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Práctica Profesional
(2, 'Práctica Docente II',               'FIS-210', 2, 'anual',         NULL),
-- 3° Año — Formación General
(2, 'Historia de la Educación Argentina','FIS-301', 3, 'cuatrimestral', 1),
(2, 'Educación y TIC',                   'FIS-302', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Específica
(2, 'Didáctica de las Ciencias Naturales','FIS-303', 3, 'anual',        NULL),
(2, 'Probabilidad y Estadística',        'FIS-304', 3, 'cuatrimestral', 1),
(2, 'Física Electromagnética',           'FIS-305', 3, 'cuatrimestral', 1),
(2, 'Física del Calor',                  'FIS-306', 3, 'cuatrimestral', 2),
(2, 'Problemáticas Socioeducativas en la Escuela Secundaria', 'FIS-307', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Práctica Profesional
(2, 'Práctica Docente III',              'FIS-310', 3, 'anual',         NULL),
-- 4° Año — Formación General
(2, 'Política Educativa y Legislación',  'FIS-401', 4, 'cuatrimestral', 1),
(2, 'Educación Sexual Integral',         'FIS-402', 4, 'cuatrimestral', 2),
-- 4° Año — Formación Específica
(2, 'Física Contemporánea',              'FIS-403', 4, 'cuatrimestral', 1),
(2, 'Seminario de Problemas Ambientales','FIS-404', 4, 'cuatrimestral', 2),
-- 4° Año — Formación Práctica Profesional
(2, 'Práctica Docente IV y Residencia Pedagógica', 'FIS-410', 4, 'anual', NULL);

-- ─────────────────────────────────────────────────────────────────
-- MATERIAS — PROFESORADO DE LENGUA Y LITERATURA (id_carrera = 3)
-- ─────────────────────────────────────────────────────────────────
INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
-- 1° Año — Formación General
(3, 'Alfabetización Académica',          'LYL-101', 1, 'cuatrimestral', 1),
(3, 'Antropología',                      'LYL-102', 1, 'cuatrimestral', 1),
(3, 'Filosofía',                         'LYL-103', 1, 'cuatrimestral', 2),
(3, 'Pedagogía',                         'LYL-104', 1, 'cuatrimestral', 2),
(3, 'Alfabetización Digital',            'LYL-105', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Específica
(3, 'Gramática y Normativa de la Lengua Española I', 'LYL-106', 1, 'cuatrimestral', 1),
(3, 'Lingüística General y Sociolingüística I',      'LYL-107', 1, 'cuatrimestral', 1),
(3, 'Introducción a los Estudios Literarios',        'LYL-108', 1, 'cuatrimestral', 1),
(3, 'Literatura y Cultura Grecolatina',              'LYL-109', 1, 'cuatrimestral', 2),
(3, 'Lingüística General y Sociolingüística II',     'LYL-110', 1, 'cuatrimestral', 2),
(3, 'Teatro',                                        'LYL-111', 1, 'cuatrimestral', 2),
(3, 'Estética e Historia del Arte',                  'LYL-112', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Práctica Profesional
(3, 'Práctica Docente I',                'LYL-113', 1, 'anual',         NULL),
-- 2° Año — Formación General
(3, 'Didáctica General',                 'LYL-201', 2, 'cuatrimestral', 1),
(3, 'Psicología Educacional',            'LYL-202', 2, 'cuatrimestral', 1),
(3, 'Sociología de la Educación',        'LYL-203', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Específica
(3, 'Gramática y Normativa de la Lengua Española II','LYL-204', 2, 'cuatrimestral', 1),
(3, 'Lingüística General y Sociolingüística II',     'LYL-205', 2, 'cuatrimestral', 1),
(3, 'Literatura Española',                           'LYL-206', 2, 'cuatrimestral', 1),
(3, 'Sujetos de la Educación Secundaria',            'LYL-207', 2, 'cuatrimestral', 1),
(3, 'Didáctica de la Lengua y la Literatura I',      'LYL-208', 2, 'cuatrimestral', 2),
(3, 'Teoría y Práctica de la Lengua y la Escritura', 'LYL-209', 2, 'cuatrimestral', 2),
(3, 'Literatura Juvenil',                            'LYL-210', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Práctica Profesional
(3, 'Práctica Docente II',               'LYL-211', 2, 'anual',         NULL),
-- 3° Año — Formación General
(3, 'Educación y TIC',                   'LYL-301', 3, 'cuatrimestral', 1),
(3, 'Historia de la Educación Argentina','LYL-302', 3, 'cuatrimestral', 1),
(3, 'Política Educativa y Legislación',  'LYL-303', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Específica
(3, 'Literatura Latinoamericana',        'LYL-304', 3, 'anual',         NULL),
(3, 'Literatura Argentina',              'LYL-305', 3, 'anual',         NULL),
(3, 'Didáctica de la Lengua y la Literatura II', 'LYL-306', 3, 'cuatrimestral', 1),
(3, 'Textos Poéticos',                   'LYL-307', 3, 'cuatrimestral', 1),
(3, 'Semiótica',                         'LYL-308', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Práctica Profesional
(3, 'Práctica Docente III',              'LYL-310', 3, 'anual',         NULL),
-- 4° Año — Formación General
(3, 'Educación Sexual Integral',         'LYL-401', 4, 'cuatrimestral', 1),
-- 4° Año — Formación Específica
(3, 'Literatura en Lenguas Extranjeras', 'LYL-402', 4, 'cuatrimestral', 1),
(3, 'Didáctica de la Lengua y la Literatura III',    'LYL-403', 4, 'cuatrimestral', 1),
(3, 'Problemáticas Socioeducativas en la Educación Secundaria', 'LYL-404', 4, 'cuatrimestral', 1),
(3, 'Alfabetización Inicial y Avanzada', 'LYL-405', 4, 'cuatrimestral', 2),
(3, 'Historia de la Lengua Castellana',  'LYL-406', 4, 'cuatrimestral', 2),
-- 4° Año — Formación Práctica Profesional
(3, 'Práctica Docente IV y Residencia Pedagógica', 'LYL-410', 4, 'anual', NULL);

-- ─────────────────────────────────────────────────────────────────
-- MATERIAS — PROFESORADO DE TEATRO (id_carrera = 4)
-- ─────────────────────────────────────────────────────────────────
INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
-- 1° Año — Formación General
(4, 'Alfabetización Académica',          'TEA-101', 1, 'cuatrimestral', 1),
(4, 'Antropología',                      'TEA-102', 1, 'cuatrimestral', 1),
(4, 'Filosofía',                         'TEA-103', 1, 'cuatrimestral', 2),
(4, 'Pedagogía',                         'TEA-104', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Específica
(4, 'Historia de las Culturas y las Artes',          'TEA-105', 1, 'cuatrimestral', 1),
(4, 'Taller de sensibilización y acercamiento vivencial a los lenguajes artísticos', 'TEA-106', 1, 'cuatrimestral', 1),
(4, 'Lenguaje Teatral',                              'TEA-107', 1, 'cuatrimestral', 1),
(4, 'Técnicas Corporales y Expresivas I',            'TEA-108', 1, 'cuatrimestral', 1),
(4, 'Educación Vocal I',                             'TEA-109', 1, 'cuatrimestral', 2),
(4, 'Teoría Teatral',                                'TEA-110', 1, 'cuatrimestral', 2),
-- 1° Año — Formación Práctica Profesional
(4, 'Taller de Práctica Docente I',      'TEA-111', 1, 'anual',         NULL),
-- 2° Año — Formación General
(4, 'Didáctica General',                 'TEA-201', 2, 'cuatrimestral', 1),
(4, 'Psicología Educacional',            'TEA-202', 2, 'cuatrimestral', 1),
(4, 'Sociología de la Educación',        'TEA-203', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Específica
(4, 'Historia del Teatro I',             'TEA-204', 2, 'cuatrimestral', 1),
(4, 'Educación Vocal II',                'TEA-205', 2, 'cuatrimestral', 1),
(4, 'Dispositivos Escénicos',            'TEA-206', 2, 'cuatrimestral', 1),
(4, 'Actuación I',                       'TEA-207', 2, 'cuatrimestral', 1),
(4, 'Técnicas Corporales y Expresivas II','TEA-208', 2, 'cuatrimestral', 1),
(4, 'Texto Dramático y Espectacular',    'TEA-209', 2, 'cuatrimestral', 2),
(4, 'Didáctica del Teatro I',            'TEA-210', 2, 'cuatrimestral', 2),
-- 2° Año — Formación Práctica Profesional
(4, 'Taller de Práctica Docente II',     'TEA-211', 2, 'anual',         NULL),
-- 3° Año — Formación General
(4, 'Las TIC y la Enseñanza',            'TEA-301', 3, 'cuatrimestral', 1),
(4, 'Historia de la Educación Argentina','TEA-302', 3, 'cuatrimestral', 1),
(4, 'Política Educativa y Trabajo Docente', 'TEA-303', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Específica
(4, 'Historia del Teatro II',            'TEA-304', 3, 'cuatrimestral', 1),
(4, 'Sujetos de la Educación I',         'TEA-305', 3, 'cuatrimestral', 1),
(4, 'Actuación II',                      'TEA-306', 3, 'cuatrimestral', 1),
(4, 'Técnicas Corporales y Expresivas III','TEA-307', 3, 'cuatrimestral', 1),
(4, 'Producción y Puesta en Escena en Contextos Socioeducativos', 'TEA-308', 3, 'anual', NULL),
(4, 'Didáctica del Teatro II',           'TEA-309', 3, 'cuatrimestral', 2),
(4, 'Sujetos de la Educación II',        'TEA-310', 3, 'cuatrimestral', 2),
-- 3° Año — Formación Práctica Profesional
(4, 'Taller de Práctica Docente III',    'TEA-311', 3, 'anual',         NULL),
-- 4° Año — Formación General
(4, 'Educación Sexual Integral',         'TEA-401', 4, 'cuatrimestral', 1),
-- 4° Año — Formación Específica
(4, 'Dramaturgia',                       'TEA-402', 4, 'cuatrimestral', 1),
(4, 'Producción Teatral',                'TEA-403', 4, 'anual',         NULL),
-- 4° Año — Formación Práctica Profesional
(4, 'Taller de Práctica Docente IV y Residencia Pedagógica', 'TEA-410', 4, 'anual', NULL);



-- ── ESTUDIANTE - CARRERAS ─────────────────────────────────────────
-- Permite que un alumno esté inscripto en más de un profesorado.
-- anio_actual y anio_cohorte se guardan por carrera.
CREATE TABLE estudiante_carreras (
    id_estudiante INT NOT NULL,
    id_carrera INT NOT NULL,
    anio_actual INT NOT NULL DEFAULT 1 CHECK (anio_actual >= 1),
    anio_cohorte INT,
    PRIMARY KEY (id_estudiante, id_carrera),
    CONSTRAINT fk_ec_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE,
    CONSTRAINT fk_ec_carrera    FOREIGN KEY (id_carrera)    REFERENCES carreras(id_carrera)
);