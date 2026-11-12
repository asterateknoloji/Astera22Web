<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

supervisor_require_permission('cc_anket');

const SURVEY_FLASH_KEY = 'callcenter_survey_flash';

function survey_positive_decimal(string $value, string $label): string
{
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float) $value <= 0) {
        throw new InvalidArgumentException($label . ' 0’dan büyük olmalı ve en fazla iki ondalık basamak içermelidir.');
    }
    return number_format((float) $value, 2, '.', '');
}

function survey_positive_integer(mixed $value, string $label): int
{
    $raw = trim((string) $value);
    if (!preg_match('/^[1-9]\d*$/', $raw)) {
        throw new InvalidArgumentException($label . ' 0’dan büyük bir tam sayı olmalıdır.');
    }
    return (int) $raw;
}

function survey_required_text(mixed $value, string $label, int $maxLength = 0): string
{
    $text = trim((string) $value);
    if ($text === '') {
        throw new InvalidArgumentException($label . ' zorunludur.');
    }
    if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
        throw new InvalidArgumentException($label . ' en fazla ' . $maxLength . ' karakter olabilir.');
    }
    return $text;
}

function survey_scoped_row(string $table, string $idColumn, int $id, string $deptId): array
{
    $allowed = [
        'callcenter_surveys' => 'survey_id',
        'callcenter_survey_categories' => 'category_id',
        'callcenter_survey_questions' => 'question_id',
    ];
    if (($allowed[$table] ?? null) !== $idColumn || $id <= 0) {
        throw new InvalidArgumentException('Geçersiz kayıt seçimi.');
    }
    $rows = callcenter_rows(
        "SELECT * FROM {$table} WHERE dept_id=:dept AND {$idColumn}=:id LIMIT 1",
        ['dept' => $deptId, 'id' => $id]
    );
    if (!$rows) {
        throw new InvalidArgumentException('Kayıt bulunamadı veya bu firmaya ait değil.');
    }
    return $rows[0];
}

function survey_database_message(Throwable $exception): string
{
    $message = $exception->getMessage();
    if ((string) $exception->getCode() === '23505' || str_contains($message, 'duplicate key')) {
        return 'Aynı adla başka bir kayıt zaten mevcut.';
    }
    if ((string) $exception->getCode() === '23503' || str_contains($message, 'foreign key')) {
        return 'Kayıt kullanımda olduğu için silinemiyor.';
    }
    return $exception instanceof InvalidArgumentException
        || ($exception instanceof RuntimeException && !$exception instanceof PDOException)
        ? $message
        : 'İşlem tamamlanamadı. Lütfen tekrar deneyin.';
}

function survey_filter_state(array $source): array
{
    $surveyId = max(0, (int) ($source['filter_survey'] ?? 0));
    $categoryId = max(0, (int) ($source['filter_category'] ?? 0));
    $type = in_array(($source['filter_type'] ?? ''), ['score', 'yes_no'], true)
        ? (string) $source['filter_type']
        : '';
    $active = in_array((string) ($source['filter_active'] ?? ''), ['0', '1'], true)
        ? (string) $source['filter_active']
        : '';
    $search = mb_substr(trim((string) ($source['filter_search'] ?? '')), 0, 180);
    return [
        'filter_survey' => $surveyId > 0 ? (string) $surveyId : '',
        'filter_category' => $categoryId > 0 ? (string) $categoryId : '',
        'filter_type' => $type,
        'filter_active' => $active,
        'filter_search' => $search,
    ];
}

