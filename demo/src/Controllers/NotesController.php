<?php // demo/src/Controllers/NotesController.php
declare(strict_types=1);

namespace App\Controllers;

use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Auth as AuthGate;
use Kip\Routing\Post;
use Kip\Session;
use Kip\View;

/**
 * The notes surface, every action gated #[Auth]: list, create, delete. The
 * plan's note_cap arrives in the rendered tenant config under plan_flags and
 * is enforced here, so the free tier visibly stops at its quota.
 */
final class NotesController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Session $session,
        private App $app,
        private Request $request,
    ) {}

    #[AuthGate]
    public function index(): string
    {
        return $this->page(null);
    }

    #[AuthGate] #[Post]
    public function create(): Response
    {
        $userId = (int) $this->session->get('user_id');
        $body = $this->request->postStr('body');
        if ($body === '') {
            return new Response($this->page('Write something first.'), 422);
        }
        $cap = $this->cap();
        if ($cap > 0 && $this->count($userId) >= $cap) {
            return new Response($this->page("Plan limit reached: {$cap} notes on this plan. Upgrade to keep writing."), 402);
        }
        $this->db->query('INSERT INTO notes (user_id, body) VALUES (?, ?)', [$userId, $body]);
        return Response::redirect('/notes');
    }

    #[AuthGate] #[Post]
    public function delete(int $id): Response
    {
        // Scoped to the session user: one owner can never delete another's row.
        $this->db->query('DELETE FROM notes WHERE id = ? AND user_id = ?', [$id, (int) $this->session->get('user_id')]);
        return Response::redirect('/notes');
    }

    private function cap(): int
    {
        $flags = $this->app->config('plan_flags', []);
        return is_array($flags) ? (int) ($flags['note_cap'] ?? 0) : 0;
    }

    private function count(int $userId): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM notes WHERE user_id = ?', [$userId])['c'];
    }

    /** @return list<array<array-key, mixed>> */
    private function notes(int $userId): array
    {
        return $this->db->all('SELECT id, body, created_at FROM notes WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    private function page(?string $error): string
    {
        $userId = (int) $this->session->get('user_id');
        $notes = $this->notes($userId);
        $cap = $this->cap();
        return $this->view->render('notes/index', [
            'title' => 'My notes',
            'csrf' => $this->session->csrfToken(),
            'notes' => $notes,
            'cap' => $cap,
            'at_cap' => $cap > 0 && count($notes) >= $cap,
            'error' => $error,
        ]);
    }
}
