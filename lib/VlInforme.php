<?php
/**
 * Salidas: consola (goals), TXT (formato del informe oficial), HTML (aspecto del oficial) y JSON
 * (misma forma que la respuesta de cada paso del oficial).
 */
class VlInforme
{
    private static function cuenta(VlPaso $p): string
    {
        if ($p->fallo !== null) {
            return 'FALLO';
        }
        if ($p->omitido) {
            return 'omitido';
        }
        $t = array();
        if ($p->types['error']) {
            $t[] = $p->types['error'] . ' error' . ($p->types['error'] > 1 ? 'es' : '');
        }
        if ($p->types['warning']) {
            $t[] = $p->types['warning'] . ' aviso' . ($p->types['warning'] > 1 ? 's' : '');
        }
        if ($p->types['notice']) {
            $t[] = $p->types['notice'] . ' nota' . ($p->types['notice'] > 1 ? 's' : '');
        }

        return $t ? implode(', ', $t) : 'OK';
    }

    public static function consola(array $res, int $muestras = 5): string
    {
        $m = $res['modulo'];
        $c = $m->compatibilidad();
        $id = $m->id;
        $o = array();
        $o[] = str_repeat('=', 78);
        $o[] = 'VALIDATOR LOCAL (réplica de validator.prestashop.com ' . VlValidador::VERSION_OFICIAL . ')';
        $o[] = 'Módulo:  ' . $m->carpeta . '  v' . (is_string($id['version'] ?? null) ? $id['version'] : '?')
            . '   compatibilidad ' . $c['min'] . ' -> ' . ($c['max'] === '99' ? '_PS_VERSION_' : $c['max']);
        $o[] = 'Origen:  ' . ($m->zip ?: $m->dir);
        if ($res['phpstan']) {
            $o[] = 'PHPStan: ' . implode(', ', array_keys($res['phpstan']));
        }
        $o[] = str_repeat('=', 78);
        foreach ($res['pasos'] as $pid => $p) {
            $o[] = '';
            $o[] = sprintf('%-15s %s%s', VlGoals::PASOS[$pid], self::cuenta($p), isset($res['tiempos'][$pid]) ? '   (' . $res['tiempos'][$pid] . ' s)' : '');
            if ($p->fallo !== null) {
                $o[] = '   !! ' . $p->fallo;
            }
            foreach ($p->avisosInternos as $a) {
                $o[] = '   (i) ' . $a;
            }
            if ($p->omitido) {
                continue;
            }
            foreach ($p->goals as $gid => $n) {
                $g = VlGoals::get($gid);
                $marca = $n === 0 ? '[OK]' : ($g['tipo'] === 'error' ? '[XX]' : ($g['tipo'] === 'warning' ? '[!!]' : '[..]'));
                $o[] = sprintf('   %s %-26s %s%s', $marca, $gid, $g['titulo'], $n ? '  -> ' . $n : '');
                if ($n && $muestras > 0) {
                    $vistas = array_slice($p->muestras[$gid] ?? array(), 0, $muestras);
                    foreach ($vistas as $s) {
                        $o[] = '          ' . mb_substr(trim($s), 0, 150);
                    }
                    if (count($p->muestras[$gid] ?? array()) > $muestras) {
                        $o[] = '          ... y ' . (count($p->muestras[$gid]) - $muestras) . ' más';
                    }
                }
            }
        }
        $t = VlValidador::totales($res);
        $o[] = '';
        $o[] = str_repeat('=', 78);
        $o[] = sprintf('GOALS: %d de %d cumplidos   |   errores: %d   avisos: %d   notas: %d   (%s s)',
            $t['goals_ok'], $t['goals'], $t['error'], $t['warning'], $t['notice'], $res['duracion']);
        $ok = $t['error'] === 0 && $t['warning'] === 0 && $t['fallos'] === 0;
        $o[] = $ok ? 'RESULTADO: 100 % OK — el informe oficial saldría todo en verde.' : 'RESULTADO: NO PASA — hay que corregir lo marcado con [XX] y [!!].';
        $o[] = str_repeat('=', 78);

        return implode(PHP_EOL, $o) . PHP_EOL;
    }