function survey_redirect(array $filters = []): never
{
    $filters = array_filter(
        survey_filter_state($filters),
        static fn(string $value): bool => $value !== ''
    );
    header('Location: anket_yonetim.php' . ($filters ? '?' . http_build_query($filters) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['message' => '', 'error' => ''];
    $returnFilters = survey_filter_state(array_merge($_GET, $_POST));
    $auditIdentity = $supervisor;
    $auditIdentity['dept_id'] = $deptId;
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }

        $action = trim((string) ($_POST['action'] ?? ''));
        $auditEvent = 'update';
        $auditAction = '';
        $auditDescription = 'Firma: ' . $deptId;

        if ($action === 'survey_save') {
            $surveyId = max(0, (int) ($_POST['survey_id'] ?? 0));
            $name = survey_required_text($_POST['name'] ?? '', 'Anket adı', 180);
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($surveyId > 0) {
                survey_scoped_row('callcenter_surveys', 'survey_id', $surveyId, $deptId);
                callcenter_execute(
                    'UPDATE callcenter_surveys SET name=:name,description=:description,updated_at=now() '
                    . 'WHERE dept_id=:dept AND survey_id=:survey',
                    ['name' => $name, 'description' => $description !== '' ? $description : null, 'dept' => $deptId, 'survey' => $surveyId]
                );
                $flash['message'] = 'Anket güncellendi.';
                $auditAction = 'Anket güncellendi';
                $auditDescription .= '; anket_id=' . $surveyId . '; ad=' . $name;
            } else {
                callcenter_execute(
                    'INSERT INTO callcenter_surveys (dept_id,name,description) VALUES (:dept,:name,:description)',
                    ['dept' => $deptId, 'name' => $name, 'description' => $description !== '' ? $description : null]
                );
                $flash['message'] = 'Anket oluşturuldu.';
                $auditEvent = 'create';
                $auditAction = 'Anket oluşturuldu';
                $auditDescription .= '; ad=' . $name;
            }
        } elseif ($action === 'survey_toggle') {
            $surveyId = max(0, (int) ($_POST['survey_id'] ?? 0));
            $survey = survey_scoped_row('callcenter_surveys', 'survey_id', $surveyId, $deptId);
            $active = !supervisor_bool($survey['active'] ?? false);
            callcenter_execute(
                'UPDATE callcenter_surveys SET active=:active,updated_at=now() WHERE dept_id=:dept AND survey_id=:survey',
                ['active' => $active, 'dept' => $deptId, 'survey' => $surveyId]
            );
            $flash['message'] = 'Anket ' . ($active ? 'aktif' : 'pasif') . ' duruma alındı.';
            $auditAction = 'Anket durumu değiştirildi';
            $auditDescription .= '; anket_id=' . $surveyId . '; aktif=' . ($active ? 'evet' : 'hayır');
        } elseif ($action === 'survey_delete') {
            $surveyId = max(0, (int) ($_POST['survey_id'] ?? 0));
            $survey = survey_scoped_row('callcenter_surveys', 'survey_id', $surveyId, $deptId);
            $evaluation = callcenter_rows(
                'SELECT evaluation_id FROM callcenter_survey_evaluations '
                . 'WHERE dept_id=:dept AND survey_id=:survey LIMIT 1',
                ['dept' => $deptId, 'survey' => $surveyId]
            );
            if ($evaluation) {
                throw new InvalidArgumentException(
                    'Bu ankete ait değerlendirmeler bulunduğu için anket kalıcı olarak silinemez. Bunun yerine pasif duruma alın.'
                );
            }
            callcenter_execute(
                'DELETE FROM callcenter_surveys WHERE dept_id=:dept AND survey_id=:survey',
                ['dept' => $deptId, 'survey' => $surveyId]
            );
            $flash['message'] = 'Anket ve bağlı tanımları silindi.';
            $auditEvent = 'delete';
            $auditAction = 'Anket silindi';
            $auditDescription .= '; anket_id=' . $surveyId . '; ad=' . (string) $survey['name'];
        } elseif ($action === 'category_save') {
            $categoryId = max(0, (int) ($_POST['category_id'] ?? 0));
            $surveyId = max(0, (int) ($_POST['survey_id'] ?? 0));
            survey_scoped_row('callcenter_surveys', 'survey_id', $surveyId, $deptId);
            $name = survey_required_text($_POST['name'] ?? '', 'Kategori adı', 180);
            $maxScore = survey_positive_decimal((string) ($_POST['max_score'] ?? ''), 'Kategori azami puanı');
            $sortOrder = survey_positive_integer($_POST['sort_order'] ?? '', 'Kategori sırası');
            $active = isset($_POST['active']);
            if ($categoryId > 0) {
                $category = survey_scoped_row('callcenter_survey_categories', 'category_id', $categoryId, $deptId);
                if ((int) $category['survey_id'] !== $surveyId) {
                    throw new InvalidArgumentException('Kategori seçilen ankete ait değil.');
                }
                $activeQuestionTotal = (float) (callcenter_rows(
                    'SELECT COALESCE(SUM(max_score),0) AS total FROM callcenter_survey_questions '
                    . 'WHERE dept_id=:dept AND survey_id=:survey AND category_id=:category AND active',
                    ['dept' => $deptId, 'survey' => $surveyId, 'category' => $categoryId]
                )[0]['total'] ?? 0);
                if ((float) $maxScore + 0.00001 < $activeQuestionTotal) {
                    throw new InvalidArgumentException(
                        'Kategori azami puanı aktif soruların toplamından düşük olamaz. '
                        . 'Aktif soru toplamı: ' . number_format($activeQuestionTotal, 2, ',', '.')
                    );
                }
                callcenter_execute(
                    'UPDATE callcenter_survey_categories SET name=:name,max_score=:max,sort_order=:sort,'
                    . 'active=:active,updated_at=now() WHERE dept_id=:dept AND survey_id=:survey AND category_id=:category',
                    ['name' => $name, 'max' => $maxScore, 'sort' => $sortOrder, 'active' => $active, 'dept' => $deptId, 'survey' => $surveyId, 'category' => $categoryId]
                );
                $flash['message'] = 'Kategori güncellendi.';
                $auditAction = 'Anket kategorisi güncellendi';
                $auditDescription .= '; kategori_id=' . $categoryId;
            } else {
                callcenter_execute(
                    'INSERT INTO callcenter_survey_categories '
                    . '(dept_id,survey_id,name,max_score,sort_order,active) '
                    . 'VALUES (:dept,:survey,:name,:max,:sort,:active)',
                    ['dept' => $deptId, 'survey' => $surveyId, 'name' => $name, 'max' => $maxScore, 'sort' => $sortOrder, 'active' => $active]
                );
                $flash['message'] = 'Kategori eklendi.';
                $auditEvent = 'create';
                $auditAction = 'Anket kategorisi eklendi';
                $auditDescription .= '; anket_id=' . $surveyId . '; ad=' . $name;
            }
        } elseif ($action === 'category_delete') {
            $categoryId = max(0, (int) ($_POST['category_id'] ?? 0));
            $category = survey_scoped_row('callcenter_survey_categories', 'category_id', $categoryId, $deptId);
            $answer = callcenter_rows(
                'SELECT a.answer_id FROM callcenter_survey_answers a '
                . 'JOIN callcenter_survey_questions q ON q.dept_id=a.dept_id '
                . 'AND q.survey_id=a.survey_id AND q.question_id=a.question_id '
                . 'WHERE a.dept_id=:dept AND q.category_id=:category LIMIT 1',
                ['dept' => $deptId, 'category' => $categoryId]
            );
            if ($answer) {
                throw new InvalidArgumentException(
                    'Bu kategoride cevaplanmış sorular bulunduğu için kategori silinemez.'
                );
            }
            callcenter_execute(
                'DELETE FROM callcenter_survey_categories '
                . 'WHERE dept_id=:dept AND survey_id=:survey AND category_id=:category',
                ['dept' => $deptId, 'survey' => (int) $category['survey_id'], 'category' => $categoryId]
            );
            $flash['message'] = 'Kategori ve bağlı soruları silindi.';
            $auditEvent = 'delete';
            $auditAction = 'Anket kategorisi silindi';
            $auditDescription .= '; kategori_id=' . $categoryId . '; ad=' . (string) $category['name'];
        } elseif ($action === 'question_save') {
            $questionId = max(0, (int) ($_POST['question_id'] ?? 0));
            $surveyId = max(0, (int) ($_POST['survey_id'] ?? 0));
            $categoryId = max(0, (int) ($_POST['category_id'] ?? 0));
            survey_scoped_row('callcenter_surveys', 'survey_id', $surveyId, $deptId);
            $category = survey_scoped_row('callcenter_survey_categories', 'category_id', $categoryId, $deptId);
            if ((int) $category['survey_id'] !== $surveyId) {
                throw new InvalidArgumentException('Kategori seçilen ankete ait değil.');
            }
            $questionText = survey_required_text($_POST['question_text'] ?? '', 'Soru');
            $questionType = (string) ($_POST['question_type'] ?? '');
            if (!in_array($questionType, ['score', 'yes_no'], true)) {
                throw new InvalidArgumentException('Geçerli bir soru tipi seçin.');
            }
            $maxScore = survey_positive_decimal((string) ($_POST['max_score'] ?? ''), 'Soru azami puanı');
            $sortOrder = survey_positive_integer($_POST['sort_order'] ?? '', 'Soru sırası');
            $active = isset($_POST['active']);
            $otherQuestionTotal = (float) (callcenter_rows(
                'SELECT COALESCE(SUM(max_score),0) AS total FROM callcenter_survey_questions '
                . 'WHERE dept_id=:dept AND survey_id=:survey AND category_id=:category '
                . 'AND active AND question_id<>:question',
                [
                    'dept' => $deptId,
                    'survey' => $surveyId,
                    'category' => $categoryId,
                    'question' => $questionId,
                ]
            )[0]['total'] ?? 0);
            $categoryMaxScore = (float) $category['max_score'];
            $remainingScore = max(0.0, $categoryMaxScore - $otherQuestionTotal);
            if ($active && $otherQuestionTotal + (float) $maxScore > $categoryMaxScore + 0.00001) {
                throw new InvalidArgumentException(
                    'Bu kategori için puan sınırı aşılamaz. Kategori azami puanı: '
                    . number_format($categoryMaxScore, 2, ',', '.')
                    . ', diğer aktif sorular: ' . number_format($otherQuestionTotal, 2, ',', '.')
                    . ', bu soru için girebileceğiniz en yüksek puan: '
                    . number_format($remainingScore, 2, ',', '.')
                );
            }
            if ($questionId > 0) {
                $question = survey_scoped_row('callcenter_survey_questions', 'question_id', $questionId, $deptId);
                if ((int) $question['survey_id'] !== $surveyId) {
                    throw new InvalidArgumentException('Soru seçilen ankete ait değil.');
                }
                callcenter_execute(
                    'UPDATE callcenter_survey_questions SET category_id=:category,question_text=:text,'
                    . 'question_type=:type,max_score=:max,sort_order=:sort,active=:active,updated_at=now() '
                    . 'WHERE dept_id=:dept AND survey_id=:survey AND question_id=:question',
                    ['category' => $categoryId, 'text' => $questionText, 'type' => $questionType, 'max' => $maxScore, 'sort' => $sortOrder, 'active' => $active, 'dept' => $deptId, 'survey' => $surveyId, 'question' => $questionId]
                );
                $flash['message'] = 'Soru güncellendi.';
                $auditAction = 'Anket sorusu güncellendi';
                $auditDescription .= '; soru_id=' . $questionId;
            } else {
                callcenter_execute(
                    'INSERT INTO callcenter_survey_questions '
                    . '(dept_id,survey_id,category_id,question_text,question_type,max_score,sort_order,active) '
                    . 'VALUES (:dept,:survey,:category,:text,:type,:max,:sort,:active)',
                    ['dept' => $deptId, 'survey' => $surveyId, 'category' => $categoryId, 'text' => $questionText, 'type' => $questionType, 'max' => $maxScore, 'sort' => $sortOrder, 'active' => $active]
                );
                $flash['message'] = 'Soru eklendi.';
                $auditEvent = 'create';
                $auditAction = 'Anket sorusu eklendi';
                $auditDescription .= '; anket_id=' . $surveyId;
            }
        } elseif ($action === 'question_delete') {
            $questionId = max(0, (int) ($_POST['question_id'] ?? 0));
            $question = survey_scoped_row('callcenter_survey_questions', 'question_id', $questionId, $deptId);
            $answer = callcenter_rows(
                'SELECT answer_id FROM callcenter_survey_answers '
                . 'WHERE dept_id=:dept AND survey_id=:survey AND question_id=:question LIMIT 1',
                ['dept' => $deptId, 'survey' => (int) $question['survey_id'], 'question' => $questionId]
            );
            if ($answer) {
                throw new InvalidArgumentException('Bu soru değerlendirmelerde cevaplandığı için silinemez.');
            }
            callcenter_execute(
                'DELETE FROM callcenter_survey_questions '
                . 'WHERE dept_id=:dept AND survey_id=:survey AND question_id=:question',
                ['dept' => $deptId, 'survey' => (int) $question['survey_id'], 'question' => $questionId]
            );
            $flash['message'] = 'Soru silindi.';
            $auditEvent = 'delete';
            $auditAction = 'Anket sorusu silindi';
            $auditDescription .= '; soru_id=' . $questionId;
        } else {
            throw new InvalidArgumentException('Geçersiz işlem.');
        }

        callcenter_audit_write(
            $auditIdentity,
            $auditEvent,
            'anket_yonetim',
            $auditAction,
            $auditDescription,
            'system'
        );
    } catch (Throwable $exception) {
        $flash['error'] = survey_database_message($exception);
        callcenter_audit_write(
            $auditIdentity,
            'access_denied',
            'anket_yonetim',
            'Anket yönetimi işlemi reddedildi',
            $flash['error'],
            'system'
        );
    }
    $_SESSION[SURVEY_FLASH_KEY] = $flash;
    survey_redirect($returnFilters);
}

