# Estado de la sesión - FlexArena (Proyecto3ro)

Guardado para retomar desde otra PC. Fecha: 2026-08-23.

## Qué se hizo
- Proyecto PHP "FlexArena" (gestión de torneos) en `C:\Users\57055626\Downloads\Proyecto3ro-master\Proyecto3ro-master\`.
- PHP/MySQL/Apache de XAMPP estaban instalados (PHP 8.2.12).
- El MySQL de XAMPP está ROTO: usa autenticación `auth_gssapi_client` que ni el cliente CLI ni PHP PDO soportan, y no se pudo reiniciar (corre como servicio, falta admin).
- **La app en realidad usa SQLite** (`core/Database.php` hardcodea `database/flexarena.sqlite`), así que MySQL no hace falta.
- Se levantó el server con el PHP built-in server:
  `php -S 0.0.0.0:8080 -t public public/dev_router.php`
  - Responde HTTP 200, título "Inicio | FlexArena".
  - Proceso php vivo (PID cambia cada arranque).

## Cómo acceder (mientras el server esté corriendo)
- Misma PC: http://localhost:8080
- Mismo WiFi: http://10.95.177.210:8080
- Tailscale: http://100.105.150.109:8080
- IP pública de la máquina: 179.29.96.7 (requiere port-forward del 8080 en el router para acceso desde fuera)

## Pendiente (decidir al retomar)
¿Hacer el server accesible desde internet?
- Opción A: instalar `cloudflared` y tunel `cloudflared tunnel --url http://localhost:8080` para obtener URL `https://xxxx.trycloudflare.com`. La descarga es MUY lenta (~14 KB/s) en esta red.
- Opción B: abrir puerto 8080 en el router y usar la IP pública 179.29.96.7.
- Opción C: dejarlo solo en LAN/WiFi/Tailscale (ya funciona).

## Para volver a levantar el server en casa
```
cd <ruta_del_proyecto>
C:\xampp\php\php.exe -S 0.0.0.0:8080 -t public public\dev_router.php
```
(usar la ruta de php que tengan; si no hay XAMPP, instalar PHP >= 8.1).

## Credenciales de prueba (del README)
- Admin: admin@flexarena.com / Adm!n-Flex2026#Tech
- Organizador: org1@flexarena.com / admin123
- Participante: matias@example.com / admin123

## Notas técnicas
- Config MySQL en `.env` (DB_HOST=127.0.0.1 etc.) NO se usa; `core/Database.php` ignora eso y va a SQLite.
- Si en la otra PC quieren MySQL en vez de SQLite, hay que arreglar el plugin de auth de root (cambiar a `mysql_native_password`) reiniciando mysqld con `--skip-grant-tables` como admin.
