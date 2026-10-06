<?php // demo/app/views/notes/index.php ?>
<?php $this->layout('layout'); ?>
<h1>My notes</h1>
<?php if (($error ?? null) !== null): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<?php if (($at_cap ?? false) && ($error ?? null) === null): ?>
<p id="cap">Plan limit reached: <?= (int) $cap ?> notes on this plan. Upgrade to keep writing.</p>
<?php endif; ?>
<?php if (!($at_cap ?? false)): ?>
<form method="post" action="/notes/create">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <p><label>New note <textarea name="body" rows="3" required></textarea></label></p>
  <p><button>Add note</button></p>
</form>
<?php endif; ?>
<?php if (($notes ?? []) === []): ?>
<p>No notes yet.</p>
<?php else: ?>
<?php foreach ($notes as $note): ?>
<div class="note">
  <p><?= $this->e($note['body']) ?></p>
  <form method="post" action="/notes/delete/<?= (int) $note['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button>Delete</button>
  </form>
</div>
<?php endforeach ?>
<?php endif; ?>
