<?php
/**
 * Tras el 100 %: instalar en la tienda local, capturas, manual PDF y vídeo (ver lib/VlTras100.php).
 *
 *   php tras100.php <id>                                ejecuta los pasos elegidos (lo lanza validar.php)
 *   php tras100.php --lanzar <id> [--pasos=a,b]         lo lanza en segundo plano
 *   php tras100.php --esperar <id>                      espera a que termine y resume
 *   php tras100.php --opciones                          pasos que se pueden elegir
 *
 * Código de salida de --esperar: 0 si todos los pasos elegidos acabaron bien, 1 si no.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('memory_limit', '-1');
set_time_limit(0);
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}
$raiz = str_replace('\\', '/', __DIR__);
require $raiz . '/vendor/autoload.php';
foreach (glob($raiz . '/lib/*.php') as $f) {
    require_once $f;
}

$args = array_slice($argv, 1);
$modo = 'ejecutar';
$id = null;
$pasos = null;
foreach ($args as $a) {
    if ($a === '--lanzar' || $a === '--esperar' || $a === '--opciones') {
        $modo = substr($a, 2);
    } elseif (strpos($a, '--pasos=') === 0) {
        $pasos = substr($a, 8);
    } elseif (strpos($a, '--') !== 0) {
        $id = $a;
    } else {
        fwrite(STDERR, "Opción desconocida: $a\n");
        exit(2);
    }
}
$t = new VlTras100($raiz);
if ($modo === 'opciones') {
    foreach ($t->opciones() as $k => $v) {
        echo $k . "\t" . $v . PHP_EOL;
    }
    exit(0);
}
if (!$id || !VlTrabajo::idValido($id)) {
    fwrite(STDERR, "Falta el id de la validación (el de /module/<id>/validate).\n");
    exit(2);
}
$srv = new VlServidor($raiz);
$url = $srv->url('/module/' . $id . '/validate');

if ($modo === 'lanzar') {
    $r = $t->lanzar($id, $pasos);
    echo $r['ok'] ? 'Tras el 100 %: en marcha (' . implode(', ', $r['seleccion']) . '). Progreso en ' . $url . PHP_EOL
        : 'Tras el 100 %: no se lanza: ' . $r['motivo'] . PHP_EOL;
    exit($r['ok'] ? 0 : 1);
}
if ($modo === 'esperar') {
    $e = $t->esperar($id, function ($linea) {
        echo $linea . PHP_EOL;
    });
    if ($e === null) {
        echo "Tras el 100 %: no se ha lanzado para esta validación.\n";
        exit(1);
    }
    echo str_repeat('-', 60) . PHP_EOL;
    foreach ($e['pasos'] as $p) {
        echo VlTras100::lineaPaso($p) . PHP_EOL;
    }
    echo 'Capturas: ' . count($e['capturas']) . PHP_EOL;
    echo 'Manual PDF: ' . ($e['pdf'] ?: '-') . PHP_EOL;
    echo 'Vídeo: ' . ($e['video'] ?: '-') . PHP_EOL;
    echo ($e['estado'] === 'interrumpido' ? 'INTERRUMPIDO' : ($e['ok'] ? 'TODO HECHO' : 'CON FALLOS')) . ' · ' . $url . PHP_EOL;
    exit($e['estado'] === 'terminado' && $e['ok'] ? 0 : 1);
}
try {
    $e = $t->ejecutar($id, $pasos);
    exit($e['ok'] ? 0 : 1);
} catch (Throwable $x) {
    fwrite(STDERR, $x->getMessage() . PHP_EOL);
    exit(1);
}
