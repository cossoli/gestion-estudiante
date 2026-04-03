CREATE TABLE usuarios (
    id_usuario SERIAL PRIMARY KEY,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    email_validado BOOLEAN NOT NULL DEFAULT FALSE,
    token_validacion VARCHAR(255),
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE carreras (
    id_carrera SERIAL PRIMARY KEY,
    nombre_carrera VARCHAR(150) NOT NULL,
    titulo_otorgado VARCHAR(150),
    duracion_anios INT NOT NULL,
    modalidad VARCHAR(30) CHECK (modalidad IN ('presencial', 'semipresencial', 'virtual')),
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    numero_resolucion VARCHAR(100)
);

CREATE TABLE estudiantes (
    id_estudiante SERIAL PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    apellido VARCHAR(100) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL UNIQUE,
    correo VARCHAR(150) NOT NULL UNIQUE,
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

CREATE TABLE documentos_estudiante (
    id_documento SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL UNIQUE,
    pdf_dni VARCHAR(255),
    pdf_titulo_secundario VARCHAR(255),
    fecha_subida TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observaciones_documentos TEXT,
    CONSTRAINT fk_documento_estudiante FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante) ON DELETE CASCADE
);

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
    CONSTRAINT fk_inscripcion_materia FOREIGN KEY (id_materia) REFERENCES materias(id_materia),
    CONSTRAINT uq_estudiante_materia_anio UNIQUE (id_estudiante, id_materia, anio_lectivo)
);

INSERT INTO carreras (nombre_carrera, titulo_otorgado, duracion_anios, modalidad, numero_resolucion) VALUES
('Profesorado de Matemática', 'Profesor/a de Matemática', 4, 'presencial', 'Res. 100/20'),
('Profesorado de Física', 'Profesor/a de Física', 4, 'presencial', 'Res. 101/20'),
('Profesorado de Lengua y Literatura', 'Profesor/a de Lengua y Literatura', 4, 'presencial', 'Res. 102/20'),
('Profesorado de Teatro', 'Profesor/a de Teatro', 4, 'presencial', 'Res. 103/20');

INSERT INTO materias (id_carrera, nombre_materia, codigo_materia, anio_plan, formato, cuatrimestre) VALUES
(1, 'Pedagogía', 'MAT-101', 1, 'anual', NULL),
(1, 'Álgebra I', 'MAT-102', 1, 'cuatrimestral', 1),
(1, 'Análisis Matemático I', 'MAT-103', 1, 'cuatrimestral', 1),
(1, 'Didáctica General', 'MAT-104', 1, 'cuatrimestral', 2),
(2, 'Pedagogía', 'FIS-101', 1, 'anual', NULL),
(2, 'Física General I', 'FIS-102', 1, 'cuatrimestral', 1),
(2, 'Matemática Aplicada', 'FIS-103', 1, 'cuatrimestral', 1),
(2, 'Didáctica General', 'FIS-104', 1, 'cuatrimestral', 2),
(3, 'Pedagogía', 'LYL-101', 1, 'anual', NULL),
(3, 'Introducción a la Literatura', 'LYL-102', 1, 'cuatrimestral', 1),
(3, 'Gramática I', 'LYL-103', 1, 'cuatrimestral', 1),
(3, 'Didáctica General', 'LYL-104', 1, 'cuatrimestral', 2),
(4, 'Pedagogía', 'TEA-101', 1, 'anual', NULL),
(4, 'Actuación I', 'TEA-102', 1, 'cuatrimestral', 1),
(4, 'Expresión Corporal', 'TEA-103', 1, 'cuatrimestral', 1),
(4, 'Didáctica General', 'TEA-104', 1, 'cuatrimestral', 2);
