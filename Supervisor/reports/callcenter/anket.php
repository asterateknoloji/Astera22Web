<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

supervisor_require_permission('cc_anket');

$cdrId = max(0, (int) ($_GET['cdr'] ?? $_POST['cdr'] ?? 0));
$agentId = max(0, (int) ($_GET['agent'] ?? $_POST['agent'] ?? 0));
$returnUrl = trim((string) ($_GET['return'] ?? $_POST['return'] ?? 'agentdetay.php'));
if (!preg_match('/^agentdetay\.php(?:\?[A-Za-z0-9_%&=.\-\[\]]*)?$/', $returnUrl)) {
    $returnUrl = 'agentdetay.php';
}

$sourceToken = "split_part(split_part(COALESCE(c.channel,''),'/',2),'-',1)";
$destinationToken = "split_part(split_part(COALESCE(c.dstchannel,''),'/',2),'-',1)";
$callRows = callcenter_rows(
    'SELECT c.id AS cdr_id,c.calldate,c.src,c.dst,c.disposition,c.duration,c.billsec,'
    . "COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AS call_linkedid,"
    . "COALESCE(c.uniqueid,'') AS call_uniqueid,a.agent_id,a.display_name,a.extension "
    . 'FROM cdr c JOIN callcenter_agents a ON a.dept_id=c.dept_id AND a.agent_id=:agent '
    . 'WHERE c.dept_id=:dept AND c.id=:cdr AND '
    . "(a.extension=c.src OR a.extension=c.dst OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension) "
    . "OR {$destinationToken} IN (a.extension,a.dept_id||'_'||a.extension)) LIMIT 1",
    ['dept' => $deptId, 'cdr' => $cdrId, 'agent' => $agentId]
);
$call = $callRows[0] ?? null;
if (!$call) {
    http_response_code(404);
    supervisor_page_start('Anket Değerlendirme', $returnUrl);
    echo '<div class="alert alert-error">Çağrı bulunamadı veya bu Agent ile eşleşmiyor.</div>';
    supervisor_page_end();
    exit;
}

$surveys = callcenter_rows(
    'SELECT s.survey_id,s.name,s.description FROM callcenter_surveys s '
    . 'WHERE s.dept_id=:dept AND s.active AND EXISTS (SELECT 1 FROM callcenter_survey_questions q '
    . 'JOIN callcenter_survey_categories c ON c.dept_id=q.dept_id AND c.survey_id=q.survey_id AND c.category_id=q.category_id '
    . 'WHERE q.dept_id=s.dept_id AND q.survey_id=s.survey_id AND q.active AND c.active) '
    . 'ORDER BY s.name,s.survey_id',
    ['dept' => $deptId]
);
$surveyIds = array_map('intval', array_column($surveys, 'survey_id'));
$surveyId = max(0, (int) ($_GET['survey'] ?? $_POST['survey'] ?? 0));
if (!$surveyId || !in_array($surveyId, $surveyIds, true)) {
    $surveyId = $surveyIds[0] ?? 0;
}

$questions = $surveyId ? callcenter_rows(
    'SELECT q.question_id,q.question_text,q.question_type,q.max_score,'
    . 'c.category_id,c.name AS category_name,c.max_score AS category_max '
    . 'FROM callcenter_survey_questions q JOIN callcenter_survey_categories c '
    . 'ON c.dept_id=q.dept_id AND c.survey_id=q.survey_id AND c.category_id=q.category_id '
    . 'WHERE q.dept_id=:dept AND q.survey_id=:survey AND q.active AND c.active '
    . 'ORDER BY c.sort_order,c.category_id,q.sort_order,q.question_id',
    ['dept' => $deptId, 'survey' => $surveyId]
) : [];