$flash = (array) ($_SESSION[SURVEY_FLASH_KEY] ?? []);
unset($_SESSION[SURVEY_FLASH_KEY]);
$activeFilters = survey_filter_state($_GET);

$surveys = callcenter_rows(
    'SELECT s.survey_id,s.name,s.description,s.active,s.created_at,s.updated_at,'
    . 'COALESCE(c.category_count,0) AS category_count,COALESCE(c.category_total,0) AS category_total,'
    . 'COALESCE(q.question_count,0) AS question_count,COALESCE(q.question_total,0) AS question_total,'
    . 'COALESCE(e.evaluation_count,0) AS evaluation_count '
    . 'FROM callcenter_surveys s '
    . 'LEFT JOIN (SELECT dept_id,survey_id,COUNT(*) AS category_count,SUM(max_score) AS category_total '
    . 'FROM callcenter_survey_categories WHERE dept_id=:dept GROUP BY dept_id,survey_id) c '
    . 'ON c.dept_id=s.dept_id AND c.survey_id=s.survey_id '
    . 'LEFT JOIN (SELECT dept_id,survey_id,COUNT(*) AS question_count,'
    . 'SUM(max_score) FILTER (WHERE active) AS question_total '
    . 'FROM callcenter_survey_questions WHERE dept_id=:dept GROUP BY dept_id,survey_id) q '
    . 'ON q.dept_id=s.dept_id AND q.survey_id=s.survey_id '
    . 'LEFT JOIN (SELECT dept_id,survey_id,COUNT(*) AS evaluation_count '
    . 'FROM callcenter_survey_evaluations WHERE dept_id=:dept GROUP BY dept_id,survey_id) e '
    . 'ON e.dept_id=s.dept_id AND e.survey_id=s.survey_id '
    . 'WHERE s.dept_id=:dept ORDER BY s.active DESC,s.name,s.survey_id',
    ['dept' => $deptId]
);
$categories = callcenter_rows(
    'SELECT c.category_id,c.survey_id,c.name,c.max_score,c.sort_order,c.active,'
    . 'COUNT(q.question_id) AS question_count,'
    . 'COALESCE(SUM(q.max_score) FILTER (WHERE q.active),0) AS question_total '
    . 'FROM callcenter_survey_categories c '
    . 'LEFT JOIN callcenter_survey_questions q ON q.dept_id=c.dept_id '
    . 'AND q.survey_id=c.survey_id AND q.category_id=c.category_id '
    . 'WHERE c.dept_id=:dept GROUP BY c.category_id,c.survey_id,c.name,c.max_score,c.sort_order,c.active '
    . 'ORDER BY c.survey_id,c.sort_order,c.category_id',
    ['dept' => $deptId]
);
$questions = callcenter_rows(
    'SELECT q.question_id,q.survey_id,q.category_id,q.question_text,q.question_type,'
    . 'q.max_score,q.sort_order,q.active '
    . 'FROM callcenter_survey_questions q WHERE q.dept_id=:dept '
    . 'ORDER BY q.survey_id,q.category_id,q.sort_order,q.question_id',
    ['dept' => $deptId]
);

