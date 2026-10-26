<?php
/**
 * Términos y Condiciones de uso. Se aceptan junto con la Política de Privacidad al registrarse (ver Politicas).
 */
$sitio    = $_sitio['nombre'];
$operador = Politicas::OPERADOR;
$contacto = $_sitio['contacto'] ?? '';
$correo   = $contacto !== ''
    ? '<a href="mailto:' . View::e($contacto) . '">' . View::e($contacto) . '</a>'
    : 'el administrador del sitio';
?>
<section class="section legal">
  <div class="eyebrow">Documento legal</div>
  <h1>Términos y Condiciones</h1>
  <p class="muted">Versión <?= View::e(Politicas::VERSION) ?> · Vigente desde el <?= View::e(Politicas::VIGENTE_DESDE) ?></p>
  <p>Estos términos regulan el uso de <?= View::e($sitio) ?>. Al crear tu cuenta aceptás estos términos y la <a href="/privacidad">Política de Privacidad</a>. Si no estás de acuerdo, no te registres.</p>

  <h2 class="como-h3">1. Qué ofrece <?= View::e($sitio) ?></h2>
  <p>Es un sistema para organizar torneos deportivos, mentales y electrónicos (liga, eliminación directa y sistema suizo): inscripciones, calendario, resultados y tablas de posiciones. Es un proyecto educativo de <?= View::e($operador) ?>.</p>

  <h2 class="como-h3">2. Tu cuenta</h2>
  <ul>
    <li>Los datos que cargues tienen que ser verdaderos y actuales.</li>
    <li>Tu registro queda <strong>pendiente</strong> hasta que un administrador lo apruebe; puede rechazarlo.</li>
    <li>Tu contraseña es personal. Sos responsable de lo que se haga con tu cuenta; si sospechás un uso no autorizado, avisá enseguida.</li>
    <li>Una persona, una cuenta.</li>
  </ul>

  <h2 class="como-h3">3. Uso aceptable</h2>
  <p>No está permitido suplantar a otra persona, subir fotos o textos ofensivos, ilegales o que no te pertenezcan, intentar acceder a datos de otras personas, vulnerar la seguridad del sitio, ni alterar o abusar del sistema de resultados. Tratá con respeto a los demás participantes.</p>

  <h2 class="como-h3">4. Tus datos y su visibilidad</h2>
  <p>El tratamiento de tus datos se rige por la <a href="/privacidad">Política de Privacidad</a>. Recordá que tu nombre, nick, foto, equipos, resultados y estadísticas son visibles para otros participantes, organizadores, administradores y cualquier visitante del sitio.</p>

  <h2 class="como-h3">5. Organizadores y administradores</h2>
  <p>Los organizadores pueden inscribirte en sus torneos, cargar resultados y solicitar correcciones; los administradores pueden aprobar, suspender, bloquear o dar de baja cuentas y corregir resultados. Las correcciones quedan registradas con el valor anterior y el nuevo.</p>

  <h2 class="como-h3">6. Suspensión y baja</h2>
  <p>Podemos suspender o bloquear una cuenta que incumpla estos términos. Vos podés pedir tu baja en cualquier momento (ver la sección 5 de la Política de Privacidad).</p>

  <h2 class="como-h3">7. Disponibilidad y responsabilidad</h2>
  <p><?= View::e($sitio) ?> se ofrece «tal como está», como proyecto educativo: puede tener interrupciones y errores. La organización de cada torneo (reglas, horarios, premios) es responsabilidad de su organizador. En la medida que la ley lo permita, <?= View::e($operador) ?> no responde por daños derivados del uso del sitio.</p>

  <h2 class="como-h3">8. Propiedad intelectual</h2>
  <p>El software, el diseño y los textos pertenecen a <?= View::e($operador) ?>. Vos conservás los derechos sobre tu foto y nos autorizás a mostrarla en el sitio mientras tengas cuenta.</p>

  <h2 class="como-h3">9. Ley aplicable y cambios</h2>
  <p>Estos términos se rigen por las leyes de la República Oriental del Uruguay. Si los modificamos, publicaremos la nueva versión con su número y su fecha. Consultas: <?= $correo ?>.</p>

  <p class="legal-pie"><a class="btn" href="/privacidad">Ver la Política de Privacidad</a> <a class="btn ghost" href="/registro">Volver al registro</a></p>
</section>
