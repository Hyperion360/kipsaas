<?php // demo/src/Controllers/AuthController.php
declare(strict_types=1);

namespace App\Controllers;

use Kip\Auth;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Auth as AuthGate;
use Kip\Routing\Post;
use Kip\Session;
use Kip\View;

/**
 * Login/logout on the framework's own Auth (throttling, session epoch,
 * password rehash). Routes: /auth/login, POST /auth/attempt,
 * POST /auth/logout (gated).
 */
final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Session $session,
        private Request $request,
    ) {}

    public function login(): string
    {
        return $this->loginView(null);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response($this->loginView('Too many attempts, try again in 15 minutes.'), 429);
        }
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            return Response::redirect('/notes');
        }
        return $this->loginView('Wrong email or password');
    }

    #[AuthGate] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }

    private function loginView(?string $error): string
    {
        return $this->view->render('auth/login', [
            'title' => 'Log in',
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
        ]);
    }
}
