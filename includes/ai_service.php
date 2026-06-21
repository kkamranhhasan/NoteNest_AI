<?php
// ============================================================
// includes/ai_service.php
// NoteNest AI Platform — Core AI Service Layer
// Connects to Google Gemini API via PHP cURL
// ============================================================

// ── Helper: Send request to Gemini API ───────────────────────
/**
 * Core cURL function to call Gemini generateContent endpoint.
 *
 * @param  array  $messages   Array of ['role'=>'user'|'model', 'text'=>'...']
 * @param  string $systemPrompt  System-level instruction for the AI
 * @param  float  $temperature   Creativity (0.0 = precise, 1.0 = creative)
 * @return array  ['success'=>bool, 'text'=>string, 'tokens'=>int, 'error'=>string]
 */
function geminiRequest(array $messages, string $systemPrompt = '', float $temperature = 0.7): array {
    // Build the Gemini "contents" array
    $contents = [];
    foreach ($messages as $msg) {
        $contents[] = [
            'role'  => ($msg['role'] === 'assistant' || $msg['role'] === 'model') ? 'model' : 'user',
            'parts' => [['text' => $msg['text']]]
        ];
    }

    // Build request payload
    $payload = [
        'contents'         => $contents,
        'generationConfig' => [
            'maxOutputTokens' => AI_MAX_TOKENS,
            'temperature'     => $temperature,
        ]
    ];

    // Attach system instruction if provided
    if (!empty($systemPrompt)) {
        $payload['system_instruction'] = [
            'parts' => [['text' => $systemPrompt]]
        ];
    }

    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;

    // cURL request
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false, // localhost-এ SSL skip
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // cURL-level error
    if ($curlError) {
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => 'cURL error: ' . $curlError];
    }

    $data = json_decode($response, true);

    // API-level error
    if ($httpCode !== 200 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? 'Unknown API error (HTTP ' . $httpCode . ')';
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => $errMsg];
    }

    // Extract response text
    $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $tokens = $data['usageMetadata']['totalTokenCount'] ?? 0;

    return ['success' => true, 'text' => trim($text), 'tokens' => $tokens, 'error' => ''];
}


// ============================================================
// PUBLIC FUNCTIONS
// ============================================================

// ── 1. AI TUTOR CHAT ─────────────────────────────────────────
/**
 * Sends a student question to the AI Tutor and gets an academic answer.
 * Maintains conversation context via $history array.
 *
 * @param  string $userMessage   The student's current question
 * @param  array  $history       Previous messages [['role'=>'user'|'assistant','text'=>'...']]
 * @param  string $courseContext Optional course name for contextual answers
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiChat(string $userMessage, array $history = [], string $courseContext = ''): array {
    $systemPrompt = "You are an expert academic tutor assistant for university students. 
Your role is to:
- Explain concepts clearly with examples
- Break down complex topics step by step  
- Encourage critical thinking by asking follow-up questions
- Provide study tips and mnemonics when helpful
- Be supportive, patient, and encouraging
- Use markdown formatting (bold, bullet points, code blocks) for clarity
- Keep responses focused and academic in nature";

    if ($courseContext) {
        $systemPrompt .= "\n\nThe student is currently studying: **{$courseContext}**. 
Tailor your explanations to this subject when relevant.";
    }

    // Build message chain: history + new message
    $messages   = $history;
    $messages[] = ['role' => 'user', 'text' => $userMessage];

    return geminiRequest($messages, $systemPrompt, 0.6);
}


// ── 2. AI QUESTION GENERATOR ─────────────────────────────────
/**
 * Generates exam questions from a given study material/context.
 *
 * @param  string $studyContent   The text/topic to generate questions from
 * @param  string $questionTypes  E.g. "5 MCQ, 3 short answer, 2 essay"
 * @param  string $difficulty     "easy" | "medium" | "hard"
 * @return array  ['success', 'text', 'questions_json', 'tokens', 'error']
 */
function aiGenerateQuestions(string $studyContent, string $questionTypes = '5 MCQ and 5 short answer', string $difficulty = 'medium'): array {
    $systemPrompt = "You are an expert academic exam setter. Generate exam questions strictly in valid JSON format.";

    $prompt = "Based on the following study material, generate exactly {$questionTypes} questions at {$difficulty} difficulty level.

STUDY MATERIAL:
{$studyContent}

Return ONLY a valid JSON array with NO extra text, NO markdown, NO explanation. Format:
[
  {
    \"type\": \"mcq\",
    \"question\": \"Question text here?\",
    \"options\": [\"A. Option 1\", \"B. Option 2\", \"C. Option 3\", \"D. Option 4\"],
    \"correct_answer\": \"A\",
    \"explanation\": \"Why this is correct\"
  },
  {
    \"type\": \"short_answer\",
    \"question\": \"Question text here?\",
    \"expected_keywords\": [\"keyword1\", \"keyword2\"],
    \"model_answer\": \"The ideal answer\"
  }
]";

    $result = geminiRequest([['role' => 'user', 'text' => $prompt]], $systemPrompt, 0.4);

    if ($result['success']) {
        // Clean up response — strip markdown code fences if present
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result['questions_json'] = $jsonText;
        } else {
            $result['success'] = false;
            $result['error']   = 'AI returned invalid JSON. Raw: ' . substr($result['text'], 0, 200);
            $result['questions_json'] = '[]';
        }
    }

    return $result;
}


