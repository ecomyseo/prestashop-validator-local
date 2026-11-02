<?php
/**
 * PRUEBAS de «tras el 100 %» (lib/VlTras100.php) sin tiendas ni Chromium: cada paso es un guion PHP
 * de pega que hace lo mismo que el de verdad (dice «FALLAN: 0», deja PNG, escribe un PDF con N
 * imágenes, un MP4…), y el «parar» se prueba con servidores php -S de verdad.
 *
 *   php pruebas/tras100.php
 *
 * VL_TRAS100_LIB=<fichero> carga otra versión de VlTras100 (lo usa pruebas/tras100_mutaciones.php).
 * Código de salida 0 solo si todo está en verde.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_time_limit(0);
$raizReal = str_replace('\\', '/', dirname(__DIR__));
require $raizReal . '/vendor/autoload.php';
$libAlt = getenv('VL_TRAS100_LIB');
foreach (glob($raizReal . '/lib/*.php') as $f) {
    if ($libAlt && basename($f) === 'VlTras100.php') {
        require_once $libAlt;
        continue;
    }
    require_once $f;
}

$fallos = 0;
$n = 0;
function ok($cond, string $que): void
{
    global $fallos, $n;
    ++$n;
    if (!$cond) {
        ++$fallos;
        echo '   [XX] ' . $que . PHP_EOL;
    } else {
        echo '   [OK] ' . $que . PHP_EOL;
    }
}

function puertoLibre(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $p = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);

    return $p;
}

function servidor(int $puerto): void
{
    $php = str_replace("'", "''", PHP_BINARY);
    if (DIRECTORY_SEPARATOR === '\\') {
        pclose(popen('powershell -NoProfile -Command "Start-Process -WindowStyle Hidden -FilePath \'' . $php . '\' -ArgumentList \'-S\',\'127.0.0.1:' . $puerto . '\'"', 'r'));
    } else {
        exec('nohup ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $puerto . ' > /dev/null 2>&1 &');
    }
    for ($i = 0; $i < 40 && !VlTras100::escuchando(array($puerto)); ++$i) {
        usleep(250000);
    }
}

// --- escenario en una carpeta temporal --------------------------------------------
$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_tras100_' . getmypid();
$mods = $tmp . '/modulos';
$guiones = $tmp . '/guiones';
@mkdir($tmp . '/trabajos', 0777, true);
@mkdir($mods . '/vlt1mod', 0777, true);
@mkdir($mods . '/manuales/vlt1mod', 0777, true);
@mkdir($guiones, 0777, true);
file_put_contents($mods . '/vlt1mod/vlt1mod.php', "<?php\n");
file_put_contents($mods . '/manuales/vlt1mod/GUIA_USUARIO.md', "# Módulo de prueba\n\nTexto de la guía.\n\n![Ya citada](../../capturas/vlt1mod/8.2b/a-config.png)\n");
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==');
file_put_contents($guiones . '/png.bin', $png);
$G = array(
    'instalar' => "<?php\n\$p = (int) (\$argv[1] ?? 0);\nif (\$p) {\n  \$php = str_replace(\"'\", \"''\", PHP_BINARY);\n  if (DIRECTORY_SEPARATOR === '\\\\') { pclose(popen('powershell -NoProfile -Command \"Start-Process -WindowStyle Hidden -FilePath \\'' . \$php . '\\' -ArgumentList \\'-S\\',\\'127.0.0.1:' . \$p . '\\'\"', 'r')); }\n  else { exec('nohup ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . \$p . ' > /dev/null 2>&1 &'); }\n  for (\$i = 0; \$i < 40; ++\$i) { \$s = @fsockopen('127.0.0.1', \$p, \$e, \$es, 0.2); if (\$s) { fclose(\$s); break; } usleep(250000); }\n}\necho 'instalado' . PHP_EOL . 'FALLAN: ' . (getenv('VLT1_FALLAN') ?: '0') . PHP_EOL;\n",
    'capturas' => "<?php\nif (getenv('VLT1_SIN_CAPTURAS')) { exit(0); }\n@mkdir(\$argv[1], 0777, true);\nforeach (array('a-config', 'b-listado', 'c front ficha') as \$c) { copy(__DIR__ . '/png.bin', \$argv[1] . '/' . \$c . '.png'); }\necho \"3 capturas\\n\";\n",
    'pdf' => "<?php\n\$md = file_get_contents(\$argv[1]);\n\$n = preg_match_all('#!\\\\[[^\\\\]]*\\\\]\\\\(#', \$md) - (int) getenv('VLT1_PDF_MENOS');\nfile_put_contents(\$argv[2], '%PDF-1.4' . str_repeat(' /Subtype /Image ', max(0, \$n)));\n",
    'video' => "<?php\nif (!getenv('VLT1_SIN_VIDEO')) { @mkdir(dirname(\$argv[1]), 0777, true); file_put_contents(\$argv[1], str_repeat('v', 2048)); }\n",
    'lento' => "<?php\nsleep(6);\n",
);
foreach ($G as $k => $codigo) {
    file_put_contents($guiones . '/' . $k . '.php', $codigo);
}
$puertoNuevo = puertoLibre();
$puertoViejo = puertoLibre();
$conf = array(
    'carpeta_modulos' => $mods,
    'tras_100' => array(
        'activo' => true,
        'capturas' => '{carpeta_modulos}/capturas/{modulo}/8.2b',
        'pasos' => array(
            array('id' => 'instalar', 'titulo' => 'Instalar', 'orden' => array('{php}', $guiones . '/instalar.php', (string) $puertoNuevo), 'exige' => 'FALLAN: 0', 'minutos' => 2),
            array('id' => 'capturas', 'titulo' => 'Capturas', 'requiere' => array('instalar'), 'orden' => array('{php}', $guiones . '/capturas.php', '{capturas}'), 'produce' => 'carpeta:{capturas}', 'minutos' => 2),
            array('id' => 'manual', 'titulo' => 'Manual PDF', 'preparar' => 'manual', 'orden' => array('{php}', $guiones . '/pdf.php', '{manual_md}', '{manual_pdf}'), 'produce' => 'fichero:{manual_pdf}', 'minutos' => 2),
            array('id' => 'video', 'titulo' => 'Vídeo', 'orden' => array('{php}', $guiones . '/video.php', '{carpeta_modulos}/videos/{modulo}.mp4'), 'produce' => 'fichero:{carpeta_modulos}/videos/{modulo}.mp4', 'minutos' => 2),
            array('id' => 'parar', 'titulo' => 'Parar', 'siempre' => true, 'si' => array('instalar', 'capturas'), 'interno' => 'parar_puertos', 'puertos' => array($puertoNuevo, $puertoViejo)),
        ),
    ),
);
$t = new VlTras100($tmp, $conf);
$trab = new VlTrabajo($tmp);
$nuevoTrabajo = function () use ($trab, $mods) {
    $id = $trab->crear($mods . '/vlt1mod', array('tras100' => array()), 'vlt1mod');
    $d = $trab->leer($id, 'trabajo');
    $d['modulo'] = array('carpeta' => 'vlt1mod', 'displayName' => 'Prueba', 'version' => '1.0.0', 'description' => 'x');
    $trab->guardar($id, 'trabajo', $d);
    usleep(1100000); // ids por segundo

    return $id;
};
$estadoDe = function (array $e, string $paso) {
    foreach ($e['pasos'] as $p) {
        if ($p['id'] === $paso) {
            return $p['estado'];
        }
    }

    return 'no está';
};

echo "1. ELECCIÓN DE PASOS\n";
ok($t->seleccion('todo') === array('instalar', 'capturas', 'manual', 'video'), '«todo» = los cuatro, en orden');
ok($t->seleccion('capturas') === array('instalar', 'capturas'), 'capturas arrastra instalar');
ok($t->seleccion('no') === array() && $t->seleccion('') === array(), '«no» y vacío = nada');
ok($t->seleccion(array('video', 'xx', 'manual')) === array('manual', 'video'), 'ids desconocidos fuera, orden de la configuración');
ok(array_keys($t->opciones()) === array('instalar', 'capturas', 'manual', 'video'), 'parar no se elige');

echo "2. DÓNDE SE PUEDE\n";
ok($t->motivoParaNo($mods . '/vlt1mod') === null, 'carpeta dentro de carpeta_modulos');
ok($t->motivoParaNo($guiones) !== null, 'carpeta fuera de carpeta_modulos');
file_put_contents($tmp . '/x.zip', 'PK');
ok($t->motivoParaNo($tmp . '/x.zip') !== null, 'un ZIP no');

echo "3. TODO BIEN (con un servidor que ya estaba antes)\n";
servidor($puertoViejo);
ok((bool) VlTras100::escuchando(array($puertoViejo)), 'servidor «de otra sesión» en marcha');
$id = $nuevoTrabajo();
$e = $t->ejecutar($id, 'todo');
ok($e['estado'] === 'terminado' && $e['ok'] === true, 'terminado y bien' . ($e['ok'] ? '' : ': ' . json_encode(array_column($e['pasos'], 'motivo', 'id'), JSON_UNESCAPED_UNICODE)));
ok(count($e['capturas']) === 3, '3 capturas recogidas');
ok($e['pdf'] && is_file($e['pdf']) && $e['video'] && is_file($e['video']), 'PDF y vídeo apuntados');
$md = (string) @file_get_contents($mods . '/manuales/vlt1mod/MANUAL.md');
ok(strpos($md, 'Texto de la guía') !== false, 'MANUAL.md sale de GUIA_USUARIO.md');
ok(substr_count($md, '](') === 3 && substr_count($md, 'a-config.png') === 1, 'las 3 capturas, sin repetir la que ya estaba citada');
ok(strpos($md, '../../capturas/vlt1mod/8.2b/c%20front%20ficha.png') !== false, 'ruta relativa con espacios escapados');
ok(!VlTras100::escuchando(array($puertoNuevo)), 'para el servidor que arrancó la instalación');
ok((bool) VlTras100::escuchando(array($puertoViejo)), 'NO para el que ya estaba');
ok($estadoDe($e, 'parar') === 'ok', 'paso parar en verde');

echo "4. REPETIR NO DUPLICA EL BLOQUE DE CAPTURAS Y QUITA LAS QUE YA NO ESTÁN\n";
$dirCaps = $mods . '/capturas/vlt1mod/8.2b';
unlink($dirCaps . '/b-listado.png');
copy($guiones . '/png.bin', $dirCaps . '/d-nueva.png');
$id2 = $nuevoTrabajo();
$e2 = $t->ejecutar($id2, 'manual');
$md2 = (string) file_get_contents($mods . '/manuales/vlt1mod/MANUAL.md');
ok($e2['ok'] === true && substr_count($md2, VlTras100::BLOQUE_INI) === 1 && substr_count($md2, '](') === 3, 'un solo bloque y 3 imágenes');
ok(strpos($md2, 'b-listado.png') === false && substr_count($md2, 'd-nueva.png') === 1, 'fuera la captura borrada, dentro la nueva');
ok($estadoDe($e2, 'parar') === 'no está' && $estadoDe($e2, 'instalar') === 'no está', 'solo el paso pedido (sin parar)');

echo "5. LA INSTALACIÓN FALLA (sale con 0 pero dice FALLAN: 1)\n";
putenv('VLT1_FALLAN=1');
$id3 = $nuevoTrabajo();
$e3 = $t->ejecutar($id3, 'todo');
putenv('VLT1_FALLAN');
ok($estadoDe($e3, 'instalar') === 'fallo', 'instalar en rojo');
ok($estadoDe($e3, 'capturas') === 'omitido' && $estadoDe($e3, 'manual') === 'omitido' && $estadoDe($e3, 'video') === 'omitido', 'lo de después, omitido');
ok($estadoDe($e3, 'parar') === 'ok' && !VlTras100::escuchando(array($puertoNuevo)), 'parar se hace igual');
ok($e3['ok'] === false, 'resultado: con fallos');

echo "6. RESULTADOS QUE NO VALEN\n";
putenv('VLT1_SIN_VIDEO=1');
$id4 = $nuevoTrabajo();
$e4 = $t->ejecutar($id4, 'video');
putenv('VLT1_SIN_VIDEO');
ok($estadoDe($e4, 'video') === 'fallo', 'un vídeo de antes no cuenta como hecho');
putenv('VLT1_PDF_MENOS=1');
$id5 = $nuevoTrabajo();
$e5 = $t->ejecutar($id5, 'manual');
putenv('VLT1_PDF_MENOS');
ok($estadoDe($e5, 'manual') === 'fallo', 'PDF con menos imágenes que capturas = fallo');
putenv('VLT1_SIN_CAPTURAS=1');
$id5b = $nuevoTrabajo();
$e5b = $t->ejecutar($id5b, 'capturas');
putenv('VLT1_SIN_CAPTURAS');
ok($estadoDe($e5b, 'capturas') === 'fallo', 'capturas de antes no cuentan como hechas');

echo "7. PROGRAMA QUE NO EXISTE Y TIEMPO AGOTADO\n";
$conf2 = $conf;
$conf2['tras_100']['pasos'][3]['orden'][1] = $guiones . '/no-existe.php';
$conf2['tras_100']['pasos'][2]['orden'][1] = $guiones . '/lento.php';
$conf2['tras_100']['pasos'][2]['segundos'] = 2;
$t2 = new VlTras100($tmp, $conf2);
$id6 = $nuevoTrabajo();
$e6 = $t2->ejecutar($id6, 'video');
ok($estadoDe($e6, 'video') === 'omitido' && $e6['ok'] === true, 'sin programa: omitido, no fallo');
$id7 = $nuevoTrabajo();
$e7 = $t2->ejecutar($id7, 'manual');
ok($estadoDe($e7, 'manual') === 'fallo' && strpos((string) $e7['pasos'][0]['motivo'], 'tiempo agotado') === 0, 'pasado el tiempo: fallo «tiempo agotado»');

echo "8. PROCESO MUERTO\n";
$id8 = $nuevoTrabajo();
$trab->guardar($id8, 'tras100', array('estado' => 'en_curso', 'latido' => time() - VlTras100::LATIDO_MAX - 5, 'pasos' => array()));
$e8 = $t->estado($id8);
ok($e8['estado'] === 'interrumpido' && $e8['vivo'] === false, 'sin latido = interrumpido');
$trab->guardar($id8, 'tras100', array('estado' => 'en_curso', 'latido' => time(), 'pasos' => array()));
ok($t->estado($id8)['vivo'] === true, 'con latido = vivo');

// limpieza
foreach (array($puertoNuevo, $puertoViejo) as $pu) {
    foreach (VlTras100::escuchando(array($pu)) as $pids) {
        foreach ($pids as $pid) {
            shell_exec(DIRECTORY_SEPARATOR === '\\' ? 'powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"' : 'kill -9 ' . $pid);
        }
    }
}
VlModulo::borrarArbol($tmp);
echo str_repeat('-', 60) . PHP_EOL . ($fallos === 0 ? "TODO EN VERDE ($n comprobaciones)\n" : "FALLOS: $fallos de $n\n");
exit($fallos === 0 ? 0 : 1);
