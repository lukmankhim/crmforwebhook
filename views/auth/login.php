<h1>Masuk</h1>
<form method="post" action="/login" class="stack">
  <?= csrf_field() ?>
  <div class="field">
    <label for="login">Username atau email</label>
    <input id="login" name="login" type="text" autocomplete="username" required autofocus value="<?= e(old('login')) ?>">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Masuk</button>
</form>
<p class="muted small">Lupa password? Minta admin tenant untuk reset.</p>
