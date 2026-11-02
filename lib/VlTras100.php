<?php
/**
 * Tras el 100 %: lo que se hace con un módulo que pasa el validator (reglas/prestashop.json, «tras_100»).
 *
 * Los pasos se ELIGEN antes de validar (validar.php los pregunta; la web y el skill también):
 * instalar en la tienda local, capturas, manual PDF y manual en vídeo. Al terminar se paran las
 * tiendas si se arrancaron. Corre en segundo plano (tras100.php) y deja su estado en
 * trabajos/<id>/tras100.json, que la web de localhost pinta y va refrescando.
 *
 * Cada paso:
 *   - se OMITE si su programa no existe (otra máquina sin tiendas de pruebas: no es un fallo);
 *   - FALLA si sale con código distinto de 0, si no imprime lo que exige («FALLAN: 0») o si no deja
 *     su resultado RECIÉN hecho (un PDF o un vídeo de antes no cuenta);
 *   - si uno falla, los siguientes se omiten, salvo los marcados «siempre» (parar las tiendas).
 * El proceso late cada pocos segundos: sin latido, la web lo da por interrumpido.
 */
class VlTras100
{
    /** Segundos sin latido tras los que el proceso se da por muerto. */
    const LATIDO_MAX = 90;
    const BLOQUE_INI = '<!-- validator-local:capturas -->';
    const BLOQUE_FIN = '<!-- /validator-local:capturas -->';

    /** @var string */
    private $raiz;
    /** @var array */
    private $conf;
    /** @var VlTrabajo */
    private $trabajos;

