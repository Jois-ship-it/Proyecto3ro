<?php
/**
 * Política de Privacidad. El texto describe lo que el sistema hace de verdad (qué se guarda, quién lo ve, qué derechos hay);
 * si el código cambia, esta página cambia con él y sube Politicas::VERSION.
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
  <h1>Política de Privacidad</h1>
  <p class="muted">Versión <?= View::e(Politicas::VERSION) ?> · Vigente desde el <?= View::e(Politicas::VIGENTE_DESDE) ?></p>
  <p>Esta política explica qué datos personales recoge <?= View::e($sitio) ?>, para qué los usa, quién puede verlos y cómo podés ejercer tus derechos. Se aplica a quienes se registran como participantes y a quienes organizan o administran torneos. Está escrita según la Ley N.º 18.331 de Protección de Datos Personales de Uruguay.</p>

  <h2 class="como-h3">1. Quién es el responsable</h2>
  <p><?= View::e($sitio) ?> es un sistema de gestión de torneos desarrollado por <?= View::e($operador) ?> como proyecto de egreso del Instituto Tecnológico Superior Arias Balparda (Montevideo, Uruguay). <?= View::e($operador) ?> es el responsable del tratamiento de los datos. Podés escribirnos a <?= $correo ?>.</p>

  <h2 class="como-h3">2. Qué datos recogemos</h2>
  <ul>
    <li><strong>Al registrarte:</strong> tu nombre completo, tu email y tu contraseña; y, si querés, tu nick y tu teléfono. Un administrador puede cargar además tu documento.</li>
    <li><strong>Mientras usás el sitio:</strong> tu foto de perfil (si la subís), tus inscripciones, equipos, partidos, resultados y estadísticas.</li>
    <li><strong>Datos de seguridad:</strong> los intentos de inicio de sesión desde cada dirección IP (para frenar ataques de adivinación de contraseñas) y un registro de auditoría de las acciones importantes: quién hizo qué, cuándo y desde qué IP.</li>
    <li><strong>Una cookie de sesión</strong>, necesaria para mantenerte conectado. No usamos cookies de publicidad ni de seguimiento, ni servicios de terceros que te rastreen.</li>
  </ul>

  <h2 class="como-h3">3. Para qué usamos tus datos</h2>
  <ul>
    <li>Crear y administrar tu cuenta, y aprobar tu registro.</li>
    <li>Inscribirte en torneos, armar calendarios, llaves y tablas, y mostrar los resultados.</li>
    <li>Contactarte por cuestiones de la competencia.</li>
    <li>Mantener la seguridad del sitio y poder auditar lo que pasó.</li>
  </ul>
  <p>No vendemos tus datos, no los cedemos a terceros y no los usamos para publicidad. Lo único que se muestra a otras personas es lo que se detalla en la sección siguiente.</p>

  <h2 class="como-h3" id="visibilidad">4. Quién puede ver tus datos</h2>
  <p class="destacado">Al registrarte <strong>das tu consentimiento</strong> para que tu nombre, tu nick, tu foto, tus equipos, tus resultados y tus estadísticas sean visibles para <strong>otros participantes, organizadores, administradores y cualquier persona que visite el sitio</strong>, aunque no tenga cuenta.</p>
  <div class="table-wrap">
    <div class="table-scroll">
      <table aria-label="Quién puede ver cada dato personal">
        <thead><tr><th scope="col">Dato</th><th scope="col">Quién lo ve</th></tr></thead>
        <tbody>
          <tr><td>Nombre, nick y foto; equipos, inscripciones, resultados y estadísticas</td><td>Cualquier visitante del sitio, incluidos otros participantes, organizadores y administradores.</td></tr>
          <tr><td>Email, teléfono y documento</td><td>Los administradores y vos. No se muestran en las páginas públicas ni a otros participantes u organizadores.</td></tr>
          <tr><td>Contraseña</td><td>Nadie. Se guarda con un algoritmo irreversible (bcrypt): ni los administradores pueden leerla.</td></tr>
          <tr><td>Registros de seguridad y auditoría</td><td>Los administradores.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <p>Un administrador puede desactivar las páginas de perfil públicas. Aun así, tu nombre seguirá apareciendo en las tablas y llaves de los torneos en los que participes.</p>

  <h2 class="como-h3">5. Tus derechos</h2>
  <p>Tenés derecho a acceder a tus datos, rectificarlos, actualizarlos, incluirlos o suprimirlos (Ley N.º 18.331). Podés:</p>
  <ul>
    <li>editar tu nombre, nick, email, teléfono y foto desde <strong>Mi perfil</strong>;</li>
    <li>pedir la eliminación de tus datos personales desde <strong>Mi perfil → Eliminar mis datos personales</strong>: tus datos se anonimizan y se cierra tu cuenta;</li>
    <li>escribir a <?= $correo ?> para cualquier otro pedido.</li>
  </ul>
  <p>Si creés que no respetamos tus derechos, podés presentar un reclamo ante la Unidad Reguladora y de Control de Datos Personales (URCDP).</p>

  <h2 class="como-h3">6. Cómo protegemos tus datos</h2>
  <p>Guardamos las contraseñas con hash, usamos conexión cifrada (HTTPS), cerramos las sesiones inactivas, limitamos los intentos de ingreso, damos a cada rol solo los permisos que necesita, registramos las acciones importantes y hacemos copias de seguridad. Ningún sistema es infalible: si ocurre una vulneración que afecte tus datos, la comunicaremos a la URCDP y, cuando corresponda, a vos, como exige la normativa.</p>

  <h2 class="como-h3">7. Cuánto tiempo los conservamos</h2>
  <p>Mientras tu cuenta exista. Si pedís la eliminación, tus datos personales se anonimizan; los resultados de los torneos se conservan sin tu identificación, porque forman parte de la historia de la competencia.</p>

  <h2 class="como-h3">8. Menores de edad</h2>
  <p>Si sos menor de 18 años, necesitás la autorización de tu madre, padre o tutor para registrarte.</p>

  <h2 class="como-h3">9. Cambios en esta política</h2>
  <p>Si cambia, publicamos acá la nueva versión con su número y su fecha.</p>

  <p class="legal-pie"><a class="btn" href="/terminos">Ver los Términos y Condiciones</a> <a class="btn ghost" href="/registro">Volver al registro</a></p>
</section>
