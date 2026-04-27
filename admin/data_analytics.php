<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'Data Analytics | Criminology LMS';
$activeAdminPage = 'data_analytics';

/**
 * Passing rate per course.
 * Counts only completed attempts so in-progress/pending-grade records don't skew the rate.
 */
$coursePassRateStmt = $pdo->query(
    "SELECT
        c.id,
        c.title,
        COUNT(ea.id) AS total_attempts,
        SUM(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END) AS passed_attempts,
        SUM(CASE WHEN ea.is_passed = 0 THEN 1 ELSE 0 END) AS failed_attempts,
        ROUND(
            COALESCE(
                SUM(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(ea.id), 0),
                0
            ),
            2
        ) AS pass_rate_percent,
        ROUND(AVG(ea.total_score), 2) AS avg_score
     FROM courses c
     LEFT JOIN exam_attempts ea
            ON ea.course_id = c.id
           AND ea.status = 'completed'
     GROUP BY c.id, c.title
     ORDER BY pass_rate_percent DESC, c.title ASC"
);
$coursePassRates = $coursePassRateStmt->fetchAll();

$passRateLabels = [];
$passRateValues = [];
$passedPerCourse = [];
$failedPerCourse = [];
foreach ($coursePassRates as $row) {
    $passRateLabels[] = (string) $row['title'];
    $passRateValues[] = (float) $row['pass_rate_percent'];
    $passedPerCourse[] = (int) $row['passed_attempts'];
    $failedPerCourse[] = (int) $row['failed_attempts'];
}

/**
 * Overall pass/fail distribution across all courses.
 */
$overallStmt = $pdo->query(
    "SELECT
        SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) AS passed_total,
        SUM(CASE WHEN is_passed = 0 THEN 1 ELSE 0 END) AS failed_total
     FROM exam_attempts
     WHERE status = 'completed'"
);
$overallRow = $overallStmt->fetch() ?: ['passed_total' => 0, 'failed_total' => 0];
$overallPassed = (int) ($overallRow['passed_total'] ?? 0);
$overallFailed = (int) ($overallRow['failed_total'] ?? 0);

/**
 * Score band distribution per course (GROUP BY on computed buckets).
 */
$scoreBandStmt = $pdo->query(
    "SELECT
        c.title AS course_title,
        CASE
            WHEN ea.total_score >= 90 THEN '90-100'
            WHEN ea.total_score >= 80 THEN '80-89'
            WHEN ea.total_score >= 70 THEN '70-79'
            WHEN ea.total_score >= 60 THEN '60-69'
            ELSE 'Below 60'
        END AS score_band,
        COUNT(*) AS attempts_in_band
     FROM exam_attempts ea
     INNER JOIN courses c ON c.id = ea.course_id
     WHERE ea.status = 'completed'
     GROUP BY c.id, c.title, score_band
     ORDER BY c.title ASC,
              FIELD(score_band, '90-100', '80-89', '70-79', '60-69', 'Below 60')"
);
$scoreBandRows = $scoreBandStmt->fetchAll();

$scoreBands = ['90-100', '80-89', '70-79', '60-69', 'Below 60'];
$scoreBandCourses = [];
$scoreBandMatrix = [];
foreach ($scoreBandRows as $row) {
    $courseTitle = (string) $row['course_title'];
    if (!in_array($courseTitle, $scoreBandCourses, true)) {
        $scoreBandCourses[] = $courseTitle;
    }
    $scoreBandMatrix[$courseTitle][(string) $row['score_band']] = (int) $row['attempts_in_band'];
}

$scoreBandSeries = [];
foreach ($scoreBands as $band) {
    $dataPoints = [];
    foreach ($scoreBandCourses as $courseTitle) {
        $dataPoints[] = (int) ($scoreBandMatrix[$courseTitle][$band] ?? 0);
    }
    $scoreBandSeries[] = [
        'name' => $band,
        'data' => $dataPoints,
    ];
}

/**
 * Monthly pass-rate trend (GROUP BY year-month).
 */