    /** Formato del "Download the report (TXT)" del oficial. */
    public static function txt(array $res): string
    {
        $m = $res['modulo'];
        $id = $m->id;
        $s = function ($k) use ($id) {
            return isset($id[$k]) && is_scalar($id[$k]) ? (string) $id[$k] : '';
        };
        $o = array();
        $o[] = $s('displayName') . ' - Validation report';
        $o[] = '-------';
        $o[] = '';
        $o[] = 'Addon details';
        $o[] = '-------------';
        $o[] = '';
        $o[] = 'Validation date: ' . substr($res['fecha'], 0, 10);
        $o[] = 'Addon type: Module';
        $o[] = 'Technical name: ' . $s('name');
        $o[] = 'Display name: ' . $s('displayName');
        $o[] = 'Author: ' . $s('author');
        $o[] = 'Version: ' . $s('version');
        $o[] = 'Category (tab): ' . $s('tab');
        $o[] = 'Description: ' . $s('description');
        $o[] = '';
        foreach ($res['pasos'] as $pid => $p) {
            if (!$p->files && !$p->code && $p->fallo === null) {
                continue;
            }
            $o[] = '';
            $o[] = VlGoals::PASOS[$pid];
            $o[] = '-------------';
            $o[] = '';
            if ($p->fallo !== null) {
                $o[] = 'Oups, An error happened during this validation step: ' . $p->fallo;
                continue;
            }
            foreach ($p->files as $msg => $rutas) {
                $o[] = strip_tags($msg) === $msg ? $msg : preg_replace('#</?code>#', '', $msg);
                foreach ($rutas as $r) {
                    $o[] = '* ' . $r;
                }
                $o[] = '';
            }
            foreach ($p->code as $fich => $datos) {
                $o[] = 'In file ' . $fich . ':';
                foreach ($datos['messages'] as $msg => $info) {
                    $o[] = ' * ' . $msg . ' [' . $info['type'] . ']';
                    foreach ($info['content'] as $c) {
                        $o[] = '     line ' . $c['line'] . ': ' . trim($c['content']);
                    }
                }
                $o[] = '';
            }
        }

        return implode("\n", $o) . "\n";
    }

