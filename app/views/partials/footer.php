<footer class="footer">
  <?= View::e($_sitio['nombre']) ?> · Sistema de Gestión Deportiva Modular · © <?= date('Y') ?> <strong>Guemanelli Tech</strong>
  <?php if (!empty($_sitio['contacto'])): ?> · Contacto: <a href="mailto:<?= View::e($_sitio['contacto']) ?>"><?= View::e($_sitio['contacto']) ?></a><?php endif; ?>
  · <span class="footer-legal"><a href="/privacidad">Privacidad</a> · <a href="/terminos">Términos</a></span>
</footer>
