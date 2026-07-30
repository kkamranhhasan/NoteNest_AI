<?php
// ============================================================
// includes/google_ai_analyzer.php
// NoteNest AI — AI Analysis for Google Classroom Content
// Analyzes assignments & materials using Groq AI
// ============================================================

require_once __DIR__ . '/ai_service.php';

/**
 * Analyze new assignments that haven't been AI-analyzed yet.
 */
function gc_analyze_new_assignments(mysqli $conn, int $userId): int {
    $analyzed = 0;

    // Get unanalyzed assignments
    $stmt = $conn->prepare(
        "SELECT ga.id, ga.title, ga.description, ga.work_type, ga.max_points, ga.due_date,
                gc.course_name, ga.course_id
         FROM google_assignments ga
         JOIN google_courses gc ON ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id
         WHERE ga.user_id = ? AND ga.ai_analysis IS NULL AND ga.description IS NOT NULL AND ga.description != ''
         LIMIT 5"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($assignments as $asg) {
        try {
            // Build context for AI analysis
            $content = "Assignment: {$asg['title']}\n";
            $content .= "Course: {$asg['course_name']}\n";
            $content .= "Type: {$asg['work_type']}\n";
            if ($asg['max_points']) $content .= "Max Points: {$asg['max_points']}\n";
            if ($asg['due_date']) $content .= "Due Date: {$asg['due_date']}\n";
            $content .= "\nDescription:\n{$asg['description']}";

            // Get AI analysis
            $analysis = gc_ai_analyze_assignment($content);

            if ($analysis['success']) {
                // Store analysis
                $upd = $conn->prepare("UPDATE google_assignments SET ai_analysis = ? WHERE id = ?");
                $upd->bind_param('si', $analysis['json'], $asg['id']);
                $upd->execute();
                $upd->close();

                $analyzed++;

                // Generate notification
                $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $msg = "🤖 AI analyzed assignment: {$asg['title']} — " . ($analysis['difficulty'] ?? 'Ready to review');
                $stmt2->bind_param('is', $userId, $msg);
                $stmt2->execute();
                $stmt2->close();
            }

            // Generate AI questions for this assignment (Feature 10)
            if (!$asg['course_id']) continue;

            $chk = $conn->prepare("SELECT ai_questions_generated FROM google_assignments WHERE id = ?");
            $chk->bind_param('i', $asg['id']);
            $chk->execute();
            $chk->bind_result($qGenerated);
            $chk->fetch();
            $chk->close();

            if (!$qGenerated && strlen($asg['description']) > 50) {
                gc_generate_assignment_questions($conn, $userId, $asg);
            }
        } catch (\Throwable $e) {
            // Silently continue — AI failures shouldn't block sync
            continue;
        }
    }

    return $analyzed;
}

/**
 * AI-analyze a single assignment.
 * @return array ['success', 'json', 'difficulty', 'error']
 */
function gc_ai_analyze_assignment(string $content): array {
    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are an expert academic assistant. Analyze the given assignment and return ONLY valid JSON, no markdown fences.',
        ],
        [
            'role'    => 'user',
            'content' => "Analyze this assignment and provide a structured analysis.

ASSIGNMENT:
{$content}

Return ONLY valid JSON:
{
  \"summary\": \"Brief 2-3 sentence summary of what the assignment requires\",
  \"important_concepts\": [\"Concept 1\", \"Concept 2\", \"Concept 3\"],
  \"required_topics\": [\"Topic 1\", \"Topic 2\"],
  \"estimated_difficulty\": \"Easy|Medium|Hard\",
  \"estimated_hours\": 3,
  \"study_tips\": [\"Tip 1\", \"Tip 2\", \"Tip 3\"],
  \"key_deliverables\": [\"Deliverable 1\", \"Deliverable 2\"],
  \"recommended_approach\": \"Step-by-step approach suggestion\"
}",
        ],
    ];

    $result = grokRequest($messages, GROQ_MODEL_FAST, 0.3, 1500);

    if ($result['success']) {
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return [
                'success'    => true,
                'json'       => $jsonText,
                'difficulty' => $decoded['estimated_difficulty'] ?? 'Unknown',
                'error'      => '',
            ];
        }
    }

    return ['success' => false, 'json' => '', 'difficulty' => '', 'error' => $result['error'] ?? 'Failed to parse AI response'];
}

/**
 * Generate exam questions from assignment content (Feature 10).
 */
function gc_generate_assignment_questions(mysqli $conn, int $userId, array $asg): bool {
    $studyContent = "Course: {$asg['course_name']}\n";
    $studyContent .= "Assignment: {$asg['title']}\n\n";
    $studyContent .= $asg['description'];

    $result = aiGenerateQuestions($studyContent, '3 MCQ and 2 short answer', 'medium');

    if ($result['success'] && !empty($result['questions_json'])) {
        // Insert into ai_evaluations
        $courseIdSafe = $asg['course_id'] ? (int)$asg['course_id'] : 0;
        $ins = $conn->prepare(
            "INSERT INTO ai_evaluations (user_id, course_id, questions_json, status)
             VALUES (?, ?, ?, 'generated')"
        );
        $ins->bind_param('iis', $userId, $courseIdSafe, $result['questions_json']);
        $ins->execute();
        $ins->close();

        // Mark as generated
        $upd = $conn->prepare("UPDATE google_assignments SET ai_questions_generated = 1 WHERE id = ?");
        $upd->bind_param('i', $asg['id']);
        $upd->execute();
        $upd->close();

        // Notification
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $msg = "🧠 AI generated practice questions for: {$asg['title']}";
        $stmt->bind_param('is', $userId, $msg);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    return false;
}

/**
 * Generate study recommendations when new content is synced (Feature 11).
 */
function gc_generate_study_recommendation(mysqli $conn, int $userId, string $courseName, string $newContent): void {
    if (!function_exists('aiStudyRecommendations')) return;

    $profile = [
        'trigger'    => 'New content synced from Google Classroom',
        'course'     => $courseName,
        'new_content' => substr($newContent, 0, 500),
    ];

    // Get existing weak areas
    $wq = $conn->prepare("SELECT weak_areas FROM ai_evaluations WHERE user_id = ? AND status = 'evaluated' ORDER BY evaluated_at DESC LIMIT 5");
    $wq->bind_param('i', $userId);
    $wq->execute();
    $wrows = $wq->get_result()->fetch_all(MYSQLI_ASSOC);
    $wq->close();

    $weakAreas = [];
    foreach ($wrows as $w) {
        if ($w['weak_areas']) {
            $weakAreas = array_merge($weakAreas, array_map('trim', explode(',', $w['weak_areas'])));
        }
    }
    $profile['weak_areas'] = array_unique($weakAreas);

    // Log a study recommendation trigger (the actual recommendation is generated on-demand in study_recommendations.php)
    if (function_exists('logProgress')) {
        logProgress($conn, $userId, 'ai_chat', "Study plan trigger: New {$courseName} content synced", 0);
    }
}
?>
