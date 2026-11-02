<?php
/**
 * Pestañas que el oficial resuelve con herramientas externas:
 *  - Compatibility: PHPStan 1.12.33, nivel 5, extensión de php-dev-tools, contra cada PrestaShop declarado.
 *  - Standards:     PHP CS Fixer 3.95.4 con la configuración de php-dev-tools v4.3.0/v5.
 *
 * Calibrado el 2026-11-02 con el módulo del generador oficial: el oficial dio Compatibility 7 y
 * Standards 378; esto da 7 y 378.
 */

use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Tokenizer\Tokens;

class VlHerramientas
{
    /** @var string */
    private $raiz;
    /** @var array */
    private $conf;

    public function __construct(string $raiz)
    {
        $this->raiz = $raiz;
        $this->conf = json_decode(file_get_contents($raiz . '/reglas/prestashop.json'), true);
    }

    private function php(): string
    {
        $p = $this->conf['php'] ?? '';

        return $p && is_file($p) ? $p : PHP_BINARY;
    }

    /** @return array{0: int, 1: string, 2: string} código, stdout, stderr */
    private function ejecutar(array $cmd, string $cwd, array $env = array()): array
    {
        $entorno = getenv();
        foreach ($env as $k => $v) {
            $entorno[$k] = $v;
        }
        $desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $proc = proc_open($cmd, $desc, $pipes, $cwd, $entorno, array('bypass_shell' => true));
        if (!is_resource($proc)) {
            return array(-1, '', 'No se pudo lanzar: ' . implode(' ', $cmd));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        while (true) {
            $st = proc_get_status($proc);
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            if (!$st['running']) {
                break;
            }
            usleep(50000);
        }
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        if (isset($st['exitcode']) && $st['exitcode'] !== -1) {
            $rc = $st['exitcode'];
        }

        return array($rc, $out, $err);
    }

    /** Versiones de PrestaShop que caen dentro de ps_versions_compliancy. */
    public function versionesPara(VlModulo $m, ?array $forzadas = null, bool $todas = false): array
    {
        $disp = $this->conf['versiones'];
        if ($forzadas) {
            $out = array();
            foreach ($forzadas as $v) {
                if (isset($disp[$v])) {
                    $out[$v] = $disp[$v];
                }
            }

            return $out;
        }
        $c = $m->compatibilidad();
        $out = array();
        foreach ($disp as $v => $ruta) {
            if (version_compare($v, $c['min'], '<')) {
                continue;
            }
            if ($c['max'] !== '99') {
                $pm = explode('.', $c['max']);
                $pv = array_slice(explode('.', $v), 0, count($pm));
                if (version_compare(implode('.', $pv), $c['max'], '>')) {
                    continue;
                }
            }
            if (is_dir($ruta)) {
                $out[$v] = $ruta;
            }
        }
        uksort($out, 'version_compare');
        if (!$todas && $out) {
            $ultima = array_key_last($out);

            return array($ultima => $out[$ultima]);
        }

        return $out;
    }

    // ===================================================================== Compatibility

    public function compatibility(VlModulo $m, VlPaso $p, ?array $forzadas, bool $todas, string $tmp): array
    {
        $versiones = $this->versionesPara($m, $forzadas, $todas);
        if (!$versiones) {
            $p->avisosInternos[] = 'Ninguna versión de PrestaShop local cae en el rango declarado: no se ha ejecutado PHPStan.';

            return array();
        }
        $phar = $this->raiz . '/vendor/phpstan/phpstan/phpstan.phar';
        $vistos = array();
        $resumen = array();
        foreach ($versiones as $v => $ruta) {
            $neon = $tmp . '/phpstan-' . $v . '.neon';
            $cache = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_phpstan/' . $v;
            @mkdir($cache, 0777, true);
            file_put_contents($neon, implode("\n", array(
                'includes:',
                '  - "' . $this->raiz . '/reglas/phpstan/ps-module-extension.neon"',
                'parameters:',
                '  level: 5',
                '  paths:',
                '    - "' . $m->dir . '"',
                '  excludePaths:',
                '    - "*/vendor/*"',
                '  tmpDir: "' . $cache . '"',
                '  reportUnmatchedIgnoredErrors: false',
                '',
            )));
            list($rc, $out, $err) = $this->ejecutar(
                array($this->php(), '-d', 'memory_limit=-1', $phar, 'analyse', '-c', $neon, '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=-1'),
                $tmp,
                array('_PS_ROOT_DIR_' => $ruta, 'VL_MODULE_DIR' => $m->dir)
            );
            $json = json_decode(trim($out), true);
            if (!is_array($json) || !isset($json['files'])) {
                $p->fallo = 'PHPStan (' . $v . ') no ha devuelto un resultado: ' . trim(substr($err . ' ' . $out, 0, 600));

                return $resumen;
            }
            $n = 0;
            foreach ($json['files'] as $fich => $datos) {
                $rel = self::relativa($m->dir, $fich);
                foreach ($datos['messages'] as $msg) {
                    $l = (int) ($msg['line'] ?? 0);
                    $k = $rel . '|' . $l . '|' . $msg['message'];
                    ++$n;
                    if (isset($vistos[$k])) {
                        continue;
                    }
                    $vistos[$k] = true;
                    $p->addCode('CMP_PHPSTAN', $rel, $msg['message'], $l, $m->linea($rel, $l), $m->bloque($rel, $l));
                }
            }
            foreach ($json['errors'] ?? array() as $e) {
                $p->avisosInternos[] = 'PHPStan ' . $v . ': ' . $e;
            }
            $resumen[$v] = $n;
        }

        return $resumen;
    }

    public static function relativa(string $dir, string $fich): string
    {
        $d = strtolower(rtrim(str_replace('\\', '/', $dir), '/'));
        $f = str_replace('\\', '/', $fich);
        if (strpos(strtolower($f), $d) === 0) {
            return substr($f, strlen($d));
        }

        return '/' . basename($f);
    }

    // ===================================================================== Standards

    public function standards(VlModulo $m, VlPaso $p, string $tmp): void
    {
        $reglas = $this->raiz . '/reglas/php-cs-fixer.reglas.php';
        $conf = $tmp . '/php-cs-fixer.conf.php';
        file_put_contents($conf, "<?php\n"
            . '$c = new PhpCsFixer\\Config(\'PrestaShop coding standard\');' . "\n"
            . '$c->setRiskyAllowed(true);' . "\n"
            . '$c->setUsingCache(false);' . "\n"
            . '$c->setRules(require ' . var_export($reglas, true) . ');' . "\n"
            . '$c->setFinder(PhpCsFixer\\Finder::create()->in(' . var_export($m->dir, true) . '));' . "\n"
            . 'return $c;' . "\n");
        list($rc, $out, $err) = $this->ejecutar(
            array($this->php(), '-d', 'memory_limit=-1', $this->raiz . '/vendor/friendsofphp/php-cs-fixer/php-cs-fixer', 'fix', '--config=' . $conf, '--dry-run', '--diff', '--format=json', '-v', '--no-interaction'),
            $tmp,
            array('PHP_CS_FIXER_IGNORE_ENV' => '1')
        );
        $json = json_decode(trim($out), true);
        if (!is_array($json) || !isset($json['files'])) {
            $p->fallo = 'PHP CS Fixer no ha devuelto un resultado: ' . trim(substr($err . ' ' . $out, 0, 600));

            return;
        }
        $factory = new FixerFactory();
        $factory->registerBuiltInFixers();
        $factory->useRuleSet(new RuleSet(require $reglas));
        $fixers = array();
        foreach ($factory->getFixers() as $fx) {
            $fixers[$fx->getName()] = $fx;
        }
        $locator = class_exists('PhpCsFixer\\Documentation\\DocumentationLocator') ? new PhpCsFixer\Documentation\DocumentationLocator() : null;
        foreach ($json['files'] as $f) {
            $rel = self::relativa($m->dir, $f['name']);
            $aplicados = $f['appliedFixers'] ?? array();
            $quitadas = self::lineasQuitadas($f['diff'] ?? '');
            if (!$quitadas) {
                continue;
            }
            // Qué regla toca cada línea: cada fixer aplicado por separado sobre el original
            $original = $m->leer($rel);
            $porFixer = array();
            foreach ($aplicados as $nombre) {
                if (!isset($fixers[$nombre])) {
                    continue;
                }
                $porFixer[$nombre] = self::lineasDeFixer($fixers[$nombre], $original, $m->abs($rel));
            }
            foreach ($quitadas as $l => $contenido) {
                $nombre = null;
                foreach ($aplicados as $a) {
                    if (isset($porFixer[$a][$l])) {
                        $nombre = $a;
                        break;
                    }
                }
                if ($nombre === null) {
                    $nombre = $aplicados[0] ?? 'php_cs_fixer';
                }
                $fx = $fixers[$nombre] ?? null;
                $mensaje = $fx ? $fx->getDefinition()->getSummary() : $nombre;
                $link = null;
                if ($fx && $locator) {
                    $link = 'https://cs.symfony.com/doc/rules/' . substr($locator->getFixerDocumentationFileRelativePath($fx), 0, -4) . '.html';
                }
                $p->addCode('STD_PHP_CS_FIXER', $rel, $mensaje, $l, rtrim($contenido), $m->bloque($rel, $l), null, $link);
            }
        }
    }

    /** Líneas "-" de un diff unificado, con su número en el fichero original. */
    public static function lineasQuitadas(string $diff): array
    {
        $out = array();
        $lin = 0;
        foreach (preg_split("/\r\n|\n/", $diff) as $l) {
            if (preg_match('#^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@#', $l, $mm)) {
                $lin = (int) $mm[1];
                continue;
            }
            // cabeceras "--- / +++" solo antes del primer @@ (una línea quitada puede empezar por "--")
            if ($lin === 0) {
                continue;
            }
            if ($l === '') {
                continue;
            }
            $c = $l[0];
            if ($c === '-') {
                $out[$lin] = substr($l, 1);
                ++$lin;
            } elseif ($c === ' ') {
                ++$lin;
            }
        }

        return $out;
    }

    /** Líneas del original que cambia un fixer aplicado él solo. */
    private static function lineasDeFixer($fixer, string $codigo, string $ruta): array
    {
        try {
            Tokens::clearCache();
            $t = Tokens::fromCode($codigo);
            $fi = new SplFileInfo($ruta);
            if (!$fixer->supports($fi) || !$fixer->isCandidate($t)) {
                return array();
            }
            $fixer->fix($fi, $t);
            $nuevo = $t->generateCode();
            if ($nuevo === $codigo) {
                return array();
            }
            $differ = new SebastianBergmann\Diff\Differ(new SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder());
            $out = array();
            $lin = 1;
            foreach ($differ->diffToArray($codigo, $nuevo) as $e) {
                if ($e[1] === 0) {
                    ++$lin;
                } elseif ($e[1] === 2) {
                    $out[$lin] = true;
                    ++$lin;
                }
            }

            return $out;
        } catch (Throwable $e) {
            return array();
        }
    }
}
