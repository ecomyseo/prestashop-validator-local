<?php
/**
 * Lectura de plantillas Smarty: etiquetas con su línea, modificadores y compilación.
 *
 * Reglas de Smarty 3/4 que se respetan para no dar falsos positivos:
 *  - "{" seguido de espacio, tabulador o salto NO abre etiqueta (auto_literal);
 *  - {* comentarios *} y {literal}...{/literal} se saltan;
 *  - las comillas y los paréntesis/corchetes anidados no cierran la etiqueta.
 */
class VlSmarty
{
    /**
     * @return array<int, array{tag: string, linea: int, inicio: int}>
     */
    public static function etiquetas(string $src): array
    {
        $out = array();
        $n = strlen($src);
        $i = 0;
        $linea = 1;
        $enLiteral = false;
        while ($i < $n) {
            $c = $src[$i];
            if ($c === "\n") {
                ++$linea;
                ++$i;
                continue;
            }
            if ($c !== '{') {
                ++$i;
                continue;
            }
            // comentario
            if (!$enLiteral && substr($src, $i, 2) === '{*') {
                $fin = strpos($src, '*}', $i + 2);
                $fin = $fin === false ? $n : $fin + 2;
                $linea += substr_count($src, "\n", $i, $fin - $i);
                $i = $fin;
                continue;
            }
            $sig = $i + 1 < $n ? $src[$i + 1] : '';
            if ($sig === '' || ctype_space($sig)) {
                ++$i;
                continue;
            }
            // fin de la etiqueta respetando comillas y anidamiento
            $j = $i + 1;
            $prof = 0;
            $q = null;
            $lineasTag = 0;
            while ($j < $n) {
                $d = $src[$j];
                if ($d === "\n") {
                    ++$lineasTag;
                }
                if ($q !== null) {
                    if ($d === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($d === $q) {
                        $q = null;
                    }
                } elseif ($d === '"' || $d === "'") {
                    $q = $d;
                } elseif ($d === '{' || $d === '(' || $d === '[') {
                    ++$prof;
                } elseif ($d === ')' || $d === ']') {
                    --$prof;
                } elseif ($d === '}') {
                    if ($prof <= 0) {
                        break;
                    }
                    --$prof;
                }
                ++$j;
            }
            $tag = substr($src, $i + 1, $j - $i - 1);
            $nombre = strtolower(preg_replace('#^(/?[a-zA-Z_][a-zA-Z0-9_]*).*$#s', '$1', $tag));
            if ($enLiteral) {
                if ($nombre === '/literal') {
                    $enLiteral = false;
                }
                $linea += $lineasTag;
                $i = $j + 1;
                continue;
            }
            if ($nombre === 'literal') {
                $enLiteral = true;
                $linea += $lineasTag;
                $i = $j + 1;
                continue;
            }
            $out[] = array('tag' => $tag, 'linea' => $linea, 'inicio' => $i);
            $linea += $lineasTag;
            $i = $j + 1;
        }

        return $out;
    }

    /**
     * Divide "expr|mod1:'a':'b'|mod2 nofilter" en expresión, modificadores y banderas.
     *
     * @return array{expr: string, mods: array<int, array{nombre: string, params: string[]}>, nofilter: bool}
     */
    public static function modificadores(string $tag): array
    {
        $nofilter = false;
        $t = rtrim($tag);
        if (preg_match('#\s+nofilter$#i', $t)) {
            $nofilter = true;
            $t = preg_replace('#\s+nofilter$#i', '', $t);
        }
        $partes = self::partir($t, '|');
        $expr = array_shift($partes);
        $mods = array();
        foreach ($partes as $p) {
            if ($p === '') {
                // "||" es un OR lógico, no un modificador
                continue;
            }
            $trozos = self::partir($p, ':');
            $nombre = strtolower(trim(array_shift($trozos)));
            $nombre = ltrim($nombre, '@');
            $params = array();
            foreach ($trozos as $pp) {
                $params[] = trim(trim($pp), '"\'');
            }
            $mods[] = array('nombre' => $nombre, 'params' => $params);
        }

        return array('expr' => trim($expr), 'mods' => $mods, 'nofilter' => $nofilter);
    }

    /** Parte por un separador fuera de comillas, paréntesis y corchetes. */
    public static function partir(string $s, string $sep): array
    {
        $out = array();
        $cur = '';
        $q = null;
        $prof = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $c = $s[$i];
            if ($q !== null) {
                $cur .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $cur .= $s[++$i];
                    continue;
                }
                if ($c === $q) {
                    $q = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $q = $c;
                $cur .= $c;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                ++$prof;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                --$prof;
            }
            if ($c === $sep && $prof === 0) {
                // "||" (OR) no es separador de modificador
                if ($sep === '|' && $i + 1 < $n && $s[$i + 1] === '|') {
                    $cur .= '||';
                    ++$i;
                    continue;
                }
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = $cur;

        return $out;
    }

    /**
     * ¿La etiqueta imprime algo? ({$var}, {$obj->m()}, {funcion($x)}...) y no es una asignación.
     */
    public static function esSalida(string $tag): bool
    {
        $t = ltrim($tag);
        if ($t === '' || $t[0] === '/' || $t[0] === '*') {
            return false;
        }
        if ($t[0] === '$') {
            // asignación corta {$a = 1}, {$a.b = 2}, {$a[] = 3}; no "==" ni "=>"
            $expr = self::partir($t, '|')[0];
            if (preg_match('#^\$[\w.\[\]\->\$\'"]*\s*(\+|-|\*|/|\.)?=(?!=|>)#', $expr)) {
                return false;
            }
            if (preg_match('#^\$[\w.\[\]\->]+\s*(\+\+|--)\s*$#', $expr)) {
                return false;
            }

            return true;
        }
        // llamada a función PHP como salida: {count($x)} {implode(',', $a)}
        if (preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*\s*\(#', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Compila una plantilla con Smarty para detectar errores de sintaxis.
     * Las etiquetas y modificadores propios de PrestaShop ({l}, {url}, {hook}, |classnames...)
     * se aceptan con un manejador por defecto: solo interesa la sintaxis.
     *
     * @return array{mensaje: string, linea: int}|null
     */
    public static function compilar(string $fichero, string $dirModulo, string $tmp): ?array
    {
        if (!class_exists('Smarty')) {
            return null;
        }
        $smarty = new Smarty();
        $smarty->setCompileDir($tmp);
        $smarty->setCacheDir($tmp);
        $smarty->setTemplateDir(array($dirModulo, dirname($fichero)));
        $smarty->setErrorReporting(0);
        $smarty->muteUndefinedOrNullWarnings();
        $smarty->registerDefaultPluginHandler(function ($name, $type, $template, &$callback, &$script, &$cacheable) {
            $callback = array('VlSmarty', 'dummy');

            return true;
        });
        try {
            $tpl = $smarty->createTemplate('file:' . $fichero, $smarty);
            $tpl->compileTemplateSource();
        } catch (SmartyCompilerException $e) {
            $msg = preg_replace('#\s+#', ' ', strip_tags($e->getMessage()));
            $msg = preg_replace('#^Syntax error in template "[^"]*"\s*#', '', $msg);

            return array('mensaje' => trim($msg), 'linea' => (int) ($e->line ?? 0));
        } catch (SmartyException $e) {
            $m = preg_replace('#\s+#', ' ', $e->getMessage());
            // Recursos que solo existen dentro de PrestaShop (module:, parent:, extends de un tema)
            if (preg_match('#Unable to load template|Unknown resource type|not found#i', $m)) {
                return null;
            }

            return array('mensaje' => trim($m), 'linea' => 0);
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    public static function dummy()
    {
        return '';
    }
}