$existing = null;
$savedAnswers = [];
if ($surveyId) {
    $existing = callcenter_rows(
        'SELECT evaluation_id,total_score,max_score,note,evaluated_at FROM callcenter_survey_evaluations '
        . "WHERE dept_id=:dept AND survey_id=:survey AND agent_id=:agent AND call_linkedid=:linkedid AND status<>'void' "
        . 'ORDER BY evaluation_id DESC LIMIT 1',
        ['dept' => $deptId, 'survey' => $surveyId, 'agent' => $agentId, 'linkedid' => (string) $call['call_linkedid']]
    )[0] ?? null;
    if ($existing) {
        foreach (callcenter_rows(
            'SELECT question_id,score FROM callcenter_survey_answers '
            . 'WHERE dept_id=:dept AND evaluation_id=:evaluation',
            ['dept' => $deptId, 'evaluation' => (int) $existing['evaluation_id']]
        ) as $answer) {
            $savedAnswers[(int) $answer['question_id']] = (string) $answer['score'];
        }
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }
        if (!$surveyId || !$questions) {
            throw new RuntimeException('Değerlendirilecek aktif anket sorusu bulunamadı.');
        }

        $postedAnswers = (array) ($_POST['answers'] ?? []);
        $answerRows = [];
        $totalScore = 0.0;
        $maxScore = 0.0;
        foreach ($questions as $question) {
            $questionId = (int) $question['question_id'];
            $raw = str_replace(',', '.', trim((string) ($postedAnswers[$questionId] ?? '')));
            if ($raw === '' || !is_numeric($raw)) {
                throw new RuntimeException('Tüm sorular puanlanmalıdır.');
            }
            $score = round((float) $raw, 2);
            $questionMax = round((float) $question['max_score'], 2);
            if ($score < 0 || $score > $questionMax) {
                throw new RuntimeException('“' . (string) $question['question_text'] . '” için en fazla ' . number_format($questionMax, 2, ',', '.') . ' puan girebilirsiniz.');
            }
            if ((string) $question['question_type'] === 'yes_no' && $score !== 0.0 && $score !== $questionMax) {
                throw new RuntimeException('Evet/Hayır sorularında yalnız Evet veya Hayır seçilebilir.');
            }
            $answerRows[] = [$questionId, $score, $questionMax];
            $totalScore += $score;
            $maxScore += $questionMax;
        }
        if ($maxScore <= 0) {
            throw new RuntimeException('Anketin toplam puanı geçersiz.');
        }

        $note = trim((string) ($_POST['note'] ?? ''));
        if (mb_strlen($note) > 4000) {
            throw new RuntimeException('Not en fazla 4.000 karakter olabilir.');
        }
        $evaluationId = (int) ($existing['evaluation_id'] ?? 0);
        if (!$evaluationId) {
            $evaluationId = (int) (callcenter_rows(
                "SELECT nextval('callcenter_survey_evaluations_evaluation_id_seq')::bigint AS id"
            )[0]['id'] ?? 0);
        }
        if (!$evaluationId) {
            throw new RuntimeException('Değerlendirme numarası oluşturulamadı.');
        }

        $statements = [];
        if ($existing) {
            $statements[] = [
                'UPDATE callcenter_survey_evaluations SET evaluator_id=:evaluator,evaluated_at=now(),'
                . "status='completed',total_score=:total,max_score=:max,note=:note,updated_at=now() "
                . 'WHERE dept_id=:dept AND evaluation_id=:evaluation',
                ['evaluator' => (int) $supervisor['supervisor_id'], 'total' => $totalScore, 'max' => $maxScore,
                    'note' => $note !== '' ? $note : null, 'dept' => $deptId, 'evaluation' => $evaluationId],
            ];
            $statements[] = [
                'DELETE FROM callcenter_survey_answers WHERE dept_id=:dept AND evaluation_id=:evaluation',
                ['dept' => $deptId, 'evaluation' => $evaluationId],
            ];
        } else {
            $statements[] = [
                'INSERT INTO callcenter_survey_evaluations '
                . '(evaluation_id,dept_id,survey_id,agent_id,evaluator_id,call_linkedid,call_uniqueid,status,total_score,max_score,note) '
                . "VALUES (:evaluation,:dept,:survey,:agent,:evaluator,:linkedid,:uniqueid,'completed',:total,:max,:note)",
                ['evaluation' => $evaluationId, 'dept' => $deptId, 'survey' => $surveyId, 'agent' => $agentId,
                    'evaluator' => (int) $supervisor['supervisor_id'], 'linkedid' => (string) $call['call_linkedid'],
                    'uniqueid' => (string) $call['call_uniqueid'], 'total' => $totalScore, 'max' => $maxScore,
                    'note' => $note !== '' ? $note : null],
            ];
        }
        foreach ($answerRows as [$questionId, $score, $questionMax]) {
            $statements[] = [
                'INSERT INTO callcenter_survey_answers '
                . '(dept_id,survey_id,evaluation_id,question_id,score,max_score) '
                . 'VALUES (:dept,:survey,:evaluation,:question,:score,:max)',
                ['dept' => $deptId, 'survey' => $surveyId, 'evaluation' => $evaluationId,
                    'question' => $questionId, 'score' => $score, 'max' => $questionMax],
            ];
        }
        callcenter_execute_batch($statements);

        $auditIdentity = $supervisor;
        $auditIdentity['dept_id'] = $deptId;
        callcenter_audit_write(
            $auditIdentity,
            $existing ? 'update' : 'create',
            'anket',
            $existing ? 'Çağrı anketi güncellendi' : 'Çağrı anketi oluşturuldu',
            'Firma: ' . $deptId . '; değerlendirme_id=' . $evaluationId . '; çağrı=' . (string) $call['call_linkedid'] . '; agent_id=' . $agentId,
            'report'
        );
        header('Location: anket.php?' . http_build_query([
            'cdr' => $cdrId, 'agent' => $agentId, 'survey' => $surveyId,
            'return' => $returnUrl, 'saved' => 1,
        ]));
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $savedAnswers = array_map('strval', (array) ($_POST['answers'] ?? []));
    }
}

