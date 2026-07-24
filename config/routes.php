<?php
declare(strict_types=1);

// ─── VISTAS PÚBLICAS ────────────────────────────────────────
$router->get('',                    'PublicoController', 'home');
$router->get('torneos',             'PublicoController', 'torneos');
$router->get('torneo/{id}',         'PublicoController', 'detalle');
$router->get('equipo/{id}',         'PublicoController', 'equipo');
$router->get('jugador/{id}',        'PublicoController', 'jugador');

// ─── AUTENTICACIÓN ──────────────────────────────────────────
$router->get('login',               'AuthController', 'loginForm');
$router->post('login',              'AuthController', 'login');
$router->get('logout',              'AuthController', 'logout');

// ─── REGISTRO PÚBLICO DE PARTICIPANTES ──────────────────────
$router->get('registro',            'AuthController', 'registroForm');
$router->post('registro',           'AuthController', 'registro');

// ─── ADMIN — DASHBOARD ──────────────────────────────────────
$router->get('admin',               'AdminController', 'dashboard');

// ─── ADMIN — REGISTROS PENDIENTES ───────────────────────────
$router->get('admin/registros',                   'AdminController', 'registros');
$router->post('admin/registros/{id}/aprobar',     'AdminController', 'registroAprobar');
$router->post('admin/registros/{id}/rechazar',    'AdminController', 'registroRechazar');

// ─── ADMIN — USUARIOS ───────────────────────────────────────
$router->get('admin/usuarios',                    'AdminController', 'usuarios');
$router->get('admin/usuarios/crear',              'AdminController', 'usuarioForm');
$router->post('admin/usuarios/crear',             'AdminController', 'usuarioGuardar');
$router->get('admin/usuarios/editar/{id}',        'AdminController', 'usuarioForm');
$router->post('admin/usuarios/editar/{id}',       'AdminController', 'usuarioGuardar');
$router->post('admin/usuarios/eliminar/{id}',     'AdminController', 'usuarioEliminar');

// ─── ADMIN — PARTICIPANTES ──────────────────────────────────
$router->get('admin/participantes',               'AdminController', 'participantes');
$router->get('admin/participantes/crear',         'AdminController', 'participanteForm');
$router->post('admin/participantes/crear',        'AdminController', 'participanteGuardar');
$router->get('admin/participantes/editar/{id}',   'AdminController', 'participanteForm');
$router->post('admin/participantes/editar/{id}',  'AdminController', 'participanteGuardar');
$router->post('admin/participantes/eliminar/{id}','AdminController', 'participanteEliminar');

// ─── ADMIN — EQUIPOS ────────────────────────────────────────
$router->get('admin/equipos',                     'AdminController', 'equipos');
$router->get('admin/equipos/crear',               'AdminController', 'equipoForm');
$router->post('admin/equipos/crear',              'AdminController', 'equipoGuardar');
$router->get('admin/equipos/editar/{id}',         'AdminController', 'equipoForm');
$router->post('admin/equipos/editar/{id}',        'AdminController', 'equipoGuardar');
$router->post('admin/equipos/eliminar/{id}',      'AdminController', 'equipoEliminar');
$router->post('admin/equipos/{id}/agregar-participante',  'AdminController', 'equipoAgregarParticipante');
$router->post('admin/equipos/{id}/quitar-participante',   'AdminController', 'equipoQuitarParticipante');

// ─── ADMIN — TORNEOS ────────────────────────────────────────
$router->get('admin/torneos',                     'TorneoController', 'listadoAdmin');
$router->get('admin/torneos/crear',               'TorneoController', 'formulario');
$router->post('admin/torneos/crear',              'TorneoController', 'guardar');
$router->get('admin/torneos/editar/{id}',         'TorneoController', 'formulario');
$router->post('admin/torneos/editar/{id}',        'TorneoController', 'guardar');
$router->get('admin/torneos/{id}',                'TorneoController', 'gestion');
$router->post('admin/torneos/{id}/generar',       'TorneoController', 'generarCompetencia');
$router->post('admin/torneos/{id}/siguiente-ronda','TorneoController','siguienteRondaSuizo');
$router->post('admin/torneos/{id}/inscribir',     'TorneoController', 'inscribir');
$router->post('admin/torneos/{id}/desinscribir',  'TorneoController', 'desinscribir');
$router->post('admin/torneos/eliminar/{id}',      'TorneoController', 'eliminar');

// ─── ADMIN — RESULTADOS ─────────────────────────────────────
$router->post('admin/resultados/cargar',          'ResultadoController', 'cargar');
$router->post('admin/resultados/corregir',        'ResultadoController', 'corregir');
$router->post('admin/resultados/programar',       'ResultadoController', 'programar');

// ─── ADMIN — CORRECCIONES (flujo de aprobación) ─────────────
$router->get('admin/correcciones',                'CorreccionController', 'index');
$router->post('admin/correcciones/{id}/aprobar',  'CorreccionController', 'aprobar');
$router->post('admin/correcciones/{id}/rechazar', 'CorreccionController', 'rechazar');

// ─── ADMIN — SISTEMA ────────────────────────────────────────
$router->get('admin/auditoria',                   'AdminController', 'auditoria');
$router->get('admin/modulos',                     'AdminController', 'modulos');
$router->post('admin/modulos/toggle/{id}',        'AdminController', 'moduloToggle');

// ─── ORGANIZADOR ────────────────────────────────────────────
$router->get('organizador',                       'OrganizadorController', 'dashboard');
$router->get('organizador/torneos',               'OrganizadorController', 'misTorneos');
$router->get('organizador/torneos/{id}',          'OrganizadorController', 'gestion');
$router->post('organizador/torneos/{id}/generar', 'OrganizadorController', 'generarCompetencia');
$router->post('organizador/torneos/{id}/siguiente-ronda','OrganizadorController','siguienteRondaSuizo');
$router->post('organizador/torneos/{id}/inscribir','OrganizadorController','inscribir');
$router->post('organizador/torneos/{id}/desinscribir','OrganizadorController','desinscribir');
$router->post('organizador/resultados/cargar',    'ResultadoController', 'cargar');
$router->post('organizador/resultados/programar', 'ResultadoController', 'programar');
// El organizador NO corrige directamente: solicita corrección (requiere aprobación admin)
$router->post('organizador/correcciones/solicitar','CorreccionController', 'solicitar');

// ─── PARTICIPANTE ───────────────────────────────────────────
$router->get('participante',                      'ParticipanteController', 'dashboard');
$router->get('participante/perfil',               'ParticipanteController', 'perfil');
$router->post('participante/perfil',              'ParticipanteController', 'guardarPerfil');
$router->get('participante/torneos',              'ParticipanteController', 'misTorneos');
$router->post('participante/perfil/eliminar-datos','ParticipanteController', 'eliminarDatos');
