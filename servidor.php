<?php
/**
 * Web local del validator (http://127.0.0.1:8077/).
 *
 *   php servidor.php start     arranca (oculto, sin ventana) si no está ya en marcha
 *   php servidor.php stop      lo para
 *   php servidor.php status    dice si está en marcha
 *   php servidor.php open      lo arranca si hace falta y abre el navegador
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$raiz = str_replace('\\', '/', __DIR__);
require $raiz . '/lib/VlServidor.php';
$s = new VlServidor($raiz);
$orden = $argv[1] ?? 'status';
switch ($orden) {
    case 'start':
        echo $s->arrancar() ? 'En marcha: ' . $s->url() . PHP_EOL : "No se ha podido arrancar.\n";
        break;
    case 'stop':
        echo $s->parar() ? "Parado.\n" : "No se ha podido parar.\n";
        break;
    case 'open':
        if ($s->arrancar()) {
            VlServidor::abrirNavegador($s->url());
            echo 'Abierto: ' . $s->url() . PHP_EOL;
        } else {
            echo "No se ha podido arrancar.\n";
        }
        break;
    default:
        echo $s->enMarcha() ? 'En marcha: ' . $s->url() . ' (PID ' . $s->pid() . ')' . PHP_EOL : "Parado.\n";
}
