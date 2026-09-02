<?php
declare(strict_types=1);

class ParticipanteController extends BaseController
{
    public function dashboard(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());

        $torneos   = [];
        $proximo   = null;
        $resultados = [];

        $stats = null;
        if ($participante) {
            $torneos = (new InscripcionModel())->getByParticipante((int)$participante['id']);
            $stats   = (new StatsService())->participante((int)$participante['id']);
        }

        $this->render('participante/dashboard', [
            'pageTitle'    => 'Mi panel',
            'participante' => $participante,
            'torneos'      => $torneos,
            'stats'        => $stats,
        ], 'admin');
    }

    public function perfil(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        $stats   = $participante ? (new StatsService())->participante((int)$participante['id']) : null;
        $usuario = (new UsuarioModel())->findByIdConRol((int)Auth::id());

        $this->render('participante/perfil', [
            'pageTitle'    => 'Mi perfil',
            'participante' => $participante,
            'usuario'      => $usuario,
            'stats'        => $stats,
            'csrf'         => Csrf::generate(),
        ], 'admin');
    }

    public function guardarPerfil(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $this->checkCsrf();

        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        if (!$participante) {
            $this->flash('error', 'No se encontró tu perfil de participante.');
            $this->redirect('/participante/perfil');
        }

        try {
            $datos = [
                'nombre'   => $this->postStr('nombre'),
                'nick'     => $this->postStr('nick'),
                'email'    => $this->postStr('email'),
                'telefono' => $this->postStr('telefono'),
            ];
            // Foto de perfil (opcional)
            $nuevaFoto = Upload::image($_FILES['foto'] ?? [], 'avatars');
            if ($nuevaFoto !== null) {
                Upload::delete('avatars', $participante['foto'] ?? null);
                $datos['foto'] = $nuevaFoto;
            }
            (new ParticipanteService())->actualizarPerfil((int)$participante['id'], $datos);
            // Refrescar la sesión: la topbar/Auth usan estos valores cacheados del login
            Session::set('user_nombre', $datos['nombre']);
            if (!empty($datos['email'])) Session::set('user_email', strtolower($datos['email']));
            $this->flash('success', 'Perfil actualizado.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/participante/perfil');
    }

    public function eliminarDatos(): void
    {
        $this->requireRole(['participante']);
        $this->checkCsrf();

        $usuarioId    = (int)Auth::id();
        $participante = (new ParticipanteModel())->findByUsuario($usuarioId);

        if (!$participante) {
            $this->flash('error', 'No se encontró tu perfil de participante.');
            $this->redirect('/participante/perfil');
        }

        try {
            (new ParticipanteService())->anonimizarDatos((int)$participante['id'], $usuarioId);
            Session::destroy();
            $this->redirect('/login?msg=datos_eliminados');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/participante/perfil');
        }
    }

    public function misTorneos(): void
    {
        $this->requireRole(['participante', 'administrador']);
        $participante = (new ParticipanteModel())->findByUsuario((int)Auth::id());
        $torneos = $participante
            ? (new InscripcionModel())->getByParticipante((int)$participante['id'])
            : [];

        $this->render('participante/mis_torneos', [
            'pageTitle' => 'Mis torneos',
            'torneos'   => $torneos,
        ], 'admin');
    }
}
