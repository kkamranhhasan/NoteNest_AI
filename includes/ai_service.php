<?php
// ============================================================
// includes/ai_service.php
// NoteNest AI Platform — Core AI Service Layer
// Powered by Groq AI (OpenAI-compatible, Llama 3.3-70B)
// ============================================================

/**
 * Core cURL function to call xAI Grok chat completions endpoint.
 *
 * @param  array  $messages     OpenAI-style messages [['role'=>'user|assistant|system','content'=>'...']]
 * @param  string $model        GROK_MODEL | GROK_MODEL_PRO
 * @param  float  $temperature  Creativity (0.0 = precise, 1.0 = creative)
 * @param  int    $maxTokens    Max output tokens
 * @return array  ['success'=>bool, 'text'=>string, 'tokens'=>int, 'error'=>string]
 */
function grokRequest(array $messages, string $model = '', float $temperature = 0.7, int $maxTokens = 0): array {
    $model     = $model     ?: GROQ_MODEL;
    $maxTokens = $maxTokens ?: AI_MAX_TOKENS;

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
    ];

    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false, // localhost XAMPP
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => 'cURL error: ' . $curlError];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? ('Unknown API error (HTTP ' . $httpCode . '). Response: ' . substr($response, 0, 300));
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => $errMsg];
    }

    $text   = $data['choices'][0]['message']['content'] ?? '';
    $tokens = $data['usage']['total_tokens'] ?? 0;

    return ['success' => true, 'text' => trim($text), 'tokens' => $tokens, 'error' => ''];
}

/**
 * Backward-compatible wrapper: accepts old Gemini-style message format
 * (['role'=>'user|model', 'text'=>'...']) and converts to OpenAI format.
 * Used by pages that call geminiRequest() directly.
 */
function geminiRequest(array $messages, string $systemPrompt = '', float $temperature = 0.7): array {
    $openAiMessages = [];

    // Add system prompt first
    if (!empty($systemPrompt)) {
        $openAiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
    }

    // Convert Gemini-style role names to OpenAI style
    foreach ($messages as $msg) {
        $role    = ($msg['role'] === 'model') ? 'assistant' : ($msg['role'] ?? 'user');
        $content = $msg['text'] ?? $msg['content'] ?? '';
        $openAiMessages[] = ['role' => $role, 'content' => $content];
    }

    return grokRequest($openAiMessages, GROQ_MODEL, $temperature);
}


// ============================================================
// PUBLIC FUNCTIONS  (all signatures unchanged)
// ============================================================

// ── 1. AI TUTOR CHAT ─────────────────────────────────────────
/**
 * Sends a student question to the AI Tutor and gets an academic answer.
 *
 * @param  string $userMessage   The student's current question
 * @param  array  $history       Previous messages [['role'=>'user|assistant','text'=>'...']]
 * @param  string $courseContext Optional course name for contextual answers
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiChat(string $userMessage, array $history = [], string $courseContext = ''): array {
    $systemContent = "You are an expert academic tutor assistant for university students powered by Grok AI.
Your role is to:
- Explain concepts clearly with examples
- Break down complex topics step by step
- Encourage critical thinking by asking follow-up questions
- Provide study tips and mnemonics when helpful
- Be supportive, patient, and encouraging
- Use markdown formatting (bold, bullet points, code blocks) for clarity
- Keep responses focused and academic in nature";

    if ($courseContext) {
        $systemContent .= "\n\nThe student is currently studying: **{$courseContext}**. Tailor your explanations to this subject when relevant.";
    }

    $messages = [['role' => 'system', 'content' => $systemContent]];

    // Add conversation history
    foreach ($history as $msg) {
        $role     = ($msg['role'] === 'model') ? 'assistant' : ($msg['role'] ?? 'user');
        $content  = $msg['text'] ?? $msg['content'] ?? '';
        $messages[] = ['role' => $role, 'content' => $content];
    }

    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return grokRequest($messages, GROQ_MODEL, 0.6);
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
    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are an expert academic exam setter. Generate exam questions strictly in valid JSON format. Return ONLY raw JSON, no markdown fences, no explanation.',
        ],
        [
            'role'    => 'user',
            'content' => "Based on the following study material, generate exactly {$questionTypes} questions at {$difficulty} difficulty level.

STUDY MATERIAL:
{$studyContent}

Return ONLY a valid JSON array. Format:
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
]",
        ],
    ];

    $result = grokRequest($messages, GROQ_MODEL, 0.4, 3000);

    if ($result['success']) {
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result['questions_json'] = $jsonText;
        } else {
            $result['success']       = false;
            $result['error']         = 'AI returned invalid JSON. Raw: ' . substr($result['text'], 0, 200);
            $result['questions_json'] = '[]';
        }
    }

    return $result;
}


// ── 3. AI ANSWER EVALUATOR ───────────────────────────────────
/**
 * Evaluates student answers against AI-generated questions.
 *
 * @param  string $questionsJson  JSON string of generated questions
 * @param  array  $studentAnswers ['q_index' => 'student answer text']
 * @return array  ['success', 'text', 'score', 'feedback_json', 'weak_areas', 'grade', 'tokens', 'error']
 */
