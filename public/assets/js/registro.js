/* FlexArena — registro.js
 *
 * Las reglas de la contraseña en el formulario de registro, a medida que se escribe. Cada regla de la lista
 * `#pwHints` (`data-rule`) se marca como cumplida o no (`.cumple`), y el botón de crear la cuenta queda
 * deshabilitado hasta que se cumplan todas Y esté marcada la casilla de la política de privacidad y los términos
 * (`#acepto_politicas`). La validación que manda es la del servidor (core/Validator.php y RegistroService):
 * esto solo evita enviar un formulario que se va a rechazar.
 */
'use strict';

(() => {
  const clave = document.getElementById('password');
  const confirmacion = document.getElementById('password_confirm');
  const reglas = document.getElementById('pwHints');
  const boton = document.getElementById('submitBtn');
  const acepto = document.getElementById('acepto_politicas');
  if (!clave || !confirmacion || !reglas || !boton) return;

  function comprobar() {
    const v = clave.value;
    const cumple = {
      len:   v.length >= 8,
      upper: /[A-ZÁÉÍÓÚÑ]/.test(v),
      lower: /[a-záéíóúñ]/.test(v),
      num:   /[0-9]/.test(v),
      sym:   /[^A-Za-z0-9]/.test(v),
      match: v.length > 0 && v === confirmacion.value,
    };
    let todas = true;
    reglas.querySelectorAll('li').forEach((li) => {
      const bien = Boolean(cumple[li.dataset.rule]);
      li.classList.toggle('cumple', bien);
      li.dataset.ok = bien ? '1' : '0';
      if (!bien) todas = false;
    });
    const listo = todas && Boolean(acepto && acepto.checked);
    boton.disabled = !listo;
    boton.classList.toggle('apagado', !listo);
  }

  clave.addEventListener('input', comprobar);
  confirmacion.addEventListener('input', comprobar);
  if (acepto) acepto.addEventListener('change', comprobar);
  comprobar();
})();