    public function __construct(string $raiz, ?array $conf = null)
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        if ($conf === null) {
            $conf = json_decode((string) @file_get_contents($this->raiz . '/reglas/prestashop.json'), true);
        }
        $this->conf = is_array($conf) ? $conf : array();
        $this->trabajos = new VlTrabajo($this->raiz);
    }

    public function activo(): bool
    {
        return !empty($this->conf['tras_100']['activo']) && !empty($this->conf['tras_100']['pasos']);
    }

    /** Pasos que se pueden elegir: id => título (los «siempre» no se eligen). */
    public function opciones(): array
    {
        $out = array();
        foreach ((array) ($this->conf['tras_100']['pasos'] ?? array()) as $pc) {
            if (empty($pc['siempre'])) {
                $out[$pc['id']] = $pc['titulo'] ?? $pc['id'];
            }
        }

        return $out;
    }

    /**
     * Normaliza una elección («todo», «no», lista con comas o array) a ids válidos, en el orden de la
     * configuración y con lo que cada uno requiere (capturas => instalar).
     */
    public function seleccion($eleccion): array
    {
        if (is_string($eleccion)) {
            $e = strtolower(trim($eleccion));
            if ($e === '' || $e === 'no' || $e === 'nada' || $e === 'ninguno') {
                return array();
            }
            $eleccion = in_array($e, array('todo', 'todos', 'si', 'sí', 'all'), true)
                ? array_keys($this->opciones()) : preg_split('#[\s,;]+#', $e);
        }
        $quiero = array_flip(array_map('strtolower', array_filter((array) $eleccion, 'is_string')));
        $pasos = (array) ($this->conf['tras_100']['pasos'] ?? array());
        for ($vuelta = 0; $vuelta < 3; ++$vuelta) {
            foreach ($pasos as $pc) {
                if (isset($quiero[$pc['id']])) {
                    foreach ((array) ($pc['requiere'] ?? array()) as $r) {
                        $quiero[$r] = true;
                    }
                }
            }
        }
        $out = array();
        foreach ($pasos as $pc) {
            if (empty($pc['siempre']) && isset($quiero[$pc['id']])) {
                $out[] = $pc['id'];
            }
        }

        return $out;
    }

    /** Configuración de los pasos que se ejecutan con esa selección (los «siempre» si procede). */
    private function pasosDe(array $sel): array
    {
        $out = array();
        if (!$sel) {
            return $out;
        }
        foreach ((array) ($this->conf['tras_100']['pasos'] ?? array()) as $pc) {
            if (!empty($pc['siempre'])) {
                $si = (array) ($pc['si'] ?? array());
                if (!$si || array_intersect($si, $sel)) {
                    $out[] = $pc;
                }
            } elseif (in_array($pc['id'], $sel, true)) {
                $out[] = $pc;
            }
        }

        return $out;
    }

    public function carpetaModulos(): string
    {
        $c = (string) ($this->conf['carpeta_modulos'] ?? '');
        $r = $c !== '' ? realpath($c) : false;

        return $r ? self::norm($r) : '';
    }

    /** null si se puede lanzar para ese origen; si no, el motivo en una frase. */
    public function motivoParaNo(string $origen): ?string
    {
        if (!$this->activo()) {
            return 'desactivado en reglas/prestashop.json (tras_100.activo)';
        }
        $real = realpath($origen);
        if (!$real || !is_dir($real)) {
            return 'solo se hace con una carpeta de módulo, no con un ZIP';
        }
        $base = $this->carpetaModulos();
        if ($base === '' || strcasecmp(self::norm(dirname($real)), $base) !== 0) {
            return 'el módulo no está en carpeta_modulos (' . ($base !== '' ? $base : 'sin definir') . ')';
        }

        return null;
    }

    // ------------------------------------------------------------------ estado

    public function estado(string $id): ?array
    {
        $e = $this->trabajos->leer($id, 'tras100');
        if (!$e) {
            return null;
        }
        $e['vivo'] = in_array($e['estado'] ?? '', array('en_cola', 'en_curso'), true)
            && time() - (int) ($e['latido'] ?? 0) <= self::LATIDO_MAX;
        if (!$e['vivo'] && in_array($e['estado'] ?? '', array('en_cola', 'en_curso'), true)) {
            $e['estado'] = 'interrumpido';
        }

        return $e;
    }

    private function guardar(string $id, array &$e): void
    {
        $e['latido'] = time();
        $this->trabajos->guardar($id, 'tras100', $e);
    }

    private function cerrojo(string $modulo): string
    {
        return $this->raiz . '/trabajos/_tras100-' . preg_replace('#[^a-z0-9_]#i', '_', $modulo) . '.lock';
    }

    /** Id del trabajo que ya lo está haciendo para ese módulo, o null. */
    public function ocupado(string $modulo): ?string
    {
        $f = $this->cerrojo($modulo);
        if (!is_file($f)) {
            return null;
        }
        $id = trim((string) @file_get_contents($f));
        $e = VlTrabajo::idValido($id) ? $this->estado($id) : null;

        return $e && $e['vivo'] ? $id : null;
    }

    // ------------------------------------------------------------------ lanzar

    /**
     * Deja el trabajo en cola y arranca tras100.php en segundo plano (sin ventana).
     * $eleccion null = la que se eligió al crear la validación (opciones.tras100).
     */
    public function lanzar(string $id, $eleccion = null): array
    {
        $t = $this->trabajos->leer($id, 'trabajo');
        if (!$t) {
            return array('ok' => false, 'motivo' => 'validación no encontrada');
        }
        $sel = $this->seleccion($eleccion === null ? ($t['opciones']['tras100'] ?? array()) : $eleccion);
        if (!$sel) {
            return array('ok' => false, 'motivo' => 'no se ha elegido ningún paso');
        }
        $motivo = $this->motivoParaNo($t['origen']);
        if ($motivo !== null) {
            return array('ok' => false, 'motivo' => $motivo);
        }
        $modulo = $t['modulo']['carpeta'] ?? basename($t['origen']);
        $otro = $this->ocupado($modulo);
        if ($otro !== null) {
            return array('ok' => false, 'motivo' => 'ya está en marcha para este módulo (' . $otro . ')');
        }
        file_put_contents($this->cerrojo($modulo), $id);
        $e = $this->estadoInicial($modulo, 'en_cola', $sel);
        $this->guardar($id, $e);
        $php = (string) ($this->conf['php'] ?? '');
        $php = $php !== '' && is_file($php) ? $php : PHP_BINARY;
        if (DIRECTORY_SEPARATOR === '\\') {
            // ruta relativa a WorkingDirectory: Start-Process no entrecomilla los argumentos con espacios
            $ps = "Start-Process -WindowStyle Hidden -FilePath '" . str_replace("'", "''", $php) . "'"
                . " -ArgumentList 'tras100.php','" . $id . "' -WorkingDirectory '" . str_replace("'", "''", $this->raiz) . "'";
            pclose(popen('powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $ps . '"', 'r'));
        } else {
            exec('cd ' . escapeshellarg($this->raiz) . ' && nohup ' . escapeshellarg($php) . ' tras100.php ' . escapeshellarg($id) . ' > /dev/null 2>&1 &');
        }

        return array('ok' => true, 'motivo' => null, 'seleccion' => $sel);
    }

    private function estadoInicial(string $modulo, string $estado, array $sel): array
    {
        $pasos = array();
        foreach ($this->pasosDe($sel) as $pc) {
            $pasos[] = array('id' => $pc['id'], 'titulo' => $pc['titulo'] ?? $pc['id'], 'estado' => 'pendiente', 'motivo' => null,
                'inicio' => null, 'segundos' => null, 'log' => null, 'cola' => array(), 'detalle' => array());
        }

        return array('modulo' => $modulo, 'seleccion' => $sel, 'estado' => $estado, 'ok' => null, 'pid' => getmypid(),
            'inicio' => date('Y-m-d H:i:s'), 'fin' => null, 'latido' => time(), 'pasos' => $pasos,
            'capturas' => array(), 'pdf' => null, 'video' => null, 'error' => null);
    }

    // ------------------------------------------------------------------ ejecutar

    /** Ejecuta los pasos elegidos (lo llama tras100.php). Devuelve el estado final. */
    public function ejecutar(string $id, $eleccion = null): array
    {
        $t = $this->trabajos->leer($id, 'trabajo');
        if (!$t) {
            throw new RuntimeException('Validación no encontrada: ' . $id);
        }
        if ($eleccion === null) {
            $previo = $this->trabajos->leer($id, 'tras100');
            $eleccion = $previo['seleccion'] ?? ($t['opciones']['tras100'] ?? array());
        }
        $sel = $this->seleccion($eleccion);
        $md = (array) ($t['modulo'] ?? array());
        $modulo = $md['carpeta'] ?? basename($t['origen']);
        $base = $this->carpetaModulos();
        $vars = array(
            '{php}' => PHP_BINARY,
            '{python}' => $this->python(),
            '{modulo}' => $modulo,
            '{carpeta_modulos}' => $base,
            '{displayName}' => is_string($md['displayName'] ?? null) ? $md['displayName'] : $modulo,
            '{description}' => is_string($md['description'] ?? null) ? $md['description'] : '',
            '{version}' => is_string($md['version'] ?? null) ? $md['version'] : '',
        );
        $vars['{capturas}'] = self::norm(strtr((string) ($this->conf['tras_100']['capturas'] ?? '{carpeta_modulos}/capturas/{modulo}/8.2b'), $vars));
        $vars['{manual_md}'] = $base . '/manuales/' . $modulo . '/MANUAL.md';
        $vars['{manual_pdf}'] = $base . '/manuales/' . $modulo . '/' . $modulo . '-manual.pdf';

        $pasos = $this->pasosDe($sel);
        $e = $this->estadoInicial($modulo, 'en_curso', $sel);
        // Lo que ya estaba en marcha antes de empezar no se para al final (puede ser de otra sesión)
        $e['puertos_antes'] = array();
        foreach ($pasos as $pc) {
            if (($pc['interno'] ?? '') === 'parar_puertos') {
                $todos = array_merge((array) ($pc['puertos'] ?? array()), isset($pc['mariadb']['puerto']) ? array((int) $pc['mariadb']['puerto']) : array());
                $e['puertos_antes'] = array_keys(self::escuchando($todos));
            }
        }
        $this->guardar($id, $e);
        $roto = null;
        try {
            foreach ($pasos as $i => $pc) {
                if (!empty($pc['siempre'])) {
                    continue;
                }
                if ($roto !== null) {
                    $e['pasos'][$i]['estado'] = 'omitido';
                    $e['pasos'][$i]['motivo'] = $roto;
                    continue;
                }
                $this->paso($id, $i, $pc, $vars, $e);
                if ($e['pasos'][$i]['estado'] === 'fallo') {
                    $roto = 'no se hace: falló «' . $e['pasos'][$i]['titulo'] . '»';
                }
                $this->guardar($id, $e);
            }
        } catch (Throwable $x) {
            $e['error'] = get_class($x) . ': ' . $x->getMessage();
        } finally {
            foreach ($pasos as $i => $pc) {
                if (!empty($pc['siempre'])) {
                    try {
                        $this->paso($id, $i, $pc, $vars, $e);
                    } catch (Throwable $x) {
                        $e['pasos'][$i]['estado'] = 'fallo';
                        $e['pasos'][$i]['motivo'] = $x->getMessage();
                    }
                }
            }
            foreach ($e['pasos'] as $i => $p) {
                if (in_array($p['estado'], array('pendiente', 'en_curso'), true)) {
                    $e['pasos'][$i]['estado'] = 'fallo';
                    $e['pasos'][$i]['motivo'] = $e['error'] ?: 'no terminó';
                }
            }
            $e['ok'] = $e['error'] === null && !array_filter($e['pasos'], function ($p) {
                return $p['estado'] === 'fallo';
            });
            $e['estado'] = 'terminado';
            $e['fin'] = date('Y-m-d H:i:s');
            $this->guardar($id, $e);
            $lock = $this->cerrojo($modulo);
            if (is_file($lock) && trim((string) file_get_contents($lock)) === $id) {
                @unlink($lock);
            }
        }

        return $e;
    }

    /**
     * Espera a que termine (o se interrumpa) y devuelve el estado final. $aviso recibe una línea
     * cada vez que un paso cambia de estado.
     */
    public function esperar(string $id, ?callable $aviso = null, int $maxSegundos = 7200): ?array
    {
        $visto = array();
        $t0 = time();
        while (true) {
            $e = $this->estado($id);
            if ($e === null) {
                return null;
            }
            foreach ($e['pasos'] as $p) {
                $clave = $p['id'] . ':' . $p['estado'];
                if ($aviso && !isset($visto[$clave]) && $p['estado'] !== 'pendiente') {
                    $visto[$clave] = true;
                    $aviso(self::lineaPaso($p));
                }
            }
            if (!$e['vivo'] || time() - $t0 > $maxSegundos) {
                return $e;
            }
            sleep(3);
        }
    }

    public static function lineaPaso(array $p): string
    {
        $marca = array('ok' => '[OK]', 'fallo' => '[XX]', 'omitido' => '[--]', 'en_curso' => '[..]', 'pendiente' => '[  ]');
        $txt = ($marca[$p['estado']] ?? '[??]') . ' ' . $p['titulo'];
        if ($p['segundos'] !== null && $p['estado'] !== 'en_curso') {
            $txt .= ' (' . $p['segundos'] . ' s)';
        }
        if (!empty($p['motivo'])) {
            $txt .= ': ' . $p['motivo'];
        }

        return $txt;
    }

    private function python(): string
    {
        $p = (string) ($this->conf['tras_100']['python'] ?? '');

        return $p !== '' && is_file($p) ? $p : 'python';
    }

    /** Ejecuta un paso y deja su resultado en $e['pasos'][$i]. */
    private function paso(string $id, int $i, array $pc, array $vars, array &$e): void
    {
        $p = &$e['pasos'][$i];
        if (($pc['interno'] ?? '') === 'parar_puertos') {
            $t0 = time();
            $p['estado'] = 'en_curso';
            $p['inicio'] = date('Y-m-d H:i:s');
            $this->guardar($id, $e);
            $r = $this->pararPuertos($pc, (array) ($e['puertos_antes'] ?? array()));
            $p['detalle'] = $r;
            $p['segundos'] = time() - $t0;
            $p['estado'] = $r['siguen'] ? 'fallo' : 'ok';
            $p['motivo'] = $r['siguen'] ? 'siguen escuchando: ' . implode(', ', $r['siguen']) : null;

            return;
        }
        $orden = array();
        foreach ((array) $pc['orden'] as $x) {
            $orden[] = strtr((string) $x, $vars);
        }
        $cwd = $this->raiz;
        foreach ($orden as $k => $x) {
            $esRuta = strpbrk($x, '/\\') !== false && preg_match('#\.(py|php|exe|bat|cmd|sh)$#i', $x);
            if ($esRuta && !is_file($x)) {
                $p['estado'] = 'omitido';
                $p['motivo'] = 'no existe ' . $x;

                return;
            }
            if ($esRuta && $k > 0 && $cwd === $this->raiz) {
                $cwd = dirname($x);
            }
        }
        $p['estado'] = 'en_curso';
        $p['inicio'] = date('Y-m-d H:i:s');
        $this->guardar($id, $e);
        $t0 = time();
        if (($pc['preparar'] ?? '') === 'manual') {
            $p['detalle'] = $this->prepararManual($vars);
        }
        $log = $this->trabajos->dir($id) . '/tras100-' . $pc['id'] . '.log';
        $p['log'] = basename($log);
        $fh = fopen($log, 'w');
        $entorno = getenv();
        $entorno['PYTHONIOENCODING'] = 'utf-8';
        $entorno['PYTHONUTF8'] = '1';
        $proc = proc_open($orden, array(0 => array('pipe', 'r'), 1 => $fh, 2 => $fh), $pipes, $cwd, $entorno, array('bypass_shell' => true));
        if (!is_resource($proc)) {
            fclose($fh);
            $p['estado'] = 'fallo';
            $p['motivo'] = 'no se pudo lanzar: ' . implode(' ', $orden);

            return;
        }
        fclose($pipes[0]);
        $limite = isset($pc['segundos']) ? max(1, (int) $pc['segundos']) : max(1, (int) ($pc['minutos'] ?? 30)) * 60;
        $ultimo = time();
        $codigo = null;
        $agotado = false;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $codigo = $st['exitcode'];
                break;
            }
            if (time() - $t0 > $limite) {
                proc_terminate($proc);
                $agotado = true;
                break;
            }
            if (time() - $ultimo >= 5) {
                $ultimo = time();
                $p['segundos'] = time() - $t0;
                $p['cola'] = self::cola($log, 6);
                $this->guardar($id, $e);
            }
            usleep(500000);
        }
        $cierre = proc_close($proc);
        if ($codigo === null || $codigo === -1) {
            $codigo = $cierre;
        }
        fclose($fh);
        $p['segundos'] = time() - $t0;
        $p['cola'] = self::cola($log, 15);
        $salida = (string) @file_get_contents($log);

        $fallo = $agotado ? 'tiempo agotado (' . $limite . ' s)' : null;
        if ($fallo === null && $codigo !== 0) {
            $fallo = 'salió con código ' . $codigo;
        }
        if ($fallo === null && !empty($pc['exige']) && strpos($salida, (string) $pc['exige']) === false) {
            $fallo = 'la salida no dice «' . $pc['exige'] . '»';
        }
        if ($fallo === null && !empty($pc['produce'])) {
            $fallo = $this->comprobarProducto(strtr((string) $pc['produce'], $vars), $t0, $vars, $p, $e);
        }
        $p['estado'] = $fallo === null ? 'ok' : 'fallo';
        $p['motivo'] = $fallo;
    }

    /** null si el paso dejó su resultado recién hecho; si no, el motivo. */
    private function comprobarProducto(string $produce, int $t0, array $vars, array &$p, array &$e): ?string
    {
        list($tipo, $ruta) = array_pad(explode(':', $produce, 2), 2, '');
        if ($tipo === 'carpeta') {
            clearstatcache();
            $todas = self::pngs($ruta);
            $nuevas = array_filter($todas, function ($f) use ($t0) {
                return filemtime($f) >= $t0 - 2;
            });
            $p['detalle']['capturas'] = count($todas);
            $p['detalle']['nuevas'] = count($nuevas);
            if (!$nuevas) {
                return 'no ha dejado capturas nuevas en ' . $ruta;
            }
            $e['capturas'] = array_values($todas);

            return null;
        }
        clearstatcache();
        if (!is_file($ruta) || filemtime($ruta) < $t0 - 2) {
            return 'no ha generado ' . $ruta;
        }
        $p['detalle']['bytes'] = filesize($ruta);
        if (preg_match('#\.pdf$#i', $ruta)) {
            $n = preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($ruta));
            $caps = count(self::pngs($vars['{capturas}']));
            $p['detalle']['imagenes'] = $n;
            $p['detalle']['capturas'] = $caps;
            if ($n < $caps) {
                return 'el PDF lleva ' . $n . ' imágenes y hay ' . $caps . ' capturas';
            }
            $e['pdf'] = $ruta;
            if (!$e['capturas']) {
                $e['capturas'] = self::pngs($vars['{capturas}']);
            }
        } elseif (preg_match('#\.(mp4|webm)$#i', $ruta)) {
            $e['video'] = $ruta;
        }

        return null;
    }

    /**
     * MANUAL.md con TODAS las capturas: si no existe, sale de la guía de usuario; las capturas que el
     * texto no cita van en un bloque al final, que se rehace en cada pasada.
     */
    public function prepararManual(array $vars): array
    {
        $md = $vars['{manual_md}'];
        $dir = dirname($md);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fuente = 'MANUAL.md';
        if (is_file($md)) {
            $texto = (string) file_get_contents($md);
        } else {
            $origen = null;
            foreach (array('GUIA_USUARIO.md', 'DOC-usuario.md', 'MANUAL_USUARIO.md', 'GUIA.md') as $n) {
                if (is_file($dir . '/' . $n)) {
                    $origen = $dir . '/' . $n;
                    break;
                }
            }
            if ($origen === null) {
                foreach (glob($dir . '/*.md') ?: array() as $f) {
                    if (stripos(basename($f), 'usuario') !== false) {
                        $origen = $f;
                        break;
                    }
                }
            }
            $texto = $origen ? (string) file_get_contents($origen)
                : '# ' . $vars['{displayName}'] . "\n\n" . $vars['{description}'] . "\n";
            $fuente = $origen ? basename($origen) : 'nuevo';
        }
        $antes = $texto;
        $texto = (string) preg_replace('#\s*' . preg_quote(self::BLOQUE_INI, '#') . '.*?' . preg_quote(self::BLOQUE_FIN, '#') . '\s*#s', "\n", $texto);
        $caps = self::pngs($vars['{capturas}']);
        preg_match_all('#\]\(\s*<?([^)>\s]+\.png)#i', $texto, $mm);
        $citadas = array_map(function ($x) {
            return strtolower(basename(rawurldecode($x)));
        }, $mm[1]);
        $faltan = array_values(array_filter($caps, function ($f) use ($citadas) {
            return !in_array(strtolower(basename($f)), $citadas, true);
        }));
        if ($faltan) {
            $rel = self::relativa($dir, $vars['{capturas}']);
            $b = "\n\n" . self::BLOQUE_INI . "\n## Pantallas del módulo\n\n";
            foreach ($faltan as $f) {
                $nombre = basename($f);
                $b .= '![' . self::tituloCaptura($nombre) . '](' . $rel . '/' . str_replace(' ', '%20', $nombre) . ")\n\n";
            }
            $texto = rtrim($texto) . $b . self::BLOQUE_FIN . "\n";
        } else {
            $texto = rtrim($texto) . "\n";
        }
        if ($texto !== $antes || !is_file($md)) {
            file_put_contents($md, $texto);
        }

        return array('fuente' => $fuente, 'capturas' => count($caps), 'anadidas' => count($faltan));
    }

    /**
     * Para SOLO lo que se arrancó durante el trabajo: los puertos que escuchan ahora y no escuchaban
     * al empezar. MariaDB se apaga con su orden de parada (matarla deja el registro Aria roto).
     */
    private function pararPuertos(array $pc, array $antes): array
    {
        $puertos = array_map('intval', (array) ($pc['puertos'] ?? array()));
        $db = isset($pc['mariadb']['puerto']) ? (int) $pc['mariadb']['puerto'] : 0;
        $ahora = self::escuchando(array_merge($puertos, $db ? array($db) : array()));
        $parados = array();
        $respetados = array();
        foreach ($ahora as $puerto => $pids) {
            if (in_array($puerto, $antes, true)) {
                $respetados[] = $puerto;
                continue;
            }
            if ($puerto === $db) {
                $orden = (array) ($pc['mariadb']['apagar'] ?? array());
                if ($orden && is_file($orden[0])) {
                    $pr = proc_open($orden, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pp, dirname($orden[0]), null, array('bypass_shell' => true));
                    if (is_resource($pr)) {
                        fclose($pp[0]);
                        stream_get_contents($pp[1]);
                        stream_get_contents($pp[2]);
                        proc_close($pr);
                    }
                }
                $parados[] = $puerto;
                continue;
            }
            foreach ($pids as $pid) {
                if ($pid > 0 && $pid !== getmypid()) {
                    self::matar($pid);
                }
            }
            $parados[] = $puerto;
        }
        $siguen = array();
        for ($i = 0; $i < 20; ++$i) {
            $siguen = array_values(array_intersect($parados, array_keys(self::escuchando($parados))));
            if (!$siguen) {
                break;
            }
            sleep(1);
        }

        return array('parados' => $parados, 'ya_estaban' => $respetados, 'siguen' => $siguen);
    }

    /** @return array<int, int[]> puerto => PID que escuchan en 127.0.0.1/0.0.0.0 */
    public static function escuchando(array $puertos): array
    {
        $out = array();
        if (!$puertos) {
            return $out;
        }
        $quiero = array_flip(array_map('intval', $puertos));
        if (DIRECTORY_SEPARATOR === '\\') {
            foreach (preg_split('#\R#', (string) shell_exec('netstat -ano -p TCP')) as $l) {
                if (preg_match('#^\s*TCP\s+\S+:(\d+)\s+\S+\s+LISTENING\s+(\d+)#i', $l, $m) && isset($quiero[(int) $m[1]])) {
                    $out[(int) $m[1]][] = (int) $m[2];
                }
            }
        } else {
            foreach (array_keys($quiero) as $pu) {
                $pids = array_filter(array_map('intval', preg_split('#\s+#', trim((string) shell_exec('lsof -nP -iTCP:' . $pu . ' -sTCP:LISTEN -t 2>/dev/null')))));
                if ($pids) {
                    $out[$pu] = array_values($pids);
                }
            }
        }
        foreach ($out as $k => $v) {
            $out[$k] = array_values(array_unique($v));
        }

        return $out;
    }

    private static function matar(int $pid): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // taskkill no funciona en todas las sesiones de esta máquina; Stop-Process sí
            shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue"');
        } elseif (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        } else {
            shell_exec('kill -9 ' . $pid);
        }
    }

    // ------------------------------------------------------------------ utilidades

    public static function norm(string $r): string
    {
        return rtrim(str_replace('\\', '/', $r), '/');
    }

    /** @return string[] PNG de la carpeta, ordenados por nombre */
    public static function pngs(string $dir): array
    {
        $out = glob(self::norm($dir) . '/*.png') ?: array();
        sort($out);

        return $out;
    }

    public static function tituloCaptura(string $fichero): string
    {
        $t = trim(preg_replace('#[-_]+#', ' ', preg_replace('#\.png$#i', '', $fichero)));
        $t = preg_replace('#^\d+\s+#', '', $t);

        return $t === '' ? $fichero : mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    /** Ruta relativa de $desde (carpeta) a $hasta (carpeta), con «/». */
    public static function relativa(string $desde, string $hasta): string
    {
        $a = explode('/', strtolower(self::norm($desde)));
        $b = explode('/', self::norm($hasta));
        $bl = array_map('strtolower', $b);
        $n = 0;
        while ($n < count($a) && $n < count($bl) && $a[$n] === $bl[$n]) {
            ++$n;
        }

        return implode('/', array_merge(array_fill(0, count($a) - $n, '..'), array_slice($b, $n))) ?: '.';
    }

    /** Últimas $n líneas no vacías de un registro. */
    public static function cola(string $f, int $n): array
    {
        $txt = (string) @file_get_contents($f);
        if (function_exists('mb_convert_encoding') && !mb_check_encoding($txt, 'UTF-8')) {
            $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        }
        $lineas = array_values(array_filter(array_map('rtrim', preg_split('#\r\n|\r|\n#', $txt)), 'strlen'));

        return array_slice($lineas, -$n);
    }
}
