<?php
declare(strict_types=1);

const DASHBOARD_PERIODS = [7, 30, 90];

/** Offset tenant dalam menit (zona Indonesia tanpa DST), dipakai untuk mengelompokkan per hari lokal. */
function tenant_offset_minutes(): int
{
    return intdiv(tenant_tz()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC'))), 60);
}

/**
 * Batas periode N hari terakhir (termasuk hari ini) dalam waktu lokal tenant, dikembalikan dalam UTC.
 * Juga periode sebelumnya dengan panjang sama untuk pembanding.
 */
function dashboard_range(int $days): array
{
    $tz = tenant_tz();
    $todayLocal = new DateTimeImmutable('today', $tz);
    $start = $todayLocal->modify('-' . ($days - 1) . ' days');
    $end = $todayLocal->modify('+1 day');
    $prevStart = $start->modify("-{$days} days");
    $utc = new DateTimeZone('UTC');
    return [
        'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        'prev_start' => $prevStart->setTimezone($utc)->format('Y-m-d H:i:s'),
        'start_local' => $start,
        'today_local' => $todayLocal,
    ];
}

function dashboard_data(int $days): array
{
    $r = dashboard_range($days);
    $range = ['s' => $r['start'], 'e' => $r['end']];
    $prev = ['s' => $r['prev_start'], 'e' => $r['start']];
    $stages = pipeline_stages();
    $openKeys = array_keys(array_filter($stages, fn($s) => $s['kind'] === 'open'));

    $count = fn(string $col, array $p) => (int) q_tenant("SELECT COUNT(*) FROM leads WHERE tenant_id = :tid AND $col >= :s AND $col < :e", $p)->fetchColumn();

    $cohort = q_tenant('SELECT COUNT(*) AS leads, SUM(qualified_at IS NOT NULL) AS qualified, SUM(deal_at IS NOT NULL) AS deals
                        FROM leads WHERE tenant_id = :tid AND created_at >= :s AND created_at < :e', $range)->fetch();
    $deals = q_tenant('SELECT COUNT(*) AS n, COALESCE(SUM(deal_value), 0) AS value, SUM(deal_value IS NULL) AS no_value
                       FROM leads WHERE tenant_id = :tid AND deal_at >= :s AND deal_at < :e', $range)->fetch();
    $prevDeals = $count('deal_at', $prev);

    // Lead masuk per hari lokal.
    $offset = tenant_offset_minutes();
    $daily = [];
    foreach (q_tenant('SELECT DATE(created_at + INTERVAL :off MINUTE) AS d, COUNT(*) AS n FROM leads
                       WHERE tenant_id = :tid AND created_at >= :s AND created_at < :e GROUP BY d', $range + ['off' => $offset]) as $row) {
        $daily[$row['d']] = (int) $row['n'];
    }
    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $r['start_local']->modify("+$i days")->format('Y-m-d');
        $series[] = ['date' => $d, 'n' => $daily[$d] ?? 0];
    }

    // Posisi pipeline saat ini (semua waktu).
    $byStage = [];
    foreach (q_tenant('SELECT stage, COUNT(*) AS n, COALESCE(SUM(quote_value), 0) AS quote FROM leads WHERE tenant_id = :tid GROUP BY stage') as $row) {
        $byStage[$row['stage']] = ['n' => (int) $row['n'], 'quote' => (float) $row['quote']];
    }

    $bySource = q_tenant('SELECT source, COUNT(*) AS leads, SUM(qualified_at IS NOT NULL) AS qualified, SUM(deal_at IS NOT NULL) AS deals,
                                 COALESCE(SUM(deal_value), 0) AS value
                          FROM leads WHERE tenant_id = :tid AND created_at >= :s AND created_at < :e
                          GROUP BY source ORDER BY leads DESC', $range)->fetchAll();

    $followups = [];
    if ($openKeys) {
        $in = [];
        $params = ['today' => $r['today_local']->format('Y-m-d')];
        foreach ($openKeys as $i => $k) {
            $in[] = ':st' . $i;
            $params['st' . $i] = $k;
        }
        $followups = q_tenant('SELECT l.id, l.name, l.phone, l.stage, l.next_followup, u.name AS assigned_name
                               FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
                               WHERE l.tenant_id = :tid AND l.next_followup <= :today AND l.stage IN (' . implode(', ', $in) . ')
                               ORDER BY l.next_followup, l.id LIMIT 20', $params)->fetchAll();
    }

    return [
        'days' => $days,
        'start_local' => $r['start_local'],
        'today_local' => $r['today_local'],
        'leads' => (int) $cohort['leads'],
        'leads_prev' => $count('created_at', $prev),
        'cohort_qualified' => (int) $cohort['qualified'],
        'cohort_deals' => (int) $cohort['deals'],
        'deals' => (int) $deals['n'],
        'deals_prev' => $prevDeals,
        'deal_value' => (float) $deals['value'],
        'deals_without_value' => (int) $deals['no_value'],
        'series' => $series,
        'stages' => $stages,
        'by_stage' => $byStage,
        'by_source' => $bySource,
        'followups' => $followups,
        'total_leads' => (int) q_tenant('SELECT COUNT(*) FROM leads WHERE tenant_id = :tid')->fetchColumn(),
    ];
}

function pct(int $part, int $whole): string
{
    if ($whole === 0) {
        return '-';
    }
    return number_format($part / $whole * 100, 1, ',', '.') . '%';
}

/** Teks pembanding periode sebelumnya. Tidak menampilkan persentase kalau pembaginya nol. */
function delta_text(int $now, int $prev): string
{
    if ($now === $prev) {
        return 'sama dengan periode sebelumnya';
    }
    $diff = $now - $prev;
    $text = ($diff > 0 ? 'naik ' : 'turun ') . abs($diff);
    return $text . ' dari periode sebelumnya (' . $prev . ')';
}
