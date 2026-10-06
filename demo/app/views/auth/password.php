<?php // demo/app/views/auth/password.php ?>
<?php $this->layout('layout'); ?>
<h1>Change password</h1>
<?php if (($error ?? null) !== null): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/auth/password">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <p><label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label></p>
  <p><label>New password (8+ characters) <input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label></p>
  <p><label>Repeat new password <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label></p>
  <p><button>Change password</button></p>
</form>
