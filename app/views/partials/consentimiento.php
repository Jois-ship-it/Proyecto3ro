<?php
/**
 * Lo que se le dice a quien se registra sobre sus datos, y la casilla con la que lo acepta (obligatoria).
 * Resume la Política de Privacidad (/privacidad) y enlaza esa página y los Términos (/terminos), que se abren
 * en otra pestaña para no perder lo ya escrito en el formulario.
 */
?>
      <div class="consentimiento" role="group" aria-labelledby="consTitulo">
        <h2 class="como-h3" id="consTitulo">Privacidad y consentimiento</h2>
        <ul class="consent-lista">
          <li><strong>Qué guardamos:</strong> tu nombre, email, contraseña (solo como hash) y, si los cargás, tu nick y tu teléfono.</li>
          <li><strong>Qué se ve y quién lo ve:</strong> tu nombre, nick, foto, equipos, resultados y estadísticas serán visibles para otros participantes, organizadores, administradores y cualquier visitante del sitio. Tu email, teléfono y documento solo los ven los administradores (y vos).</li>
          <li><strong>Para qué:</strong> gestionar tu participación en torneos y mantener la seguridad del sitio. No vendemos ni cedemos tus datos.</li>
        </ul>
        <label class="check-linea" for="<?= View::e(Politicas::CAMPO_ACEPTACION) ?>">
          <input type="checkbox" id="<?= View::e(Politicas::CAMPO_ACEPTACION) ?>" name="<?= View::e(Politicas::CAMPO_ACEPTACION) ?>" value="1" required>
          <span>Leí y acepto la <a href="/privacidad" target="_blank" rel="noopener">Política de Privacidad</a> y los <a href="/terminos" target="_blank" rel="noopener">Términos y Condiciones</a>, y <strong>doy mi consentimiento</strong> para el tratamiento de mis datos y para que se muestren como se indica arriba. *</span>
        </label>
      </div>
