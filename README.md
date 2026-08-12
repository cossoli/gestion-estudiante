# Sistema base IFDC

## Qué trae
- Acceso estudiante : ingresa con correo y contraseña, y si no tiene cuenta se da de alta.
- Secretaría con acceso por contraseña única.
- Área TIC con acceso por contraseña única.
- Formulario de inscripción con PDFs de DNI y título secundario.
- Aprobación desde Secretaría.
- Pase automático a TIC cuando Secretaría aprueba.
- Asignación automática de materias anuales y del 1° cuatrimestre según carrera y año.

## Claves demo
- Secretaría: `Secre12456`
- TIC: `Tic12456`

## Levantar
```bash
docker compose down -v --remove-orphans
docker compose up --build
```

## URLs
- Frontend: http://localhost:3000/inicio/
- Backend salud: http://localhost:8080/health/
- Estudiante: http://localhost:3000/acceso/
- Alta de usuario: http://localhost:3000/alta-usuario/
- Secretaría: http://localhost:3000/secretaria/
- TIC: http://localhost:3000/tic/

## Notas
- La validación de correo quedó simulada: al crear la cuenta se marca como validado para simplificar las pruebas.
- Los PDFs se guardan dentro del contenedor backend en la carpeta `uploads/`.
- Si cambiás la estructura de la base, usá `docker compose down -v` antes de volver a levantar.
