<?php
/**
 * Una validación guardada en disco (trabajos/<id>/), para que la web de localhost la pinte
 * pestaña a pestaña igual que el oficial (/module/<id>/validate/<paso>).
 *
 *   trabajos/<id>/trabajo.json   origen (carpeta o ZIP subido), opciones, identidad del módulo
 *   trabajos/<id>/<paso>.json    resultado del paso (forma JSON del oficial + goals)
 */
class VlTrabajo
{
    /** @var string */
    private $raiz;

    public function __construct(string $raiz)
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/');
    }

    public function dir(string $id): string
    {
        return $this->raiz . '/trabajos/' . $id;
    }

    public static function idValido(string $id): bool
    {
        return (bool) preg_match('#^[a-z0-9_]+-\d{8}-\d{6}(-\d+)?$#', $id);
    }

    public function crear(string $origen, array $opciones = array(), ?string $nombre = null): string
    {
        $base = $nombre ?: basename(preg_replace('#\.zip$#i', '', str_replace('\\', '/', $origen)));
        $base = strtolower(preg_replace('#[^a-z0-9_]+#i', '_', $base)) ?: 'modulo';
        $id = $base . '-' . date('Ymd-His');
        $n = 1;
        while (is_dir($this->dir($id))) {
            $id = $base . '-' . date('Ymd-His') . '-' . (++$n);
        }
        @mkdir($this->dir($id), 0777, true);
        $this->guardar($id, 'trabajo', array(
            'id' => $id,
            'origen' => str_replace('\\', '/', $origen),
            'tipo' => is_dir($origen) ? 'carpeta' : 'zip',
            'opciones' => $opciones,
            'creado' => date('Y-m-d H:i:s'),
        ));

        return $id;
    }

    public function guardar(string $id, string $nombre, array $datos): void
    {
        file_put_contents($this->dir($id) . '/' . $nombre . '.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function leer(string $id, string $nombre): ?array
    {
        $f = $this->dir($id) . '/' . $nombre . '.json';
        if (!self::idValido($id) || !is_file($f)) {
            return null;
        }
        $d = json_decode(file_get_contents($f), true);

        return is_array($d) ? $d : null;
    }

    public static function serializarPaso(VlPaso $p, ?float $tiempo = null): array
    {
        return array(
            'oficial' => $p->aOficial(),
            'files' => $p->files,
            'readMoreLinks' => $p->readMoreLinks,
            'code' => $p->code,
            'types' => $p->types,
            'goals' => $p->goals,
            'muestras' => $p->muestras,
            'fallo' => $p->fallo,
            'avisosInternos' => $p->avisosInternos,
            'omitido' => $p->omitido,
            'tiempo' => $tiempo,
        );
    }

    public static function hidratarPaso(string $id, array $d): VlPaso
    {
        $p = new VlPaso($id);
        foreach (array('files', 'readMoreLinks', 'code', 'types', 'goals', 'muestras', 'avisosInternos') as $k) {
            if (isset($d[$k]) && is_array($d[$k])) {
                $p->$k = $d[$k];
            }
        }
        $p->fallo = $d['fallo'] ?? null;
        $p->omitido = !empty($d['omitido']);

        return $p;
    }

    /** Guarda en el trabajo un resultado completo del validador (lo usa la línea de órdenes). */
    public function guardarResultado(string $id, array $res): void
    {
        $t = $this->leer($id, 'trabajo');
        $t['modulo'] = $this->identidad($res['modulo']);
        $t['phpstan'] = $res['phpstan'];
        $t['terminado'] = $res['fecha'];
        $this->guardar($id, 'trabajo', $t);
        foreach ($res['pasos'] as $pid => $p) {
            $this->guardar($id, $pid, self::serializarPaso($p, $res['tiempos'][$pid] ?? null));
        }
    }

    public function identidad(VlModulo $m): array
    {
        $out = array('carpeta' => $m->carpeta, 'compatibilidad' => $m->compatibilidad());
        foreach ($m->id as $k => $v) {
            if (strpos($k, '__') !== 0 && (is_scalar($v) || is_array($v))) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Ejecuta (o devuelve ya hecho) un paso del trabajo. */
    public function paso(string $id, string $paso, VlValidador $v): array
    {
        $hecho = $this->leer($id, $paso);
        if ($hecho !== null) {
            return $hecho;
        }
        $t = $this->leer($id, 'trabajo');
        if (!$t) {
            throw new RuntimeException('Validación no encontrada: ' . $id);
        }
        $op = (array) ($t['opciones'] ?? array());
        $op['solo'] = array($paso);
        unset($op['sin']);
        $res = $v->validar($t['origen'], $op);
        if (empty($t['modulo'])) {
            $t['modulo'] = $this->identidad($res['modulo']);
        }
        if ($paso === 'compatibility') {
            $t['phpstan'] = $res['phpstan'];
        }
        $this->guardar($id, 'trabajo', $t);
        $datos = self::serializarPaso($res['pasos'][$paso], $res['tiempos'][$paso] ?? null);
        $this->guardar($id, $paso, $datos);

        return $datos;
    }

    /** Reconstruye un resultado completo (para el TXT) con los pasos ya hechos. */
    public function resultado(string $id): ?array
    {
        $t = $this->leer($id, 'trabajo');
        if (!$t) {
            return null;
        }
        $m = new VlModulo($t['origen'], $this->raiz);
        $pasos = array();
        foreach (array_keys(VlGoals::PASOS) as $pid) {
            $d = $this->leer($id, $pid);
            $pasos[$pid] = $d ? self::hidratarPaso($pid, $d) : new VlPaso($pid);
            if (!$d) {
                $pasos[$pid]->omitido = true;
            }
        }

        return array('modulo' => $m, 'pasos' => $pasos, 'tiempos' => array(), 'phpstan' => $t['phpstan'] ?? array(), 'duracion' => 0, 'fecha' => $t['terminado'] ?? $t['creado']);
    }

    /** ¿Están las 9 pestañas hechas, sin fallos, con 0 errores y 0 avisos? */
    public function es100(string $id): bool
    {
        foreach (array_keys(VlGoals::PASOS) as $pid) {
            $d = $this->leer($id, $pid);
            if (!$d || !empty($d['fallo']) || (int) ($d['types']['error'] ?? 0) + (int) ($d['types']['warning'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /** Validaciones guardadas, la más reciente primero. */
    public function lista(int $max = 200): array
    {
        $out = array();
        foreach (glob($this->raiz . '/trabajos/*/trabajo.json') ?: array() as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) {
                continue;
            }
            $tot = array('error' => 0, 'warning' => 0, 'hechos' => 0);
            foreach (array_keys(VlGoals::PASOS) as $pid) {
                $pf = dirname($f) . '/' . $pid . '.json';
                if (is_file($pf)) {
                    $pd = json_decode(file_get_contents($pf), true);
                    $tot['error'] += (int) ($pd['types']['error'] ?? 0);
                    $tot['warning'] += (int) ($pd['types']['warning'] ?? 0);
                    ++$tot['hechos'];
                }
            }
            $d['totales'] = $tot;
            $d['_mtime'] = filemtime($f);
            $out[] = $d;
        }
        usort($out, function ($a, $b) {
            return strcmp($b['creado'], $a['creado']);
        });

        return array_slice($out, 0, $max);
    }
}
