<?php
declare(strict_types=1);

const LEADS_PER_PAGE = 50;

function page_leads_index(): void
{
    $f = [
        'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        'stage' => (string) ($_GET['stage'] ?? ''),
        'source' => (string) ($_GET['source'] ?? ''),
        'mine' => ($_GET['mine'] ?? '') === '1' ? '1' : '',
        'view' => ($_GET['view'] ?? '') === 'kanban' ? 'kanban' : '',
    ];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $stages = pipeline_stages();

    $where = ['l.tenant_id = :tid'];
    $params = [];
    if ($f['q'] !== '') {
        $digits = preg_replace('/\D/', '', $f['q']);
        if ($digits !== '' && strlen($digits) >= 4) {
            if (str_starts_with($digits, '0')) {
                $digits = '62' . substr($digits, 1);
            }
            $where[] = '(l.phone LIKE :qp OR l.name LIKE :qn)';
            $params['qp'] = '%' . $digits . '%';
        } else {
            $where[] = 'l.name LIKE :qn';
        }
        $params['qn'] = '%' . addcslashes($f['q'], '%_\\') . '%';
    }
    if ($f['view'] !== 'kanban' && $f['stage'] !== '' && isset($stages[$f['stage']])) {
        $where[] = 'l.stage = :stage';
        $params['stage'] = $f['stage'];
    }
    if ($f['source'] !== '' && isset(source_labels()[$f['source']])) {
        $where[] = 'l.source = :source';
        $params['source'] = $f['source'];
    }
    if ($f['mine'] === '1') {
        $where[] = 'l.assigned_to = :me';
        $params['me'] = (int) current_user()['id'];
    }
    $whereSql = implode(' AND ', $where);

    if ($f['view'] === 'kanban') {
        page_leads_kanban($f, $stages, $whereSql, $params);
        return;
    }

    $total = (int) q_tenant("SELECT COUNT(*) FROM leads l WHERE $whereSql", $params)->fetchColumn();
    $pages = max(1, (int) ceil($total / LEADS_PER_PAGE));
    $page = min($page, $pages);
    $leads = q_tenant("SELECT l.id, l.name, l.phone, l.source, l.stage, l.created_at, l.next_followup, u.name AS assigned_name
                       FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
                       WHERE $whereSql ORDER BY l.created_at DESC, l.id DESC LIMIT :lim OFFSET :off",
        $params + ['lim' => LEADS_PER_PAGE, 'off' => ($page - 1) * LEADS_PER_PAGE])->fetchAll();

    render('leads/index', [
        'title' => 'Leads',
        'nav' => 'leads',
        'leads' => $leads,
        'stages' => $stages,
        'filters' => $f,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'filtered' => $f['q'] !== '' || $f['stage'] !== '' || $f['source'] !== '' || $f['mine'] !== '',
    ]);
}

const KANBAN_PER_COLUMN = 30;

/** Kanban per stage. Kartu dipindah lewat form (bisa keyboard, tanpa drag). Lost lewat halaman detail karena butuh alasan. */
function page_leads_kanban(array $f, array $stages, string $whereSql, array $params): void
{
    $counts = [];
    foreach (q_tenant("SELECT l.stage, COUNT(*) AS n FROM leads l WHERE $whereSql GROUP BY l.stage", $params) as $row) {
        $counts[$row['stage']] = (int) $row['n'];
    }
    $columns = [];
    foreach ($stages as $key => $s) {
        $columns[$key] = empty($counts[$key]) ? [] : q_tenant("SELECT l.id, l.name, l.phone, l.stage, l.next_followup, l.quote_value, u.name AS assigned_name
                       FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
                       WHERE $whereSql AND l.stage = :kstage ORDER BY COALESCE(l.touched_at, l.created_at) DESC, l.id DESC LIMIT :lim",
            $params + ['kstage' => $key, 'lim' => KANBAN_PER_COLUMN])->fetchAll();
    }
    render('leads/kanban', [
        'title' => 'Leads',
        'nav' => 'leads',
        'stages' => $stages,
        'columns' => $columns,
        'counts' => $counts,
        'filters' => $f,
        'total' => array_sum($counts),
        'filtered' => $f['q'] !== '' || $f['source'] !== '' || $f['mine'] !== '',
        'back' => url('/leads', $f),
    ]);
}

