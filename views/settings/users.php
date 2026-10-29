<?php $me = current_user(); ?>
<h1>Pengaturan</h1>
<?= render_partial('settings/_tabs', ['tab' => $tab]) ?>

<?php if ($shown_password): ?>
  <div class="card reveal" role="status">
    <p>Password sementara untuk <strong><?= e($shown_password['username']) ?></strong>. Kirim lewat jalur pribadi, lalu minta pengguna menggantinya di halaman Akun.</p>
    <div class="copyrow">
      <code class="secret"><?= e($shown_password['password']) ?></code>
      <button class="btn btn-quiet" type="button" data-copy="<?= e($shown_password['password']) ?>">Salin</button>
    </div>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Pengguna tenant ini</h2>
  <?php if (!$users): ?>
    <p class="muted">Belum ada pengguna. Tambahkan admin atau sales di bawah.</p>
  <?php else: ?>
  <table class="table table--cards">
    <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>2FA</th><th>Login terakhir</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
      <tr class="<?= $u['active'] ? '' : 'is-off' ?>">
        <td data-label="Nama"><?= e($u['name']) ?><?= $u['active'] ? '' : ' <span class="tag">nonaktif</span>' ?><?= $self ? ' <span class="tag">anda</span>' : '' ?></td>
        <td data-label="Username"><?= e($u['username']) ?></td>
        <td data-label="Role">
          <?php if ($self): ?>
            <?= e(role_labels()[$u['role']]) ?>
          <?php else: ?>
          <form method="post" action="/settings/users/<?= (int) $u['id'] ?>" class="inline-form inline-form--tight">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role">
            <label class="visually-hidden" for="role-<?= (int) $u['id'] ?>">Role <?= e($u['name']) ?></label>
            <select id="role-<?= (int) $u['id'] ?>" name="role">
              <?php foreach (role_labels() as $key => $label): ?>
                <option value="<?= $key ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-quiet" type="submit">Ubah</button>
          </form>
          <?php endif; ?>
        </td>
        <td data-label="2FA"><?= $u['has_2fa'] ? 'Aktif' : ($u['role'] === 'admin' ? '<span class="warn-text">Belum (wajib)</span>' : 'Belum') ?></td>
        <td data-label="Login terakhir"><?= e(fmt_dt($u['last_login_at'])) ?: '<span class="muted">Belum pernah</span>' ?></td>
        <td data-label="Aksi">
          <?php if (!$self): ?>
          <div class="btn-row">
            <form method="post" action="/settings/users/<?= (int) $u['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <button class="btn btn-quiet" type="submit" data-confirm="Reset password <?= e($u['name']) ?>?">Reset password</button>
            </form>
            <?php if ($u['has_2fa']): ?>
            <form method="post" action="/settings/users/<?= (int) $u['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_2fa">
              <button class="btn btn-quiet" type="submit" data-confirm="Reset 2FA <?= e($u['name']) ?>? Dipakai kalau HP hilang.">Reset 2FA</button>
            </form>
            <?php endif; ?>
            <form method="post" action="/settings/users/<?= (int) $u['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $u['active'] ? 'deactivate' : 'activate' ?>">
              <button class="btn <?= $u['active'] ? 'btn-danger' : 'btn-quiet' ?>" type="submit" <?= $u['active'] ? 'data-confirm="Nonaktifkan ' . e($u['name']) . '? Pengguna tidak bisa login."' : '' ?>><?= $u['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
            </form>
          </div>
          <?php else: ?>
            <a href="/account">Kelola di Akun</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Tambah pengguna</h2>
  <form method="post" action="/settings/users" class="stack">
    <?= csrf_field() ?>
    <div class="grid-2">
      <div class="field"><label for="u-name">Nama</label><input id="u-name" name="name" type="text" required maxlength="150" value="<?= e(old('name')) ?>"></div>
      <div class="field"><label for="u-username">Username</label><input id="u-username" name="username" type="text" required pattern="[a-z0-9._\-]{3,64}" maxlength="64" autocomplete="off" value="<?= e(old('username')) ?>"></div>
      <div class="field"><label for="u-email">Email (opsional)</label><input id="u-email" name="email" type="email" maxlength="190" value="<?= e(old('email')) ?>"></div>
      <div class="field">
        <label for="u-role">Role</label>
        <select id="u-role" name="role">
          <?php foreach (role_labels() as $key => $label): ?>
            <option value="<?= $key ?>" <?= old('role', 'sales') === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="muted small">Admin: semua menu termasuk pengaturan, wajib 2FA. Sales: lihat dan ubah lead. Viewer: hanya lihat.</p>
    <div class="actions"><button class="btn btn-primary" type="submit">Buat pengguna</button></div>
  </form>
</section>
