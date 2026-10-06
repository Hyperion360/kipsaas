<?php // demo/src/Controllers/HomeController.php
declare(strict_types=1);

namespace App\Controllers;

use Kip\App;
use Kip\Session;
use Kip\View;

/**
 * The landing page. Reads the starter nav the seed command wrote
 * (app/nav.json), so a provisioned tenant proves its own db:seed-demo ran.
 */
final class HomeController
{
    public function __construct(private View $view, private App $app, private Session $session) {}

    public function index(): string
    {
        $navFile = (string) $this->app->config('nav_file', '');
        $nav = [];
        if ($navFile !== '' && is_file($navFile)) {
            $decoded = json_decode((string) file_get_contents($navFile), true);
            if (is_array($decoded)) $nav = $decoded;
        }
        return $this->view->render('home/index', [
            'title' => (string) $this->app->config('site_name', 'Demo Notes'),
            'tagline' => (string) $this->app->config('tagline', ''),
            'nav' => $nav,
            // peek(), not get(): a guest page never starts a session, so it
            // stays cacheable.
            'logged_in' => $this->session->peek('user_id') !== null,
        ]);
    }
}