$canSeePhone = callcenter_supervisor_has_permission($supervisor, 'phone_unmasked');
$maskPhone = static function (string $number) use ($canSeePhone): string {
    if ($canSeePhone || strlen($number) <= 5) {
        return $number;
    }
    return substr($number, 0, 3) . str_repeat('*', max(3, strlen($number) - 7)) . substr($number, -4);
};
$categoryColors = ['#eaf3ff', '#ecfbf4', '#fff6df', '#f5edff', '#ffeef1', '#e9f9fb'];

supervisor_page_start('Anket Değerlendirme', $returnUrl);
?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><div class="alert alert-success">Değerlendirme kaydedildi.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="stats-grid survey-call-summary">
    <article class="stat-card"><div class="stat-label">Agent</div><div class="stat-value"><?= e((string) $call['display_name']) ?></div><small><?= e((string) $call['extension']) ?></small></article>
    <article class="stat-card"><div class="stat-label">Çağrı</div><div class="stat-value survey-call-number"><?= e($maskPhone((string) $call['src'])) ?> → <?= e($maskPhone((string) $call['dst'])) ?></div><small><?= e(date('d.m.Y H:i:s', strtotime((string) $call['calldate']))) ?></small></article>
    <article class="stat-card"><div class="stat-label">Durum</div><div class="stat-value"><?= e((string) $call['disposition']) ?></div><small>Görüşme: <?= e(supervisor_duration($call['billsec'])) ?></small></article>
</section>

<?php if (!$surveys): ?>
<div class="alert">Aktif ve puanlanabilir anket bulunamadı. Önce Anket Yönetimi ekranından anket, kategori ve soruları etkinleştirin.</div>
<?php else: ?>
<form class="filter-bar operational-no-print" method="get">
    <input type="hidden" name="cdr" value="<?= $cdrId ?>"><input type="hidden" name="agent" value="<?= $agentId ?>">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
    <label class="filter-group"><span class="filter-label">Değerlendirme Formu</span><select name="survey" onchange="this.form.submit()">
        <?php foreach ($surveys as $survey): ?><option value="<?= (int) $survey['survey_id'] ?>"<?= $surveyId === (int) $survey['survey_id'] ? ' selected' : '' ?>><?= e((string) $survey['name']) ?></option><?php endforeach; ?>
    </select></label>
    <noscript><button class="btn btn-primary" type="submit">Anketi Aç</button></noscript>
</form>