$categoriesBySurvey = [];
foreach ($categories as $category) {
    $categoriesBySurvey[(int) $category['survey_id']][] = $category;
}
$questionsByCategory = [];
foreach ($questions as $question) {
    $questionsByCategory[(int) $question['category_id']][] = $question;
}
$categoryPalette = [
    ['#6f8fff', '111,143,255'],
    ['#35b889', '53,184,137'],
    ['#b879f2', '184,121,242'],
    ['#e5a33f', '229,163,63'],
    ['#e8687d', '232,104,125'],
    ['#39abc0', '57,171,192'],
    ['#a5b847', '165,184,71'],
    ['#e17ab6', '225,122,182'],
];

supervisor_page_start('Anket Yönetimi', '../../dashboard.php?tab=callcenter&subtab=sistem');
if (!empty($flash['message'])) {
    supervisor_notice((string) $flash['message']);
}
if (!empty($flash['error'])) {
    supervisor_notice((string) $flash['error'], true);
}
?>
<section class="agent-guide">
    <div>
        <span class="eyebrow">POSTGRESQL ANKET TANIMLARI</span>
        <h2>Anket, kategori ve soru yönetimi</h2>
        <p>Her anketin kategori azami puanları toplamı 100 olmalıdır. Kategori soru puanlarının da kategori azami puanıyla eşleşmesi önerilir.</p>
    </div>
    <div class="agent-guide-stats">
        <span><strong><?= count($surveys) ?></strong> Anket</span>
        <span><strong><?= count($categories) ?></strong> Kategori</span>
        <span><strong><?= count($questions) ?></strong> Soru</span>
    </div>
</section>

<section class="card">
    <div class="card-header">Yeni Anket</div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="survey_save">
            <label>Anket adı
                <input name="name" maxlength="180" placeholder="Örn. Çağrı Kalite Değerlendirmesi" required>
            </label>
            <label>Açıklama
                <input name="description" placeholder="İsteğe bağlı kısa açıklama">
            </label>
            <button class="btn btn-primary" type="submit">Anket Oluştur</button>
        </form>
    </div>
</section>

