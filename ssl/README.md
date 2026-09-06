# Certificado SSL

Colocá acá los dos archivos que provee la institución:

- `certificate.crt` — certificado público (o cadena completa).
- `private.key` — clave privada.

El contenedor `app` los detecta automáticamente al arrancar (`docker/docker-entrypoint.sh`)
y los usa para HTTPS en el puerto 443. No hace falta reconstruir la imagen, solo reiniciar
el contenedor: `docker compose restart app`.

Si estos archivos no están presentes, el contenedor genera un certificado autofirmado
temporal para que el sitio siga funcionando por HTTPS en desarrollo (con la advertencia
normal de "no seguro" del navegador, ya que nadie lo avala).

**No subas `private.key` a ningún repositorio ni lo compartas fuera del equipo del proyecto.**
