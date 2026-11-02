/**
 * Página de validación del validator local. Misma mecánica que el validation.js del oficial:
 * warmup y después un GET por pestaña (/module/<id>/validate/<paso>) que se pinta al llegar.
 * Las funciones de pintado son puras (devuelven HTML) para poder probarlas sin navegador.
 */
(function (global) {
  'use strict';

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function readMore(links) {
    var out = '';
    if (!links) { return out; }
    Object.keys(links).forEach(function (k) {
      out += ' <a class="rm" href="' + esc(links[k]) + '" target="_blank" rel="noopener">Read more: ' + esc(isNaN(k) ? k : links[k]) + ' &#8599;</a>';
    });
    return out;
  }

  /** Mensajes con lista de ficheros (result.files). El oficial deja <code> en el texto. */
  function renderFiles(r) {
    if (!r.files) { return ''; }
    var out = '<dl>';
    Object.keys(r.files).forEach(function (msg) {
      var texto = esc(msg).replace(/&lt;(\/?)code&gt;/g, '<$1code>');
      out += '<dt class="section-title">&#128193; ' + texto + readMore(r.readMoreLinks && r.readMoreLinks[msg]) + '</dt>';
      out += '<dd><blockquote class="error-message error-file"><ul>';
      r.files[msg].forEach(function (f) { out += '<li>' + esc(f) + '</li>'; });
      out += '</ul></blockquote></dd>';
    });
    return out + '</dl>';
  }

  /** Mensajes por fichero y línea (result.code). */
  function renderCode(r) {
    if (!r.code) { return ''; }
    var out = '<dl>';
    Object.keys(r.code).forEach(function (file) {
      out += '<dt class="section-title">&#128196; ' + esc(file) + '</dt><dd><ul>';
      var msgs = r.code[file].messages;
      Object.keys(msgs).forEach(function (msg) {
        var o = msgs[msg];
        var tipo = String(o.type || 'Error').toLowerCase();
        out += '<li><blockquote class="' + tipo + '-message"><span class="lbl lbl-' + tipo + '">' + esc(o.type) + '</span> ' + esc(msg);
        if (o.content && o.content[0]) { out += readMore(o.content[0].readMoreLinks); }
        out += '<dl class="lines">';
        o.content.forEach(function (c) {
          out += '<dt title="' + esc(file + ':' + c.line) + '">' + esc(c.line) + '.</dt><dd><code class="error">' + esc(c.content).replace(/ /g, '<span class="sp">·</span>') + '</code>';
          if (c.block) { out += '<pre class="error-details" hidden>' + esc(c.block) + '</pre>'; }
          out += '</dd>';
        });
        out += '</dl></blockquote></li>';
      });
      out += '</ul></dd>';
    });
    return out + '</dl>';
  }

  function renderBadges(r) {
    if (r.fallo) { return '<span class="badge badge-fail" title="' + esc(r.fallo) + '">!</span>'; }
    var t = r.types || {};
    var out = '';
    if (t.warning) { out += '<span class="badge badge-warning">' + t.warning + '</span>'; }
    if (t.error) { out += '<span class="badge badge-danger">' + t.error + '</span>'; }
    if (!out) { out = '<span class="badge badge-success">&#10003;</span>'; }
    return out;
  }

  function renderGoals(step, titulo, r) {
    var out = '';
    Object.keys(r.goals || {}).forEach(function (g) {
      var x = r.goals[g];
      var cl = x.hits === 0 ? 'badge-success' : (x.tipo === 'error' ? 'badge-danger' : (x.tipo === 'warning' ? 'badge-warning' : 'badge-info'));
      out += '<tr><td>' + esc(titulo) + '</td><td><code>' + esc(g) + '</code></td><td>' + esc(x.titulo) + ' <small class="muted">(' + esc(x.origen) + ')</small></td><td><span class="badge ' + cl + '">' + (x.hits === 0 ? '&#10003;' : x.hits) + '</span></td></tr>';
    });
    return out;
  }

  /**
   * Panel «Tras el 100 %»: elegir pasos (si aún no se ha lanzado), progreso de cada paso y lo que ha
   * generado (capturas, manual PDF y vídeo). d = respuesta de GET /module/<id>/tras100.
   */
  function renderTras100(d, idVal) {
    var b = '/module/' + encodeURIComponent(idVal) + '/tras100';
    var e = d.estado;
    var ops = d.opciones || {};
    var sel = d.seleccion || [];
    var marcas = { ok: '&#10003;', fallo: '&#10007;', omitido: '&ndash;', en_curso: '&#8987;', pendiente: '&middot;' };
    var nombres = { ok: 'hecho', fallo: 'falló', omitido: 'omitido', en_curso: 'en curso', pendiente: 'pendiente' };
    var out = '';
    function elegir(textoBoton) {
      var h = '<div class="t1-elegir">';
      Object.keys(ops).forEach(function (k) {
        h += '<label class="chk"><input type="checkbox" name="vl-t1" value="' + esc(k) + '"' + (sel.indexOf(k) !== -1 ? ' checked' : '') + '> ' + esc(ops[k]) + '</label>';
      });
      return h + '<button type="button" class="btn btn-primary" data-tras100="lanzar">' + esc(textoBoton) + '</button></div>';
    }
    if (!e) {
      if (!d.es100) {
        out += '<p class="muted">Se hace cuando el módulo pasa al 100 %' + (sel.length ? ' (elegido: ' + esc(sel.join(', ')) + ')' : '') + '.</p>';
      } else if (d.puede) {
        out += '<p class="muted">No se puede hacer aquí: ' + esc(d.puede) + '.</p>';
      } else {
        out += '<p>El módulo pasa al 100 %. ¿Qué hago ahora?</p>' + elegir('Hacerlo');
      }
      return out;
    }
    var cab;
    if (e.estado === 'terminado') {
      cab = e.ok ? '<div class="alert alert-success">Hecho: ' + esc((e.seleccion || []).join(', ')) + ' (' + esc(e.inicio) + ' → ' + esc(e.fin) + ').</div>'
        : '<div class="alert alert-danger">Terminado con fallos. Mira el registro del paso marcado.</div>';
    } else if (e.estado === 'interrumpido') {
      cab = '<div class="alert alert-danger">Interrumpido: el proceso dejó de responder.</div>';
    } else {
      cab = '<div class="alert alert-info"><span class="spin"></span> En marcha desde ' + esc(e.inicio) + '. Esta sección se actualiza sola.</div>';
    }
    out += cab + '<ul class="t1-pasos">';
    (e.pasos || []).forEach(function (p) {
      out += '<li class="t1-' + esc(p.estado) + '"><span class="t1-m">' + (marcas[p.estado] || '?') + '</span> <b>' + esc(p.titulo) + '</b> '
        + '<span class="muted">' + esc(nombres[p.estado] || p.estado) + (p.segundos !== null && p.segundos !== undefined ? ' · ' + esc(p.segundos) + ' s' : '') + '</span>'
        + (p.motivo ? '<div class="t1-motivo">' + esc(p.motivo) + '</div>' : '')
        + (p.log ? ' <a class="small" target="_blank" href="' + b + '/log/' + encodeURIComponent(p.id) + '">registro</a>' : '')
        + (p.estado === 'en_curso' && p.cola && p.cola.length ? '<pre class="t1-cola">' + esc(p.cola.join('\n')) + '</pre>' : '')
        + '</li>';
    });
    out += '</ul>';
    if (e.pdf || e.video) {
      out += '<p class="actions">'
        + (e.pdf ? '<a class="btn" target="_blank" href="' + b + '/pdf">Abrir el manual PDF</a> ' : '')
        + (e.video ? '<a class="btn" target="_blank" href="' + b + '/video">Abrir el vídeo</a>' : '') + '</p>'
        + '<p class="small muted">' + (e.pdf ? esc(e.pdf) + '<br>' : '') + (e.video ? esc(e.video) : '') + '</p>';
    }
    if (e.video) {
      out += '<video class="t1-video" controls preload="metadata" src="' + b + '/video"></video>';
    }
    if (e.capturas && e.capturas.length) {
      out += '<h5>Capturas (' + e.capturas.length + ')</h5><div class="t1-galeria">';
      e.capturas.forEach(function (c) {
        var n = String(c).split('/').pop();
        var u = b + '/captura/' + encodeURIComponent(n);
        out += '<a target="_blank" href="' + u + '" title="' + esc(n) + '"><img loading="lazy" src="' + u + '" alt="' + esc(n) + '"><span>' + esc(n) + '</span></a>';
      });
      out += '</div>';
    }
    if (e.estado !== 'en_curso' && e.estado !== 'en_cola' && d.es100 && !d.puede) {
      out += '<details class="t1-repetir"><summary>Volver a hacerlo</summary>' + elegir('Volver a hacerlo') + '</details>';
    }
    return out;
  }

  global.VlRender = { esc: esc, renderFiles: renderFiles, renderCode: renderCode, renderBadges: renderBadges, renderGoals: renderGoals, renderTras100: renderTras100 };

  if (typeof document === 'undefined') { return; }

  var raizVal = document.querySelector('.validation');
  if (!raizVal) { return; }
  var id = raizVal.getAttribute('data-id');
  var base = '/module/' + id + '/validate';
  var total = { error: 0, warning: 0, fallos: 0, hechos: 0 };

  // Menú de pestañas por delegación (los paneles ya existen, pero así nada depende del orden)
  document.addEventListener('click', function (e) {
    var a = e.target.closest('#validation-menu .list-group-item');
    if (a) {
      e.preventDefault();
      document.querySelectorAll('#validation-menu .list-group-item').forEach(function (x) { x.classList.remove('active'); });
      a.classList.add('active');
      document.querySelectorAll('.tab-pane').forEach(function (x) { x.classList.remove('active'); });
      var p = document.getElementById(a.getAttribute('data-step'));
      if (p) { p.classList.add('active'); }
      return;
    }
    var lz = e.target.closest('[data-tras100="lanzar"]');
    if (lz) {
      var caja = lz.closest('.t1-elegir');
      var marcados = Array.prototype.map.call(caja.querySelectorAll('input[name="vl-t1"]:checked'), function (x) { return x.value; });
      if (!marcados.length) { alert('Elige al menos un paso.'); return; }
      lz.disabled = true;
      lanzarTras100(marcados.join(','));
      return;
    }
    var c = e.target.closest('code.error');
    if (c) {
      // el bloque de contexto va justo después de la línea, dentro del mismo <dd>
      var pre = c.parentNode ? c.parentNode.querySelector('.error-details') : null;
      if (pre) { pre.hidden = !pre.hidden; }
    }
  });

  function pasos() {
    return Array.prototype.map.call(document.querySelectorAll('.validation-step'), function (x) { return x.id; });
  }

  function pintar(step, r) {
    var panel = document.getElementById(step);
    var pill = document.getElementById(step + '-pill');
    var titulo = pill ? pill.querySelector('span').textContent : step;
    panel.querySelector('.validation-wait').hidden = true;
    if (r.fallo) {
      var f = panel.querySelector('.validation-fail');
      f.hidden = false;
      f.textContent = 'Oups, An error happened during this validation step: ' + r.fallo;
      total.fallos++;
    } else if (!r.files && !r.code) {
      panel.querySelector('.validation-ok').hidden = false;
    } else {
      var nok = panel.querySelector('.validation-nok');
      nok.hidden = false;
      var intro = '<p class="alert alert-info">' + esc(r.intro || '') + (r.introLink ? ' <a class="rm" target="_blank" rel="noopener" href="' + esc(r.introLink) + '">' + esc(r.introLink) + '</a>' : '') + '</p>';
      nok.innerHTML = intro + renderFiles(r) + renderCode(r);
    }
    var extra = '';
    if (r.phpstan && r.phpstan.length) { extra += '<p class="muted small">PHPStan nivel 5 contra PrestaShop ' + esc(r.phpstan.join(', ')) + '</p>'; }
    (r.avisos || []).forEach(function (a) { extra += '<p class="muted small">' + esc(a) + '</p>'; });
    if (r.tiempo !== null && r.tiempo !== undefined) { extra += '<p class="muted small">' + esc(r.tiempo) + ' s</p>'; }
    if (extra) { panel.querySelector('.panel-body').insertAdjacentHTML('beforeend', extra); }
    if (pill) { pill.querySelector('.badges').innerHTML = renderBadges(r); }
    var t = r.types || {};
    total.error += t.error || 0;
    total.warning += t.warning || 0;
    total.hechos++;
    document.querySelector('#vl-goals tbody').insertAdjacentHTML('beforeend', renderGoals(step, titulo, r));
  }

  // ---- Tras el 100 %
  var t1Base = '/module/' + id + '/tras100';
  var t1Timer = null;
  var t1Auto = false;

  function cargarTras100(alTerminarValidacion) {
    var caja = document.getElementById('vl-tras100');
    if (!caja) { return; }
    pedir(t1Base).then(function (d) {
      if (d.fallo || d.error) { caja.innerHTML = '<p class="muted">' + esc(d.fallo || d.error) + '</p>'; return; }
      // elegido antes de validar y recién llegado al 100 %: se lanza solo, una vez
      if (alTerminarValidacion && !t1Auto && !d.estado && d.es100 && !d.puede && d.seleccion && d.seleccion.length) {
        t1Auto = true;
        lanzarTras100(d.seleccion.join(','));
        return;
      }
      caja.innerHTML = renderTras100(d, id);
      clearTimeout(t1Timer);
      if (d.estado && d.estado.vivo) { t1Timer = setTimeout(function () { cargarTras100(false); }, 3000); }
    });
  }

  function lanzarTras100(pasosCsv) {
    var caja = document.getElementById('vl-tras100');
    var cuerpo = new URLSearchParams();
    cuerpo.set('pasos', pasosCsv);
    fetch(t1Base, { method: 'POST', cache: 'no-store', credentials: 'same-origin', body: cuerpo }).then(function (res) {
      return res.json();
    }).then(function (r) {
      if (!r.ok && caja) { caja.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger">No se lanza: ' + esc(r.motivo || '') + '</div>'); }
      setTimeout(function () { cargarTras100(false); }, 800);
    }).catch(function (err) {
      if (caja) { caja.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger">' + esc(String(err)) + '</div>'); }
    });
  }

  function final() {
    cargarTras100(true);
    var el = document.getElementById('vl-final');
    if (total.fallos) {
      el.className = 'alert alert-danger';
      el.textContent = total.fallos + ' paso(s) no se han podido ejecutar. Errores: ' + total.error + ' · avisos: ' + total.warning + '.';
    } else if (total.error + total.warning === 0) {
      el.className = 'alert alert-success';
      el.innerHTML = '<b>All green!</b> 0 errores y 0 avisos: el módulo pasa el validator al 100 %. Ya se puede subir a <a href="https://validator.prestashop.com/" target="_blank" rel="noopener">validator.prestashop.com</a>.';
    } else {
      el.className = 'alert alert-warning';
      el.textContent = 'No pasa: ' + total.error + ' errores y ' + total.warning + ' avisos. Revisa las pestañas marcadas.';
    }
  }

  function pedir(url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' }).then(function (res) {
      return res.json().catch(function () { return { fallo: 'Respuesta no válida del servidor (HTTP ' + res.status + ')' }; });
    });
  }

  function correr(lista, i) {
    if (i >= lista.length) { final(); return; }
    var step = lista[i];
    var pill = document.getElementById(step + '-pill');
    if (pill) { pill.classList.add('running'); }
    document.getElementById(step).querySelector('.validation-wait').textContent = 'Validando…';
    pedir(base + '/' + step).then(function (r) {
      if (pill) { pill.classList.remove('running'); }
      pintar(step, r);
      correr(lista, i + 1);
    }).catch(function (err) {
      if (pill) { pill.classList.remove('running'); }
      pintar(step, { fallo: String(err) });
      correr(lista, i + 1);
    });
  }

  cargarTras100(false);
  pedir(base + '/warmup').then(function () { correr(pasos(), 0); }, function () { correr(pasos(), 0); });
})(typeof window !== 'undefined' ? window : globalThis);
