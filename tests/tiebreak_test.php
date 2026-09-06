<?php
declare(strict_types=1);

/**
 * Test de lógica de desempate (sin base de datos).
 *
 * Reproduce fielmente la regla implementada en LigaService / SistemaSuizoService:
 *   - Dos punteros empatados en TODAS las métricas disparan un partido de desempate.
 *   - El desempate suma a la tabla como un partido más.
 *   - Si hay ganador, rompe el empate (queda 1.º = campeón).
 *   - Si vuelve a empatar, se genera otro desempate (sin límite).
 *
 * Ejecutar:  php sgdm/tests/tiebreak_test.php
 */

// ── Reglas de orden (idéntico a TablaPosicionesService::recalcular) ──
function ordenar(array &$tabla): void
{
    usort($tabla, function (array $a, array $b): int {
        if ($a['puntos']     !== $b['puntos'])     return $b['puntos']     - $a['puntos'];
        if ($a['diferencia'] !== $b['diferencia']) return $b['diferencia'] - $a['diferencia'];
        if ($a['pf']         !== $b['pf'])         return $b['pf']         - $a['pf'];
        if ($a['pg']         !== $b['pg'])         return $b['pg']         - $a['pg'];
        if ($a['buchholz']   !== $b['buchholz'])   return (int)($b['buchholz'] - $a['buchholz']);
        return $a['id'] - $b['id'];
    });
}

// ── Empate exacto entre los dos primeros (idéntico a hayEmpateEnCima) ──
function hayEmpateEnCima(array $tabla): bool
{
    if (count($tabla) < 2) return false;
    $a = $tabla[0]; $b = $tabla[1];
    return (int)$a['puntos']     === (int)$b['puntos']
        && (int)$a['diferencia'] === (int)$b['diferencia']
        && (int)$a['pf']         === (int)$b['pf']
        && (int)$a['pg']         === (int)$b['pg']
        && abs((float)$a['buchholz'] - (float)$b['buchholz']) < 0.01;
}

/**
 * Simula la serie de desempate.
 * @param array $resultados lista de [puntosA, puntosB] de cada desempate consecutivo
 * @return array{0:string,1:int} [etiqueta campeón | 'SIN_DEFINIR', desempates jugados]
 */
function simularSerie(array $resultados, int $pv = 3, int $pe = 1, int $pd = 0): array
{
    // Arranque: A y B empatados en absolutamente todo (como en la imagen del usuario).
    $A = ['label'=>'A','id'=>1,'puntos'=>4,'pf'=>4,'pc'=>4,'diferencia'=>0,'pg'=>1,'buchholz'=>0.0];
    $B = ['label'=>'B','id'=>2,'puntos'=>4,'pf'=>4,'pc'=>4,'diferencia'=>0,'pg'=>1,'buchholz'=>0.0];

    $jugados = 0;
    foreach ($resultados as [$pa, $pb]) {
        // El desempate suma como un partido más.
        $A['pf'] += $pa; $A['pc'] += $pb;
        $B['pf'] += $pb; $B['pc'] += $pa;
        if ($pa > $pb)      { $A['puntos'] += $pv; $A['pg']++; $B['puntos'] += $pd; }
        elseif ($pb > $pa)  { $B['puntos'] += $pv; $B['pg']++; $A['puntos'] += $pd; }
        else                { $A['puntos'] += $pe; $B['puntos'] += $pe; }
        $A['diferencia'] = $A['pf'] - $A['pc'];
        $B['diferencia'] = $B['pf'] - $B['pc'];
        $jugados++;

        $tabla = [$A, $B];
        ordenar($tabla);
        if (!hayEmpateEnCima($tabla)) {
            return [$tabla[0]['label'], $jugados]; // campeón definido
        }
        // sigue empatado → se generaría otro desempate (continúa el bucle)
    }
    return ['SIN_DEFINIR', $jugados];
}

// ── Casos de prueba (los del enunciado) ──
$D = [1, 1]; // empate
$AW = [2, 1]; // gana A
$BW = [1, 2]; // gana B

$casos = [
    'Caso 1 (empate, gana A)'                 => [[ $D, $AW ],            'A', 2],
    'Caso 2 (empate, empate, gana B)'         => [[ $D, $D, $BW ],        'B', 3],
    'Caso 3 (4 empates, gana A)'              => [[ $D, $D, $D, $D, $AW ],'A', 5],
    'Sin empate previo no aplica (gana A ya)' => [[ $AW ],                'A', 1],
];

$fallos = 0;
foreach ($casos as $nombre => [$seq, $campeonEsperado, $desempatesEsperados]) {
    [$campeon, $jugados] = simularSerie($seq);
    $ok = ($campeon === $campeonEsperado && $jugados === $desempatesEsperados);
    printf("[%s] %s → campeón=%s (esperado %s), desempates=%d (esperado %d)\n",
        $ok ? 'PASS' : 'FAIL', $nombre, $campeon, $campeonEsperado, $jugados, $desempatesEsperados);
    if (!$ok) $fallos++;
}

echo $fallos === 0 ? "\nTODOS LOS TESTS PASARON\n" : "\n{$fallos} TEST(S) FALLARON\n";
exit($fallos === 0 ? 0 : 1);
