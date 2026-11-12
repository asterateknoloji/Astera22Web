<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

function survey_report_percent(mixed $score, mixed $maxScore): float
{
    $max = (float) $maxScore;
    return $max > 0 ? round(((float) $score / $max) * 100, 1) : 0.0;
}

function survey_report_number(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

$from = supervisor_date('from', date('Y-m-01'));
$to = supervisor_date('to', date('Y-m-d'));
if ($to < $from) {
    $to = $from;
}
$evaluatorSearch = trim((string) ($_GET['evaluator'] ?? ''));

$agents = callcenter_rows(
    "SELECT a.agent_id,a.display_name,a.extension FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND a.status='active' AND " . supervisor_agent_sql('a')
    . ' ORDER BY a.display_name,a.extension',
    ['dept' => $deptId]
);
$allowedAgentIds = array_map('intval', array_column($agents, 'agent_id'));
$requestedAgentIds = array_values(array_unique(array_filter(array_map(
    'intval',
    (array) ($_GET['agents'] ?? [])
))));
$selectedAgentIds = array_values(array_intersect($allowedAgentIds, $requestedAgentIds));

$surveys = callcenter_rows(
    'SELECT survey_id,name FROM callcenter_surveys '
    . 'WHERE dept_id=:dept ORDER BY active DESC,name,survey_id',
    ['dept' => $deptId]
);
$allowedSurveyIds = array_map('intval', array_column($surveys, 'survey_id'));
$surveyId = max(0, (int) ($_GET['survey'] ?? 0));
if ($surveyId && !in_array($surveyId, $allowedSurveyIds, true)) {
    $surveyId = 0;
}

$categories = callcenter_rows(
    'SELECT c.category_id,c.survey_id,c.name,s.name AS survey_name '
    . 'FROM callcenter_survey_categories c '
    . 'JOIN callcenter_surveys s ON s.dept_id=c.dept_id AND s.survey_id=c.survey_id '
    . 'WHERE c.dept_id=:dept ORDER BY s.name,c.sort_order,c.category_id',
    ['dept' => $deptId]
);
$allowedCategoryIds = array_map('intval', array_column($categories, 'category_id'));
$categoryId = max(0, (int) ($_GET['category'] ?? 0));
if ($categoryId && !in_array($categoryId, $allowedCategoryIds, true)) {
    $categoryId = 0;
}

$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
$where = [
    'e.dept_id=:dept',
    "e.status='completed'",
    'e.evaluated_at>=:from::date',
    "e.evaluated_at<(:to::date+interval '1 day')",
    supervisor_agent_sql('a'),
];
if ($selectedAgentIds) {
    $placeholders = [];
    foreach ($selectedAgentIds as $index => $selectedAgentId) {
        $name = 'agent_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $selectedAgentId;
    }
    $where[] = 'e.agent_id IN (' . implode(',', $placeholders) . ')';
}
if ($surveyId) {
    $where[] = 'e.survey_id=:survey';
    $params['survey'] = $surveyId;
}
if ($categoryId) {
    $where[] = 'EXISTS (SELECT 1 FROM callcenter_survey_answers fa '
        . 'JOIN callcenter_survey_questions fq ON fq.dept_id=fa.dept_id '
        . 'AND fq.survey_id=fa.survey_id AND fq.question_id=fa.question_id '
        . 'WHERE fa.dept_id=e.dept_id AND fa.survey_id=e.survey_id '
        . 'AND fa.evaluation_id=e.evaluation_id AND fq.category_id=:category)';
    $params['category'] = $categoryId;
}
if ($evaluatorSearch !== '') {
    $where[] = '(u.display_name ILIKE :evaluator_search OR u.username ILIKE :evaluator_search)';
    $params['evaluator_search'] = '%' . $evaluatorSearch . '%';
}

$filteredCte = 'WITH filtered_evaluations AS ('
    . 'SELECT e.evaluation_id,e.dept_id,e.survey_id,e.agent_id,e.evaluator_id,'
    . 'e.evaluated_at,e.total_score,e.max_score,a.display_name AS agent_name,'
    . 'a.extension,u.display_name AS evaluator_name,u.username AS evaluator_username '
    . 'FROM callcenter_survey_evaluations e '
    . 'JOIN callcenter_agents a ON a.dept_id=e.dept_id AND a.agent_id=e.agent_id '
    . 'JOIN callcenter_supervisor_users u '
    . 'ON u.dept_id=e.dept_id AND u.supervisor_id=e.evaluator_id '
    . 'WHERE ' . implode(' AND ', $where) . ') ';

$summaryRows = callcenter_rows(
    $filteredCte
    . 'SELECT count(*)::integer AS evaluation_count,'
    . 'count(DISTINCT agent_id)::integer AS agent_count,'
    . 'count(DISTINCT evaluator_id)::integer AS evaluator_count,'
    . 'COALESCE(sum(total_score),0) AS total_score,COALESCE(sum(max_score),0) AS max_score '
    . 'FROM filtered_evaluations',
    $params
);
$summary = $summaryRows[0] ?? [];

$agentRows = callcenter_rows(
    $filteredCte
    . 'SELECT agent_id,agent_name,extension,count(*)::integer AS evaluation_count,'
    . 'sum(total_score) AS total_score,sum(max_score) AS max_score,'
    . "to_char(min(evaluated_at),'DD.MM.YYYY HH24:MI') AS first_evaluation,"
    . "to_char(max(evaluated_at),'DD.MM.YYYY HH24:MI') AS last_evaluation "
    . 'FROM filtered_evaluations GROUP BY agent_id,agent_name,extension '
    . 'ORDER BY sum(total_score)/NULLIF(sum(max_score),0) DESC,agent_name',
    $params
);

$answerCategoryFilter = $categoryId ? ' AND q.category_id=:category' : '';
$categoryRows = callcenter_rows(
    $filteredCte
    . 'SELECT c.category_id,c.name,count(DISTINCT fe.evaluation_id)::integer AS evaluation_count,'
    . 'sum(sa.score) AS total_score,sum(sa.max_score) AS max_score '
    . 'FROM filtered_evaluations fe '
    . 'JOIN callcenter_survey_answers sa ON sa.dept_id=fe.dept_id '
    . 'AND sa.survey_id=fe.survey_id AND sa.evaluation_id=fe.evaluation_id '
    . 'JOIN callcenter_survey_questions q ON q.dept_id=sa.dept_id '
    . 'AND q.survey_id=sa.survey_id AND q.question_id=sa.question_id '
    . 'JOIN callcenter_survey_categories c ON c.dept_id=q.dept_id '
    . 'AND c.survey_id=q.survey_id AND c.category_id=q.category_id '
    . 'WHERE TRUE' . $answerCategoryFilter
    . ' GROUP BY c.category_id,c.name '
    . 'ORDER BY sum(sa.score)/NULLIF(sum(sa.max_score),0) DESC,c.name',
    $params
);

$questionRows = callcenter_rows(
    $filteredCte
    . 'SELECT q.question_id,q.question_text,q.question_type,c.name AS category_name,'
    . 'count(*)::integer AS answer_count,sum(sa.score) AS total_score,'
    . 'sum(sa.max_score) AS max_score,avg(sa.score) AS average_score,avg(sa.max_score) AS average_max '
    . 'FROM filtered_evaluations fe '
    . 'JOIN callcenter_survey_answers sa ON sa.dept_id=fe.dept_id '
    . 'AND sa.survey_id=fe.survey_id AND sa.evaluation_id=fe.evaluation_id '
    . 'JOIN callcenter_survey_questions q ON q.dept_id=sa.dept_id '
    . 'AND q.survey_id=sa.survey_id AND q.question_id=sa.question_id '
    . 'JOIN callcenter_survey_categories c ON c.dept_id=q.dept_id '
    . 'AND c.survey_id=q.survey_id AND c.category_id=q.category_id '
    . 'WHERE TRUE' . $answerCategoryFilter
    . ' GROUP BY q.question_id,q.question_text,q.question_type,c.name '
    . 'ORDER BY sum(sa.score)/NULLIF(sum(sa.max_score),0),q.question_id LIMIT 10',
    $params
);

$evaluatorRows = callcenter_rows(
    $filteredCte
    . 'SELECT evaluator_id,evaluator_name,evaluator_username,'
    . 'count(*)::integer AS evaluation_count,count(DISTINCT agent_id)::integer AS agent_count,'
    . 'sum(total_score) AS total_score,sum(max_score) AS max_score '
    . 'FROM filtered_evaluations '
    . 'GROUP BY evaluator_id,evaluator_name,evaluator_username '
    . 'ORDER BY count(*) DESC,evaluator_name',
    $params
);

$dailyRows = callcenter_rows(
    $filteredCte
    . "SELECT to_char(evaluated_at::date,'DD.MM.YYYY') AS day,"
    . 'evaluated_at::date AS day_sort,count(*)::integer AS evaluation_count,'
    . 'sum(total_score) AS total_score,sum(max_score) AS max_score '
    . 'FROM filtered_evaluations GROUP BY evaluated_at::date ORDER BY day_sort',
    $params
);

$evaluatorOptions = callcenter_rows(
    'SELECT DISTINCT u.supervisor_id,u.display_name,u.username '
    . 'FROM callcenter_survey_evaluations e '
    . 'JOIN callcenter_agents a ON a.dept_id=e.dept_id AND a.agent_id=e.agent_id '
    . 'JOIN callcenter_supervisor_users u '
    . 'ON u.dept_id=e.dept_id AND u.supervisor_id=e.evaluator_id '
    . "WHERE e.dept_id=:dept AND e.status='completed' AND " . supervisor_agent_sql('a')
    . ' ORDER BY u.display_name,u.username',
    ['dept' => $deptId]
);

$evaluationCount = (int) ($summary['evaluation_count'] ?? 0);
$overallPercent = survey_report_percent($summary['total_score'] ?? 0, $summary['max_score'] ?? 0);
$questionTypeLabels = ['score' => 'Puan', 'yes_no' => 'Evet / Hayır'];

supervisor_page_start('Agent Anket Özet');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgentIds, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Anket</span><select name="survey"><option value="0">Tüm anketler</option>
        <?php foreach ($surveys as $survey): ?><option value="<?= (int) $survey['survey_id'] ?>"<?= $surveyId === (int) $survey['survey_id'] ? ' selected' : '' ?>><?= e((string) $survey['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Kategori</span><select name="category"><option value="0">Tüm kategoriler</option>
        <?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>"<?= $categoryId === (int) $category['category_id'] ? ' selected' : '' ?>><?= e((string) $category['survey_name'] . ' · ' . (string) $category['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-search-filter"><span class="filter-label">Değerlendirici ara</span><input name="evaluator" list="survey-evaluators" value="<?= e($evaluatorSearch) ?>" placeholder="Ad veya kullanıcı adı"></label>
    <datalist id="survey-evaluators"><?php foreach ($evaluatorOptions as $option): ?><option value="<?= e((string) $option['display_name']) ?>"><?= e((string) $option['username']) ?></option><?php endforeach; ?></datalist>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="agent_anket_ozet.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Toplam Değerlendirme</div><div class="stat-value"><?= $evaluationCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Değerlendirilen Agent</div><div class="stat-value"><?= (int) ($summary['agent_count'] ?? 0) ?></div></article>
    <article class="stat-card"><div class="stat-label">Genel Başarı</div><div class="stat-value"><?= number_format($overallPercent, 1, ',', '.') ?>%</div></article>
    <article class="stat-card"><div class="stat-label">Değerlendirici</div><div class="stat-value"><?= (int) ($summary['evaluator_count'] ?? 0) ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam / Maksimum Puan</div><div class="stat-value operational-duration"><?= survey_report_number($summary['total_score'] ?? 0) ?> / <?= survey_report_number($summary['max_score'] ?? 0) ?></div></article>
</section>

<?php if ($evaluationCount === 0): ?>
<div class="table-container"><div class="table-header">Anket Değerlendirmeleri</div><div class="empty">Henüz değerlendirme yok. Seçilen tarih ve filtrelerde tamamlanmış bir anket değerlendirmesi bulunamadı.</div></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header">Agent Performansı</div>
    <table class="operational-report-table"><thead><tr><th>Agent</th><th>Değerlendirme</th><th>Toplam / Maks.</th><th>Başarı</th><th>İlk</th><th>Son</th></tr></thead><tbody>
    <?php foreach ($agentRows as $row): $percent = survey_report_percent($row['total_score'], $row['max_score']); ?>
        <tr><td><strong><?= e((string) $row['agent_name']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['extension']) ?></small></td>
            <td><?= (int) $row['evaluation_count'] ?></td><td><?= survey_report_number($row['total_score']) ?> / <?= survey_report_number($row['max_score']) ?></td>
            <td><span class="badge <?= $percent >= 80 ? 'badge-success' : ($percent >= 60 ? 'badge-warning' : 'badge-danger') ?>"><?= number_format($percent, 1, ',', '.') ?>%</span></td>
            <td class="audit-time"><?= e((string) $row['first_evaluation']) ?></td><td class="audit-time"><?= e((string) $row['last_evaluation']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$agentRows): ?><tr><td colspan="6" class="empty">Henüz değerlendirme yok.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<div class="table-container">
    <div class="table-header">Kategori Performansı</div>
    <table class="operational-report-table"><thead><tr><th>Kategori</th><th>Değerlendirme</th><th>Toplam / Maks.</th><th>Başarı</th></tr></thead><tbody>
    <?php foreach ($categoryRows as $row): $percent = survey_report_percent($row['total_score'], $row['max_score']); ?>
        <tr><td><strong><?= e((string) $row['name']) ?></strong></td><td><?= (int) $row['evaluation_count'] ?></td>
            <td><?= survey_report_number($row['total_score']) ?> / <?= survey_report_number($row['max_score']) ?></td><td><?= number_format($percent, 1, ',', '.') ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$categoryRows): ?><tr><td colspan="4" class="empty">Henüz değerlendirme yok.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<div class="table-container">
    <div class="table-header">En Düşük 10 Soru · Gelişim Alanları</div>
    <table class="operational-report-table"><thead><tr><th>Soru</th><th>Kategori</th><th>Tip</th><th>Cevap</th><th>Ort. Puan</th><th>Başarı</th></tr></thead><tbody>
    <?php foreach ($questionRows as $row): $percent = survey_report_percent($row['total_score'], $row['max_score']); ?>
        <tr><td><?= e((string) $row['question_text']) ?></td><td><?= e((string) $row['category_name']) ?></td>
            <td><?= e($questionTypeLabels[(string) $row['question_type']] ?? (string) $row['question_type']) ?></td><td><?= (int) $row['answer_count'] ?></td>
            <td><?= survey_report_number($row['average_score']) ?> / <?= survey_report_number($row['average_max']) ?></td><td><?= number_format($percent, 1, ',', '.') ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$questionRows): ?><tr><td colspan="6" class="empty">Henüz değerlendirme yok.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<div class="table-container">
    <div class="table-header">Değerlendirici İstatistiği</div>
    <table class="operational-report-table"><thead><tr><th>Değerlendirici</th><th>Değerlendirme</th><th>Agent</th><th>Toplam / Maks.</th><th>Ortalama Başarı</th></tr></thead><tbody>
    <?php foreach ($evaluatorRows as $row): $percent = survey_report_percent($row['total_score'], $row['max_score']); ?>
        <tr><td><strong><?= e((string) $row['evaluator_name']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['evaluator_username']) ?></small></td>
            <td><?= (int) $row['evaluation_count'] ?></td><td><?= (int) $row['agent_count'] ?></td>
            <td><?= survey_report_number($row['total_score']) ?> / <?= survey_report_number($row['max_score']) ?></td><td><?= number_format($percent, 1, ',', '.') ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$evaluatorRows): ?><tr><td colspan="5" class="empty">Henüz değerlendirme yok.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<div class="table-container">
    <div class="table-header">Günlük Trend</div>
    <table class="operational-report-table"><thead><tr><th>Gün</th><th>Değerlendirme</th><th>Toplam / Maks.</th><th>Başarı</th></tr></thead><tbody>
    <?php foreach ($dailyRows as $row): $percent = survey_report_percent($row['total_score'], $row['max_score']); ?>
        <tr><td class="audit-time"><?= e((string) $row['day']) ?></td><td><?= (int) $row['evaluation_count'] ?></td>
            <td><?= survey_report_number($row['total_score']) ?> / <?= survey_report_number($row['max_score']) ?></td><td><?= number_format($percent, 1, ',', '.') ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$dailyRows): ?><tr><td colspan="4" class="empty">Henüz değerlendirme yok.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php supervisor_page_end(); ?>
