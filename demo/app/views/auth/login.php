<?php // demo/app/views/auth/login.php ?>
<?php $this->layout('layout'); ?>
<h1>Log in</h1>
<?php if (($error ?? null) !== null): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/auth/attempt">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <p><label>Email <input type="email" name="email" required autocomplete="email"></label></p>
  <p><label>Password <input type="password" name="password" required autocomplete="current-password"></label></p>
  <p><button>Log in</button></p>
</form>