    public static function json(array $res): string
    {
        $m = $res['modulo'];
        $out = array(
            'validator' => array('version' => VlValidador::VERSION_OFICIAL, 'local' => true),
            'module' => array_filter($m->id, function ($k) {
                return strpos($k, '__') !== 0;
            }, ARRAY_FILTER_USE_KEY),
            'date' => $res['fecha'],
            'phpstan' => $res['phpstan'],
            'totals' => VlValidador::totales($res),
            'steps' => array(),
            'goals' => array(),
        );
        foreach ($res['pasos'] as $pid => $p) {
            $out['steps'][$pid] = $p->aOficial();
            foreach ($p->goals as $gid => $n) {
                $out['goals'][$gid] = array('step' => $pid, 'hits' => $n, 'ok' => $n === 0, 'type' => VlGoals::get($gid)['tipo']);
            }
        }

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** HTML autocontenido con la misma estructura que la página de validación oficial. */
    public static function html(array $res): string
    {
        $m = $res['modulo'];
        $id = $m->id;
        $t = VlValidador::totales($res);
        $s = function ($k) use ($id) {
            return isset($id[$k]) && is_scalar($id[$k]) ? (string) $id[$k] : '';
        };
        $h = array();
        $h[] = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Validation - ' . self::e($s('displayName')) . '</title>';
        $h[] = '<style>
body{font-family:"Open Sans",Arial,sans-serif;background:#f4f6f8;color:#363a41;margin:0}
header{background:#25b9d7;color:#fff;padding:14px 24px;font-size:18px}
header small{opacity:.85;font-size:13px;margin-left:12px}
.wrap{display:flex;gap:20px;padding:20px}
.menu{width:230px;flex:none;position:sticky;top:20px;align-self:flex-start}
.menu a{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#fff;border:1px solid #dbe6e9;margin-bottom:-1px;color:#363a41;text-decoration:none}
.menu a.active{background:#25b9d7;color:#fff}
.b{display:inline-block;min-width:22px;padding:2px 7px;border-radius:10px;font-size:12px;color:#fff;text-align:center;margin-left:4px}
.b-e{background:#d9534f}.b-w{background:#f0ad4e}.b-ok{background:#5cb85c}.b-n{background:#5bc0de}.b-f{background:#777}
.main{flex:1;min-width:0}
.panel{background:#fff;border:1px solid #dbe6e9;border-radius:4px;margin-bottom:20px}
.panel h3{margin:0;padding:12px 16px;border-bottom:1px solid #dbe6e9;font-size:16px}
.pb{padding:16px}
.alert{padding:10px 14px;border-radius:4px;margin-bottom:12px}
.info{background:#e6f6fa;border:1px solid #bfe9f3}.ok{background:#dff0d8;border:1px solid #c3e6cb}.fail{background:#f2dede;border:1px solid #ebccd1}
dt.st{font-weight:600;margin:14px 0 6px}
blockquote{margin:0 0 10px;padding:8px 12px;border-left:4px solid #d9534f;background:#fcf2f2}
blockquote.warning-message{border-color:#f0ad4e;background:#fdf7ee}
blockquote.notice-message{border-color:#5bc0de;background:#eef8fb}
code{font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;word-break:break-all}
.ln{display:inline-block;min-width:44px;color:#888}
table.g{width:100%;border-collapse:collapse;font-size:13px}table.g td{border-bottom:1px solid #eee;padding:5px 6px;vertical-align:top}
.tab{display:none}.tab.active{display:block}
a.rm{font-size:12px;color:#25b9d7}
</style></head><body>';
        $h[] = '<header>Validation - ' . self::e($s('displayName')) . '<small>validator local · réplica de ' . VlValidador::VERSION_OFICIAL . ' · ' . self::e($res['fecha']) . '</small></header>';
        $h[] = '<div class="wrap"><nav class="menu">';
        $h[] = '<a href="#summary" class="active" data-t="summary">Summary</a>';
        foreach ($res['pasos'] as $pid => $p) {
            $bad = '';
            if ($p->fallo !== null) {
                $bad = '<span class="b b-f">!</span>';
            } elseif ($p->omitido) {
                $bad = '<span class="b b-f">-</span>';
            } else {
                if ($p->types['warning']) {
                    $bad .= '<span class="b b-w">' . $p->types['warning'] . '</span>';
                }
                if ($p->types['error']) {
                    $bad .= '<span class="b b-e">' . $p->types['error'] . '</span>';
                }
                if (!$bad) {
                    $bad = '<span class="b b-ok">&#10003;</span>';
                }
            }
            $h[] = '<a href="#' . $pid . '" data-t="' . $pid . '">' . VlGoals::PASOS[$pid] . '<span>' . $bad . '</span></a>';
        }
        $h[] = '</nav><div class="main">';
        // Summary
        $h[] = '<div class="tab active" id="t-summary"><div class="panel"><h3>Summary</h3><div class="pb">';
        $h[] = '<p><b>Technical name:</b> ' . self::e($s('name')) . '<br><b>Display name:</b> ' . self::e($s('displayName'))
            . '<br><b>Description:</b> ' . self::e($s('description')) . '<br><b>Version:</b> v' . self::e($s('version'))
            . '<br><b>Author:</b> ' . self::e($s('author')) . '<br><b>Category (tab):</b> ' . self::e($s('tab')) . '</p>';
        $h[] = '<div class="alert ' . ($t['error'] + $t['warning'] + $t['fallos'] === 0 ? 'ok' : 'fail') . '"><b>Goals: ' . $t['goals_ok'] . ' / ' . $t['goals'] . '</b> · errors ' . $t['error'] . ' · warnings ' . $t['warning'] . ' · notices ' . $t['notice'] . '</div>';
        $h[] = '<table class="g">';
        foreach ($res['pasos'] as $pid => $p) {
            foreach ($p->goals as $gid => $n) {
                $g = VlGoals::get($gid);
                $cl = $n === 0 ? 'b-ok' : ($g['tipo'] === 'error' ? 'b-e' : ($g['tipo'] === 'warning' ? 'b-w' : 'b-n'));
                $h[] = '<tr><td>' . VlGoals::PASOS[$pid] . '</td><td><code>' . $gid . '</code></td><td>' . self::e($g['titulo']) . '</td><td><span class="b ' . $cl . '">' . ($n === 0 ? '&#10003;' : $n) . '</span></td><td style="color:#999">' . $g['origen'] . '</td></tr>';
            }
        }
        $h[] = '</table></div></div></div>';
        foreach ($res['pasos'] as $pid => $p) {
            $h[] = '<div class="tab" id="t-' . $pid . '"><div class="panel"><h3>' . VlGoals::PASOS[$pid] . '</h3><div class="pb">';
            if ($p->fallo !== null) {
                $h[] = '<div class="alert fail">Oups, An error happened during this validation step: ' . self::e($p->fallo) . '</div>';
            } elseif (!$p->files && !$p->code) {
                $h[] = '<div class="alert ok">' . ($p->omitido ? 'Step skipped.' : '0 results found.') . '</div>';
            } else {
                $intro = VlGoals::INTRO[$pid];
                if (isset(VlGoals::INTRO_LINK[$pid])) {
                    $intro .= ' <a class="rm" href="' . VlGoals::INTRO_LINK[$pid] . '">' . VlGoals::INTRO_LINK[$pid] . '</a>';
                }
                $h[] = '<p class="alert info">' . self::e(VlGoals::INTRO[$pid]) . (isset(VlGoals::INTRO_LINK[$pid]) ? ' <a class="rm" target="_blank" href="' . VlGoals::INTRO_LINK[$pid] . '">' . VlGoals::INTRO_LINK[$pid] . '</a>' : '') . '</p>';
                if ($p->files) {
                    $h[] = '<dl>';
                    foreach ($p->files as $msg => $rutas) {
                        $link = $p->readMoreLinks[$msg]['Documentation'] ?? null;
                        $h[] = '<dt class="st">' . preg_replace('#&lt;(/?)code&gt;#', '<$1code>', self::e($msg)) . ($link ? ' <a class="rm" target="_blank" href="' . self::e($link) . '">Read more: Documentation</a>' : '') . '</dt><dd><blockquote><ul>';
                        foreach ($rutas as $r) {
                            $h[] = '<li>' . self::e($r) . '</li>';
                        }
                        $h[] = '</ul></blockquote></dd>';
                    }
                    $h[] = '</dl>';
                }
                if ($p->code) {
                    $h[] = '<dl>';
                    foreach ($p->code as $fich => $datos) {
                        $h[] = '<dt class="st">&#128196; ' . self::e($fich) . '</dt><dd><ul>';
                        foreach ($datos['messages'] as $msg => $info) {
                            $link = $info['content'][0]['readMoreLinks']['Documentation'] ?? null;
                            $h[] = '<li><blockquote class="' . strtolower($info['type']) . '-message"><span>' . self::e($msg) . ($link ? ' <a class="rm" target="_blank" href="' . self::e($link) . '">Read more</a>' : '') . '</span><div>';
                            foreach ($info['content'] as $c) {
                                $h[] = '<div><span class="ln">' . (int) $c['line'] . '.</span><code>' . self::e($c['content']) . '</code></div>';
                            }
                            $h[] = '</div></blockquote></li>';
                        }
                        $h[] = '</ul></dd>';
                    }
                    $h[] = '</dl>';
                }
            }
            foreach ($p->avisosInternos as $a) {
                $h[] = '<p style="color:#777;font-size:12px">' . self::e($a) . '</p>';
            }
            $h[] = '</div></div></div>';
        }
        $h[] = '</div></div><script>
document.addEventListener("click",function(e){var a=e.target.closest(".menu a");if(!a)return;e.preventDefault();
document.querySelectorAll(".menu a").forEach(function(x){x.classList.remove("active")});a.classList.add("active");
document.querySelectorAll(".tab").forEach(function(x){x.classList.remove("active")});
var t=document.getElementById("t-"+a.getAttribute("data-t"));if(t)t.classList.add("active");});
</script></body></html>';

        return implode("\n", $h);
    }
}
