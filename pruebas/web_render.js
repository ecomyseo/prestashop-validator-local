/**
 * Prueba sin navegador de las funciones de pintado de web/assets/validation.js:
 * se les pasan respuestas JSON reales de la web local y se comprueba lo que pintan.
 *
 *   node pruebas/web_render.js <respuesta1.json> [<respuesta2.json> ...]
 */
'use strict';
var fs = require('fs');
var path = require('path');
global.window = undefined;
require(path.join(__dirname, '..', 'web', 'assets', 'validation.js'));
var R = globalThis.VlRender;
var fallos = 0;
function ok(c, msg) {
  if (!c) { fallos++; console.log('  [XX] ' + msg); } else { console.log('  [OK] ' + msg); }
}
process.argv.slice(2).forEach(function (f) {
  var r = JSON.parse(fs.readFileSync(f, 'utf8'));
  console.log(path.basename(f));
  var html = R.renderFiles(r) + R.renderCode(r);
  var esperados = 0;
  Object.keys(r.files || {}).forEach(function (m) { esperados += r.files[m].length; });
  Object.keys(r.code || {}).forEach(function (fi) {
    Object.keys(r.code[fi].messages).forEach(function (m) { esperados += r.code[fi].messages[m].content.length; });
  });
  var pintados = (html.match(/<li>/g) || []).length - Object.keys(r.code || {}).reduce(function (a, fi) {
    return a + Object.keys(r.code[fi].messages).length;
  }, 0) + (html.match(/<dt title=/g) || []).length;
  ok(pintados === esperados, 'pinta ' + esperados + ' resultados (pintados ' + pintados + ')');
  ok(html.indexOf('<script') === -1, 'nada sin escapar (<script)');
  var badges = R.renderBadges(r);
  var t = r.types || {};
  ok(t.error ? badges.indexOf('>' + t.error + '<') !== -1 : true, 'insignia de errores = ' + (t.error || 0));
  var goals = R.renderGoals('x', 'X', r);
  ok((goals.match(/<tr>/g) || []).length === Object.keys(r.goals || {}).length, 'una fila por goal (' + Object.keys(r.goals || {}).length + ')');
});
// Escapado: un mensaje con HTML no puede salir como HTML
var malicioso = { files: { '<img src=x onerror=alert(1)>': ['/a<b>.php'] }, types: { error: 1 }, goals: {} };
var h = R.renderFiles(malicioso);
ok(h.indexOf('<img') === -1 && h.indexOf('&lt;img') !== -1, 'escapa el HTML de los mensajes');
ok(R.renderFiles({ files: { 'x <code>a</code>': ['/f'] } }).indexOf('<code>a</code>') !== -1, 'deja <code> del mensaje oficial');
// Panel «Tras el 100 %»
var ops = { instalar: 'Instalar', capturas: 'Capturas', manual: 'Manual PDF', video: 'Vídeo' };
var t1 = R.renderTras100({ es100: true, puede: null, opciones: ops, seleccion: ['video'], estado: null }, 'm-20261102-000000');
ok(t1.indexOf('data-tras100="lanzar"') !== -1 && (t1.match(/name="vl-t1"/g) || []).length === 4, '100 % sin lanzar: casillas y botón');
ok(/value="video" checked/.test(t1) && !/value="instalar" checked/.test(t1), 'marca lo elegido antes de validar');
ok(R.renderTras100({ es100: false, opciones: ops, seleccion: [], estado: null }, 'x').indexOf('data-tras100') === -1, 'sin 100 %: no se ofrece');
ok(R.renderTras100({ es100: true, puede: 'solo carpetas', opciones: ops, estado: null }, 'x').indexOf('solo carpetas') !== -1, 'dice por qué no se puede');
var hecho = {
  es100: true, puede: null, opciones: ops, seleccion: [],
  estado: { estado: 'terminado', ok: true, vivo: false, inicio: 'a', fin: 'b', seleccion: ['instalar', 'capturas', 'manual', 'video'],
    pdf: 'Z:/m/manual.pdf', video: 'Z:/m/v.mp4', capturas: ['Z:/c/a b.png', 'Z:/c/<x>.png'],
    pasos: [{ id: 'instalar', titulo: 'Instalar', estado: 'ok', segundos: 40, motivo: null, log: 'l', cola: [] },
      { id: 'video', titulo: 'Vídeo', estado: 'fallo', segundos: 3, motivo: 'salió con código 1', log: 'l', cola: [] }] }
};
var t2 = R.renderTras100(hecho, 'm-20261102-000000');
ok(t2.indexOf('/module/m-20261102-000000/tras100/pdf') !== -1 && t2.indexOf('<video') !== -1, 'enlace al PDF y vídeo reproducible');
ok((t2.match(/<img /g) || []).length === 2 && t2.indexOf('/captura/a%20b.png') !== -1, 'galería con las capturas');
ok(t2.indexOf('<x>') === -1 && t2.indexOf('&lt;x&gt;') !== -1, 'escapa los nombres de las capturas');
ok(t2.indexOf('salió con código 1') !== -1 && t2.indexOf('/log/video') !== -1, 'motivo del fallo y enlace al registro');
ok(R.renderTras100({ es100: true, opciones: ops, estado: { estado: 'en_curso', vivo: true, inicio: 'a', pasos: [] } }, 'x').indexOf('se actualiza sola') !== -1, 'en marcha: avisa de que se refresca');
console.log(fallos ? 'FALLOS: ' + fallos : 'TODO EN VERDE');
process.exit(fallos ? 1 : 0);
