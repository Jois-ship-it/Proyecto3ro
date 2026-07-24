<?php
declare(strict_types=1);

/**
 * Test de validación de fecha de partido dentro del rango del torneo (sin base de datos).
 * Reproduce la regla de ResultadoService::assertFechaEnRangoTorneo:
 *   - El partido debe caer en [fecha_inicio 00:00, fecha_fin 23:59], inclusive.
 *   - Torneo de una sola jornada: solo esa fecha (cualquier hora 00:00–23:59).
 *
 * Ejecutar:  php sgdm/tests/match_schedule_test.php
 */

function enRangoTorneo(string $fechaPartido, string $ini, string $fin): bool
{
    $ts    = strtotime($fechaPartido);
    $iniTs = strtotime($ini . ' 00:00:00');
    $finTs = strtotime($fin . ' 23:59:59');
    return $ts !== false && $ts >= $iniTs && $ts <= $finTs;
}

$casos = [
    // [fechaPartido, inicioTorneo, finTorneo, esperado, descripción]
    ['2026-07-10 10:00', '2026-07-10', '2026-07-15', true,  'multi: primer día'],
    ['2026-07-13 18:30', '2026-07-10', '2026-07-15', true,  'multi: día intermedio'],
    ['2026-07-15 23:59', '2026-07-10', '2026-07-15', true,  'multi: último minuto del último día'],
    ['2026-07-09 23:59', '2026-07-10', '2026-07-15', false, 'multi: un día antes → inválido'],
    ['2026-07-16 00:00', '2026-07-10', '2026-07-15', false, 'multi: un día después → inválido'],

    ['2026-07-15 00:00', '2026-07-15', '2026-07-15', true,  'single: 00:00 del día → válido'],
    ['2026-07-15 08:30', '2026-07-15', '2026-07-15', true,  'single: media mañana → válido'],
    ['2026-07-15 23:59', '2026-07-15', '2026-07-15', true,  'single: 23:59 del día → válido'],
    ['2026-07-14 23:59', '2026-07-15', '2026-07-15', false, 'single: día anterior → inválido'],
    ['2026-07-16 00:00', '2026-07-15', '2026-07-15', false, 'single: día siguiente → inválido'],
];

$fallos = 0;
foreach ($casos as [$fecha, $ini, $fin, $esperado, $desc]) {
    $got = enRangoTorneo($fecha, $ini, $fin);
    $ok  = ($got === $esperado);
    printf("[%s] %s → %s (esperado %s)\n",
        $ok ? 'PASS' : 'FAIL', $desc,
        $got ? 'permitido' : 'rechazado',
        $esperado ? 'permitido' : 'rechazado');
    if (!$ok) $fallos++;
}

echo $fallos === 0 ? "\nTODOS LOS TESTS PASARON\n" : "\n{$fallos} TEST(S) FALLARON\n";
exit($fallos === 0 ? 0 : 1);