<section class="card operational-no-print">
    <div class="card-header">Soru Filtreleri</div>
    <div class="card-body">
        <div class="survey-question-filters" id="survey-question-filters">
            <label>Anket
                <select id="survey-filter-survey">
                    <option value="">Tüm anketler</option>
                    <?php foreach ($surveys as $filterSurvey): ?>
                        <option value="<?= (int) $filterSurvey['survey_id'] ?>"<?= $activeFilters['filter_survey'] === (string) $filterSurvey['survey_id'] ? ' selected' : '' ?>><?= e((string) $filterSurvey['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kategori
                <select id="survey-filter-category">
                    <option value="">Tüm kategoriler</option>
                    <?php foreach ($categories as $filterCategory): ?>
                        <option value="<?= (int) $filterCategory['category_id'] ?>"
                            data-survey="<?= (int) $filterCategory['survey_id'] ?>"<?= $activeFilters['filter_category'] === (string) $filterCategory['category_id'] ? ' selected' : '' ?>><?= e((string) $filterCategory['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Soru tipi
                <select id="survey-filter-type">
                    <option value="">Tümü</option>
                    <option value="score"<?= $activeFilters['filter_type'] === 'score' ? ' selected' : '' ?>>Puan</option>
                    <option value="yes_no"<?= $activeFilters['filter_type'] === 'yes_no' ? ' selected' : '' ?>>Evet / Hayır</option>
                </select>
            </label>
            <label>Durum
                <select id="survey-filter-active">
                    <option value="">Tümü</option>
                    <option value="1"<?= $activeFilters['filter_active'] === '1' ? ' selected' : '' ?>>Aktif</option>
                    <option value="0"<?= $activeFilters['filter_active'] === '0' ? ' selected' : '' ?>>Pasif</option>
                </select>
            </label>
            <label class="survey-question-search">Soru içinde ara
                <input id="survey-filter-search" type="search" value="<?= e($activeFilters['filter_search']) ?>" placeholder="Soru metni...">
            </label>
            <button class="btn btn-outline" id="survey-filter-reset" type="button">Filtreleri Temizle</button>
        </div>
        <p class="survey-filter-result"><strong id="survey-visible-count"><?= count($questions) ?></strong> / <?= count($questions) ?> soru gösteriliyor</p>
    </div>
</section>

<?php if (!$surveys): ?>
    <div class="empty">Henüz anket tanımlanmamış.</div>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>
    <?php
    $surveyId = (int) $survey['survey_id'];
    $surveyCategories = $categoriesBySurvey[$surveyId] ?? [];
    $categoryTotal = (float) $survey['category_total'];
    $questionTotal = (float) $survey['question_total'];
    $isActive = supervisor_bool($survey['active']);
    ?>
    <section class="card survey-definition-card" data-survey-card="<?= $surveyId ?>">
        <div class="card-header">
            <?= e((string) $survey['name']) ?>
            <span class="badge <?= $isActive ? 'badge-success' : 'badge-danger' ?>"><?= $isActive ? 'Aktif' : 'Pasif' ?></span>
            <span class="badge <?= abs($categoryTotal - 100.0) < 0.001 ? 'badge-success' : 'badge-warning' ?>">
                Kategori toplamı: <?= e(number_format($categoryTotal, 2, ',', '.')) ?>
            </span>
            <span class="badge">Soru toplamı: <?= e(number_format($questionTotal, 2, ',', '.')) ?></span>
        </div>
        <div class="card-body">
            <?php if (abs($categoryTotal - 100.0) >= 0.001): ?>
                <div class="alert">Uyarı: Kategori azami puanları toplamı 100 değil.</div>
            <?php endif; ?>

            <details>
                <summary>Anket bilgilerini düzenle</summary>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="survey_save">
                    <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                    <label>Anket adı
                        <input name="name" maxlength="180" value="<?= e((string) $survey['name']) ?>" required>
                    </label>
                    <label>Açıklama
                        <input name="description" value="<?= e((string) ($survey['description'] ?? '')) ?>">
                    </label>
                    <button class="btn btn-primary" type="submit">Anketi Kaydet</button>
                </form>
            </details>

            <div class="operational-report-actions">
                <span><?= (int) $survey['category_count'] ?> kategori · <?= (int) $survey['question_count'] ?> soru · <?= (int) $survey['evaluation_count'] ?> değerlendirme</span>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="survey_toggle">
                    <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                    <button class="btn btn-outline" type="submit"><?= $isActive ? 'Pasife Al' : 'Aktifleştir' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Anket ve bağlı tanımlar kalıcı olarak silinsin mi?')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="survey_delete">
                    <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                    <button class="btn btn-danger" type="submit">Anketi Sil</button>
                </form>
            </div>

            <section class="card">
                <div class="card-header">Yeni Kategori</div>
                <div class="card-body">
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="category_save">
                        <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                        <label>Kategori adı
                            <input name="name" maxlength="180" required>
                        </label>
                        <label>Azami puan
                            <input type="number" name="max_score" min="0.01" step="0.01" required>
                        </label>
                        <label>Sıra
                            <input type="number" name="sort_order" min="1" step="1" value="<?= count($surveyCategories) + 1 ?>" required>
                        </label>
                        <label class="choice-row">
                            <input type="checkbox" name="active" checked>
                            <span><strong>Aktif</strong></span>
                        </label>
                        <button class="btn btn-primary" type="submit">Kategori Ekle</button>
                    </form>
                </div>
            </section>

            <?php foreach ($surveyCategories as $categoryIndex => $category): ?>
                <?php
                $categoryId = (int) $category['category_id'];
                $categoryQuestions = $questionsByCategory[$categoryId] ?? [];
                $categoryMax = (float) $category['max_score'];
                $categoryQuestionTotal = (float) $category['question_total'];
                $categoryRemainingScore = max(0.0, $categoryMax - $categoryQuestionTotal);
                [$categoryAccent, $categoryAccentRgb] = $categoryPalette[$categoryIndex % count($categoryPalette)];
                ?>
                <div class="table-container survey-category-block" data-category-block="<?= $categoryId ?>"
                    style="--category-accent:<?= e($categoryAccent) ?>;--category-accent-rgb:<?= e($categoryAccentRgb) ?>">
                    <div class="table-header">
                        <?= (int) $category['sort_order'] ?>. <?= e((string) $category['name']) ?>
                        <span class="badge <?= supervisor_bool($category['active']) ? 'badge-success' : 'badge-danger' ?>">
                            <?= supervisor_bool($category['active']) ? 'Aktif' : 'Pasif' ?>
                        </span>
                        <span class="badge">Kategori: <?= e(number_format($categoryMax, 2, ',', '.')) ?></span>
                        <span class="badge <?= abs($categoryMax - $categoryQuestionTotal) < 0.001 ? 'badge-success' : 'badge-warning' ?>">
                            Sorular: <?= e(number_format($categoryQuestionTotal, 2, ',', '.')) ?>
                        </span>
                    </div>
                    <?php if (abs($categoryMax - $categoryQuestionTotal) >= 0.001): ?>
                        <div class="alert">Uyarı: Soru puanları toplamı kategori azami puanıyla eşleşmiyor.</div>
                    <?php endif; ?>
                    <table class="survey-question-table">
                        <thead>
                        <tr>
                            <th>Sıra</th>
                            <th>Kategori</th>
                            <th>Soru</th>
                            <th>Tip</th>
                            <th>Azami Puan</th>
                            <th>Aktif</th>
                            <th>İşlem</th>
                        </tr>
                        </thead>
                        <tbody class="survey-question-body">
                        <?php foreach ($categoryQuestions as $question): ?>
                            <?php
                            $questionId = (int) $question['question_id'];
                            $questionFormId = 'question-edit-' . $questionId;
                            $questionActive = supervisor_bool($question['active']);
                            $questionMaxScore = (float) $question['max_score'];
                            $otherActiveScore = max(
                                0.0,
                                $categoryQuestionTotal - ($questionActive ? $questionMaxScore : 0.0)
                            );
                            $questionAllowedMax = max(0.0, $categoryMax - $otherActiveScore);
                            ?>
                            <tr class="survey-question-row"
                                data-survey="<?= $surveyId ?>"
                                data-category="<?= $categoryId ?>"
                                data-type="<?= e((string) $question['question_type']) ?>"
                                data-active="<?= $questionActive ? '1' : '0' ?>"
                                data-search="<?= e(mb_strtolower((string) $question['question_text'], 'UTF-8')) ?>">
                                <td class="survey-question-order">
                                    <input form="<?= e($questionFormId) ?>" type="number" name="sort_order" min="1" step="1" value="<?= (int) $question['sort_order'] ?>" aria-label="Sıra" required>
                                </td>
                                <td class="survey-question-category">
                                    <select form="<?= e($questionFormId) ?>" name="category_id" aria-label="Kategori" required>
                                        <?php foreach ($surveyCategories as $targetCategory): ?>
                                            <option value="<?= (int) $targetCategory['category_id'] ?>"<?= (int) $targetCategory['category_id'] === $categoryId ? ' selected' : '' ?>>
                                                <?= e((string) $targetCategory['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="survey-question-text">
                                    <textarea form="<?= e($questionFormId) ?>" name="question_text" rows="2" aria-label="Soru metni" required><?= e((string) $question['question_text']) ?></textarea>
                                </td>
                                <td class="survey-question-type">
                                    <select form="<?= e($questionFormId) ?>" name="question_type" aria-label="Soru tipi">
                                        <option value="score"<?= $question['question_type'] === 'score' ? ' selected' : '' ?>>Puan</option>
                                        <option value="yes_no"<?= $question['question_type'] === 'yes_no' ? ' selected' : '' ?>>Evet / Hayır</option>
                                    </select>
                                </td>
                                <td class="survey-question-score">
                                    <input form="<?= e($questionFormId) ?>" type="number" name="max_score" min="0.01" step="0.01"
                                        value="<?= e((string) $question['max_score']) ?>"
                                        data-allowed-max="<?= e(number_format($questionAllowedMax, 2, '.', '')) ?>"
                                        title="En fazla <?= e(number_format($questionAllowedMax, 2, ',', '.')) ?>"
                                        aria-label="Azami puan" required>
                                </td>
                                <td class="survey-question-active">
                                    <label title="<?= $questionActive ? 'Aktif' : 'Pasif' ?>">
                                        <input form="<?= e($questionFormId) ?>" type="checkbox" name="active"<?= $questionActive ? ' checked' : '' ?>>
                                        <span><?= $questionActive ? 'Aktif' : 'Pasif' ?></span>
                                    </label>
                                </td>
                                <td class="survey-question-actions">
                                    <form id="<?= e($questionFormId) ?>" method="post">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="question_save">
                                        <input type="hidden" name="question_id" value="<?= $questionId ?>">
                                        <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                                        <button class="btn btn-primary" type="submit">Kaydet</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Soru kalıcı olarak silinsin mi?')">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="question_delete">
                                        <input type="hidden" name="question_id" value="<?= $questionId ?>">
                                        <button class="btn btn-danger" type="submit">Sil</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$categoryQuestions): ?>
                            <tr class="survey-no-question-row"><td colspan="7" class="empty">Bu kategoride soru yok.</td></tr>
                        <?php endif; ?>
                        <tr class="survey-filter-empty" hidden><td colspan="7" class="empty">Filtreye uygun soru bulunamadı.</td></tr>
                        </tbody>
                    </table>

                    <div class="card-body">
                        <details>
                            <summary>Kategoriyi düzenle / sil</summary>
                            <form method="post" class="form-grid">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="category_save">
                                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                                <label>Kategori adı
                                    <input name="name" maxlength="180" value="<?= e((string) $category['name']) ?>" required>
                                </label>
                                <label>Azami puan
                                    <input type="number" name="max_score" min="0.01" step="0.01" value="<?= e((string) $category['max_score']) ?>" required>
                                </label>
                                <label>Sıra
                                    <input type="number" name="sort_order" min="1" step="1" value="<?= (int) $category['sort_order'] ?>" required>
                                </label>
                                <label class="choice-row">
                                    <input type="checkbox" name="active"<?= supervisor_bool($category['active']) ? ' checked' : '' ?>>
                                    <span><strong>Aktif</strong></span>
                                </label>
                                <button class="btn btn-primary" type="submit">Kategoriyi Kaydet</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Kategori ve bağlı sorular kalıcı olarak silinsin mi?')">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="category_delete">
                                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                                <button class="btn btn-danger" type="submit">Kategoriyi Sil</button>
                            </form>
                        </details>

                        <details class="survey-question-add">
                            <summary>Bu kategoriye soru ekle · Kalan puan: <?= e(number_format($categoryRemainingScore, 2, ',', '.')) ?></summary>
                            <?php if ($categoryRemainingScore <= 0.00001): ?>
                                <div class="alert alert-danger">Kategori puan limiti dolu veya aşılmış. Yeni soru eklemek için mevcut aktif soruların puanını düşürün, pasife alın ya da silin.</div>
                            <?php else: ?>
                            <form method="post" class="survey-question-add-form">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="question_save">
                                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                                <label class="survey-question-add-text">Soru
                                    <textarea name="question_text" rows="3" placeholder="Soru metnini yazın..." required></textarea>
                                </label>
                                <div class="survey-question-add-meta">
                                    <label>Tip
                                        <select name="question_type">
                                            <option value="score">Puan</option>
                                            <option value="yes_no">Evet / Hayır</option>
                                        </select>
                                    </label>
                                    <label>Azami puan
                                        <input type="number" name="max_score" min="0.01" max="<?= e(number_format($categoryRemainingScore, 2, '.', '')) ?>"
                                            data-allowed-max="<?= e(number_format($categoryRemainingScore, 2, '.', '')) ?>"
                                            step="0.01" value="<?= e(number_format($categoryRemainingScore, 2, '.', '')) ?>" required>
                                        <small>En fazla <?= e(number_format($categoryRemainingScore, 2, ',', '.')) ?></small>
                                    </label>
                                    <label>Sıra
                                        <input type="number" name="sort_order" min="1" step="1" value="<?= count($categoryQuestions) + 1 ?>" required>
                                    </label>
                                    <label class="choice-row">
                                        <input type="checkbox" name="active" checked>
                                        <span><strong>Aktif</strong></span>
                                    </label>
                                    <button class="btn btn-primary" type="submit">Soru Ekle</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </details>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
<script>
(()=>{
    const rows=[...document.querySelectorAll('.survey-question-row')];
    const survey=document.getElementById('survey-filter-survey');
    const category=document.getElementById('survey-filter-category');
    const type=document.getElementById('survey-filter-type');
    const active=document.getElementById('survey-filter-active');
    const search=document.getElementById('survey-filter-search');
    const visibleCount=document.getElementById('survey-visible-count');
    const normalize=value=>String(value??'').toLocaleLowerCase('tr-TR').trim();
    const scrollStorageKey='astera.survey.management.scroll';
    const offerScoreCap=(input,enabled=true)=>{
        const allowed=Number(input?.dataset.allowedMax||0);
        const value=Number(input?.value||0);
        if(!input||!enabled||value<=allowed+0.00001){input?.setCustomValidity('');return true;}
        const formatted=allowed.toLocaleString('tr-TR',{maximumFractionDigits:2});
        const message=allowed>0
            ?`Bu puanı giremezsiniz. En fazla ${formatted} puan girebilirsiniz. ${formatted} puan olarak düzeltelim mi?`
            :'Kategori puan limiti dolu. Bu soruyu pasife alın veya mevcut soru puanlarını düşürün.';
        if(allowed>0&&window.confirm(message)){
            input.value=allowed.toFixed(2);
            input.setCustomValidity('');
            return true;
        }
        input.setCustomValidity(`En fazla ${formatted} puan girebilirsiniz.`);
        input.reportValidity();
        input.focus();
        input.select();
        return false;
    };
    const filterControls={
        filter_survey:survey,
        filter_category:category,
        filter_type:type,
        filter_active:active,
        filter_search:search
    };
    const syncUrl=()=>{
        const params=new URLSearchParams(window.location.search);
        Object.entries(filterControls).forEach(([name,control])=>{
            const value=control?.value?.trim()??'';
            value?params.set(name,value):params.delete(name);
        });
        const query=params.toString();
        window.history.replaceState(null,'',window.location.pathname+(query?'?'+query:''));
    };
    const syncCategoryOptions=resetInvalid=>{
        const selectedSurvey=survey?.value??'';
        [...(category?.options??[])].forEach(option=>{
            if(!option.value)return;
            option.hidden=!!selectedSurvey&&option.dataset.survey!==selectedSurvey;
        });
        const selectedOption=category?.selectedOptions?.[0];
        if(resetInvalid&&selectedOption?.value&&selectedSurvey&&selectedOption.dataset.survey!==selectedSurvey){
            category.value='';
        }
    };
    const apply=()=>{
        const query=normalize(search?.value);
        let count=0;
        rows.forEach(row=>{
            const visible=(!survey?.value||row.dataset.survey===survey.value)
                &&(!category?.value||row.dataset.category===category.value)
                &&(!type?.value||row.dataset.type===type.value)
                &&(!active?.value||row.dataset.active===active.value)
                &&(!query||normalize(row.dataset.search).includes(query));
            row.hidden=!visible;
            if(visible)count++;
        });
        document.querySelectorAll('.survey-category-block').forEach(block=>{
            const categoryMatches=!category?.value||block.dataset.categoryBlock===category.value;
            const surveyCard=block.closest('[data-survey-card]');
            const surveyMatches=!survey?.value||surveyCard?.dataset.surveyCard===survey.value;
            block.hidden=!(categoryMatches&&surveyMatches);
            const body=block.querySelector('.survey-question-body');
            const bodyRows=[...(body?.querySelectorAll('.survey-question-row')||[])];
            const empty=body?.querySelector('.survey-filter-empty');
            if(empty)empty.hidden=bodyRows.length===0||bodyRows.some(row=>!row.hidden);
        });
        document.querySelectorAll('[data-survey-card]').forEach(card=>{
            const surveyMatches=!survey?.value||card.dataset.surveyCard===survey.value;
            const categoryMatches=!category?.value||!!card.querySelector(`[data-category-block="${category.value}"]`);
            const questionFilterActive=!!(type?.value||active?.value||query);
            const hasVisibleQuestion=!![...card.querySelectorAll('.survey-question-row')].find(row=>!row.hidden);
            card.hidden=!(surveyMatches&&categoryMatches)||(questionFilterActive&&!hasVisibleQuestion);
        });
        if(visibleCount)visibleCount.textContent=String(count);
        syncUrl();
    };
    survey?.addEventListener('change',()=>{syncCategoryOptions(true);apply();});
    category?.addEventListener('change',()=>{
        const ownerSurvey=category.selectedOptions?.[0]?.dataset.survey??'';
        if(ownerSurvey&&survey)survey.value=ownerSurvey;
        syncCategoryOptions(false);
        apply();
    });
    [type,active].forEach(control=>control?.addEventListener('change',apply));
    search?.addEventListener('input',apply);
    document.getElementById('survey-filter-reset')?.addEventListener('click',()=>{
        [survey,category,type,active,search].forEach(control=>{if(control)control.value='';});
        syncCategoryOptions(false);
        apply();
    });
    document.addEventListener('submit',event=>{
        const form=event.target;
        if(!(form instanceof HTMLFormElement)||form.method.toLowerCase()!=='post')return;
        const categoryBlock=form.closest('.survey-category-block');
        try{
            sessionStorage.setItem(scrollStorageKey,JSON.stringify({
                category:categoryBlock?.dataset.categoryBlock??'',
                top:categoryBlock?.getBoundingClientRect().top??null,
                y:window.scrollY,
                savedAt:Date.now()
            }));
        }catch(error){}
        Object.entries(filterControls).forEach(([name,control])=>{
            let input=form.querySelector(`input[type="hidden"][name="${name}"]`);
            if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.appendChild(input);}
            input.value=control?.value??'';
        });
    });
    rows.forEach(row=>{
        const scoreInput=row.querySelector('input[name="max_score"]');
        const activeInput=row.querySelector('input[name="active"]');
        const validateScore=()=>{
            if(!scoreInput)return;
            const allowed=Number(scoreInput.dataset.allowedMax||0);
            const exceeds=!!activeInput?.checked&&Number(scoreInput.value||0)>allowed+0.00001;
            scoreInput.setCustomValidity(exceeds?`Bu soru için en fazla ${allowed.toLocaleString('tr-TR',{maximumFractionDigits:2})} puan girebilirsiniz.`:'');
        };
        scoreInput?.addEventListener('input',validateScore);
        scoreInput?.addEventListener('change',()=>{
            offerScoreCap(scoreInput,!!activeInput?.checked);
            validateScore();
        });
        activeInput?.addEventListener('change',validateScore);
        validateScore();
        row.querySelector('textarea[name="question_text"]')?.addEventListener('input',event=>{
            row.dataset.search=normalize(event.target.value);apply();
        });
        row.querySelector('select[name="question_type"]')?.addEventListener('change',event=>{
            row.dataset.type=event.target.value;apply();
        });
        row.querySelector('input[name="active"]')?.addEventListener('change',event=>{
            row.dataset.active=event.target.checked?'1':'0';
            const label=event.target.closest('label')?.querySelector('span');
            if(label)label.textContent=event.target.checked?'Aktif':'Pasif';
            apply();
        });
    });
    document.querySelectorAll('.survey-question-add input[name="max_score"][data-allowed-max]').forEach(input=>{
        input.addEventListener('change',()=>offerScoreCap(input,true));
    });
    const initialCategorySurvey=category?.selectedOptions?.[0]?.dataset.survey??'';
    if(initialCategorySurvey&&survey&&!survey.value)survey.value=initialCategorySurvey;
    syncCategoryOptions(true);
    apply();
    try{
        const saved=JSON.parse(sessionStorage.getItem(scrollStorageKey)||'null');
        sessionStorage.removeItem(scrollStorageKey);
        if(saved&&Date.now()-Number(saved.savedAt||0)<300000){
            requestAnimationFrame(()=>requestAnimationFrame(()=>{
                const block=saved.category?document.querySelector(`[data-category-block="${saved.category}"]`):null;
                if(block&&Number.isFinite(Number(saved.top))){
                    window.scrollBy(0,block.getBoundingClientRect().top-Number(saved.top));
                }else{
                    window.scrollTo(0,Number(saved.y)||0);
                }
            }));
        }
    }catch(error){}
})();
</script>
<?php supervisor_page_end(); ?>
