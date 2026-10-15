<?php
declare(strict_types=1);

/**
 * Inicios de sesión fallidos, para limitar los intentos por dirección IP además de por cuenta.
 * Es un registro operativo: se consulta por ventana de tiempo y se purga solo.
 */
class IntentoLoginModel extends BaseModel
{
    protected string $table = 'intentos_login';

    /** El email se corta al largo de la columna: lo escribe quien ataca, puede traer cualquier cosa. */
    public function registrar(string $ip, ?string $email): void
    {
        $this->execute(
            'INSERT INTO intentos_login (ip, email) VALUES (:ip, :email)',
            [':ip' => mb_substr($ip, 0, 45), ':email' => $email === null ? null : mb_substr($email, 0, 150)]
        );
    }

    /** Intentos fallidos de una IP en los últimos $minutos. */
    public function contarPorIp(string $ip, int $minutos): int
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM intentos_login WHERE ip = :ip AND created_at >= NOW() - INTERVAL :m MINUTE',
            [':ip' => mb_substr($ip, 0, 45), ':m' => $minutos]
        );
    }

    /** Borra lo anterior a $horas. Devuelve cuántas filas. */
    public function purgarAnteriores(int $horas): int
    {
        return $this->execute('DELETE FROM intentos_login WHERE created_at < NOW() - INTERVAL :h HOUR', [':h' => $horas]);
    }
}
