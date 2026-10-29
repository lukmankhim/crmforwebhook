<?php
declare(strict_types=1);

function import_session(): ?array
{
    $s = $_SESSION['import'] ?? null;
    if (!$s || (int) $s['tenant_id'] !== tenant_id() || !is_file(import_file_path($s['token']))) {
        return null;
    }
    return $s;
}

function import_discard(): void
{
    if (!empty($_SESSION['import']['token']) && (int) $_SESSION['import']['tenant_id'] === tenant_id()) {
        @unlink(import_file_path($_SESSION['import']['token']));
    }
    unset($_SESSION['import']);
}

function page_import_form(): void
{
    if (import_session()) {
        redirect('/leads/import/map');
    }
    render('leads/import', ['title' => 'Import CSV', 'nav' => 'leads']);
}

function page_import_upload(): never
{
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        flash('error', $tooBig ? 'File terlalu besar, maksimal 2MB.' : 'Pilih file CSV dulu.');
        redirect('/leads/import');
    }
    if ($file['size'] > IMPORT_MAX_BYTES) {
        flash('error', 'File terlalu besar, maksimal 2MB.');
        redirect('/leads/import');
    }
    $name = basename((string) $file['name']);
    if (!preg_match('/\.(csv|txt)$/i', $name)) {
        flash('error', 'Format harus .csv. Di Excel: File, Save As, pilih CSV.');
        redirect('/leads/import');
    }
    $parsed = import_read_csv($file['tmp_name']);
    if (is_string($parsed)) {
        flash('error', $parsed);
        redirect('/leads/import');
    }
    [$rows] = $parsed;
    import_discard();
    $token = bin2hex(random_bytes(16));
    file_put_contents(import_file_path($token), json_encode($rows, JSON_UNESCAPED_UNICODE));
    chmod(import_file_path($token), 0600);
    $_SESSION['import'] = ['token' => $token, 'tenant_id' => tenant_id(), 'name' => $name, 'rows' => count($rows)];
    redirect('/leads/import/map');
}

function page_import_map(): void
{
    $s = import_session();
    if (!$s) {
        flash('info', 'Upload file CSV dulu.');
        redirect('/leads/import');
    }
    $rows = json_decode((string) file_get_contents(import_file_path($s['token'])), true);
    $header = $rows[0];
    $guess = [];
    foreach ($header as $i => $h) {
        $field = import_guess_field($h);
        if ($field !== null && !in_array($field, $guess, true)) {
            $guess[$i] = $field;
        }
    }
    render('leads/import_map', [
        'title' => 'Pasangkan kolom',
        'nav' => 'leads',
        'file' => $s,
        'header' => $header,
        'samples' => array_slice($rows, 1, 5),
        'guess' => $guess,
        'fields' => import_fields(),
        'stages' => pipeline_stages(),
    ]);
}

function page_import_run(): never
{
    $s = import_session();
    if (!$s) {
        flash('error', 'Sesi import sudah habis. Upload ulang file-nya.');
        redirect('/leads/import');
    }
    $rows = json_decode((string) file_get_contents(import_file_path($s['token'])), true);
    $cols = $_POST['col'] ?? [];
    $map = [];
    if (is_array($cols)) {
        foreach ($cols as $idx => $field) {
            if (!is_string($field) || $field === '' || !isset(import_fields()[$field])) {
                continue;
            }
            if (isset($map[$field])) {
                flash('error', 'Field "' . import_fields()[$field] . '" dipilih untuk lebih dari satu kolom.');
                redirect('/leads/import/map');
            }
            $map[$field] = (int) $idx;
        }
    }
    if (!isset($map['phone'])) {
        flash('error', 'Pilih kolom untuk Nomor HP.');
        redirect('/leads/import/map');
    }
    $hasHeader = isset($_POST['has_header']);
    $data = [];
    foreach ($rows as $i => $row) {
        if ($i === 0 && $hasHeader) {
            continue;
        }
        $data[$i + 1] = $row; // nomor baris sesuai file
    }
    $result = import_run($data, $map, $s['name'], (int) current_user()['id']);
    import_discard();
    $_SESSION['import_result'] = $result + ['name' => $s['name']];
    redirect('/leads/import/result');
}

function page_import_result(): void
{
    $result = $_SESSION['import_result'] ?? null;
    if (!$result) {
        redirect('/leads/import');
    }
    unset($_SESSION['import_result']);
    render('leads/import_result', ['title' => 'Hasil import', 'nav' => 'leads', 'r' => $result]);
}

function page_import_cancel(): never
{
    import_discard();
    flash('info', 'Import dibatalkan, file sudah dihapus.');
    redirect('/leads/import');
}
