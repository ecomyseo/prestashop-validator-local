<?php
/**
 * Web local del validator: http://127.0.0.1:8077/
 * Mismas rutas que validator.prestashop.com:
 *   GET  /                                   subir un ZIP o elegir una carpeta
 *   POST /                                   crea la validación y redirige a /module/<id>/validate
 *   GET  /module/<id>/validate               página de la validación (pestañas)
 *   GET  /module/<id>/validate/warmup        preparación
 *   GET  /module/<id>/validate/<paso>        resultado JSON de un paso (forma del oficial)
 *   GET  /module/<id>/validate/reporting     informe TXT
 *   GET  /module/<id>/tras100                estado de «tras el 100 %» (instalar, capturas, manual, vídeo)
 *   POST /module/<id>/tras100                lanzarlo (pasos=instalar,capturas,manual,video)
 *   GET  /module/<id>/tras100/<pdf|video|captura/<f>|log/<paso>>   lo que ha generado
 *   GET  /user/validations                   validaciones hechas
 *
 * Arranque: php servidor.php start   (o validar.php ... --abrir)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('memory_limit', '-1');
set_time_limit(0);

$raiz = str_replace('\\', '/', dirname(__DIR__));
require $raiz . '/vendor/autoload.php';
foreach (glob($raiz . '/lib/*.php') as $f) {
    require_once $f;
}

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$trabajos = new VlTrabajo($raiz);
$conf = json_decode(file_get_contents($raiz . '/reglas/prestashop.json'), true);

function vlSinCache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function vlE($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function vlJson($d, int $codigo = 200): void
{
    http_response_code($codigo);
    vlSinCache();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function vlPagina(string $titulo, string $cuerpo, string $extraHead = ''): void
{
    vlSinCache();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . vlE($titulo) . ' - Validator local</title><link rel="stylesheet" href="/assets/app.css">' . $extraHead . '</head><body>'
        . '<nav class="top"><a class="logo" href="/">PrestaShop <b>Validator</b> <small>local</small></a>'
        . '<span class="sp"></span><a href="/">Validar</a><a href="/user/validations">Validaciones</a>'
        . '<a class="ext" href="https://validator.prestashop.com/" target="_blank" rel="noopener">validator.prestashop.com &#8599;</a></nav>'
        . '<main>' . $cuerpo . '</main>'
        . '<footer>Réplica local de validator.prestashop.com ' . VlValidador::VERSION_OFICIAL . ' · PHP CS Fixer 3.95.4 · PHPStan 1.12.33 nivel 5</footer>'
        . '</body></html>';
}

/** Sirve un fichero generado (con Range, para que el vídeo se pueda adelantar). */
function vlServir(string $f, string $tipo): void
{
    if (!is_file($f)) {
        http_response_code(404);
        return;
    }
    $tam = filesize($f);
    $ini = 0;
    $fin = $tam - 1;
    vlSinCache();
    header('Content-Type: ' . $tipo);
    header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('#bytes=(\d*)-(\d*)#', $_SERVER['HTTP_RANGE'], $r)) {
        if ($r[1] !== '') {
            $ini = (int) $r[1];
            $fin = $r[2] !== '' ? min((int) $r[2], $tam - 1) : $tam - 1;
        } elseif ($r[2] !== '') {
            $ini = max(0, $tam - (int) $r[2]);
        }
        if ($ini > $fin || $ini >= $tam) {
            http_response_code(416);
            header('Content-Range: bytes */' . $tam);
            return;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $ini . '-' . $fin . '/' . $tam);
    }
    header('Content-Length: ' . ($fin - $ini + 1));
    $fh = fopen($f, 'rb');
    fseek($fh, $ini);
    $quedan = $fin - $ini + 1;
    while ($quedan > 0 && !feof($fh)) {
        $trozo = fread($fh, (int) min(1048576, $quedan));
        $quedan -= strlen($trozo);
        echo $trozo;
        flush();
    }
    fclose($fh);
}

/** Carpetas de módulos (una por nivel) para el desplegable. */
function vlModulosLocales(string $base): array
{
    $out = array();
    foreach (glob(rtrim($base, '/') . '/*', GLOB_ONLYDIR) ?: array() as $d) {
        if (is_file($d . '/' . basename($d) . '.php')) {
            $out[] = str_replace('\\', '/', $d);
        }
    }

    return $out;
}

