<?php
/**
 * VALIDATOR LOCAL — réplica de https://validator.prestashop.com (5.14.6) que corre en esta máquina.
 *
 * Uso:
 *   php validar.php <carpeta_del_modulo | modulo.zip> [opciones]
 *
 * Opciones:
 *   --todas-ps          PHPStan contra TODAS las versiones del rango (el oficial usa solo la más alta)
 *   --ps=8.2.7,9.2.0    PHPStan contra esas versiones (ver reglas/prestashop.json)
 *   --solo=security,errors   ejecutar solo esas pestañas
 *   --sin=standards     saltarse pestañas
 *   --muestras=N        ocurrencias por goal en consola (5 por defecto, 0 = ninguna)
 *   --informes=DIR      carpeta de informes (por defecto ./informes/<modulo>)
 *   --sin-informes      no escribir TXT/HTML/JSON
 *   --arreglar          aplica los arreglos automáticos seguros y vuelve a validar (ver README.md)
 *   --tras100=PASOS     si pasa al 100 %: instalar,capturas,manual,video (o «todo» / «no»).
 *                       Sin esta opción, en una terminal se PREGUNTA antes de validar.
 *   --esperar           espera a que terminen esos pasos (si no, siguen en segundo plano)
 *
 * Código de salida: 0 si el módulo pasa al 100 % (0 errores y 0 avisos), 1 si no.
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
$ruta = null;
$op = array('solo' => array(), 'sin' => array(), 'ps' => null, 'todas_ps' => false);
$muestras = 5;
$dirInformes = null;
$informes = true;
$arreglar = false;
$web = true;
$abrir = false;
$tras100 = null;
$esperar = false;
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) {
        $ruta = $a;
        continue;
    }
    list($k, $v) = array_pad(explode('=', substr($a, 2), 2), 2, null);
    switch ($k) {
        case 'rapido':
            // compatibilidad: ya es lo de por defecto (PHPStan solo contra la versión más alta, como el oficial)
            break;
        case 'todas-ps':
            $op['todas_ps'] = true;
            break;
        case 'ps':
            $op['ps'] = array_filter(array_map('trim', explode(',', (string) $v)));
            break;
        case 'solo':
        case 'sin':
            $op[$k] = array_filter(array_map('trim', explode(',', strtolower((string) $v))));
            break;
        case 'muestras':
            $muestras = (int) $v;
            break;
        case 'informes':
            $dirInformes = $v;
            break;
        case 'sin-informes':
            $informes = false;
            break;
        case 'arreglar':
            $arreglar = true;
            break;
        case 'abrir':
            $abrir = true;
            break;
        case 'sin-web':
            $web = false;
            break;
        case 'tras100':
            $tras100 = (string) $v;
            break;
        case 'esperar':
            $esperar = true;
            break;
        default:
            fwrite(STDERR, "Opción desconocida: $a\n");
            exit(2);
    }
}
if (!$ruta) {
    // Sin ruta: el módulo de la carpeta actual (o de la carpeta padre que lo contenga)
    $d = str_replace('\\', '/', getcwd());
    while ($d && $d !== dirname($d)) {
        if (is_file($d . '/' . basename($d) . '.php')) {
            $ruta = $d;
            break;
        }
        $d = dirname($d);
    }
    if (!$ruta) {
        fwrite(STDERR, "No estás dentro de la carpeta de un módulo (no hay <carpeta>/<carpeta>.php).\n"
            . "Uso: php validar.php <carpeta_del_modulo | modulo.zip> [--todas-ps] [--ps=8.2.7] [--solo=...] [--sin=...] [--arreglar]\n");
        exit(2);
    }
}

// Tras el 100 %: qué pasos hacer se decide ANTES de validar (en una terminal, preguntando)
$t100 = new VlTras100($raiz);
$motivoNo100 = $t100->motivoParaNo($ruta);
$sel100 = array();
if ($web && $motivoNo100 === null) {
    if ($tras100 === null && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
        $ops = array_values($t100->opciones());
        $ids = array_keys($t100->opciones());
        echo "Si el módulo pasa al 100 %, ¿qué hago después?\n";
        foreach ($ops as $i => $o) {
            echo '  ' . ($i + 1) . ') ' . $o . PHP_EOL;
        }
        echo 'Números separados por comas, «t» = todo, Intro = nada: ';
        $resp = strtolower(trim((string) fgets(STDIN)));
        if ($resp === 't' || $resp === 'todo') {
            $tras100 = 'todo';
        } else {
            $elegidos = array();
            foreach (preg_split('#[\s,;]+#', $resp) as $n) {
                if (ctype_digit($n) && isset($ids[(int) $n - 1])) {
                    $elegidos[] = $ids[(int) $n - 1];
                }
            }
            $tras100 = implode(',', $elegidos);
        }
    }
    $sel100 = $t100->seleccion((string) $tras100);
}

$validador = new VlValidador($raiz);
if ($arreglar) {
    if (strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'zip') {
        fwrite(STDERR, "--arreglar solo trabaja sobre una carpeta, no sobre un ZIP.\n");
        exit(2);
    }
    $cambios = (new VlArreglar($raiz))->arreglar($ruta);
    echo 'ARREGLOS APLICADOS: ' . count($cambios) . PHP_EOL;
    foreach ($cambios as $c) {
        echo '  - ' . $c . PHP_EOL;
    }
}
$res = $validador->validar($ruta, $op);
echo VlInforme::consola($res, $muestras);

if ($informes) {
    $m = $res['modulo'];
    $dir = $dirInformes ?: $raiz . '/informes/' . $m->carpeta;
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/' . $m->carpeta . '.txt', VlInforme::txt($res));
    file_put_contents($dir . '/' . $m->carpeta . '.html', VlInforme::html($res));
    file_put_contents($dir . '/' . $m->carpeta . '.json', VlInforme::json($res));
    echo 'Informes: ' . str_replace('/', DIRECTORY_SEPARATOR, $dir) . DIRECTORY_SEPARATOR . $m->carpeta . '.{html,txt,json}' . PHP_EOL;
}
if ($web) {
    // La misma validación, ya hecha, en la web de localhost (como la página del oficial)
    $tr = new VlTrabajo($raiz);
    $idTrabajo = $tr->crear(str_replace('\\', '/', realpath($ruta) ?: $ruta), array('todas_ps' => $op['todas_ps'], 'ps' => $op['ps'], 'tras100' => $sel100), $res['modulo']->carpeta);
    $tr->guardarResultado($idTrabajo, $res);
    $srv = new VlServidor($raiz);
    if ($srv->arrancar()) {
        $url = $srv->url('/module/' . $idTrabajo . '/validate');
        echo 'Resultado en localhost: ' . $url . PHP_EOL;
        if ($abrir) {
            VlServidor::abrirNavegador($url);
        }
    } else {
        echo 'No se ha podido arrancar la web local (puerto ' . $srv->puerto . ').' . PHP_EOL;
    }
}
$t = VlValidador::totales($res);
$es100 = $t['error'] === 0 && $t['warning'] === 0 && $t['fallos'] === 0;
if ($web && $sel100) {
    if (!$es100) {
        echo 'Tras el 100 %: no se hace (' . implode(', ', $sel100) . '): el módulo no pasa al 100 %.' . PHP_EOL;
    } else {
        $r = $t100->lanzar($idTrabajo, $sel100);
        if (!$r['ok']) {
            echo 'Tras el 100 %: no se lanza: ' . $r['motivo'] . PHP_EOL;
        } elseif (!$esperar) {
            echo 'Tras el 100 %: en marcha en segundo plano (' . implode(', ', $r['seleccion']) . '). Se ve en la misma página;'
                . ' para esperar aquí: php tras100.php --esperar ' . $idTrabajo . PHP_EOL;
        } else {
            echo 'Tras el 100 %: ' . implode(', ', $r['seleccion']) . PHP_EOL;
            $e = $t100->esperar($idTrabajo, function ($linea) {
                echo '  ' . $linea . PHP_EOL;
            });
            echo 'Manual PDF: ' . ($e['pdf'] ?? '-') . PHP_EOL . 'Vídeo: ' . ($e['video'] ?? '-') . PHP_EOL;
            echo 'Tras el 100 %: ' . ($e && $e['estado'] === 'terminado' && $e['ok'] ? 'TODO HECHO' : 'CON FALLOS') . PHP_EOL;
        }
    }
} elseif ($web && $motivoNo100 !== null && $tras100 !== null && $tras100 !== '' && $tras100 !== 'no') {
    echo 'Tras el 100 %: no se hace: ' . $motivoNo100 . PHP_EOL;
}
exit($es100 ? 0 : 1);
