<?php
/**
 * Arreglos automáticos SEGUROS (solo con --arreglar, y solo sobre una carpeta):
 *
 *  1. index.php en cada carpeta que no lo tenga (vendor/ y carpetas ignoradas fuera).
 *  2. .htaccess en la raíz con el ejemplo del checklist oficial, si no existe.
 *  3. if (!defined('_PS_VERSION_')) { exit; } en los PHP que no lo lleven (tras namespace/declare).
 *  4. PHP CS Fixer con la configuración del oficial (pestaña Standards).
 *  5. Cabeceras de licencia que falten o lleven línea en blanco delante, y @author en el bloque de fichero.
 *  6. config_xx.xml de la raíz fuera (los genera PrestaShop) y finales de línea mezclados a LF.
 *
 * No toca nada más: lo demás (SQL, Smarty, funciones prohibidas...) exige criterio.
 */
class VlArreglar
{
    /** @var string */
    private $raiz;

    public function __construct(string $raiz)
    {
        $this->raiz = $raiz;
    }

    const INDEX = "<?php\n/**\n * NOTICE OF LICENSE\n *\n * This source file is subject to the Academic Free License (AFL 3.0).\n *\n * @author    %s\n * @copyright %s\n * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)\n */\nheader('Expires: Mon, 26 Jul 1997 05:00:00 GMT');\nheader('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');\n\nheader('Cache-Control: no-store, no-cache, must-revalidate');\nheader('Cache-Control: post-check=0, pre-check=0', false);\nheader('Pragma: no-cache');\n\nheader('Location: ../');\nexit;\n";

    const HTACCESS = "# Apache 2.2\n<IfModule !mod_authz_core.c>\n   <Files *.php>\n   order allow,deny\n   deny from all\n   </Files>\n</IfModule>\n# Apache 2.4\n<IfModule mod_authz_core.c>\n   <Files *.php>\n   Require all denied\n   </Files>\n</IfModule>\n";