// ------------------------------------------------------------------ estáticos y ping
if ($ruta === '/_vl_ping') {
    header('Content-Type: text/plain');
    echo 'validator-local';
    return true;
}
if (preg_match('#^/assets/([a-z0-9_.-]+\.(css|js))$#i', $ruta, $mm)) {
    $f = __DIR__ . '/assets/' . $mm[1];
    if (!is_file($f)) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: ' . ($mm[2] === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($f);
    return true;
}
if ($ruta === '/favicon.ico') {
    http_response_code(204);
    return true;
}

$validador = new VlValidador($raiz);
$t100 = new VlTras100($raiz);

// ------------------------------------------------------------------ crear validación
if ($ruta === '/' && $metodo === 'POST') {
    $opciones = array('todas_ps' => !empty($_POST['todas_ps']), 'tras100' => $t100->seleccion((array) ($_POST['tras100'] ?? array())));
    $origen = null;
    $nombre = null;
    $error = null;
    if (!empty($_FILES['archive']['name'])) {
        $fi = $_FILES['archive'];
        if ($fi['error'] !== UPLOAD_ERR_OK) {
            $error = 'No se ha podido subir el ZIP (código ' . $fi['error'] . ', máximo 30 MB).';
        } elseif (strtolower(pathinfo($fi['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $error = 'El fichero tiene que ser un .zip';
        } else {
            $dest = $raiz . '/trabajos/_subidas/' . date('Ymd-His') . '-' . substr(md5($fi['name'] . microtime(true)), 0, 6);
            @mkdir($dest, 0777, true);
            $origen = $dest . '/' . basename($fi['name']);
            move_uploaded_file($fi['tmp_name'], $origen);
            $nombre = pathinfo($fi['name'], PATHINFO_FILENAME);
        }
    } elseif (!empty($_POST['carpeta'])) {
        $c = rtrim(str_replace('\\', '/', trim($_POST['carpeta'])), '/');
        if (is_dir($c) || (is_file($c) && strtolower(pathinfo($c, PATHINFO_EXTENSION)) === 'zip')) {
            $origen = $c;
        } else {
            $error = 'No existe la carpeta o el ZIP: ' . $c;
        }
    } else {
        $error = 'Elige un ZIP o una carpeta.';
    }
    if ($error) {
        header('Location: /?error=' . rawurlencode($error), true, 303);
        return true;
    }
    $id = $trabajos->crear($origen, $opciones, $nombre);
    header('Location: /module/' . $id . '/validate', true, 303);
    return true;
}

// ------------------------------------------------------------------ portada
if ($ruta === '/') {
    $mods = vlModulosLocales($conf['carpeta_modulos'] ?? '');
    $ultimas = array_slice($trabajos->lista(), 0, 8);
    $h = '<div class="row"><section class="card wide"><h1>Validate your module</h1>'
        . '<p>Sube el ZIP del módulo o elige su carpeta. El ZIP no puede pasar de 30 MB, como en el oficial.</p>';
    if (!empty($_GET['error'])) {
        $h .= '<div class="alert alert-danger">' . vlE($_GET['error']) . '</div>';
    }
    $h .= '<form method="post" action="/" enctype="multipart/form-data" class="upload">'
        . '<div class="opt"><label>From archive</label><input type="file" name="archive" accept=".zip"></div>'
        . '<div class="or">o</div>'
        . '<div class="opt"><label>From local folder</label><input type="text" name="carpeta" list="vl-mods" placeholder="Z:/modulos ps mios finalizados y ok/modulos IA/mi_modulo" autocomplete="off">'
        . '<datalist id="vl-mods">';
    foreach ($mods as $m) {
        $h .= '<option value="' . vlE($m) . '">';
    }
    $h .= '</datalist></div>'
        . '<label class="chk"><input type="checkbox" name="todas_ps" value="1"> PHPStan contra todas las versiones del rango (más lento; el oficial usa solo la más alta)</label>';
    if ($t100->activo()) {
        $h .= '<fieldset class="tras100-elegir"><legend>Si pasa al 100 %, después:</legend>';
        foreach ($t100->opciones() as $k => $v) {
            $h .= '<label class="chk"><input type="checkbox" name="tras100[]" value="' . vlE($k) . '"> ' . vlE($v) . '</label>';
        }
        $h .= '<p class="hint">Solo con una carpeta de ' . vlE($conf['carpeta_modulos'] ?? '') . ' (un ZIP no se instala). Las capturas incluyen la instalación.</p></fieldset>';
    }
    $h .= '<button class="btn btn-primary" type="submit">Process the validation &raquo;</button></form>'
        . '<p class="hint">¿Validar también en el oficial? <a href="https://validator.prestashop.com/" target="_blank" rel="noopener">Abrir validator.prestashop.com &#8599;</a> y subir allí el mismo ZIP.</p>'
        . '</section><section class="card"><h2>Últimas validaciones</h2>';
    if (!$ultimas) {
        $h .= '<p class="muted">Todavía ninguna.</p>';
    }
    foreach ($ultimas as $u) {
        $mod = $u['modulo']['displayName'] ?? ($u['modulo']['carpeta'] ?? $u['id']);
        $tt = $u['totales'];
        $ok = $tt['hechos'] === 9 && $tt['error'] + $tt['warning'] === 0;
        $h .= '<a class="last" href="/module/' . vlE($u['id']) . '/validate"><b>' . vlE(is_string($mod) ? $mod : $u['id']) . '</b><span>'
            . vlE($u['creado']) . '</span><span class="badges">'
            . ($tt['hechos'] < 9 ? '<span class="badge">' . $tt['hechos'] . '/9</span>' : '')
            . ($tt['warning'] ? '<span class="badge badge-warning">' . $tt['warning'] . '</span>' : '')
            . ($tt['error'] ? '<span class="badge badge-danger">' . $tt['error'] . '</span>' : '')
            . ($ok ? '<span class="badge badge-success">&#10003;</span>' : '') . '</span></a>';
    }
    $h .= '<p><a href="/user/validations">Ver todas &raquo;</a></p></section></div>';
    vlPagina('Validate', $h);
    return true;
}

// ------------------------------------------------------------------ historial
if ($ruta === '/user/validations') {
    $h = '<section class="card wide"><h1>Validations</h1><table class="table"><thead><tr><th>#</th><th>Name</th><th>Module</th><th>Version</th><th>Origen</th><th>Fecha</th><th>Compat.</th><th>Resultado</th></tr></thead><tbody>';
    foreach ($trabajos->lista(500) as $u) {
        $md = $u['modulo'] ?? array();
        $tt = $u['totales'];
        $c = $md['compatibilidad'] ?? array();
        $h .= '<tr><td><a href="/module/' . vlE($u['id']) . '/validate">' . vlE($u['id']) . '</a></td><td>' . vlE(is_string($md['displayName'] ?? null) ? $md['displayName'] : '') . '</td><td>'
            . vlE($md['carpeta'] ?? '') . '</td><td>' . vlE(is_string($md['version'] ?? null) ? $md['version'] : '') . '</td><td class="small">' . vlE($u['tipo'] === 'zip' ? basename($u['origen']) : $u['origen'])
            . '</td><td>' . vlE($u['creado']) . '</td><td>' . vlE(($c['min'] ?? '') . ' → ' . (($c['max'] ?? '') === '99' ? '_PS_VERSION_' : ($c['max'] ?? ''))) . '</td><td>'
            . ($tt['hechos'] < 9 ? '<span class="badge">' . $tt['hechos'] . '/9</span>' : '')
            . ($tt['warning'] ? '<span class="badge badge-warning">' . $tt['warning'] . '</span>' : '')
            . ($tt['error'] ? '<span class="badge badge-danger">' . $tt['error'] . '</span>' : '')
            . ($tt['hechos'] === 9 && !$tt['error'] && !$tt['warning'] ? '<span class="badge badge-success">&#10003;</span>' : '') . '</td></tr>';
    }
    $h .= '</tbody></table></section>';
    vlPagina('Validations', $h);
    return true;
}

// ------------------------------------------------------------------ tras el 100 %
if (preg_match('#^/module/([a-z0-9_]+-\d{8}-\d{6}(?:-\d+)?)/tras100(?:/(pdf|video|captura|log)(?:/([A-Za-z0-9_.-]+))?)?/?$#', $ruta, $mm)) {
    $id = $mm[1];
    $t = $trabajos->leer($id, 'trabajo');
    if (!$t) {
        vlJson(array('error' => 'validación no encontrada'), 404);
        return true;
    }
    $e = $t100->estado($id);
    $que = $mm[2] ?? '';
    if ($que !== '') {
        $nombre = $mm[3] ?? '';
        $f = null;
        $tipo = 'application/octet-stream';
        if ($que === 'pdf') {
            $f = $e['pdf'] ?? null;
            $tipo = 'application/pdf';
        } elseif ($que === 'video') {
            $f = $e['video'] ?? null;
            $tipo = 'video/mp4';
        } elseif ($que === 'captura') {
            foreach ((array) ($e['capturas'] ?? array()) as $c) {
                if (basename($c) === $nombre) {
                    $f = $c;
                    $tipo = 'image/png';
                }
            }
        } elseif ($que === 'log') {
            foreach ((array) ($e['pasos'] ?? array()) as $p) {
                if ($p['id'] === $nombre && !empty($p['log'])) {
                    $f = $trabajos->dir($id) . '/' . $p['log'];
                    $tipo = 'text/plain; charset=utf-8';
                }
            }
        }
        if (!$f) {
            http_response_code(404);
            return true;
        }
        vlServir($f, $tipo);
        return true;
    }
    if ($metodo === 'POST') {
        if (!$trabajos->es100($id)) {
            vlJson(array('ok' => false, 'motivo' => 'el módulo no pasa al 100 %'), 409);
            return true;
        }
        $pedidos = isset($_POST['pasos']) ? (string) $_POST['pasos'] : null;
        $r = $t100->lanzar($id, $pedidos);
        vlJson($r, $r['ok'] ? 200 : 409);
        return true;
    }
    vlJson(array(
        'es100' => $trabajos->es100($id),
        'puede' => $t100->motivoParaNo($t['origen']),
        'opciones' => $t100->opciones(),
        'seleccion' => $t100->seleccion($t['opciones']['tras100'] ?? array()),
        'estado' => $e,
    ));
    return true;
}

// ------------------------------------------------------------------ validación
if (preg_match('#^/module/([a-z0-9_]+-\d{8}-\d{6}(?:-\d+)?)/(validate|zip)(?:/([a-z]+))?/?$#', $ruta, $mm)) {
    $id = $mm[1];
    $t = $trabajos->leer($id, 'trabajo');
    if (!$t) {
        http_response_code(404);
        vlPagina('404', '<section class="card wide"><h1>Validación no encontrada</h1><p><a href="/">Volver</a></p></section>');
        return true;
    }
    if ($mm[2] === 'zip') {
        if ($t['tipo'] !== 'zip' || !is_file($t['origen'])) {
            http_response_code(404);
            return true;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($t['origen']) . '"');
        readfile($t['origen']);
        return true;
    }
    $paso = $mm[3] ?? '';
    if ($paso === 'warmup') {
        try {
            if (empty($t['modulo'])) {
                $m = new VlModulo($t['origen'], $raiz);
                $t['modulo'] = $trabajos->identidad($m);
                $trabajos->guardar($id, 'trabajo', $t);
            }
            vlJson(array('ok' => true, 'modulo' => $t['modulo']));
        } catch (Throwable $e) {
            vlJson(array('ok' => false, 'error' => $e->getMessage()), 500);
        }
        return true;
    }
    if ($paso === 'reporting') {
        $res = $trabajos->resultado($id);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $id . '.txt"');
        vlSinCache();
        echo VlInforme::txt($res);
        return true;
    }
    if ($paso !== '') {
        if (!isset(VlGoals::PASOS[$paso])) {
            vlJson(array('error' => 'paso desconocido'), 404);
            return true;
        }
        try {
            $d = $trabajos->paso($id, $paso, $validador);
            $info = array();
            foreach ($d['goals'] as $g => $n) {
                $gg = VlGoals::get($g);
                $info[$g] = array('titulo' => $gg['titulo'], 'tipo' => $gg['tipo'], 'origen' => $gg['origen'], 'hits' => $n);
            }
            $salida = $d['oficial'];
            $salida['goals'] = $info;
            $salida['fallo'] = $d['fallo'];
            $salida['avisos'] = $d['avisosInternos'];
            $salida['tiempo'] = $d['tiempo'];
            $salida['intro'] = VlGoals::INTRO[$paso];
            $salida['introLink'] = VlGoals::INTRO_LINK[$paso] ?? null;
            if ($paso === 'compatibility') {
                $t2 = $trabajos->leer($id, 'trabajo');
                $salida['phpstan'] = array_keys((array) ($t2['phpstan'] ?? array()));
            }
            vlJson($salida);
        } catch (Throwable $e) {
            vlJson(array('fallo' => get_class($e) . ': ' . $e->getMessage()), 500);
        }
        return true;
    }
    // página de la validación (los datos del módulo se leen ya aquí para pintarlos en Summary)
    if (empty($t['modulo'])) {
        try {
            $t['modulo'] = $trabajos->identidad(new VlModulo($t['origen'], $raiz));
            $trabajos->guardar($id, 'trabajo', $t);
        } catch (Throwable $e) {
            $t['modulo'] = array();
        }
    }
    $md = $t['modulo'] ?? array();
    $nombre = is_string($md['displayName'] ?? null) ? $md['displayName'] : ($md['carpeta'] ?? $id);
    $menu = '<a href="#summary" data-step="summary" class="list-group-item active"><span>Summary</span></a>';
    $paneles = '';
    foreach (VlGoals::PASOS as $pid => $titulo) {
        $menu .= '<a href="#' . $pid . '" id="' . $pid . '-pill" data-step="' . $pid . '" class="list-group-item validation-pill"><span>' . $titulo . '</span><span class="badges"><span class="spin"></span></span></a>';
        $paneles .= '<div class="tab-pane validation-step" id="' . $pid . '"><div class="panel"><div class="panel-heading"><h3>' . $titulo . '</h3></div><div class="panel-body">'
            . '<div class="validation-ok alert alert-success" hidden>0 results found.</div>'
            . '<div class="validation-nok" hidden></div>'
            . '<div class="validation-fail alert alert-danger" hidden></div>'
            . '<div class="validation-wait muted">En cola…</div></div></div></div>';
    }
    $c = $md['compatibilidad'] ?? array();
    $sum = '<div class="tab-pane active" id="summary"><div class="panel"><div class="panel-heading"><h3>Summary</h3></div><div class="panel-body">'
        . '<div id="vl-final" class="alert alert-info">Validando… las pestañas se rellenan a medida que termina cada paso.</div>'
        . '<h4>Module details</h4><dl class="details">'
        . '<dt>Technical name:</dt><dd>' . vlE($md['name'] ?? ($md['carpeta'] ?? '')) . '</dd>'
        . '<dt>Display name:</dt><dd>' . vlE($nombre) . '</dd>'
        . '<dt>Description:</dt><dd>' . vlE(is_string($md['description'] ?? null) ? $md['description'] : '') . '</dd>'
        . '<dt>Version:</dt><dd>v' . vlE(is_string($md['version'] ?? null) ? $md['version'] : '') . '</dd>'
        . '<dt>Author:</dt><dd>' . vlE(is_string($md['author'] ?? null) ? $md['author'] : '') . '</dd>'
        . '<dt>Category (tab):</dt><dd>' . vlE(is_string($md['tab'] ?? null) ? $md['tab'] : '') . '</dd>'
        . '<dt>Compatibility:</dt><dd>' . vlE(($c['min'] ?? '?') . ' → ' . (($c['max'] ?? '') === '99' ? '_PS_VERSION_' : ($c['max'] ?? '?'))) . '</dd>'
        . '<dt>Origen:</dt><dd class="small">' . vlE($t['origen']) . '</dd></dl>'
        . '<p class="actions"><a class="btn" href="/module/' . vlE($id) . '/validate/reporting" target="_blank">Download the report (TXT)</a> '
        . '<a class="btn btn-official" href="https://validator.prestashop.com/" target="_blank" rel="noopener">Validar en validator.prestashop.com &#8599;</a> '
        . ($t['tipo'] === 'zip' ? '<a class="btn" href="/module/' . vlE($id) . '/zip">Descargar el ZIP validado</a> ' : '')
        . '</p><form method="post" action="/" class="inline"><input type="hidden" name="carpeta" value="' . vlE($t['origen']) . '">'
        . (!empty($t['opciones']['todas_ps']) ? '<input type="hidden" name="todas_ps" value="1">' : '')
        . '<button class="btn btn-primary" type="submit">Volver a validar</button></form>'
        . '<h4 id="tras100">Tras el 100 %</h4><div id="vl-tras100" class="tras100"><p class="muted">Cargando…</p></div>'
        . '<h4>Goals</h4><table class="table goals" id="vl-goals"><thead><tr><th>Pestaña</th><th>Goal</th><th>Qué comprueba</th><th>Resultado</th></tr></thead><tbody></tbody></table>'
        . '</div></div></div>';
    $h = '<h1 class="vtitle">Validation - ' . vlE($nombre) . '</h1><div class="validation" data-id="' . vlE($id) . '">'
        . '<div class="side"><div class="list-group" id="validation-menu">' . $menu . '</div></div>'
        . '<div class="content">' . $sum . $paneles . '</div></div>'
        . '<script src="/assets/validation.js"></script>';
    vlPagina('Validation - ' . $nombre, $h);
    return true;
}

http_response_code(404);
vlPagina('404', '<section class="card wide"><h1>404</h1><p><a href="/">Volver</a></p></section>');
return true;