$trendStmt = $pdo->query(
    "SELECT
        DATE_FORMAT(ea.completed_at, '%Y-%m') AS period,
        COUNT(*) AS attempts,
        SUM(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END) AS passed,
        ROUND(SUM(CASE WHEN ea.is_passed = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 2) AS pass_rate
     FROM exam_attempts ea
     WHERE ea.status = 'completed'
       AND ea.completed_at IS NOT NULL
     GROUP BY period
     ORDER BY period ASC
     LIMIT 12"
);
$trendRows = $trendStmt->fetchAll();
$trendLabels = [];
$trendPassRates = [];
foreach ($trendRows as $row) {
    $trendLabels[] = (string) $row['period'];
    $trendPassRates[] = (float) $row['pass_rate'];
}

$sneatBaseEscaped = htmlspecialchars($clmsSneatBase, ENT_QUOTES, 'UTF-8');
$extraHead = '<link rel="stylesheet" href="' . $sneatBaseEscaped . '/assets/vendor/libs/apex-charts/apex-charts.css" />'
    . '<script src="' . $sneatBaseEscaped . '/assets/vendor/libs/apex-charts/apexcharts.js"></script>';
$extraScripts = '';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Data Analytics</h4>
                  <small class="text-muted">Visual insights into criminology course performance and passing rates.</small>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-xl-8">
                  <div class="card h-100">
                    <h5 class="card-header">Passing Rate by Course</h5>
                    <div class="card-body">
<?php if ($passRateLabels === []) : ?>
                      <p class="mb-0 text-muted">No course data yet.</p>
<?php else : ?>
                      <div id="coursePassRateChart"></div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-xl-4">
                  <div class="card h-100">
                    <h5 class="card-header">Overall Pass vs Fail</h5>
                    <div class="card-body">
<?php if ($overallPassed + $overallFailed === 0) : ?>
                      <p class="mb-0 text-muted">No completed attempts yet.</p>
<?php else : ?>
                      <div id="overallPassPieChart"></div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-xl-7">
                  <div class="card h-100">
                    <h5 class="card-header">Score Band Distribution per Course</h5>
                    <div class="card-body">
<?php if ($scoreBandCourses === []) : ?>
                      <p class="mb-0 text-muted">No completed attempts yet.</p>
<?php else : ?>
                      <div id="scoreBandChart"></div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-xl-5">
                  <div class="card h-100">
                    <h5 class="card-header">Passed vs Failed per Course</h5>
                    <div class="card-body">
<?php if ($passRateLabels === []) : ?>
                      <p class="mb-0 text-muted">No course data yet.</p>
<?php else : ?>
                      <div id="passFailByCourseChart"></div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-4">
                <h5 class="card-header">Monthly Pass Rate Trend</h5>
                <div class="card-body">
<?php if ($trendLabels === []) : ?>
                  <p class="mb-0 text-muted">Not enough historical data yet.</p>
<?php else : ?>
                  <div id="monthlyTrendChart"></div>
<?php endif; ?>
                </div>
              </div>

              <div class="card">
                <h5 class="card-header">Course Pass Rate Summary</h5>
                <div class="card-body">
<?php if ($coursePassRates === []) : ?>
                  <p class="mb-0 text-muted">No courses yet.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Course</th>
                          <th>Total Attempts</th>
                          <th>Passed</th>
                          <th>Failed</th>
                          <th>Average Score</th>
                          <th>Pass Rate</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($coursePassRates as $row) : ?>
                        <tr>
                          <td><?php echo htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><?php echo (int) $row['total_attempts']; ?></td>
                          <td><?php echo (int) $row['passed_attempts']; ?></td>
                          <td><?php echo (int) $row['failed_attempts']; ?></td>
                          <td><?php echo $row['avg_score'] !== null ? number_format((float) $row['avg_score'], 2) . '%' : '—'; ?></td>
                          <td>
                            <span class="badge bg-label-<?php echo ((float) $row['pass_rate_percent']) >= 75 ? 'success' : (((float) $row['pass_rate_percent']) >= 50 ? 'warning' : 'danger'); ?>">
                              <?php echo number_format((float) $row['pass_rate_percent'], 2); ?>%
                            </span>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
              </div>

              <script>
                (function () {
                  var chartData = {
                    passRateLabels: <?php echo json_encode($passRateLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    passRateValues: <?php echo json_encode($passRateValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    overallPassed: <?php echo (int) $overallPassed; ?>,
                    overallFailed: <?php echo (int) $overallFailed; ?>,
                    scoreBandSeries: <?php echo json_encode($scoreBandSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    scoreBandCourses: <?php echo json_encode($scoreBandCourses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    passedPerCourse: <?php echo json_encode($passedPerCourse, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    failedPerCourse: <?php echo json_encode($failedPerCourse, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    trendLabels: <?php echo json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    trendPassRates: <?php echo json_encode($trendPassRates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                  };

                  function renderCharts() {
                    if (typeof ApexCharts === 'undefined') {
                      console.error('[Data Analytics] ApexCharts library did not load.');
                      document.querySelectorAll('[id$="Chart"]').forEach(function (el) {
                        el.innerHTML = '<div class="alert alert-warning mb-0">Chart library failed to load. Check the browser console.</div>';
                      });
                      return;
                    }

                    function tryRender(selector, config) {
                      var el = document.querySelector(selector);
                      if (!el) { return; }
                      try {
                        new ApexCharts(el, config).render();
                      } catch (err) {
                        console.error('[Data Analytics] Failed to render ' + selector, err);
                        el.innerHTML = '<div class="alert alert-danger mb-0">Unable to render this chart: ' + (err && err.message ? err.message : err) + '</div>';
                      }
                    }

                    tryRender('#coursePassRateChart', {
                      chart: { type: 'bar', height: 360, toolbar: { show: false } },
                      plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '65%' } },
                      dataLabels: { enabled: true, formatter: function (v) { return v + '%'; } },
                      series: [{ name: 'Pass Rate', data: chartData.passRateValues }],
                      xaxis: {
                        categories: chartData.passRateLabels,
                        max: 100,
                        labels: { formatter: function (v) { return v + '%'; } }
                      },
                      colors: ['#696cff']
                    });

                    tryRender('#overallPassPieChart', {
                      chart: { type: 'pie', height: 320 },
                      series: [chartData.overallPassed, chartData.overallFailed],
                      labels: ['Passed', 'Failed'],
                      colors: ['#71dd37', '#ff3e1d'],
                      legend: { position: 'bottom' }
                    });

                    tryRender('#scoreBandChart', {
                      chart: { type: 'bar', stacked: true, height: 360, toolbar: { show: false } },
                      plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 } },
                      dataLabels: { enabled: false },
                      series: chartData.scoreBandSeries,
                      xaxis: { categories: chartData.scoreBandCourses },
                      colors: ['#71dd37', '#03c3ec', '#696cff', '#ffab00', '#ff3e1d'],
                      legend: { position: 'bottom' }
                    });

                    tryRender('#passFailByCourseChart', {
                      chart: { type: 'bar', stacked: true, height: 360, toolbar: { show: false } },
                      plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
                      dataLabels: { enabled: false },
                      series: [
                        { name: 'Passed', data: chartData.passedPerCourse },
                        { name: 'Failed', data: chartData.failedPerCourse }
                      ],
                      xaxis: { categories: chartData.passRateLabels },
                      colors: ['#71dd37', '#ff3e1d'],
                      legend: { position: 'bottom' }
                    });

                    tryRender('#monthlyTrendChart', {
                      chart: { type: 'line', height: 320, toolbar: { show: false } },
                      stroke: { curve: 'smooth', width: 3 },
                      markers: { size: 4 },
                      series: [{ name: 'Pass Rate', data: chartData.trendPassRates }],
                      xaxis: { categories: chartData.trendLabels },
                      yaxis: { max: 100, labels: { formatter: function (v) { return v + '%'; } } },
                      colors: ['#03c3ec']
                    });
                  }

                  if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', renderCharts);
                  } else {
                    renderCharts();
                  }
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