<form method="post" class="survey-evaluation-form" id="survey-evaluation-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="cdr" value="<?= $cdrId ?>"><input type="hidden" name="agent" value="<?= $agentId ?>">
    <input type="hidden" name="survey" value="<?= $surveyId ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
    <?php
    $lastCategory = null;
    $categoryIndex = -1;
    foreach ($questions as $question):
        if ($lastCategory !== (int) $question['category_id']):
            if ($lastCategory !== null): ?></div></section><?php endif;
            $lastCategory = (int) $question['category_id'];
            $categoryIndex++;
    ?>
    <section class="survey-evaluation-category" style="--survey-category-color:<?= e($categoryColors[$categoryIndex % count($categoryColors)]) ?>">
        <header><div><strong><?= e((string) $question['category_name']) ?></strong><small>Kategori azami <?= e(number_format((float) $question['category_max'], 2, ',', '.')) ?> puan</small></div><span class="survey-category-total" data-category="<?= $lastCategory ?>">0,00</span></header>
        <div class="survey-evaluation-questions">
        <?php endif;
        $questionId = (int) $question['question_id'];
        $questionMax = (float) $question['max_score'];
        $value = $savedAnswers[$questionId] ?? (string) $questionMax;
        ?>
        <label class="survey-evaluation-question">
            <span><?= e((string) $question['question_text']) ?><small>En fazla <?= e(number_format($questionMax, 2, ',', '.')) ?> puan</small></span>
            <?php if ((string) $question['question_type'] === 'yes_no'): ?>
            <select name="answers[<?= $questionId ?>]" data-category="<?= $lastCategory ?>" data-max="<?= e((string) $questionMax) ?>" required>
                <option value="">Seçiniz</option><option value="<?= e((string) $questionMax) ?>"<?= (float) $value === $questionMax && $value !== '' ? ' selected' : '' ?>>Evet · <?= e(number_format($questionMax, 2, ',', '.')) ?></option>
                <option value="0"<?= $value !== '' && (float) $value === 0.0 ? ' selected' : '' ?>>Hayır · 0</option>
            </select>
            <?php else: ?>
            <input type="number" name="answers[<?= $questionId ?>]" min="0" max="<?= e((string) $questionMax) ?>" step="0.01" value="<?= e($value) ?>" data-category="<?= $lastCategory ?>" data-max="<?= e((string) $questionMax) ?>" required>
            <?php endif; ?>
        </label>
    <?php endforeach; if ($lastCategory !== null): ?></div></section><?php endif; ?>
    <label class="survey-evaluation-note"><span>Değerlendirme Notu</span><textarea name="note" maxlength="4000" rows="4" placeholder="Görüşmeyle ilgili açıklama veya gelişim notu"><?= e((string) ($_POST['note'] ?? $existing['note'] ?? '')) ?></textarea></label>
    <div class="survey-evaluation-footer operational-no-print">
        <div><span>Toplam Puan</span><strong id="survey-total">0,00 / 0,00</strong></div>
        <a class="btn btn-outline" href="<?= e($returnUrl) ?>">İptal</a>
        <button class="btn btn-primary" type="submit"><?= $existing ? 'Değerlendirmeyi Güncelle' : 'Değerlendirmeyi Kaydet' ?></button>
    </div>
</form>
<?php endif; ?>
<script>
(()=>{
    const form=document.getElementById('survey-evaluation-form');
    if(!form)return;
    const fields=[...form.querySelectorAll('[data-category][data-max]')];
    const format=value=>value.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
    const update=()=>{
        let total=0,max=0; const categories={};
        fields.forEach(field=>{
            const category=field.dataset.category;
            const value=Math.max(0,Number(String(field.value).replace(',','.'))||0);
            total+=value; max+=Number(field.dataset.max)||0;
            categories[category]=(categories[category]||0)+value;
        });
        Object.entries(categories).forEach(([category,value])=>{
            const target=form.querySelector(`[data-category="${category}"].survey-category-total`);
            if(target)target.textContent=format(value);
        });
        document.getElementById('survey-total').textContent=`${format(total)} / ${format(max)}`;
    };
    fields.forEach(field=>field.addEventListener('input',update));
    update();
})();
</script>
<?php supervisor_page_end(); ?>
