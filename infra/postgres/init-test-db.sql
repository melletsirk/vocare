-- Crea una base de datos separada para los tests (PostgreSQL, no SQLite).
-- Solo se ejecuta automáticamente la primera vez que se inicializa el
-- volumen de datos del contenedor postgres (docker-entrypoint-initdb.d).
-- Si el volumen ya existe (instalaciones previas), crear manualmente:
--   docker compose exec postgres psql -U vocare -d vocare -c "CREATE DATABASE vocare_test;"
CREATE DATABASE vocare_test;