/** Tujuan redirect setelah aksi: hanya halaman leads internal. */
function safe_back(string $fallback): string
{
    $back = (string) ($_POST['back'] ?? '');
    return preg_match('#^/leads(\?[A-Za-z0-9_=&%.+-]*)?$#', $back) ? $back : $fallback;
}

function page_leads_new(): void
{
    render('leads/new', [
        'title' => 'Tambah lead',
        'nav' => 'leads',
        'members' => tenant_members(),
    ]);
}

function page_leads_create(): never
{
    $input = [
        'phone' => str_input('phone', 30),
        'name' => str_input('name', 150),
        'source' => str_input('source', 20),
        'src_tag' => str_input('src_tag', 150),
        'product' => str_input('product', 150),
        'city' => str_input('city', 100),
        'assigned_to' => (int) ($_POST['assigned_to'] ?? 0) ?: null,
        'next_followup' => str_input('next_followup', 10),
    ];
    if ($input['assigned_to'] !== null && !in_array($input['assigned_to'], array_map(fn($m) => (int) $m['id'], tenant_members()), true)) {
        $input['assigned_to'] = null;
    }
    if ($input['next_followup'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['next_followup'])) {
        $input['next_followup'] = '';
    }
    [$id, $error] = lead_create_manual($input, (int) current_user()['id']);
    if ($error) {
        flash('error', $error);
        if ($id) {
            redirect('/leads/' . $id);
        }
        keep_old($input);
        redirect('/leads/new');
    }
    flash('success', 'Lead tersimpan.');
    redirect('/leads/' . $id);
}

function lead_or_404(int $id): array
{
    $lead = find_lead($id);
    if (!$lead) {
        error_page(404);
    }
    return $lead;
}

function page_leads_show(int $id): void
{
    $lead = lead_or_404($id);
    $activity = q_tenant('SELECT a.*, u.name AS user_name FROM lead_activity a LEFT JOIN users u ON u.id = a.user_id
                          WHERE a.tenant_id = :tid AND a.lead_id = :id ORDER BY a.created_at DESC, a.id DESC LIMIT 200', ['id' => $id])->fetchAll();
    render('leads/show', [
        'title' => $lead['name'] ?: $lead['phone'],
        'nav' => 'leads',
        'lead' => $lead,
        'stages' => pipeline_stages((int) $lead['pipeline_id']),
        'members' => tenant_members(),
        'activity' => $activity,
    ]);
}

function page_leads_update(int $id): never
{
    $lead = lead_or_404($id);
    $input = [
        'name' => nullable_input('name', 150),
        'product' => nullable_input('product', 150),
        'city' => nullable_input('city', 100),
        'assigned_to' => ((int) ($_POST['assigned_to'] ?? 0)) ?: null,
        'quote_value' => money_input('quote_value'),
        'deal_value' => money_input('deal_value'),
        'next_followup' => nullable_input('next_followup', 10),
        'lost_reason' => nullable_input('lost_reason', 255),
    ];
    $error = lead_update_fields($lead, $input, (int) current_user()['id']);
    flash($error ? 'error' : 'success', $error ?: 'Perubahan tersimpan.');
    redirect('/leads/' . $id);
}

function page_leads_stage(int $id): never
{
    $lead = lead_or_404($id);
    $error = lead_change_stage($lead, str_input('stage', 40), (int) current_user()['id'], nullable_input('lost_reason', 255));
    flash($error ? 'error' : 'success', $error ?: 'Stage ' . ($lead['name'] ?: mask_phone($lead['phone'])) . ' diperbarui.');
    header('Location: ' . safe_back('/leads/' . $id), true, 303);
    exit;
}

function page_leads_note(int $id): never
{
    $lead = lead_or_404($id);
    $note = str_input('note', 2000);
    if ($note === '') {
        flash('error', 'Catatan masih kosong.');
        redirect('/leads/' . $id);
    }
    lead_log((int) $lead['id'], 'note', $note, (int) current_user()['id']);
    q_tenant('UPDATE leads SET touched_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id', ['id' => $id]);
    flash('success', 'Catatan ditambahkan.');
    redirect('/leads/' . $id);
}
