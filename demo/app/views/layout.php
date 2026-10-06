<?php // demo/app/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Notes') ?></title>
  <link rel="icon" href="data:,">
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; color: #1a1a1a; }
    header { display: flex; justify-content: space-between; align-items: center;
             padding: 0.75rem 1.25rem; border-bottom: 1px solid #ddd; }
    header a { color: inherit; text-decoration: none; font-weight: 600; }
    main { max-width: 42rem; margin: 0 auto; padding: 1.25rem; }
    form button { cursor: pointer; }
    .error { color: #a00; }
    .note { border: 1px solid #ddd; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 0.75rem; }
    .note form { display: inline; }
    .note time { color: #666; font-size: 0.85rem; }
  </style>
</head>
<body>
  <header>
    <a href="/">Notes</a>
    <?php if (($loggedIn ?? false) && isset($csrf)): ?>
    <a href="/auth/password">Password</a>
    <form method="post" action="/auth/logout">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button>Log out</button>
    </form>
    <?php else: ?>
    <a href="/auth/login">Log in</a>
    <?php endif; ?>
  </header>
  <main><?= $content ?></main>
</body>
</html>