// ── 3. AI ANSWER EVALUATOR ───────────────────────────────────
/**
 * Evaluates student answers against the AI-generated questions.
 * Returns a score, per-question feedback, weak areas, and study suggestions.
 *
 * @param  string $questionsJson  JSON string of generated questions
 * @param  array  $studentAnswers ['q_index' => 'student answer text']
 * @return array  ['success', 'text', 'score', 'feedback_json', 'weak_areas', 'tokens', 'error']
 */
function aiEvaluateAnswers(string $questionsJson, array $studentAnswers): array {
    $systemPrompt = "You are a strict but fair academic evaluator. Evaluate student answers objectively and provide constructive feedback. Always respond in valid JSON.";

    $answersFormatted = json_encode($studentAnswers, JSON_PRETTY_PRINT);

    $prompt = "Evaluate the following student answers against the given questions.

QUESTIONS JSON:
{$questionsJson}

STUDENT ANSWERS (indexed by question number, 0-based):
{$answersFormatted}

Evaluate each answer and return ONLY a valid JSON object:
{
  \"total_score\": 85,
  \"max_score\": 100,
  \"grade\": \"B+\",
  \"overall_feedback\": \"General performance summary\",
  \"weak_areas\": [\"Topic 1\", \"Topic 2\"],
  \"study_suggestions\": [\"Suggestion 1\", \"Suggestion 2\"],
  \"question_results\": [
    {
      \"question_no\": 1,
      \"student_answer\": \"What student wrote\",
      \"is_correct\": true,
      \"marks_earned\": 10,
      \"marks_possible\": 10,
      \"feedback\": \"Detailed feedback\"
    }
  ]
}";

    $result = geminiRequest([['role' => 'user', 'text' => $prompt]], $systemPrompt, 0.3);

    if ($result['success']) {
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result['score']         = $decoded['total_score'] ?? 0;
            $result['feedback_json'] = $jsonText;
            $result['weak_areas']    = implode(', ', $decoded['weak_areas'] ?? []);
            $result['grade']         = $decoded['grade'] ?? 'N/A';
        } else {
            $result['success'] = false;
            $result['error']   = 'AI returned invalid evaluation JSON.';
        }
    }

    return $result;
}


// ── 4. AI CONTENT SUMMARIZER ─────────────────────────────────
/**
 * Summarizes a long text into concise study notes.
 *
 * @param  string $content   Raw text to summarize
 * @param  string $style     "bullet_points" | "paragraph" | "flashcards"
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiSummarize(string $content, string $style = 'bullet_points'): array {
    $styleInstructions = [
        'bullet_points' => 'Use clear bullet points with key terms bolded. Group by topic.',
        'paragraph'     => 'Write a concise paragraph summary covering all key ideas.',
        'flashcards'    => 'Create flashcard-style Q&A pairs. Format: "Q: ... | A: ..."',
    ];
    $styleInstruction = $styleInstructions[$style] ?? $styleInstructions['bullet_points'];

    $prompt = "Summarize the following academic content for a student studying for exams.
Style: {$styleInstruction}

CONTENT:
{$content}

Provide a clear, structured summary that covers all important concepts.";

    return geminiRequest([['role' => 'user', 'text' => $prompt]], '', 0.5);
}


// ── 5. SAVE AI INTERACTION TO DB ─────────────────────────────
/**
 * Logs an AI interaction to the ai_chat_history table.
 *
 * @param  mysqli $conn
 * @param  int    $userId
 * @param  string $sessionId
 * @param  string $role       'user' | 'assistant'
 * @param  string $message
 * @param  string $type       'tutor' | 'exam_hint' | 'summary' | 'general'
 * @param  int    $courseId   Optional
 * @param  int    $tokens     Token count
 */
function saveAiChat(mysqli $conn, int $userId, string $sessionId, string $role, string $message, string $type = 'tutor', int $courseId = 0, int $tokens = 0): void {
    $courseIdVal = $courseId > 0 ? $courseId : null;
    $stmt = $conn->prepare(
        "INSERT INTO ai_chat_history (user_id, session_id, role, message, interaction_type, course_id, tokens_used)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issssii', $userId, $sessionId, $role, $message, $type, $courseIdVal, $tokens);
    $stmt->execute();
    $stmt->close();
}


// ── 6. LOG PROGRESS EVENT ────────────────────────────────────
/**
 * Records a user activity event in user_progress for analytics.
 *
 * @param  mysqli $conn
 * @param  int    $userId
 * @param  string $eventType  'file_upload'|'ai_chat'|'exam_taken'|'task_done'|'login'|'note_view'
 * @param  string $detail     Optional label
 * @param  int    $courseId   Optional
 * @param  float  $score      Optional score value
 */
function logProgress(mysqli $conn, int $userId, string $eventType, string $detail = '', int $courseId = 0, float $score = 0.0): void {
    $courseIdVal = $courseId > 0 ? $courseId : null;
    $scoreVal    = $score > 0    ? $score    : null;
    $stmt = $conn->prepare(
        "INSERT INTO user_progress (user_id, course_id, event_type, event_detail, score_value)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissd', $userId, $courseIdVal, $eventType, $detail, $scoreVal);
    $stmt->execute();
    $stmt->close();
}