function aiEvaluateAnswers(string $questionsJson, array $studentAnswers): array {
    $answersFormatted = json_encode($studentAnswers, JSON_PRETTY_PRINT);

    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are a strict but fair academic evaluator. Evaluate student answers objectively and provide constructive feedback. Always respond with ONLY valid JSON, no markdown.',
        ],
        [
            'role'    => 'user',
            'content' => "Evaluate the following student answers against the given questions.

QUESTIONS JSON:
{$questionsJson}

STUDENT ANSWERS (indexed by question number, 0-based):
{$answersFormatted}

Return ONLY a valid JSON object:
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
}",
        ],
    ];

    // Use the more powerful model for evaluation
    $result = grokRequest($messages, GROQ_MODEL_PRO, 0.3, 4000);

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
 * Summarizes long text into concise study notes.
 *
 * @param  string $content  Raw text to summarize
 * @param  string $style    "bullet_points" | "paragraph" | "flashcards"
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiSummarize(string $content, string $style = 'bullet_points'): array {
    $styleInstructions = [
        'bullet_points' => 'Use clear bullet points with key terms bolded. Group by topic.',
        'paragraph'     => 'Write a concise paragraph summary covering all key ideas.',
        'flashcards'    => 'Create flashcard-style Q&A pairs. Format: "Q: ... | A: ..."',
    ];
    $styleInstruction = $styleInstructions[$style] ?? $styleInstructions['bullet_points'];

    $messages = [
        [
            'role'    => 'user',
            'content' => "Summarize the following academic content for a student studying for exams.
Style: {$styleInstruction}

CONTENT:
{$content}

Provide a clear, structured summary that covers all important concepts.",
        ],
    ];

    return grokRequest($messages, GROQ_MODEL, 0.5);
}


// ── 5. AI STUDY RECOMMENDATIONS ──────────────────────────────
/**
 * Generates a personalized study plan based on user's exam history and weak areas.
 *
 * @param  array  $profile   User learning profile data
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiStudyRecommendations(array $profile): array {
    $profileText = json_encode($profile, JSON_PRETTY_PRINT);

    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are an expert academic advisor and study coach. Provide personalized, actionable study recommendations based on the student\'s performance data.',
        ],
        [
            'role'    => 'user',
            'content' => "Based on this student's learning profile, generate a personalized study plan:

{$profileText}

Provide:
1. Top 3 priority topics to focus on (with specific study tips for each)
2. A 7-day study schedule
3. Recommended study techniques for their weak areas
4. A motivational message

Format your response clearly with sections and bullet points.",
        ],
    ];

    return grokRequest($messages, GROQ_MODEL, 0.7);
}


// ── 6. SAVE AI INTERACTION TO DB ─────────────────────────────
/**
 * Logs an AI interaction to the ai_chat_history table.
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


// ── 7. LOG PROGRESS EVENT ────────────────────────────────────
/**
 * Records a user activity event in user_progress for analytics.
 */
function logProgress(mysqli $conn, int $userId, string $eventType, string $detail = '', int $courseId = 0, float $score = 0.0): void {
    $courseIdVal = $courseId > 0 ? $courseId : null;
    $scoreVal    = $score   > 0 ? $score    : null;
    $stmt = $conn->prepare(
        "INSERT INTO user_progress (user_id, course_id, event_type, event_detail, score_value)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissd', $userId, $courseIdVal, $eventType, $detail, $scoreVal);
    $stmt->execute();
    $stmt->close();
}
