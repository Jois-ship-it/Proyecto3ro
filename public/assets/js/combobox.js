(function(){
  'use strict';

  function escapeHtml(s){
    const d = document.createTextNode(s);
    const span = document.createElement('span');
    span.appendChild(d);
    return span.innerHTML;
  }

  class Combobox {
    constructor(root){
      this.root = root;
      this.options = [];
      try {
        const raw = root.getAttribute('data-options') || '[]';
        this.options = JSON.parse(raw);
      } catch (e) { this.options = []; }
      this.name = root.getAttribute('data-name') || '';
      this.required = root.getAttribute('data-required') === 'true';
      this.input = root.querySelector('input[type="text"]');
      this.hidden = root.querySelector('input[type="hidden"]');
      this.list = root.querySelector('.combobox-list');
      this.items = [];
      this.selected = -1;
      this.uid = Math.random().toString(36).slice(2,9);
      this.setupA11y();
      this.bind();
    }

    setupA11y(){
      if (this.input) {
        this.input.setAttribute('role','combobox');
        this.input.setAttribute('aria-expanded','false');
        this.input.setAttribute('aria-autocomplete','list');
        const listId = `cb-list-${this.uid}`;
        this.list.id = listId;
        this.input.setAttribute('aria-controls', listId);
      }
      if (this.list) this.list.setAttribute('role','listbox');
    }

    bind(){
      this.input.addEventListener('input', (e)=>{
        const v = e.target.value.trim().toLowerCase();
        // clear hidden on manual edits
        if (this.hidden) this.hidden.value = '';
        if (v.length === 0) return this.close();
        this.filter(v);
      });

      this.input.addEventListener('keydown', (e)=>{
        if (e.key === 'ArrowDown') { e.preventDefault(); this.move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.move(-1); }
        else if (e.key === 'Enter') { e.preventDefault(); this.choose(); }
        else if (e.key === 'Escape') { this.close(); }
      });

      this.list.addEventListener('click', (ev)=>{
        const li = ev.target.closest('li[data-index]');
        if (!li) return;
        const idx = parseInt(li.getAttribute('data-index'), 10);
        this.setValue(idx);
      });

      document.addEventListener('click', (ev)=>{ if (!this.root.contains(ev.target)) this.close(); });

      // form submit guard
      const form = this.input.closest('form');
      if (form) {
        form.addEventListener('submit', (ev)=>{
          if (this.required && this.hidden && !this.hidden.value) {
            ev.preventDefault();
            this.input.focus();
            this.input.setSelectionRange(0, this.input.value.length);
          }
        });
      }
    }

    filter(q){
      const found = [];
      const needle = q.toLowerCase();
      for (let i=0;i<this.options.length;i++){
        const it = this.options[i];
        const name = (it.nombre ?? it.nombre_completo ?? it.nombre_corto ?? '') + '';
        if (name.toLowerCase().indexOf(needle) !== -1) found.push(Object.assign({__idx:i}, it));
      }
      this.render(found);
    }

    render(items){
      this.items = items;
      this.selected = -1;
      this.list.innerHTML = '';
      if (!this.items.length) return this.close();
      const frag = document.createDocumentFragment();
      this.items.forEach((it, idx)=>{
        const li = document.createElement('li');
        li.setAttribute('role','option');
        li.setAttribute('data-index', idx);
        li.innerHTML = escapeHtml(it.nombre ?? '');
        frag.appendChild(li);
      });
      this.list.appendChild(frag);
      this.list.style.display = 'block';
      this.input.setAttribute('aria-expanded','true');
    }

    move(dir){
      if (!this.items.length) return;
      const next = Math.max(0, Math.min(this.items.length-1, this.selected + dir));
      this.select(next);
    }

    select(idx){
      const prev = this.list.querySelector('li.active');
      if (prev) prev.classList.remove('active');
      const node = this.list.querySelector(`li[data-index="${idx}"]`);
      if (node) node.classList.add('active');
      this.selected = idx;
      const id = `cb-opt-${this.uid}-${idx}`;
      if (node) node.id = id;
      if (this.input) this.input.setAttribute('aria-activedescendant', id);
    }

    choose(){
      if (this.selected >= 0) this.setValue(this.selected);
      else if (this.items.length === 1) this.setValue(0);
    }

    setValue(idx){
      const it = this.items[idx];
      if (!it) return;
      if (this.hidden) this.hidden.value = String(it.id ?? it.ID ?? '');
      this.input.value = it.nombre ?? '';
      this.close();
    }

    close(){
      this.list.innerHTML = '';
      this.list.style.display = 'none';
      this.input.setAttribute('aria-expanded','false');
      this.input.removeAttribute('aria-activedescendant');
      this.items = [];
      this.selected = -1;
    }
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('[data-combobox]').forEach(root=>new Combobox(root));
  });

})();
