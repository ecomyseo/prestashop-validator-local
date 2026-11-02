<?php
/**
 * Orquesta las nueve pestañas del validator oficial sobre un módulo (carpeta o ZIP).
 */
class VlValidador
{
    const VERSION_OFICIAL = '5.14.6';

    /** @var string */
    private $raiz;

    public function __construct(string $raiz)
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/');
    }

    /**
     * @param array $op ['solo' => [...], 'sin' => [...], 'ps' => [...]|null, 'todas_ps' => bool]
     */
    public function validar(string $ruta, array $op = array()): array
    {
        $inicio = microtime(true);
        $m = new VlModulo($ruta, $this->raiz);
        $tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_run_' . substr(md5($ruta . microtime(true)), 0, 10);
        @mkdir($tmp, 0777, true);
        $c = new VlComprobaciones($m);
        $h = new VlHerramientas($this->raiz);
        $pasos = array();
        $tiempos = array();
        $versionesPhpstan = array();
        foreach (array_keys(VlGoals::PASOS) as $id) {
            $p = new VlPaso($id);
            $pasos[$id] = $p;
            if (!empty($op['solo']) && !in_array($id, $op['solo'], true)) {
                $p->avisosInternos[] = 'Paso no ejecutado (--solo).';
                $p->omitido = true;
                continue;
            }
            if (!empty($op['sin']) && in_array($id, $op['sin'], true)) {
                $p->avisosInternos[] = 'Paso no ejecutado (--sin).';
                $p->omitido = true;
                continue;
            }
            $t0 = microtime(true);
            try {
                switch ($id) {
                    case 'errors':
                        $c->errors($p, $tmp);
                        break;
                    case 'compatibility':
                        $versionesPhpstan = $h->compatibility($m, $p, $op['ps'] ?? null, !empty($op['todas_ps']), $tmp);
                        $c->deprecados($p);
                        break;
                    case 'standards':
                        $h->standards($m, $p, $tmp);
                        break;
                    default:
                        $c->$id($p);
                }
            } catch (Throwable $e) {
                $p->fallo = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            $tiempos[$id] = round(microtime(true) - $t0, 2);
        }
        VlModulo::borrarArbol($tmp);

        return array(
            'modulo' => $m,
            'pasos' => $pasos,
            'tiempos' => $tiempos,
            'phpstan' => $versionesPhpstan,
            'duracion' => round(microtime(true) - $inicio, 1),
            'fecha' => date('Y-m-d H:i:s'),
        );
    }

    public static function totales(array $res): array
    {
        $t = array('error' => 0, 'warning' => 0, 'notice' => 0, 'fallos' => 0, 'goals' => 0, 'goals_ok' => 0);
        foreach ($res['pasos'] as $p) {
            $t['error'] += $p->types['error'];
            $t['warning'] += $p->types['warning'];
            $t['notice'] += $p->types['notice'];
            if ($p->fallo !== null) {
                ++$t['fallos'];
            }
            if (!empty($p->omitido)) {
                continue;
            }
            foreach ($p->goals as $g => $n) {
                ++$t['goals'];
                if ($n === 0 || VlGoals::get($g)['tipo'] === 'notice') {
                    ++$t['goals_ok'];
                }
            }
        }

        return $t;
    }
}
