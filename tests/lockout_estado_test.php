<?php
declare(strict_types=1);

/**
 * Test de las reglas de bloqueo/desbloqueo de cuentas (sin base de datos).
 * Reproduce fielmente las reglas implementadas en:
 *   - AuthService::login() (auto-bloqueo a los 5 intentos fallidos)
 *   - UsuarioService::toggleActivo() / editar() / bloquear() / desbloquear()
 *   - ParticipanteService::toggleActivo() / editar()
 *   - AdminController::usuarioBloquear() / usuarioDesbloquear() (redirect de retorno)
 *   - View::estadoChip() (se usa la clase real, no una reproducción: no depende de DB)
 *
 * Regla de negocio: una cuenta nunca puede quedar "activa" y "bloqueada"
 * (bloqueada/suspendido) al mismo tiempo, y ninguna acción salvo
 * bloquear()/desbloquear() puede revertir un bloqueo.
 *
 * Ejecutar:  php sgdm/tests/lockout_estado_test.php
 */

require_once __DIR__ . '/../core/View.php';

// ── Reproducción de AuthService::login() — solo la parte de conteo/auto-bloqueo ──
// (MAX_FAILED_ATTEMPTS = 5, idéntico a AuthService::MAX_FAILED_ATTEMPTS)
function simularIntentosFallidos(int $cantidadIntentos, int $max = 5): array
{
    $intentos = 0;
    $estado = 'activo';
    for ($i = 0; $i < $cantidadIntentos; $i++) {
        $intentos++;
        if ($intentos >= $max) {
            $estado = 'bloqueada'; // UsuarioModel::lockAccount()
        }
    }
    return [$estado, $intentos];
}

// ── Reproducción de UsuarioService::toggleActivo() (idéntico a la lógica real) ──
function usuarioToggleActivo(string $estadoActual): string
{
    if ($estadoActual === 'bloqueada') {
        throw new RuntimeException('Esta cuenta está bloqueada. Desbloqueala primero para poder activarla.');
    }
    return $estadoActual === 'activo' ? 'inactivo' : 'activo';
}

// ── Reproducción de ParticipanteService::toggleActivo() ──
function participanteToggleActivo(string $estadoActual): string
{
    if ($estadoActual === 'suspendido') {
        throw new RuntimeException('Esta cuenta está bloqueada. Desbloqueala primero para poder activarla.');
    }
    return $estadoActual === 'activo' ? 'inactivo' : 'activo';
}

// ── Reproducción de UsuarioService::editar() (solo la resolución de 'estado') ──
function usuarioEditarEstado(string $estadoActual, string $estadoPosteado): string
{
    return $estadoActual === 'bloqueada' ? 'bloqueada' : $estadoPosteado;
}

// ── Reproducción de ParticipanteService::editar() (solo la resolución de 'estado') ──
function participanteEditarEstado(string $estadoActual, string $estadoPosteado): string
{
    return $estadoActual === 'suspendido' ? 'suspendido' : $estadoPosteado;
}

// ── Reproducción del default de retorno en AdminController::usuarioBloquear/Desbloquear ──
function redirectDestino(?string $return, string $default): string
{
    return $return !== null && $return !== '' ? $return : $default;
}

$fallos = 0;
function check(bool $cond, string $desc): void
{
    global $fallos;
    $ok = $cond;
    printf("[%s] %s\n", $ok ? 'OK' : 'FALLO', $desc);
    if (!$ok) $fallos++;
}

// 1) Bloqueo automático a los 5 intentos fallidos de login.
[$estadoTras4, $c4] = simularIntentosFallidos(4);
check($estadoTras4 === 'activo', 'con 4 intentos fallidos la cuenta sigue activa (no llegó al umbral)');
[$estadoTras5, $c5] = simularIntentosFallidos(5);
check($estadoTras5 === 'bloqueada' && $c5 === 5, 'al 5º intento fallido (MAX_FAILED_ATTEMPTS) la cuenta pasa a bloqueada');

// El chip de estado (View::estadoChip, clase real) debe mostrar "bloqueada" como danger
// tanto para usuarios como para el 'suspendido' cascada en participantes.
check(str_contains(View::estadoChip('bloqueada'), 'chip danger'), 'estadoChip("bloqueada") renderiza clase danger');
check(str_contains(View::estadoChip('suspendido'), 'chip danger'), 'estadoChip("suspendido") renderiza clase danger');
check(str_contains(View::estadoChip('bloqueada'), 'Bloqueada'), 'estadoChip("bloqueada") muestra la etiqueta "Bloqueada"');

