<?php // demo/app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<?php if (($tagline ?? '') !== ''): ?><p><?= $this->e($tagline) ?></p><?php endif; ?>
<?php if (($nav ?? []) !== []): ?>
<nav aria-label="Site">
  <?php foreach ($nav as $link): ?>
  <a href="<?= $this->e($link['url'] ?? '#') ?>"><?= $this->e($link['label'] ?? '') ?></a>
  <?php endforeach ?>
</nav>
<?php endif ?>
<p><?= ($loggedIn ?? false)
    ? 'Your notes are waiting. <a href="/notes">Open the list</a>.'
    : 'The owner account was created at provisioning time. <a href="/auth/login">Log in</a> to read and write notes.' ?></p>
