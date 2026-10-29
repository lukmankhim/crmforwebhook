<?php
declare(strict_types=1);

const SOURCES_TOP = 20;

/** Halaman sumber: GA4 dan Search Console per periode, dibanding periode sebelumnya. Hanya provider yang tersambung atau punya data. */
function page_sources(): void
{
    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, DASHBOARD_PERIODS, true)) {
        $days = 30;
    }
    $tz = tenant_tz();
    $today = new DateTimeImmutable('today', $tz);
    $cur = ['f' => $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 't' => $today->format('Y-m-d')];
    $prev = ['f' => $today->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d'), 't' => $today->modify("-{$days} days")->format('Y-m-d')];

    $integrations = tenant_integrations();
    $hasGa4 = isset($integrations['ga4']) || (bool) q_tenant('SELECT 1 FROM site_daily WHERE tenant_id = :tid LIMIT 1')->fetchColumn();
    $hasGsc = isset($integrations['gsc']) || (bool) q_tenant('SELECT 1 FROM search_daily WHERE tenant_id = :tid LIMIT 1')->fetchColumn();

    $ga4 = null;
    if ($hasGa4) {
        $tot = fn(array $r) => q_tenant('SELECT COALESCE(SUM(sessions), 0) AS sessions, COALESCE(SUM(conversions), 0) AS conversions
                                         FROM site_daily WHERE tenant_id = :tid AND date BETWEEN :f AND :t', $r)->fetch();
        $by = function (string $dimSql) use ($cur, $prev) {
            return q_tenant("SELECT $dimSql AS dim,
                    SUM(CASE WHEN date BETWEEN :cf AND :ct THEN sessions ELSE 0 END) AS sessions,
                    SUM(CASE WHEN date BETWEEN :cf2 AND :ct2 THEN conversions ELSE 0 END) AS conversions,
                    SUM(CASE WHEN date BETWEEN :pf AND :pt THEN sessions ELSE 0 END) AS sessions_prev
                FROM site_daily WHERE tenant_id = :tid AND date BETWEEN :pf2 AND :ct3
                GROUP BY dim HAVING sessions > 0 OR sessions_prev > 0 ORDER BY sessions DESC LIMIT " . SOURCES_TOP, [
                'cf' => $cur['f'], 'ct' => $cur['t'], 'cf2' => $cur['f'], 'ct2' => $cur['t'],
                'pf' => $prev['f'], 'pt' => $prev['t'], 'pf2' => $prev['f'], 'ct3' => $cur['t'],
            ])->fetchAll();
        };
        $ga4 = [
            'cur' => $tot($cur),
            'prev' => $tot($prev),
            'by_source' => $by("CONCAT(source, ' / ', medium)"),
            'by_campaign' => $by("campaign"),
            'by_page' => $by('landing_page'),
        ];
    }

    $gsc = null;
    if ($hasGsc) {
        // Total dari baris per halaman (query kosong) supaya query anonim ikut terhitung.
        $tot = fn(array $r) => q_tenant("SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions,
                                         COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS position
                                         FROM search_daily WHERE tenant_id = :tid AND query = '' AND date BETWEEN :f AND :t", $r)->fetch();
        $by = function (string $dim, string $filter) use ($cur, $prev) {
            return q_tenant("SELECT $dim AS dim,
                    SUM(CASE WHEN date BETWEEN :cf AND :ct THEN clicks ELSE 0 END) AS clicks,
                    SUM(CASE WHEN date BETWEEN :cf2 AND :ct2 THEN impressions ELSE 0 END) AS impressions,
                    SUM(CASE WHEN date BETWEEN :cf3 AND :ct3 THEN position * impressions ELSE 0 END)
                      / NULLIF(SUM(CASE WHEN date BETWEEN :cf4 AND :ct4 THEN impressions ELSE 0 END), 0) AS position,
                    SUM(CASE WHEN date BETWEEN :pf AND :pt THEN clicks ELSE 0 END) AS clicks_prev
                FROM search_daily WHERE tenant_id = :tid AND $filter AND date BETWEEN :pf2 AND :ct5
                GROUP BY dim HAVING clicks > 0 OR impressions > 0 OR clicks_prev > 0
                ORDER BY clicks DESC, impressions DESC LIMIT " . SOURCES_TOP, [
                'cf' => $cur['f'], 'ct' => $cur['t'], 'cf2' => $cur['f'], 'ct2' => $cur['t'], 'cf3' => $cur['f'], 'ct3' => $cur['t'],
                'cf4' => $cur['f'], 'ct4' => $cur['t'], 'pf' => $prev['f'], 'pt' => $prev['t'], 'pf2' => $prev['f'], 'ct5' => $cur['t'],
            ])->fetchAll();
        };
        $gsc = [
            'cur' => $tot($cur),
            'prev' => $tot($prev),
            'by_page' => $by('page', "query = ''"),
            'by_query' => $by('query', "query <> ''"),
        ];
    }

    render('sources/index', [
        'title' => 'Sumber',
        'nav' => 'sources',
        'days' => $days,
        'cur' => $cur,
        'prev' => $prev,
        'integrations' => $integrations,
        'ga4' => $ga4,
        'gsc' => $gsc,
    ]);
}

/** Perubahan dibanding periode sebelumnya. Tanpa persentase kalau periode sebelumnya nol. */
function change_cell(float $now, float $prev): string
{
    if ($prev == 0.0) {
        return $now > 0 ? 'baru' : '-';
    }
    $p = ($now - $prev) / $prev * 100;
    return ($p > 0 ? '+' : '') . number_format($p, 0, ',', '.') . '%';
}