    public function arreglar(string $dir): array
    {
        $cambios = array();
        $m = new VlModulo($dir, $this->raiz);
        $autor = is_string($m->id['author'] ?? null) ? $m->id['author'] : 'Author';
        foreach (array_merge(array(''), $m->carpetas) as $d) {
            if ($d !== '' && $m->esIgnorado($d)) {
                continue;
            }
            if (!in_array($d . '/index.php', $m->ficheros, true)) {
                file_put_contents($m->dir . $d . '/index.php', sprintf(self::INDEX, $autor, date('Y') . ' ' . $autor));
                $cambios[] = 'creado ' . $d . '/index.php';
            }
        }
        if (!in_array('/.htaccess', $m->ficheros, true)) {
            file_put_contents($m->dir . '/.htaccess', self::HTACCESS);
            $cambios[] = 'creado /.htaccess';
        }
        // config_xx.xml: caché de PrestaShop, el oficial pide quitarlo (Structure)
        foreach ($m->ficheros as $f) {
            if (preg_match('#^/config_[a-z]{2}\.xml$#i', $f) && @unlink($m->abs($f))) {
                $cambios[] = 'borrado ' . $f;
            }
        }
        // Finales de línea mezclados (Optimizations y Licenses): todo a LF, que es lo que pide PHP CS Fixer
        foreach ($m->conExtension(array('php')) as $f) {
            if ($m->esIgnorado(dirname($f))) {
                continue;
            }
            $src = $m->leer($f);
            if (VlComprobaciones::finesMezclados($src)) {
                file_put_contents($m->abs($f), str_replace(array("\r\n", "\r"), "\n", $src));
                $cambios[] = 'finales de línea a LF en ' . $f;
            }
        }
        unset($m);
        $m = new VlModulo($dir, $this->raiz);
        $guardia = '#if\s*\(\s*!\s*defined\s*\(\s*([\'"])_PS_VERSION_\1\s*\)\s*\)\s*(\{\s*)?(exit|die)\b#i';
        foreach ($m->phps() as $f) {
            if (strtolower(basename($f)) === 'index.php' || $m->esIgnorado($f)) {
                continue;
            }
            if (preg_match($guardia, $m->phpSinComentarios($f))) {
                continue;
            }
            $src = $m->leer($f);
            $nuevo = self::insertarGuardia($src);
            if ($nuevo !== null && $nuevo !== $src) {
                file_put_contents($m->abs($f), $nuevo);
                $cambios[] = 'guarda _PS_VERSION_ en ' . $f;
            }
        }
        unset($m);
        $this->cabeceras($dir, $cambios);
        // PHP CS Fixer (configuración oficial), lo ÚLTIMO: deja en su forma lo que se ha añadido antes
        $conf = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_fix_' . md5($dir) . '.php';
        $reglas = $this->raiz . '/reglas/php-cs-fixer.reglas.php';
        file_put_contents($conf, "<?php\n" . '$c = new PhpCsFixer\\Config(\'PrestaShop coding standard\');' . "\n"
            . '$c->setRiskyAllowed(true); $c->setUsingCache(false);' . "\n"
            // sin blank_line_after_opening_tag: esa línea en blanco solo añade líneas «+» (no cuenta en
            // Standards) y en Licenses es un ERROR («There must be no blank lines before the file comment»)
            . '$c->setRules(array_merge(require ' . var_export($reglas, true) . ', array(\'blank_line_after_opening_tag\' => false)));' . "\n"
            . '$c->setFinder(PhpCsFixer\\Finder::create()->in(' . var_export(str_replace('\\', '/', realpath($dir)), true) . '));' . "\nreturn \$c;\n");
        $conf2 = json_decode(file_get_contents($this->raiz . '/reglas/prestashop.json'), true);
        $php = is_file($conf2['php'] ?? '') ? $conf2['php'] : PHP_BINARY;
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($this->raiz . '/vendor/friendsofphp/php-cs-fixer/php-cs-fixer')
            . ' fix --config=' . escapeshellarg($conf) . ' --format=json --no-interaction';
        $cwd = getcwd();
        chdir(sys_get_temp_dir());
        putenv('PHP_CS_FIXER_IGNORE_ENV=1');
        $out = shell_exec($cmd . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
        chdir($cwd);
        @unlink($conf);
        $j = json_decode((string) $out, true);
        if (is_array($j) && isset($j['files'])) {
            foreach ($j['files'] as $f) {
                $cambios[] = 'PHP CS Fixer: ' . VlHerramientas::relativa(str_replace('\\', '/', realpath($dir)), $f['name']);
            }
        } else {
            $cambios[] = 'PHP CS Fixer NO se ha podido aplicar: ' . substr((string) $out, 0, 300);
        }

        return $cambios;
    }

    /** Cabeceras (pestaña Licenses): bloque /** *\/ pegado a <?php en cada PHP con @author, cabecera en JS y TPL. */
    private function cabeceras(string $dir, array &$cambios): void
    {
        $m = new VlModulo($dir, $this->raiz);
        $autor = is_string($m->id['author'] ?? null) ? $m->id['author'] : 'Author';
        $regexLic = $m->reglas['Licenses']['Copyright regex'];
        foreach ($m->conExtension(array('php', 'js', 'tpl')) as $f) {
            if ($m->esIgnorado(dirname($f))) {
                continue;
            }
            $src = $m->leer($f);
            if (trim($src) === '') {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $nuevo = $src;
            if ($ext === 'php') {
                $estado = VlComprobaciones::docDeFichero($src);
                if ($estado === 'hueco') {
                    $nuevo = preg_replace('#^(<\?php)[ \t]*\R(?:[ \t]*\R)+#', "$1\n", $src, 1);
                } elseif ($estado === 'estilo') {
                    $nuevo = preg_replace('#^(<\?php\s*)/\*(?!\*)#', '$1/**', $src, 1);
                } elseif ($estado === 'falta' && preg_match('#^<\?php[ \t]*\R#', $src)) {
                    $nuevo = preg_replace('#^<\?php[ \t]*\R#', "<?php\n" . self::cabecera($autor, '/**', ' */') . "\n", $src, 1);
                }
                // @author en el bloque de fichero (Licenses: «Missing @author tag in file comment»)
                $doc = VlComprobaciones::bloqueDeFichero((string) $nuevo);
                if ($doc !== null && !preg_match('#@author\b#', $doc[0])) {
                    $conAutor = preg_replace('#\s*\*/$#', "\n *\n * @author    " . $autor . "\n */", $doc[0], 1);
                    $nuevo = substr_replace((string) $nuevo, $conAutor, strpos((string) $nuevo, $doc[0]), strlen($doc[0]));
                }
            } elseif (!preg_match($regexLic . 'm', substr($src, 0, 4000))) {
                $nuevo = $ext === 'tpl' ? self::cabecera($autor, '{**', ' *}') . "\n" . $src : self::cabecera($autor, '/**', ' */') . "\n" . $src;
            }
            if ($nuevo !== null && $nuevo !== $src) {
                file_put_contents($m->abs($f), $nuevo);
                $cambios[] = 'cabecera de licencia en ' . $f;
            }
        }
    }

    public static function cabecera(string $autor, string $abre, string $cierra): string
    {
        return $abre . "\n * NOTICE OF LICENSE\n *\n * This source file is subject to the Academic Free License version 3.0\n"
            . " * that is bundled with this package in the file LICENSE.md.\n *\n * @author    " . $autor . "\n"
            . ' * @copyright Since ' . date('Y') . ' ' . $autor . "\n"
            . " * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0\n" . $cierra;
    }

    /**
     * Inserta la guarda tras <?php + docblock de cabecera, y tras namespace/declare si los hay.
     * En un script que se abre por URL (cron, ajax) y carga él mismo config.inc.php / init.php, la guarda
     * va DESPUÉS de esa carga: delante, _PS_VERSION_ aún no existe y el script saldría en blanco siempre.
     */
    public static function insertarGuardia(string $src): ?string
    {
        $tokens = @token_get_all($src);
        $pos = null;
        $offset = 0;
        $despuesDe = null;
        $trasCarga = null;
        foreach ($tokens as $i => $t) {
            $txt = is_array($t) ? $t[1] : $t;
            if (is_array($t) && $t[0] === T_OPEN_TAG && $pos === null) {
                $pos = $offset + strlen($txt);
            }
            if (is_array($t) && in_array($t[0], array(T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE), true)) {
                $j = $offset;
                $sentencia = '';
                for ($k = $i, $n = count($tokens); $k < $n; ++$k) {
                    $tx = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    $j += strlen($tx);
                    $sentencia .= $tx;
                    if ($tokens[$k] === ';') {
                        break;
                    }
                }
                if (preg_match('#config\.inc\.php|(^|[/\\\\\'"])init\.php#i', $sentencia)) {
                    $trasCarga = $j;
                }
            }
            if (is_array($t) && ($t[0] === T_NAMESPACE || $t[0] === T_DECLARE)) {
                // hasta el ; que cierra la sentencia
                $j = $offset;
                $k = $i;
                $n = count($tokens);
                while ($k < $n) {
                    $tt = $tokens[$k];
                    $tx = is_array($tt) ? $tt[1] : $tt;
                    $j += strlen($tx);
                    if ($tt === ';') {
                        $despuesDe = $j;
                        break;
                    }
                    if ($tt === '{') {
                        return null; // namespace con llaves: mejor a mano
                    }
                    ++$k;
                }
            }
            $offset += strlen($txt);
        }
        if ($pos === null) {
            return null;
        }
        if ($trasCarga !== null) {
            $despuesDe = $trasCarga;
        }
        if ($despuesDe === null) {
            // tras el docblock de cabecera, si va pegado a <?php
            if (preg_match('#^(<\?php\s*/\*.*?\*/)#s', $src, $mm)) {
                $despuesDe = strlen($mm[1]);
            } else {
                $despuesDe = $pos;
            }
        }
        $guardia = "\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n";

        return substr($src, 0, $despuesDe) . "\n" . ltrim($guardia, "\n") . substr($src, $despuesDe);
    }
}
