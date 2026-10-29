<?php
declare(strict_types=1);

function page_dashboard(): void
{
    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, DASHBOARD_PERIODS, true)) {
        $days = 30;
    }
    render('dashboard/index', [
        'title' => 'Ringkasan',
        'nav' => 'dashboard',
        'd' => dashboard_data($days),
    ]);
}
