/**
 * Prueba de punta a punta de la web local SIN abrir navegador: descarga la página de una
 * validación del servidor (http://127.0.0.1:8077), la carga en jsdom, ejecuta validation.js
 * con fetch real contra el servidor y comprueba lo que queda pintado y que los clics funcionan.
 *
 *   node pruebas/web_e2e.js <carpeta_con_jsdom/node_modules> <ruta /module/<id>/validate> [errores_esperados]
 */
'use strict';
var path = require('path');
var fs = require('fs');
var nm = process.argv[2];
var ruta = process.argv[3];
var esperados = process.argv[4] !== undefined ? parseInt(process.argv[4], 10) : null;
var JSDOM = require(path.join(nm, 'jsdom')).JSDOM;
var BASE = 'http://127.0.0.1:8077';
var fallos = 0;
function ok(c, m) { if (!c) { fallos++; console.log('  [XX] ' + m); } else { console.log('  [OK] ' + m); } }

fetch(BASE + ruta, { cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
  var errores = [];
  var dom = new JSDOM(html.replace(/<script src="\/assets\/validation\.js"><\/script>/, ''), { url: BASE + ruta, runScripts: 'outside-only' });
  var w = dom.window;
  w.fetch = function (u, o) { return fetch(new URL(u, BASE).href, o); };
  w.addEventListener('error', function (e) { errores.push(String(e.message)); });
  var js = fs.readFileSync(path.join(__dirname, '..', 'web', 'assets', 'validation.js'), 'utf8');
  try { w.eval(js); } catch (e) { errores.push(String(e)); }
  var d = w.document;
  var inicio = Date.now();
  (function esperar() {
    var fin = d.getElementById('vl-final');
    if (fin && !/Validando/.test(fin.textContent)) { return comprobar(); }
    if (Date.now() - inicio > 600000) { ok(false, 'la validación no termina'); return salir(); }
    setTimeout(esperar, 300);
  })();
  function comprobar() {
    ok(errores.length === 0, 'cero errores de JavaScript' + (errores.length ? ': ' + errores.join(' | ') : ''));
    var pills = d.querySelectorAll('.validation-pill');
    ok(pills.length === 9, '9 pestañas en el menú');
    var conInsignia = Array.prototype.filter.call(pills, function (p) { return p.querySelector('.badge'); }).length;
    ok(conInsignia === 9, 'las 9 pestañas tienen su insignia (' + conInsignia + ')');
    var errs = 0;
    d.querySelectorAll('.validation-pill .badge-danger').forEach(function (b) { errs += parseInt(b.textContent, 10); });
    if (esperados !== null) { ok(errs === esperados, 'errores en insignias = ' + esperados + ' (hay ' + errs + ')'); }
    var filas = d.querySelectorAll('#vl-goals tbody tr').length;
    var nGoals = (fs.readFileSync(path.join(__dirname, '..', 'lib', 'VlGoals.php'), 'utf8').match(/\$d\('[A-Z0-9_]+'/g) || []).length;
    ok(filas === nGoals, 'tabla de goals con una fila por goal (' + filas + ' de ' + nGoals + ')');
    d.querySelectorAll('.validation-step').forEach(function (p) {
      var visibles = ['.validation-ok', '.validation-nok', '.validation-fail'].filter(function (s) { return !p.querySelector(s).hidden; }).length;
      if (visibles !== 1) { ok(false, 'el panel ' + p.id + ' muestra ' + visibles + ' estados'); }
    });
    // clic en una pestaña: se activa su panel y solo ese
    var pill = d.getElementById('standards-pill');
    pill.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
    ok(d.getElementById('standards').classList.contains('active') && !d.getElementById('summary').classList.contains('active'), 'clic en Standards muestra solo su panel');
    var code = d.querySelector('#standards code.error');
    if (code) {
      code.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
      var pre = code.parentNode.querySelector('.error-details');
      ok(pre && !pre.hidden, 'clic en una línea despliega el bloque de código');
    }
    var oficial = Array.prototype.filter.call(d.querySelectorAll('a'), function (a) { return a.href === 'https://validator.prestashop.com/'; });
    ok(oficial.length >= 2, 'enlaces a validator.prestashop.com (' + oficial.length + ')');
    console.log('  resumen: ' + d.getElementById('vl-final').textContent.trim());
    // Sección «Tras el 100 %»: se pinta sola tras la validación (y la carga es asíncrona)
    var t0 = Date.now();
    (function esperarT1() {
      var caja = d.getElementById('vl-tras100');
      if (caja && !/Cargando/.test(caja.textContent)) { return comprobarT1(caja); }
      if (Date.now() - t0 > 15000) { ok(false, 'la sección «Tras el 100 %» no se pinta'); return salir(); }
      setTimeout(esperarT1, 200);
    })();
  }
  function comprobarT1(caja) {
    ok(errores.length === 0, 'cero errores de JavaScript con la sección «Tras el 100 %»');
    fetch(BASE + ruta.replace(/\/validate$/, '/tras100'), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (t) {
      var e = t.estado;
      if (!e) {
        ok(!!caja.querySelector('[data-tras100="lanzar"]') === (t.es100 && !t.puede), 'sin lanzar: botón solo si se puede');
        return salir();
      }
      ok(caja.querySelectorAll('.t1-pasos li').length === e.pasos.length, 'un renglón por paso (' + e.pasos.length + ')');
      ok(caja.querySelectorAll('.t1-galeria img').length === (e.capturas || []).length, 'galería con ' + (e.capturas || []).length + ' capturas');
      ok(!e.video || !!caja.querySelector('video'), 'vídeo reproducible si lo hay');
      ok(!e.pdf || !!caja.querySelector('a[href$="/tras100/pdf"]'), 'enlace al PDF si lo hay');
      var pruebas = [];
      if (e.pdf) { pruebas.push(fetch(BASE + ruta.replace(/\/validate$/, '/tras100/pdf'), { method: 'GET' }).then(function (r) { ok(r.status === 200 && /pdf/.test(r.headers.get('content-type')), 'el PDF se sirve (' + r.status + ')'); })); }
      if (e.video) { pruebas.push(fetch(BASE + ruta.replace(/\/validate$/, '/tras100/video'), { headers: { Range: 'bytes=0-1023' } }).then(function (r) { ok(r.status === 206 && r.headers.get('content-length') === '1024', 'el vídeo se sirve por trozos (' + r.status + ')'); })); }
      if (e.capturas && e.capturas.length) { var img = caja.querySelector('.t1-galeria img'); pruebas.push(fetch(new URL(img.getAttribute('src'), BASE).href).then(function (r) { ok(r.status === 200 && r.headers.get('content-type') === 'image/png', 'las capturas se sirven (' + r.status + ')'); })); }
      Promise.all(pruebas).then(salir, function (err) { ok(false, String(err)); salir(); });
    });
  }
  function salir() {
    console.log(fallos ? 'FALLOS: ' + fallos : 'TODO EN VERDE');
    process.exit(fallos ? 1 : 0);
  }
});
