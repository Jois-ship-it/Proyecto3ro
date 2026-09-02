<?php
declare(strict_types=1);

/**
 * Auto-registro público de participantes con aprobación administrativa.
 *
 * Flujo:
 *  1. El visitante se registra → se crea un usuario (rol participante,
 *     estado 'pendiente') + su participante vinculado (estado 'pendiente').
 *  2. No puede iniciar sesión (el login filtra estado='activo').
 *  3. Un administrador aprueba (→ activo) o rechaza (→ rechazado).
 */
class RegistroService
{
    private UsuarioModel     $usuarioModel;
    private ParticipanteModel $partModel;
    private RolModel         $rolModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
        $this->partModel    = new ParticipanteModel();
        $this->rolModel     = new RolModel();
        $this->auditoria    = new AuditoriaService();
    }

    /** Registra un participante. Lanza RuntimeException con mensaje claro si falla. */
    public function registrar(array $d): int
    {
        $nombre   = trim($d['nombre'] ?? '');
        $email    = trim(strtolower($d['email'] ?? ''));
        $nick     = trim($d['nick'] ?? '');
        $telefono = trim($d['telefono'] ?? '');
        $documento= trim($d['documento'] ?? '');
        $password = (string)($d['password'] ?? '');
        $confirm  = (string)($d['password_confirm'] ?? '');

        if ($nombre === '')           throw new RuntimeException('El nombre es obligatorio.');
        if (!Validator::email($email)) throw new RuntimeException('Ingresá un email válido.');
        if ($this->usuarioModel->emailExiste($email)) {
            throw new RuntimeException('Ese email ya está registrado.');
        }
        // Política de contraseñas + confirmación (autoritativo en backend)
        Validator::assertPassword($password, $confirm);

        $rolPart = $this->rolModel->findByNombre('participante');
        if (!$rolPart) throw new RuntimeException('Configuración de roles inválida.');

        // 1) Cuenta de acceso (pendiente)
        $usuarioId = $this->usuarioModel->insert([
            'rol_id'        => (int)$rolPart['id'],
            'nombre'        => $nombre,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'estado'        => 'pendiente',
        ]);

        // 2) Perfil de participante (pendiente)
        $this->partModel->insert([
            'usuario_id' => $usuarioId,
            'nombre'     => $nombre,
            'nick'       => $nick ?: null,
            'email'      => $email,
            'telefono'   => $telefono ?: null,
            'documento'  => $documento ?: null,
            'estado'     => 'pendiente',
        ]);

        $this->auditoria->log('registro_participante', 'usuarios', $usuarioId,
            "Auto-registro de participante: {$email} (pendiente de aprobación)", null, null, $usuarioId);

        return $usuarioId;
    }

    /** Cuentas de participante pendientes de aprobación. */
    public function getPendientes(): array
    {
        return $this->usuarioModel->findParticipantesPendientes();
    }

    public function countPendientes(): int
    {
        return $this->usuarioModel->countParticipantesPendientes();
    }

    public function aprobar(int $usuarioId, int $revisorId): void
    {
        $u = $this->usuarioModel->findByIdConRol($usuarioId);
        if (!$u || $u['rol_nombre'] !== 'participante') throw new RuntimeException('Solicitud no encontrada.');
        if ($u['estado'] !== 'pendiente') throw new RuntimeException('La solicitud ya fue resuelta.');

        $this->usuarioModel->update($usuarioId, ['estado' => 'activo']);
        $part = $this->partModel->findByUsuario($usuarioId);
        if ($part) $this->partModel->update((int)$part['id'], ['estado' => 'activo']);

        $this->auditoria->log('aprobar_registro', 'usuarios', $usuarioId,
            "Registro aprobado: {$u['email']}", null, null, $revisorId);
    }

    public function rechazar(int $usuarioId, int $revisorId): void
    {
        $u = $this->usuarioModel->findByIdConRol($usuarioId);
        if (!$u || $u['rol_nombre'] !== 'participante') throw new RuntimeException('Solicitud no encontrada.');
        if ($u['estado'] !== 'pendiente') throw new RuntimeException('La solicitud ya fue resuelta.');

        $this->usuarioModel->update($usuarioId, ['estado' => 'rechazado']);
        $part = $this->partModel->findByUsuario($usuarioId);
        if ($part) $this->partModel->update((int)$part['id'], ['estado' => 'rechazado']);

        $this->auditoria->log('rechazar_registro', 'usuarios', $usuarioId,
            "Registro rechazado: {$u['email']}", null, null, $revisorId);
    }
}
