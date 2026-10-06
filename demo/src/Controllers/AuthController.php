<?php // demo/src/Controllers/AuthController.php
declare(strict_types=1);

namespace App\Controllers;

use Kip\Auth;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Auth as AuthGate;
use Kip\Routing\Get;
use Kip\Routing\Post;
use Kip\Session;
use Kip\View;

/**
 * Login, logout, and the password change. Routes: /auth/login,
 * POST /auth/attempt, /auth/password (GET form, POST change), and
 * POST /auth/logout (gated).
 */
final class AuthController
{
    public function __construct(
        private Auth $auth,
        private Database $db,
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

    /**
     * The change-password form and its handler in one gated action. The
     * provisioned owner starts on a one-time password; this is where it stops
     * being one. Current password first, new hash in one transaction, and the
     * session's password epoch rides the new hash: this tab stays signed in,
     * every other session of the account dies.
     */
    #[AuthGate] #[Get] #[Post]
    public function password(): Response|string
    {
        if ($this->request->method !== 'POST') {
            return $this->passwordView(null);
        }
        $userId = (int) $this->session->get('user_id');
        $current = $this->request->postStr('current_password');
        $new = $this->request->postStr('new_password');
        $confirm = $this->request->postStr('confirm_password');
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            return new Response($this->passwordView('Your current password is wrong.'), 422);
        }
        if (strlen($new) < 8) {
            return new Response($this->passwordView('The new password needs at least 8 characters.'), 422);
        }
        if ($new !== $confirm) {
            return new Response($this->passwordView('The new passwords do not match.'), 422);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $this->db->begin();
        try {
            $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
            $this->db->query('DELETE FROM login_attempts WHERE email = ?', [(string) $user['email']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->session->set('pwd_epoch', substr($hash, 0, Auth::EPOCH_LEN));
        return Response::redirect('/notes');
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

    private function passwordView(?string $error): string
    {
        return $this->view->render('auth/password', [
            'title' => 'Change password',
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
        ]);
    }
}