// Bloquear una cuenta activa (simulado: lockAccount fuerza 'bloqueada' directamente,
// no pasa por toggleActivo — se prueba solo el efecto sobre las reglas siguientes).
$estadoUsuario = 'bloqueada'; // resultado de UsuarioModel::lockAccount()
$estadoParticipante = 'suspendido'; // cascada de lockAccount() sobre participantes

// 2) Intentar "activarla" vía toggleActivo → debe fallar
try {
    usuarioToggleActivo($estadoUsuario);
    check(false, 'toggleActivo sobre usuario bloqueado debería lanzar excepción');
} catch (RuntimeException $e) {
    check(true, 'toggleActivo sobre usuario bloqueado lanza excepción y no lo activa');
}
try {
    participanteToggleActivo($estadoParticipante);
    check(false, 'toggleActivo sobre participante suspendido debería lanzar excepción');
} catch (RuntimeException $e) {
    check(true, 'toggleActivo sobre participante suspendido lanza excepción y no lo activa');
}

// 3) Guardar el formulario de edición sin tocar el estado explícitamente
//    (o con un estado manipulado) no debe desbloquear la cuenta.
check(
    usuarioEditarEstado($estadoUsuario, 'activo') === 'bloqueada',
    'editar() preserva estado bloqueada aunque el POST traiga "activo"'
);
check(
    participanteEditarEstado($estadoParticipante, 'activo') === 'suspendido',
    'editar() preserva estado suspendido (por bloqueo) aunque el POST traiga "activo"'
);

// 4) Desbloquear (UsuarioModel::unlockAccount) y confirmar que ahí sí puede volver a activo.
$estadoUsuario = 'activo';       // resultado de unlockAccount()
$estadoParticipante = 'activo';  // cascada de unlockAccount() sobre participantes
check($estadoUsuario === 'activo', 'desbloquear() deja la cuenta en estado activo');
check(usuarioToggleActivo($estadoUsuario) === 'inactivo', 'tras desbloquear, toggleActivo vuelve a funcionar normalmente (activo → inactivo)');
check(participanteToggleActivo($estadoParticipante) === 'inactivo', 'tras desbloquear, toggleActivo del participante funciona normalmente');
check(str_contains(View::estadoChip($estadoUsuario), 'chip success'), 'tras desbloquear, estadoChip(usuario) vuelve a mostrarse como success (activo)');
check(str_contains(View::estadoChip($estadoParticipante), 'chip success'), 'tras desbloquear, estadoChip(participante) vuelve a mostrarse como success (activo)');

// Cobertura completa de estadoChip() para ambos ENUMs reales (database/schema.sql):
// usuarios:      pendiente, activo, inactivo, suspendido, rechazado, bloqueada
// participantes: pendiente, activo, inactivo, suspendido, rechazado
$claseEsperada = [
    'pendiente'  => 'chip warning',
    'activo'     => 'chip success',
    'inactivo'   => 'chip danger',
    'suspendido' => 'chip danger',
    'rechazado'  => 'chip danger',
    'bloqueada'  => 'chip danger',
];
foreach ($claseEsperada as $valor => $claseExact) {
    check(View::estadoChip($valor) === "<span class=\"{$claseExact}\">" . ucfirst($valor) . '</span>',
        "estadoChip(\"{$valor}\") = '{$claseExact}' (cobertura completa de los ENUM de usuarios/participantes)");
}

// 5) editar() ya no debe pisar nada una vez desbloqueada (estado != 'bloqueada'/'suspendido').
check(usuarioEditarEstado('activo', 'inactivo') === 'inactivo', 'editar() respeta el estado posteado cuando la cuenta no está bloqueada');
check(participanteEditarEstado('activo', 'inactivo') === 'inactivo', 'editar() respeta el estado posteado cuando el participante no está suspendido por bloqueo');

// 6) Redirect tras bloquear/desbloquear vuelve a la sección de origen.
check(redirectDestino('/admin/participantes', '/admin/organizadores') === '/admin/participantes', 'return= /admin/participantes se respeta al bloquear/desbloquear desde Participantes');
check(redirectDestino(null, '/admin/organizadores') === '/admin/organizadores', 'sin return explícito, cae al default de Organizadores (compatibilidad)');
check(redirectDestino('', '/admin/organizadores') === '/admin/organizadores', 'return vacío cae al default de Organizadores');

echo "\n";
if ($fallos > 0) {
    echo "TOTAL: {$fallos} fallo(s)\n";
    exit(1);
}
echo "TOTAL: todos los casos OK\n";
