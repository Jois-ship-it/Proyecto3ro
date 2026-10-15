// Un DOM mínimo para probar los scripts del sitio con Node, sin instalar nada.
//
// No es un navegador: solo lo que usan app.js, modales.js, gestion-torneo.js, bracket.js, registro.js,
// correcciones.js, proximos-partidos.js, calendar.js y torneos-en-vivo.js (clases, atributos, dataset, eventos con burbujeo,
// closest, insertBefore/remove y selectores simples). Si un script empieza a usar algo que acá no existe, el test falla a propósito.
'use strict';

class Elemento {
  constructor(etiqueta, atributos = {}, id = null) {
    this.tagName = etiqueta.toUpperCase();
    this.attrs = {};
    this.hijos = [];
    this.padre = null;
    this.escuchas = {};
    this.textContent = '';
    this.value = '';
    this.disabled = false;
    this.hidden = false;
    this.style = {};
    this.caja = { top: 0, bottom: 0, left: 0, right: 0 };
    this.offsetHeight = 100;
    this.offsetWidth = 150;
    this.focoRecibido = false;
    const self = this;
    this.classList = {
      add: (...c) => { const s = new Set(self.clases()); c.forEach((x) => s.add(x)); self.attrs.class = [...s].join(' '); },
      remove: (...c) => { self.attrs.class = self.clases().filter((x) => !c.includes(x)).join(' '); },
      contains: (c) => self.clases().includes(c),
      toggle: (c, forzar) => {
        const tiene = self.clases().includes(c);
        const poner = forzar === undefined ? !tiene : Boolean(forzar);
        if (poner) self.classList.add(c); else self.classList.remove(c);
        return poner;
      },
    };
    if (id) this.attrs.id = id;
    for (const [k, v] of Object.entries(atributos)) this.attrs[k] = v;
    this.dataset = new Proxy({}, {
      get: (_, k) => { const a = 'data-' + String(k).replace(/[A-Z]/g, (m) => '-' + m.toLowerCase()); return a in self.attrs ? self.attrs[a] : undefined; },
      set: (_, k, v) => { self.attrs['data-' + String(k).replace(/[A-Z]/g, (m) => '-' + m.toLowerCase())] = String(v); return true; },
      has: (_, k) => ('data-' + String(k).replace(/[A-Z]/g, (m) => '-' + m.toLowerCase())) in self.attrs,
      ownKeys: () => Object.keys(self.attrs).filter((a) => a.startsWith('data-')).map((a) => a.slice(5).replace(/-([a-z])/g, (_, l) => l.toUpperCase())),
      getOwnPropertyDescriptor: (_, k) => ({ enumerable: true, configurable: true, value: self.dataset[k] }),
    });
  }
  clases() { return (this.attrs.class || '').split(/\s+/).filter(Boolean); }
  get id() { return this.attrs.id || ''; }
  get className() { return this.attrs.class || ''; }
  set className(v) { this.attrs.class = v; }
  get action() { return this.attrs.action || ''; }
  // como en un navegador: asignar la propiedad `href` escribe el atributo (y `a[href]` lo encuentra)
  get href() { return this.attrs.href || ''; }
  set href(v) { this.attrs.href = String(v); }
  set action(v) { this.attrs.action = v; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  appendChild(h) { h.padre = this; this.hijos.push(h); return h; }
  insertBefore(nuevo, referencia) {
    nuevo.padre = this;
    const i = referencia ? this.hijos.indexOf(referencia) : -1;
    if (i < 0) this.hijos.push(nuevo); else this.hijos.splice(i, 0, nuevo);
    return nuevo;
  }
  remove() { if (this.padre) { this.padre.hijos = this.padre.hijos.filter((h) => h !== this); this.padre = null; } }
  get firstChild() { return this.hijos[0] || null; }
  focus() { this.focoRecibido = true; if (this.doc) this.doc.activeElement = this; }
  getBoundingClientRect() { return { ...this.caja, width: this.caja.right - this.caja.left, height: this.caja.bottom - this.caja.top }; }
  addEventListener(tipo, f, captura = false) { (this.escuchas[tipo] ||= []).push({ f, captura: Boolean(captura) }); }
  contains(otro) { for (let n = otro; n; n = n.padre) if (n === this) return true; return false; }
  closest(sel) { for (let n = this; n; n = n.padre) if (n.coincide && n.coincide(sel)) return n; return null; }
  descendientes() { const out = []; const rec = (n) => n.hijos.forEach((h) => { out.push(h); rec(h); }); rec(this); return out; }

  // selectores: lista separada por comas; cada uno, compuestos separados por espacio (descendiente)
  coincideSimple(compuesto) {
    const m = compuesto.match(/^([a-z0-9]*)((?:#[\w-]+|\.[\w-]+|\[[^\]]+\]|:not\([^)]*\))*)$/i);
    if (!m) throw new Error('selector no soportado por el DOM falso: ' + compuesto);
    if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
    for (const t of m[2].match(/#[\w-]+|\.[\w-]+|\[[^\]]+\]|:not\([^)]*\)/g) || []) {
      if (t.startsWith(':not(')) { if (this.coincideSimple(t.slice(5, -1))) return false; }
      else if (t[0] === '#') { if (this.id !== t.slice(1)) return false; }
      else if (t[0] === '.') { if (!this.clases().includes(t.slice(1))) return false; }
      else {
        const at = t.slice(1, -1).match(/^([\w-]+)(?:=["']?([^"']*)["']?)?$/);
        if (!at || !(at[1] in this.attrs)) return false;
        if (at[2] !== undefined && this.attrs[at[1]] !== at[2]) return false;
      }
    }
    return true;
  }
  coincide(sel) {
    return sel.split(',').some((parte) => {
      const comp = parte.trim().split(/\s+/);
      if (!this.coincideSimple(comp[comp.length - 1])) return false;
      let nodo = this.padre;
      for (let i = comp.length - 2; i >= 0; i--) {
        while (nodo && !nodo.coincideSimple(comp[i])) nodo = nodo.padre;
        if (!nodo) return false;
        nodo = nodo.padre;
      }
      return true;
    });
  }
  querySelectorAll(sel) { return this.descendientes().filter((n) => n.coincide(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  get innerHTML() { return this._html || ''; }
  set innerHTML(v) { this._html = v; this.hijos = []; }

  // eventos con burbujeo; devuelve el evento
  despachar(tipo, extra = {}) {
    const ev = { type: tipo, target: this, currentTarget: null, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
    const cadena = []; for (let n = this; n; n = n.padre) cadena.push(n);
    cadena.reverse();                                    // captura: de la raíz hacia el objetivo
    for (const n of cadena) for (const e of n.escuchas[tipo] || []) if (e.captura) { ev.currentTarget = n; e.f(ev); }
    cadena.reverse();                                    // burbuja: del objetivo hacia la raíz
    for (const n of cadena) for (const e of n.escuchas[tipo] || []) if (!e.captura) { ev.currentTarget = n; e.f(ev); }
    return ev;
  }
  click() { return this.despachar('click'); }
}

class ElementoFormulario extends Elemento {}

/** Un documento con su <body>. `agregar(elemento, padre?)` arma el árbol; `por(id)` lo busca. */
function crearDocumento() {
  const body = new Elemento('body');
  const documento = new Elemento('html');
  documento.appendChild(body);
  documento.body = body;
  documento.activeElement = body;
  body.doc = documento;
  documento.doc = documento;
  documento.addEventListener = (tipo, f, c) => Elemento.prototype.addEventListener.call(documento, tipo, f, c);
  documento.getElementById = (id) => documento.descendientes().find((n) => n.id === id) || null;
  documento.createElement = (t) => new Elemento(t);
  documento.createTextNode = (t) => { const e = new Elemento('#text'); e.textContent = String(t); return e; };
  documento.title = '';
  return documento;
}

function nuevo(documento, etiqueta, atributos = {}, padre = null) {
  const e = (etiqueta === 'form' ? new ElementoFormulario(etiqueta, atributos) : new Elemento(etiqueta, atributos));
  e.doc = documento;
  (padre || documento.body).appendChild(e);
  return e;
}

/**
 * Un «navegador» para los scripts: `window` ES el contexto global (como en un navegador, donde `window.FlexModales` y
 * un `FlexModales` suelto son lo mismo). Se le pasa el documento y, si hace falta, valores propios (confirm, tamaño de ventana…).
 */
function crearVentana(documento, extra = {}) {
  const ventana = {
    document: documento, console, Element: Elemento, HTMLFormElement: ElementoFormulario,
    innerHeight: 800, innerWidth: 1200, location: { pathname: '/', href: '/' },
    escuchas: {}, impresiones: 0, confirmaciones: [],
    addEventListener(tipo, f) { (this.escuchas[tipo] ||= []).push(f); },
    print() { this.impresiones++; },
    confirm(mensaje) { this.confirmaciones.push(mensaje); return this.respuestaConfirm !== false; },
    ...extra,
  };
  ventana.window = ventana;
  require('vm').createContext(ventana);
  return ventana;
}

/** Ejecuta un script de public/assets/js en esa ventana (varios scripts pueden compartir una). */
function correr(archivo, ventana) {
  const fs = require('fs'); const path = require('path');
  require('vm').runInContext(fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', archivo), 'utf8'), ventana, { filename: archivo });
  return ventana;
}

module.exports = { Elemento, ElementoFormulario, crearDocumento, nuevo, crearVentana, correr };
