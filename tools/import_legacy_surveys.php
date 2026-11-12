<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnız komut satırından çalıştırılabilir.\n");
    exit(1);
}

$input = $argv[1] ?? '';
$deptId = trim((string) ($argv[2] ?? ''));
if ($input === '' || $deptId === '' || !is_readable($input)) {
    fwrite(STDERR, "Kullanım: php import_legacy_surveys.php export.json DEPT_ID\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

$payload = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
$pdo = callcenter_pdo();
if (!$pdo instanceof PDO) {
    throw new RuntimeException('PostgreSQL bağlantısı kurulamadı.');
}
if (!callcenter_rows('SELECT dept_id FROM astera_tenants WHERE dept_id=:dept', ['dept' => $deptId])) {
    throw new RuntimeException('Hedef firma bulunamadı: ' . $deptId);
}

$clean = static function (mixed $value): string {
    return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
};
$execute = static function (string $sql, array $params = []) use ($pdo): PDOStatement {
    [$sql, $params] = callcenter_prepared_sql($sql, $params);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement;
};
$returningId = static function (string $sql, array $params) use ($execute): int {
    return (int) $execute($sql, $params)->fetchColumn();
};

$surveys = (array) ($payload['surveys'] ?? []);
$categories = (array) ($payload['categories'] ?? []);
$questions = (array) ($payload['questions'] ?? []);
$masters = (array) ($payload['masters'] ?? []);
$answers = (array) ($payload['answers'] ?? []);
$sourceAgents = (array) ($payload['agents'] ?? []);
$calls = (array) ($payload['calls'] ?? []);

$answersByMaster = [];
$usedQuestionNames = [];
$questionTypeByName = [];
foreach ($answers as $answer) {
    $masterId = (int) ($answer['Anket_id'] ?? 0);
    $answersByMaster[$masterId][] = $answer;
    $name = $clean($answer['AnketAdi'] ?? '');
    if ($name !== '') {
        $usedQuestionNames[$name] = true;
        $questionTypeByName[$name] = (int) ($answer['Tip'] ?? 0);
    }
}

$questionsByCategory = [];
foreach ($questions as $question) {
    $questionsByCategory[(int) ($question['Kategori_id'] ?? 0)][] = $question;
}
$categoryMaxScores = [];
foreach ($categories as $category) {
    $categoryId = (int) ($category['id'] ?? 0);
    $derived = 0.0;
    foreach ($questionsByCategory[$categoryId] ?? [] as $question) {
        if (isset($usedQuestionNames[$clean($question['AnketAdi'] ?? '')])) {
            $derived += max(0.0, (float) ($question['MaxPuan'] ?? 0));
        }
    }
    $sourceMax = max(0.0, (float) ($category['MaxPuan'] ?? 0));
    $categoryMaxScores[$categoryId] = $sourceMax > 0 ? $sourceMax : ($derived > 0 ? $derived : 1.0);
}

$callById = [];
foreach ($calls as $call) {
    $callById[(int) ($call['id'] ?? 0)] = $call;
}
$sourceAgentIds = [];
foreach ($sourceAgents as $agent) {
    $sourceAgentIds[(string) ($agent['AgentID'] ?? '')] = true;
}

$counts = [
    'surveys' => 0,
    'categories' => 0,
    'questions' => 0,
    'agents' => 0,
    'evaluators' => 0,
    'evaluations' => 0,
    'answers' => 0,
];

$pdo->beginTransaction();
try {
    $surveyIdByLegacy = [];
    foreach ($surveys as $survey) {
        $legacyId = (int) ($survey['id'] ?? 0);
        $name = $clean($survey['AnketAdi'] ?? '');
        if ($legacyId < 1 || $name === '') {
            continue;
        }
        $surveyIdByLegacy[$legacyId] = $returningId(
            'INSERT INTO callcenter_surveys '
            . '(dept_id,name,description,active,created_at,updated_at,legacy_source,legacy_id) '
            . 'VALUES (:dept,:name,:description,:active,:created,:created,:source,:legacy) '
            . 'ON CONFLICT (dept_id,legacy_source,legacy_id) '
            . 'WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL '
            . 'DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description,'
            . 'active=EXCLUDED.active,updated_at=now() RETURNING survey_id',
            [
                'dept' => $deptId,
                'name' => mb_substr($name, 0, 180),
                'description' => ($description = $clean($survey['Aciklama'] ?? '')) !== '' ? $description : null,
                'active' => !empty($survey['Aktif']) ? 'true' : 'false',
                'created' => (string) ($survey['OlusturmaTarihi'] ?? date('Y-m-d H:i:s')),
                'source' => 'mysql.Anketler',
                'legacy' => $legacyId,
            ]
        );
        $counts['surveys']++;
    }

    $legacySurveyId = $returningId(
        'INSERT INTO callcenter_surveys '
        . '(dept_id,name,description,active,legacy_source,legacy_id) '
        . 'VALUES (:dept,:name,:description,TRUE,:source,:legacy) '
        . 'ON CONFLICT (dept_id,legacy_source,legacy_id) '
        . 'WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL '
        . 'DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description,active=TRUE,updated_at=now() '
        . 'RETURNING survey_id',
        [
            'dept' => $deptId,
            'name' => 'Eski Genel Kalite Değerlendirmesi',
            'description' => 'Eski sistemde bir ankete bağlanmadan kullanılan kategori, soru ve değerlendirmeler.',
            'source' => 'mysql.LegacySurvey',
            'legacy' => 1,
        ]
    );
    $counts['surveys']++;

    $categoryIdByLegacy = [];
    $categorySequence = 0;
    foreach ($categories as $category) {
        $categorySequence++;
        $legacyId = (int) ($category['id'] ?? 0);
        $sourceSurveyId = (int) ($category['anket_id'] ?? 0);
        $surveyId = $surveyIdByLegacy[$sourceSurveyId] ?? $legacySurveyId;
        $name = $clean($category['adi'] ?? '');
        if ($legacyId < 1 || $name === '') {
            continue;
        }
        $categoryIdByLegacy[$legacyId] = $returningId(
            'INSERT INTO callcenter_survey_categories '
            . '(dept_id,survey_id,name,max_score,sort_order,active,legacy_source,legacy_id) '
            . 'VALUES (:dept,:survey,:name,:max,:sort,TRUE,:source,:legacy) '
            . 'ON CONFLICT (dept_id,legacy_source,legacy_id) '
            . 'WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL '
            . 'DO UPDATE SET survey_id=EXCLUDED.survey_id,name=EXCLUDED.name,max_score=EXCLUDED.max_score,'
            . 'sort_order=EXCLUDED.sort_order,active=TRUE,updated_at=now() RETURNING category_id',
            [
                'dept' => $deptId,
                'survey' => $surveyId,
                'name' => mb_substr($name, 0, 180),
                'max' => $categoryMaxScores[$legacyId],
                'sort' => (int) ($category['Sira'] ?? 0) > 0
                    ? (int) $category['Sira']
                    : $categorySequence,
                'source' => 'mysql.anketkategori',
                'legacy' => $legacyId,
            ]
        );
        $counts['categories']++;
    }

    $questionIdByLegacy = [];
    $sortByCategory = [];
    foreach ($questions as $question) {
        $legacyId = (int) ($question['id'] ?? 0);
        $legacyCategoryId = (int) ($question['Kategori_id'] ?? 0);
        $categoryId = $categoryIdByLegacy[$legacyCategoryId] ?? 0;
        $name = $clean($question['AnketAdi'] ?? '');
        $maxScore = (float) ($question['MaxPuan'] ?? 0);
        if ($legacyId < 1 || $categoryId < 1 || $name === '' || $maxScore <= 0) {
            continue;
        }
        $sortByCategory[$legacyCategoryId] = ($sortByCategory[$legacyCategoryId] ?? 0) + 1;
        $type = ($questionTypeByName[$name] ?? (int) ($question['Tip'] ?? 0)) === 1 ? 'yes_no' : 'score';
        $questionIdByLegacy[$legacyId] = $returningId(
            'INSERT INTO callcenter_survey_questions '
            . '(dept_id,survey_id,category_id,question_text,question_type,max_score,sort_order,active,legacy_source,legacy_id) '
            . 'VALUES (:dept,:survey,:category,:text,:type,:max,:sort,:active,:source,:legacy) '
            . 'ON CONFLICT (dept_id,legacy_source,legacy_id) '
            . 'WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL '
            . 'DO UPDATE SET survey_id=EXCLUDED.survey_id,category_id=EXCLUDED.category_id,'
            . 'question_text=EXCLUDED.question_text,question_type=EXCLUDED.question_type,'
            . 'max_score=EXCLUDED.max_score,sort_order=EXCLUDED.sort_order,'
            . 'active=EXCLUDED.active,updated_at=now() RETURNING question_id',
            [
                'dept' => $deptId,
                'survey' => $legacySurveyId,
                'category' => $categoryId,
                'text' => $name,
                'type' => $type,
                'max' => $maxScore,
                'sort' => $sortByCategory[$legacyCategoryId],
                'active' => !isset($question['Aktif']) || !empty($question['Aktif']) ? 'true' : 'false',
                'source' => 'mysql.AnketTanim',
                'legacy' => $legacyId,
            ]
        );
        $counts['questions']++;
    }

    $rubricQuestions = array_values(array_filter(
        $questions,
        static fn(array $question): bool => isset($usedQuestionNames[$clean($question['AnketAdi'] ?? '')])
    ));
    usort($rubricQuestions, static function (array $left, array $right) use ($categories): int {
        static $orders;
        if ($orders === null) {
            $orders = [];
            foreach ($categories as $category) {
                $orders[(int) ($category['id'] ?? 0)] = max(1, (int) ($category['Sira'] ?? 0));
            }
        }
        $leftCategory = (int) ($left['Kategori_id'] ?? 0);
        $rightCategory = (int) ($right['Kategori_id'] ?? 0);
        return [$orders[$leftCategory] ?? 9999, (int) ($left['id'] ?? 0)]
            <=> [$orders[$rightCategory] ?? 9999, (int) ($right['id'] ?? 0)];
    });

    $legacyAgentIds = [];
    foreach ($masters as $master) {
        $extension = trim((string) ($master['AgentID'] ?? ''));
        if ($extension !== '') {
            $legacyAgentIds[$extension] = true;
        }
    }
    $agentIdByExtension = [];
    $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    foreach (array_keys($legacyAgentIds) as $extensionKey) {
        $extension = (string) $extensionKey;
        $existing = $execute(
            'SELECT agent_id FROM callcenter_agents WHERE dept_id=:dept AND extension=:extension LIMIT 1',
            ['dept' => $deptId, 'extension' => $extension]
        )->fetchColumn();
        if ($existing) {
            $agentIdByExtension[$extension] = (int) $existing;
            continue;
        }
        $username = 'legacy-survey-agent-' . preg_replace('/[^0-9A-Za-z_.-]/', '-', $extension);
        $agentIdByExtension[$extension] = $returningId(
            'INSERT INTO callcenter_agents '
            . '(dept_id,username,display_name,extension,password_hash,status) '
            . "VALUES (:dept,:username,:name,:extension,:hash,'inactive') "
            . 'ON CONFLICT (dept_id,username) DO UPDATE SET display_name=EXCLUDED.display_name,'
            . "status='inactive',updated_at=now() RETURNING agent_id",
            [
                'dept' => $deptId,
                'username' => $username,
                'name' => isset($sourceAgentIds[$extension]) ? 'Eski Agent ' . $extension : 'Arşiv Agent ' . $extension,
                'extension' => $extension,
                'hash' => $passwordHash,
            ]
        );
        $counts['agents']++;
    }

    $evaluatorIdByName = [];
    foreach ($masters as $master) {
        $name = $clean($master['Degerlendiren'] ?? '');
        if ($name === '' || isset($evaluatorIdByName[$name])) {
            continue;
        }
        $existing = false;
        if (str_contains(mb_strtolower($name, 'UTF-8'), 'admin')) {
            $existing = $execute(
                "SELECT supervisor_id FROM callcenter_supervisor_users "
                . "WHERE dept_id=:dept AND username='admin' LIMIT 1",
                ['dept' => $deptId]
            )->fetchColumn();
        }
        if (!$existing) {
            $existing = $execute(
                'SELECT supervisor_id FROM callcenter_supervisor_users '
                . 'WHERE dept_id=:dept AND lower(display_name)=lower(:name) LIMIT 1',
                ['dept' => $deptId, 'name' => $name]
            )->fetchColumn();
        }
        if ($existing) {
            $evaluatorIdByName[$name] = (int) $existing;
            continue;
        }
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $slug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii)), '-');
        $username = mb_substr('legacy-evaluator-' . ($slug !== '' ? $slug : 'unknown'), 0, 120);
        $evaluatorIdByName[$name] = $returningId(
            'INSERT INTO callcenter_supervisor_users '
            . '(dept_id,username,display_name,password_hash,role,active,can_choose_tenant) '
            . "VALUES (:dept,:username,:name,:hash,'viewer',FALSE,FALSE) "
            . 'ON CONFLICT (dept_id,username) DO UPDATE SET display_name=EXCLUDED.display_name,'
            . 'active=FALSE,updated_at=now() RETURNING supervisor_id',
            ['dept' => $deptId, 'username' => $username, 'name' => $name, 'hash' => $passwordHash]
        );
        $counts['evaluators']++;
    }

    foreach ($masters as $master) {
        $legacyId = (int) ($master['id'] ?? 0);
        $extension = trim((string) ($master['AgentID'] ?? ''));
        $evaluatorName = $clean($master['Degerlendiren'] ?? '');
        $masterAnswers = $answersByMaster[$legacyId] ?? [];
        if ($legacyId < 1 || !isset($agentIdByExtension[$extension], $evaluatorIdByName[$evaluatorName])) {
            throw new RuntimeException('Eski değerlendirme Agent/değerlendirici eşleştirmesi eksik: ' . $legacyId);
        }
        if (count($masterAnswers) !== count($rubricQuestions)) {
            throw new RuntimeException(
                "Eski değerlendirme {$legacyId} cevap sayısı (" . count($masterAnswers)
                . ') beklenen soru sayısıyla (' . count($rubricQuestions) . ') eşleşmiyor.'
            );
        }
        $callId = (int) ($master['cagriid'] ?? 0);
        $call = $callById[$callId] ?? [];
        $uniqueId = trim((string) ($call['uniqid'] ?? ''));
        $linkedId = $uniqueId !== '' ? $uniqueId : 'legacy-survey-call-' . ($callId > 0 ? $callId : $legacyId);
        $totalScore = array_sum(array_map(static fn(array $row): float => (float) ($row['Puan'] ?? 0), $masterAnswers));
        $maxScore = array_sum(array_map(static fn(array $row): float => (float) ($row['MaxPuan'] ?? 0), $masterAnswers));
        $noteParts = array_filter([
            $clean($master['Aciklama'] ?? ''),
            ($direction = $clean($master['AramaYonu'] ?? '')) !== '' ? 'Arama yönü: ' . $direction : '',
            'Eski değerlendirici: ' . ($evaluatorName !== '' ? $evaluatorName : 'Bilinmiyor'),
            'Eski değerlendirme ID: ' . $legacyId,
            $callId > 0 ? 'Eski çağrı ID: ' . $callId : '',
        ]);
        $evaluationId = $returningId(
            'INSERT INTO callcenter_survey_evaluations '
            . '(dept_id,survey_id,agent_id,evaluator_id,call_linkedid,call_uniqueid,evaluated_at,'
            . 'status,total_score,max_score,note,created_at,updated_at,legacy_source,legacy_id) '
            . "VALUES (:dept,:survey,:agent,:evaluator,:linked,:unique,:evaluated,'completed',"
            . ':total,:max,:note,:evaluated,:evaluated,:source,:legacy) '
            . 'ON CONFLICT (dept_id,legacy_source,legacy_id) '
            . 'WHERE legacy_source IS NOT NULL AND legacy_id IS NOT NULL '
            . 'DO UPDATE SET agent_id=EXCLUDED.agent_id,evaluator_id=EXCLUDED.evaluator_id,'
            . 'call_linkedid=EXCLUDED.call_linkedid,call_uniqueid=EXCLUDED.call_uniqueid,'
            . 'evaluated_at=EXCLUDED.evaluated_at,total_score=EXCLUDED.total_score,'
            . 'max_score=EXCLUDED.max_score,note=EXCLUDED.note,updated_at=now() RETURNING evaluation_id',
            [
                'dept' => $deptId,
                'survey' => $legacySurveyId,
                'agent' => $agentIdByExtension[$extension],
                'evaluator' => $evaluatorIdByName[$evaluatorName],
                'linked' => mb_substr($linkedId, 0, 150),
                'unique' => mb_substr($uniqueId, 0, 150),
                'evaluated' => (string) ($master['Tarih'] ?? date('Y-m-d H:i:s')),
                'total' => $totalScore,
                'max' => max(0.01, $maxScore),
                'note' => implode("\n", $noteParts),
                'source' => 'mysql.AnketMaster',
                'legacy' => $legacyId,
            ]
        );
        $counts['evaluations']++;

        foreach (array_values($masterAnswers) as $index => $answer) {
            $legacyQuestionId = (int) ($rubricQuestions[$index]['id'] ?? 0);
            $questionId = $questionIdByLegacy[$legacyQuestionId] ?? 0;
            if ($questionId < 1) {
                throw new RuntimeException('Eski cevap için soru eşleştirmesi bulunamadı: ' . (int) ($answer['id'] ?? 0));
            }
            $answerText = 'Eski cevap ID: ' . (int) ($answer['id'] ?? 0)
                . '; ağırlık: ' . (string) ($answer['Agirlik'] ?? '')
                . '; eski not: ' . (string) ($answer['AnketNotu'] ?? '');
            $execute(
                'INSERT INTO callcenter_survey_answers '
                . '(dept_id,survey_id,evaluation_id,question_id,score,max_score,answer_text,created_at,updated_at) '
                . 'VALUES (:dept,:survey,:evaluation,:question,:score,:max,:text,:created,:created) '
                . 'ON CONFLICT (dept_id,evaluation_id,question_id) DO UPDATE SET '
                . 'score=EXCLUDED.score,max_score=EXCLUDED.max_score,answer_text=EXCLUDED.answer_text,updated_at=now()',
                [
                    'dept' => $deptId,
                    'survey' => $legacySurveyId,
                    'evaluation' => $evaluationId,
                    'question' => $questionId,
                    'score' => max(0.0, (float) ($answer['Puan'] ?? 0)),
                    'max' => max(0.01, (float) ($answer['MaxPuan'] ?? 0)),
                    'text' => $answerText,
                    'created' => (string) ($master['Tarih'] ?? date('Y-m-d H:i:s')),
                ]
            );
            $counts['answers']++;
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$admin = callcenter_rows(
    "SELECT supervisor_id,dept_id,username,role FROM callcenter_supervisor_users "
    . "WHERE dept_id=:dept AND username='admin' LIMIT 1",
    ['dept' => $deptId]
)[0] ?? null;
if ($admin) {
    callcenter_audit_write(
        $admin,
        'create',
        'anket_yonetim',
        'Eski anket verileri aktarıldı',
        json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'system',
        'cli:tools/import_legacy_surveys.php'
    );
}

echo json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
