<div class="page-head">
  <div>
    <p class="crumb"><a href="/leads">Leads</a></p>
    <h1>Pasangkan kolom</h1>
    <p class="muted small"><?= e($file['name']) ?> · <?= number_format($file['rows'], 0, ',', '.') ?> baris terbaca</p>
  </div>
</div>

<form method="post" action="/leads/import/run" class="card stack">
  <?= csrf_field() ?>
  <label class="check"><input type="checkbox" name="has_header" value="1" checked> Baris pertama adalah judul kolom (tidak diimport)</label>
  <table class="table table--cards">
    <thead><tr><th>Kolom di file</th><th>Contoh isi</th><th>Simpan sebagai</th></tr></thead>
    <tbody>
      <?php foreach ($header as $i => $h): ?>
        <tr>
          <td data-label="Kolom di file"><strong><?= e($h !== '' ? $h : 'Kolom ' . ($i + 1)) ?></strong></td>
          <td data-label="Contoh isi" class="small breakall"><?= e(implode(' · ', array_filter(array_map(fn($r) => (string) ($r[$i] ?? ''), $samples), fn($v) => $v !== ''))) ?: '<span class="muted">kosong</span>' ?></td>
          <td data-label="Simpan sebagai">
            <label class="visually-hidden" for="col-<?= $i ?>">Simpan kolom <?= e($h) ?> sebagai</label>
            <select id="col-<?= $i ?>" name="col[<?= $i ?>]">
              <option value="">Abaikan</option>
              <?php foreach ($fields as $key => $label): ?>
                <option value="<?= $key ?>" <?= ($guess[$i] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">
    Stage dicocokkan dengan kode atau label: <?= e(implode(', ', array_map(fn($s) => $s['label'], $stages))) ?>.
    Tanggal: 2026-10-29, 29/10/2026, atau 29-10-2026 (zona waktu tenant). Nilai rupiah boleh pakai titik ribuan.
  </p>
  <div class="actions">
    <button class="btn btn-primary" type="submit" data-confirm="Proses import <?= (int) $file['rows'] ?> baris sekarang?">Proses import</button>
  </div>
</form>
<form method="post" action="/leads/import/cancel">
  <?= csrf_field() ?>
  <button class="btn btn-quiet" type="submit">Batalkan dan hapus file</button>
</form>
