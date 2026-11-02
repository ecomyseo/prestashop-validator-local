# Validator local de PrestaShop

Réplica local de **[validator.prestashop.com](https://validator.prestashop.com)** (versión 5.14.6, octubre de 2026)
para validar módulos de PrestaShop **sin subirlos**: las mismas 9 pestañas, la misma forma de los resultados y
las mismas herramientas, versiones y configuración que usa el oficial.

- **Línea de órdenes** (`validar.php`) y **web en localhost** (http://127.0.0.1:8077) con el aspecto del oficial.
- **Cada verificación es un *goal*** con identificador, comprobado por una batería de pruebas que demuestra que
  caza su fallo y nada más.
- **Calibrado contra informes reales** del oficial: da exactamente los mismos resultados en las 9 pestañas.
- **`--arreglar`** aplica las correcciones automáticas seguras.
- **Tras el 100 %** (opcional, se elige antes de validar): instala el módulo en una tienda local, hace las
  capturas, regenera el manual PDF y el manual en vídeo.
- **Skill** para Claude Code y Codex: basta con decir «valida este módulo».

> Proyecto independiente: no es de PrestaShop SA ni está avalado por ellos. El validator oficial es de código
> cerrado; este reproduce su comportamiento medido. Antes de publicar en el Marketplace, valida también en el
> oficial (la web local tiene el botón).

## Contenido

1. [Requisitos](#requisitos)
2. [Instalación](#instalación)
3. [Uso desde la línea de órdenes](#uso-desde-la-línea-de-órdenes)
4. [La web de localhost](#la-web-de-localhost)
5. [Tras el 100 %: instalar, capturas, manual PDF y vídeo](#tras-el-100--instalar-capturas-manual-pdf-y-vídeo)
6. [Skill para Claude Code y Codex](#skill-para-claude-code-y-codex)
7. [Las 9 pestañas](#las-9-pestañas)
8. [Goals](#goals)
9. [Qué está medido contra el oficial](#qué-está-medido-contra-el-oficial)
10. [Arreglos automáticos](#arreglos-automáticos---arreglar)
11. [Pruebas](#pruebas)
12. [Estructura del repositorio](#estructura-del-repositorio)
13. [Mantenimiento](#mantenimiento)
14. [Licencia](#licencia)

## Requisitos

| Qué | Para qué |
|---|---|
| **PHP 8.1 o superior** (CLI), con `mbstring`, `tokenizer`, `json`, `zip` | todo. El oficial corre en PHP 8.2 |
| **Composer** | instalar PHP CS Fixer 3.95.4, PHPStan 1.12.33, Smarty y el analizador de PHP |
| **Código fuente de PrestaShop** (una o varias versiones, con su `vendor/`) | la pestaña *Compatibility* (PHPStan analiza el módulo contra el núcleo) |
| Node.js + `jsdom` (opcional) | solo las pruebas de la web |

Probado en Windows 11. En Linux y macOS funciona la validación; la web se arranca con `nohup` en vez de
`Start-Process`.

## Instalación

```bash
git clone https://github.com/ecomyseo/prestashop-validator-local.git
cd prestashop-validator-local
composer install
```

### Las versiones de PrestaShop para PHPStan

PHPStan necesita el núcleo de PrestaShop de cada versión contra la que se valida. Descarga la versión desde
[github.com/PrestaShop/PrestaShop/releases](https://github.com/PrestaShop/PrestaShop/releases), descomprímela
(el ZIP de la release trae dentro otro `prestashop.zip`: hay que descomprimir también ese) y apunta a la
carpeta que contiene `vendor/`, `classes/` y `config/`.

El oficial pasa PHPStan **solo contra la versión más alta** que cae dentro del `ps_versions_compliancy` del
módulo, así que con tener la última versión de cada rama que te interese basta. Las que usa el autor:
1.7.8.11, 8.1.7, 8.2.7, 9.1.5 y 9.2.0. Sin ninguna, la pestaña *Compatibility* sale vacía con un aviso.

### Configuración: `reglas/prestashop.json`

```json
{
    "versiones": {
        "8.2.7": "/ruta/a/prestashop-8.2.7",
        "9.2.0": "/ruta/a/prestashop-9.2.0"
    },
    "php": "/usr/bin/php8.2",
    "puerto_web": 8077,
    "carpeta_modulos": "/ruta/a/mis/modulos",
    "tras_100": { "activo": false }
}
```

| Clave | Qué es |
|---|---|
| `versiones` | versión de PrestaShop → carpeta de su núcleo (para PHPStan) |
| `php` | PHP con el que se lanzan PHPStan, PHP CS Fixer y la web (si no existe, el que ejecuta el script) |
| `puerto_web` | puerto de la web local (solo escucha en 127.0.0.1) |
| `carpeta_modulos` | carpeta con tus módulos: alimenta el desplegable de la web y delimita «tras el 100 %» |
| `tras_100` | pasos que se pueden hacer cuando un módulo pasa al 100 % ([ver abajo](#tras-el-100--instalar-capturas-manual-pdf-y-vídeo)) |

El fichero del repositorio lleva la configuración del autor (Windows, rutas en `C:/dev-tools`); cámbiala por
las tuyas.

## Uso desde la línea de órdenes

```bash
php validar.php ruta/a/mi_modulo            # una carpeta
php validar.php mi_modulo.zip               # o un ZIP, como en el oficial
php validar.php                             # sin ruta: el módulo de la carpeta actual
```

En Windows también `validar.bat`, que se puede copiar a una carpeta del `PATH` como `validar-modulo.bat`.

| Opción | Qué hace |
|---|---|
| `--abrir` | abre el resultado en el navegador (la web arranca sola) |
| `--todas-ps` | PHPStan contra **todas** las versiones del rango (el oficial usa solo la más alta) |
| `--ps=8.2.7,9.2.0` | PHPStan contra esas versiones |
| `--solo=security,errors` / `--sin=standards` | ejecutar solo o saltarse pestañas |
| `--muestras=N` | ocurrencias que se enseñan por goal (5 por defecto; 0 = ninguna) |
| `--informes=DIR` / `--sin-informes` | dónde escribir los informes, o no escribirlos |
| `--arreglar` | aplica los arreglos automáticos seguros y vuelve a validar |
| `--tras100=instalar,capturas,manual,video` | qué hacer si pasa al 100 % (`todo`, `no`). Sin esta opción, en una terminal **se pregunta antes de validar** |
| `--esperar` | espera a que terminen esos pasos (si no, siguen en segundo plano) |
| `--sin-web` | no crear la validación en la web local |

**Código de salida:** `0` si el módulo pasa al 100 % (0 errores y 0 avisos), `1` si no. Sirve para CI.

Salida (resumida):

```
[OK] Requirements  [OK] Structure  [XX] Errors (3)  ...
  [!!] ERR_CONTEXT  Use of class Context  (14)
       classes/Buscador.php:88  ...
RESULTADO: NO PASA — hay que corregir lo marcado con [XX] y [!!].
Informes: informes/mi_modulo/mi_modulo.{html,txt,json}
Resultado en localhost: http://127.0.0.1:8077/module/mi_modulo-20261102-221455/validate
```

Cada goal sale marcado `[OK]` cumplido, `[XX]` error, `[!!]` aviso o `[..]` nota. Los informes se escriben en
`informes/<modulo>/`: HTML con el aspecto del oficial, TXT con el formato de su «Download the report» y JSON con
la misma forma que la respuesta de cada pestaña del oficial.

## La web de localhost

```bash
php servidor.php start      # también start | stop | status | open
```

Arranca sola, oculta, al validar desde la terminal. Funciona como el oficial:

- **`/`**: subir un ZIP (máx. 30 MB) o elegir una carpeta de `carpeta_modulos`, y marcar qué hacer si pasa
  al 100 %.
- **`/module/<id>/validate`**: la validación. Las pestañas se rellenan una a una (un GET por pestaña, como el
  oficial) con sus insignias; cada línea de código se despliega con su contexto. En *Summary*: datos del
  módulo, tabla de goals, **Download the report (TXT)**, **Validar en validator.prestashop.com ↗**,
  *Volver a validar* y la sección **Tras el 100 %**.
- **`/user/validations`**: todas las validaciones, también las lanzadas desde la terminal.

## Tras el 100 %: instalar, capturas, manual PDF y vídeo

Cuando un módulo pasa al 100 %, el validator puede seguir con lo que hay que hacer antes de entregarlo. **Los
pasos se eligen antes de validar**: en la terminal se pregunta (o `--tras100=`), en la web son casillas, y el
skill lo pregunta en el chat.

| Paso | Qué hace (configuración del autor) | Se da por hecho si… |
|---|---|---|
| `instalar` | instala el módulo en la tienda local PrestaShop 8.2 | la salida dice `FALLAN: 0` |
| `capturas` | captura cada pantalla del módulo (incluye `instalar`) | deja capturas **nuevas** |
| `manual` | `MANUAL.md` con **todas** las capturas → PDF | el PDF es nuevo y lleva tantas imágenes como capturas |
| `video` | manual en vídeo a partir del manual y las capturas | el MP4 es nuevo |
| (`parar`) | para **solo** las tiendas que se arrancaron durante el trabajo | los puertos quedan libres |

- Corre **en segundo plano**: la terminal termina enseguida y el progreso se ve en la misma página de la
  validación, que se actualiza sola (estado de cada paso, registro, galería de capturas, enlace al PDF y el
  vídeo reproducible). Para esperar en la terminal: `php tras100.php --esperar <id>` (o `--esperar` al validar).
- Si un paso falla, los siguientes se omiten (salvo parar las tiendas). Un paso cuyo programa no existe se
  **omite** sin dar fallo: en otra máquina se queda en nada.
- Solo con carpetas dentro de `carpeta_modulos` (un ZIP no se instala) y nunca dos a la vez para el mismo
  módulo.
- `MANUAL.md`: si no existe, sale de la guía de usuario (`GUIA_USUARIO.md`, `DOC-usuario.md`…). Las capturas
  que el texto no cita se añaden al final en un bloque marcado que se rehace en cada pasada.
- Lo que se para al final es lo que **no** estaba en marcha al empezar: una tienda que estuviera usando otra
  persona se respeta. MariaDB se apaga con su orden de parada, no matándola.

Los pasos son órdenes configurables en `reglas/prestashop.json` → `tras_100.pasos`. Cada uno:

```json
{
    "id": "manual",
    "titulo": "Manual PDF con todas las capturas",
    "preparar": "manual",
    "orden": ["{python}", "C:/dev-tools/manuales/md_a_pdf.py", "{manual_md}", "-o", "{manual_pdf}", "--titulo", "{displayName}"],
    "produce": "fichero:{manual_pdf}",
    "minutos": 10
}
```

| Clave | |
|---|---|
| `orden` | programa y argumentos. Marcadores: `{python}` `{php}` `{modulo}` `{carpeta_modulos}` `{capturas}` `{displayName}` `{version}` `{manual_md}` `{manual_pdf}` |
| `exige` | texto que tiene que salir en la salida (`FALLAN: 0`) |
| `produce` | `fichero:<ruta>` o `carpeta:<ruta>`: tiene que quedar **recién** generado |
| `requiere` | pasos que se añaden solos al elegir este |
| `minutos` | tiempo máximo |
| `siempre` + `si` | se hace al final aunque algo falle, si se eligió alguno de `si` |

Las herramientas que usa el autor (tiendas locales, capturas, PDF y vídeo) no forman parte de este repositorio:
pon en `orden` las tuyas, o deja `"activo": false`.

## Skill para Claude Code y Codex

`skill/prestashop-validator/SKILL.md` es un skill que se activa con «valida este módulo», «pásale el
validator», «validate this module»… Valida el módulo de la carpeta actual, pregunta antes qué hacer si pasa al
100 % y contesta con el resultado y la URL de localhost. Si se le pide, lo deja al 100 % goal a goal: lleva una
tabla con cómo se arregla cada uno.

```bash
php skill/instalar.php            # Claude Code (~/.claude/skills) y Codex (~/.codex/skills, si existe)
php skill/instalar.php --claude   # solo Claude Code
php skill/instalar.php --codex    # solo Codex
php skill/instalar.php --destino=DIR   # cualquier otra herramienta que lea SKILL.md
```

El instalador escribe las rutas reales de esta copia y del PHP; si ya había un `SKILL.md` distinto, guarda una
copia `.bak-<fecha>`.

## Las 9 pestañas

| Pestaña | Cómo se comprueba |
|---|---|
| Requirements | constructor de la clase principal (`name`, `tab`, `version`, `author`, `displayName`, `description`, `ps_versions_compliancy`, `parent::__construct`) y `config.xml` |
| Structure | guarda `_PS_VERSION_` en cada PHP, descripción en inglés (detector de idioma), ficheros en su carpeta de `views/`, ficheros que sobran, ZIP con una sola carpeta |
| Errors | sintaxis PHP y Smarty, `install()`/`uninstall()` devuelven valor, SQL con `IF (NOT) EXISTS`, hooks obsoletos y eliminados en 9, librerías quitadas en 9, `Context::`, BOM, UTF-8… |
| Compatibility | **PHPStan 1.12.33 nivel 5** con la extensión de `prestashop/php-dev-tools`, contra la versión más alta del rango; notas por métodos `@deprecated` del núcleo |
| Optimizations | librerías JS que ya trae PrestaShop, cURL/SOAP sin comprobar la extensión, ficheros vacíos, HTML en el PHP principal |
| Translations | `$this->l()` con comillas simples y literal; `{l s='…'}` con `mod=` o `d=` |
| Licenses | bloque de documentación tras `<?php` en cada PHP, cabecera de licencia en JS y TPL, año y licencia compatible |
| Security | `.htaccess`, `index.php` en cada carpeta, funciones y variables prohibidas, `serialize`, `global`, variables Smarty sin escapar |
| Standards | **PHP CS Fixer 3.95.4** con la configuración de `php-dev-tools` v4.3.0/v5; un resultado por línea a cambiar |

## Goals

Cada verificación es un goal con identificador (`SEC_SMARTY_ESCAPE`, `STR_PS_VERSION_GUARD`, `ERR_CONTEXT`…):
64 en total, en [`lib/VlGoals.php`](lib/VlGoals.php), cada uno con su origen:

- **verificado**: mensaje y comportamiento medidos contra un informe oficial real;
- **herramienta**: misma herramienta, versión y configuración que el oficial;
- **reglas**: dato literal de `https://validator.prestashop.com/rules` (copia en `reglas/rules.json`);
- **documentado**: regla del changelog o del checklist oficial; el texto exacto del mensaje no es público.

Cómo se deja en verde cada uno: tabla del [skill](skill/prestashop-validator/SKILL.md#cómo-se-deja-en-verde-cada-goal).

## Qué está medido contra el oficial

Informes reales de validator.prestashop.com. El local da **exactamente** lo mismo en las 9 pestañas:

| Módulo | Req | Str | Err | Comp | Opt | Trad | Lic | Seg | Std |
|---|---|---|---|---|---|---|---|---|---|
| refstandard (generador oficial, informe 3785333) | 0 | 2 | 0 | 7 | 0 | 0 | 0 | 1 | 378 |
| módulo comercial A (informe 3785345) | 0 | 1 | 0 | 8 | 0 | 0 | 20 | 1 | 22 |
| módulo comercial B (informe 3785354) | 0 | 0 | 14 | 2 notas | 35 | 0 | 26 | 0 | 0 |

El primero está en el repositorio (`oficial/informes/` y `oficial/generador/`) y la batería lo comprueba en cada
pasada; los otros dos son módulos privados y se comprobaron en local.

Lo que se descubrió así:

- PHPStan corre **solo contra la versión más alta** del rango declarado.
- El inglés se mira en la **descripción** del módulo (pestaña Structure), no en los comentarios.
- Licenses: cada PHP empieza por `<?php` y **en la línea siguiente** un bloque `/** … */` («Missing file doc
  comment»; con una línea en blanco en medio, «There must be no blank lines before the file comment»). También
  los `index.php`, salvo los de `views/css`, `views/js`… y `translations/`. JS y TPL llevan cabecera de licencia;
  los CSS no se miran.
- La línea en blanco tras `<?php` de `blank_line_after_opening_tag` **no cuenta** en Standards (solo añade
  líneas y el oficial cuenta las que se quitan). No hay que ponerla: rompe Licenses.
- Cada línea con `Context::` es un aviso en Errors («Use of class Context»).
- HTML en el PHP principal: un error en Optimizations por cada línea de cadena con `<div`, `<p`, `<span` o
  `<h1`…`<h6`. `<strong>`, `<a>`, `<table>`… no cuentan, y los demás PHP no se miran.
- Escribir en `$_GET['x'] = …` no cuenta; leerlo, sí. `'home_default'` solo se marca en módulos para < 1.7.
- El oficial **no** comprueba `logo.png`.

Cada informe oficial nuevo que no cuadre se guarda en `oficial/informes/` con su ZIP y se corrige la regla
hasta que la batería vuelva a verde.

## Arreglos automáticos (`--arreglar`)

Solo lo seguro: `index.php` en cada carpeta, `.htaccess` en la raíz, la guarda
`if (!defined('_PS_VERSION_')) { exit; }`, PHP CS Fixer con la configuración del oficial y las cabeceras de
licencia que falten o que tengan una línea en blanco delante. Smarty sin escapar, funciones prohibidas, errores
de PHPStan, SQL, HTML en el PHP principal o `Context::` se corrigen a mano (el skill tiene la tabla).

## Pruebas

```bash
php pruebas/goals.php                 # base limpia, cada goal cazado, falsos positivos, oro, cobertura
php pruebas/goals.php --rapido        # sin el módulo oficial de referencia
php pruebas/tras100.php               # «tras el 100 %» con pasos de pega y servidores reales
php pruebas/tras100_mutaciones.php    # repone 12 fallos y exige que las pruebas los cacen
node pruebas/web_render.js <respuesta.json>...                       # pintado de la web
node pruebas/web_e2e.js <node_modules con jsdom> /module/<id>/validate   # web de punta a punta
```

`pruebas/goals.php` termina con `CAZADAS n de N` (una copia del módulo base con un fallo puesto por goal) y
`TODO EN VERDE`.

## Estructura del repositorio

```
validar.php            línea de órdenes
servidor.php           arrancar / parar la web
tras100.php            «tras el 100 %» en segundo plano
validar.bat            lanzador para Windows
lib/                   VlValidador (orquesta), VlComprobaciones (reglas), VlHerramientas (PHPStan y
                       PHP CS Fixer), VlGoals, VlModulo, VlPaso, VlInforme, VlArreglar, VlIdioma,
                       VlTrabajo, VlServidor, VlTras100
web/                   router.php y assets/ de la web de localhost
reglas/                rules.json (copia de /rules del oficial), hooks.json, deprecados.json,
                       php-cs-fixer.reglas.php, phpstan/ (extensión y stubs), prestashop.json
oficial/               módulos del generador oficial e informes reales para la batería
pruebas/               baterías y el módulo base limpio
herramientas/          regenerar hooks.json, deprecados.json y los módulos del generador
skill/                 skill «prestashop-validator» y su instalador
```

`informes/` y `trabajos/` se crean al validar y no se suben.

## Mantenimiento

- `reglas/rules.json`: copia de `https://validator.prestashop.com/rules`. Si el oficial cambia de versión:
  `curl -s https://validator.prestashop.com/rules -o reglas/rules.json`.
- `reglas/hooks.json`: `php herramientas/generar_hooks.php` (lee los hooks del código real de PrestaShop).
- `reglas/deprecados.json`: `php herramientas/generar_deprecados.php`.
- `herramientas/descargar_generador.py`: vuelve a bajar los módulos del generador oficial.
- El changelog del oficial está en <https://validator.prestashop.com/changelog>.

## Licencia

[AFL-3.0](LICENSE.md) · © 2026 Ecom Experts. Los ficheros de `reglas/phpstan/` proceden de
[prestashop/php-dev-tools](https://github.com/PrestaShop/php-dev-tools) (MIT, ver
`reglas/phpstan/LICENSE-php-dev-tools`). `reglas/rules.json` es una copia de los datos públicos del validator
oficial.
