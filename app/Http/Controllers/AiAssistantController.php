<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiAssistantController extends Controller
{
    /**
     * POST /api/ai/chat
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:3000',
            'conversation_id' => 'nullable|integer',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!empty($validated['conversation_id'])) {
            $conversation = AiConversation::where('user_id', $user->id)
                ->find($validated['conversation_id']);

            if (!$conversation) {
                return response()->json([
                    'status' => false,
                    'message' => 'Conversation not found.',
                ], 404);
            }
        } else {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title' => Str::limit($validated['message'], 100),
            ]);
        }

        $question = trim($validated['message']);

        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $question,
        ]);

        try {
            $history = AiMessage::where(
                'conversation_id',
                $conversation->id
            )
                ->orderByDesc('id')
                ->limit(12)
                ->get(['role', 'content'])
                ->reverse()
                ->values()
                ->map(fn ($item) => [
                    'role' => $item->role,
                    'content' => $item->content,
                ])
                ->all();

            $category = $this->classifyQuestion($question);
            $records = [];

            if ($category === 'unsupported') {
                $answer = [
                    'answer' => 'I can only answer questions about the TrailBound app and its database.',
                    'type' => 'unsupported_question',
                ];

                $this->saveAssistantMessage($conversation->id, $answer);

                return response()->json([
                    'status' => true,
                    'message' => 'Question category not supported.',
                    'conversation_id' => $conversation->id,
                    'category' => $category,
                    'ai_response' => $answer,
                    'data' => [],
                ]);
            }

            if ($category === 'database') {
                $schema = $this->getDatabaseSchema();

                try {
                    $sql = $this->generateSql(
                        $question,
                        $schema,
                        $user
                    );

                    $sql = $this->validateReadOnlySql(
                        $sql,
                        array_keys($schema)
                    );

                    $records = DB::select($sql);
                    $records = $this->sanitizeRecords($records);
                    $records = $this->formatMediaRecords($records);

                    // FORCE LIMIT TO 5 FOR RECOMMENDATIONS/LISTS
                    // This ensures that even if SQL returns more, we only send 5
                    if (count($records) > 5) {
                        $records = array_slice($records, 0, 5);
                    }

                    $answer = $this->generateAnswer(
                        $question,
                        $records,
                        $history
                    );
                } catch (\RuntimeException $e) {
                    if (str_contains(
                        $e->getMessage(),
                        'does not support this question'
                    )) {
                        $answer = [
                            'answer' => 'I could not find the required information in the available database schema.',
                            'records_found' => 0,
                            'details' => [],
                        ];
                    } else {
                        throw $e;
                    }
                }
            } else {
                $answer = $this->generateAppAnswer(
                    $question,
                    $history
                );
            }

            $this->saveAssistantMessage($conversation->id, $answer);

            return response()->json([
                'status' => true,
                'message' => 'AI response generated successfully.',
                'conversation_id' => $conversation->id,
                'category' => $category,
                'ai_response' => $answer,
                // 'data' => $records,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to generate an AI response.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Please try again.',
            ], 500);
        }
    }

    /**
     * Save an assistant response in conversation history.
     */
    private function saveAssistantMessage(
        int $conversationId,
        array $answer
    ): void {
        // CHANGED: Sirf 'answer' text save kar rahe hain taake frontend par readable ho
        AiMessage::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $answer['answer'] ?? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Classify TrailBound questions.
     *
     * Recommendation questions are identified locally first,
     * so the AI classifier cannot incorrectly reject them.
     */
    private function classifyQuestion(string $question): string
    {
        $text = strtolower(trim($question));

        // Trail and trip recommendations always use database records.
        $recommendationPattern = '/\b('
            . 'suggest|recommend|recommendation|'
            . 'which\s+(trip|trail)|'
            . 'what\s+(trip|trail)|'
            . 'best\s+(trip|trips|trail|trails)|'
            . 'good\s+(trip|trips|trail|trails)|'
            . 'where\s+should\s+i\s+go|'
            . 'trip\s+for\s+me|'
            . 'trips?\s+for\s+me|'
            . 'trails?\s+for\s+me|'
            . 'plan\s+my\s+hike|'
            . 'suitable\s+(trip|trail)|'
            . 'upcoming\s+trips?'
            . ')\b/i';

        if (preg_match($recommendationPattern, $text)) {
            return 'database';
        }

        $systemPrompt = <<<'PROMPT'
You classify questions for the TrailBound hiking application.

Return exactly one category: database, app, or unsupported.

database:
Questions asking for actual database records or values,
including trips, trails, posts, users, comments, media,
counts, searches, recommendations, and suggestions based
on trips or trails stored in the database.

IMPORTANT:
- "Suggest me a good trail" is database.
- "Recommend a trail for me" is database.
- "Which trail is best?" is database.
- "Which trip should I go on?" is database.
- "Suggest a trip based on my preferences" is database.
- "Show upcoming trips" is database.
- "Find an easy hiking trip" is database.
- "Show the best-rated trails" is database.

app:
Questions about how to use TrailBound, app features,
and documented workflows.

Examples:
"How do I create a trip?" => app
"How do I upload a post?" => app
"What is TrailBound?" => app

unsupported:
Unrelated general knowledge and questions not about
TrailBound or its stored data.

Examples:
"What is the capital of Pakistan?" => unsupported
"Explain the history of Rome" => unsupported

Return only one word:
database, app, or unsupported.
PROMPT;

        $result = strtolower(trim($this->groqChat([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ])));

        return in_array(
            $result,
            ['database', 'app', 'unsupported'],
            true
        ) ? $result : 'unsupported';
    }

    /**
     * Discover application tables and columns.
     */
    private function getDatabaseSchema(): array
    {
        $connection = DB::connection();
        $rows = $connection->select('SHOW TABLES');

        $tables = [];

        $excludedTables = [
            'migrations',
            'password_reset_tokens',
            'personal_access_tokens',
            'failed_jobs',
            'jobs',
            'job_batches',
            'sessions',
        ];

        foreach ($rows as $row) {
            $table = array_values((array) $row)[0] ?? null;

            if (
                !$table ||
                in_array($table, $excludedTables, true)
            ) {
                continue;
            }

            $columns = Schema::connection(
                $connection->getName()
            )->getColumnListing($table);

            $safeColumns = array_values(array_filter(
                $columns,
                fn ($column) => !$this->isSensitiveField($column)
            ));

            if (!empty($safeColumns)) {
                $tables[$table] = $safeColumns;
            }
        }

        if (empty($tables)) {
            throw new \RuntimeException(
                'No searchable application tables were found.'
            );
        }

        return $tables;
    }

    /**
     * Generate a SELECT query based on the real schema.
     */
    private function generateSql(
        string $question,
        array $schema,
        $user
    ): string {
        $schemaText = '';

        foreach ($schema as $table => $columns) {
            $schemaText .= $table . ': '
                . implode(', ', $columns)
                . "\n";
        }

        // Pass only non-sensitive preference context to the planner.
        $userContext = [
            'user_id' => $user->id,
        ];

        foreach (['location', 'latitude', 'longitude', 'bio'] as $field) {
            if (
                isset($user->{$field}) &&
                is_scalar($user->{$field}) &&
                trim((string) $user->{$field}) !== ''
            ) {
                $userContext[$field] = $user->{$field};
            }
        }

        $systemPrompt = <<<'PROMPT'
You are a read-only MySQL query planner for the TrailBound
hiking application.

Generate exactly ONE MySQL SELECT query using only the
supplied schema. Answer the user's question with real records.

DATABASE RECOMMENDATIONS:
The user may ask for a good trail, best trail, suitable trip,
or which trip they should go on.

For these requests:
- Query the actual trails or trips table that exists.
- Return actual matching database records, not general advice.
- Select useful available columns such as id, title, name,
  description, location, location_name, start_date, end_date,
  pace, difficulty, distance, rating, group_capacity,
  latitude, longitude, and image URL/path fields when present.
- Only use columns that actually exist in the supplied schema.
- Prefer upcoming trips when the user asks which trip to go on
  and the trips table has a start_date column.
- If the user asks for an easy trip and a pace/difficulty column
  exists, filter using that column.
- If the user asks for a nearby trip or trail and coordinates
  or location columns exist, use supported location data where
  appropriate.
- If a rating or popularity column exists, use it to help
  rank requests for the best-rated or most popular records.
- If no rating exists, do not invent ratings or claim that a
  result is the highest-rated.
- Do not assume the user's preferences. Use only preferences
  stated in the question or supplied user context.
- User context may help with a location-related request, but
  only query actual columns and actual records.
- If a required table or useful column does not exist, use
  other relevant available fields rather than inventing schema.
- If there are no matching records, return a valid query that
  yields no results where possible.
- IMPORTANT: For recommendations involving trails, trips, or posts, 
  ALWAYS use LIMIT 5 to show only the top 5 results.

GENERAL RULES:
- Use only tables and columns in the supplied schema.
- Never invent tables, columns, relationships, or URLs.
- Never use SELECT *.
- Never select passwords, tokens, secrets, email addresses,
  phone numbers, credentials, or other sensitive fields.
- Use COUNT(*) for count questions.
- Join tables only when the supplied schema supports the
  relationship.
- Use LIMIT 5 for recommendations (trails/trips/posts).
- Use LIMIT 100 or less for other lists.
- Do not use CTEs, nested SELECT statements, or multiple queries.
- Never generate INSERT, UPDATE, DELETE, or write queries.
- If the schema cannot answer the question, return exactly
  CANNOT_ANSWER.
- Return SQL only, without Markdown or explanation.

MEDIA HANDLING (CRITICAL):
- Images are often stored in separate media tables (e.g., trail_media, trip_media, post_media).
- ALWAYS check if a media table exists in the schema and JOIN it properly.
- Example: LEFT JOIN trail_media ON trail_media.trail_id = trails.id
- Select the media path/column AS image_url.
- If no media table exists, look for image/path columns directly in the main table.
- Use LEFT JOIN so entities without media still appear.
- Never invent image URLs or foreign key relationships.
PROMPT;

        $content = trim($this->groqChat([
            [
                'role' => 'system',
                'content' => $systemPrompt
                    . "\n\nAvailable database schema:\n"
                    . $schemaText
                    . "\nAuthenticated user's available context:\n"
                    . json_encode(
                        $userContext,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ]));

        $content = preg_replace(
            '/^```(?:sql)?\s*|\s*```$/i',
            '',
            $content
        );

        $content = trim($content);

        if (strtoupper($content) === 'CANNOT_ANSWER') {
            throw new \RuntimeException(
                'The database schema does not support this question.'
            );
        }

        if ($content === '') {
            throw new \RuntimeException(
                'Groq returned an empty SQL query.'
            );
        }

        return $content;
    }

    /**
     * Validate the generated read-only SQL query.
     */
    private function validateReadOnlySql(
        string $sql,
        array $allowedTables
    ): string {
        $sql = trim($sql);

        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        if ($sql === '' || !preg_match('/^SELECT\b/i', $sql)) {
            throw new \RuntimeException(
                'Only SELECT queries are allowed.'
            );
        }

        if (
            str_contains($sql, ';') ||
            preg_match('~--|#|/\*|\*/~', $sql)
        ) {
            throw new \RuntimeException(
                'The generated SQL contains disallowed syntax.'
            );
        }

        $blocked = '~\b('
            . 'INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|'
            . 'REPLACE|GRANT|REVOKE|CALL|EXEC|EXECUTE|OUTFILE|'
            . 'DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|'
            . 'INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|'
            . 'PROCEDURE|HANDLER|LOCK|UNLOCK'
            . ')\b~i';

        if (preg_match($blocked, $sql)) {
            throw new \RuntimeException(
                'The generated SQL contains a blocked operation.'
            );
        }

        if (
            preg_match('/\bWITH\b/i', $sql) ||
            preg_match('/\(\s*SELECT\b/i', $sql)
        ) {
            throw new \RuntimeException(
                'CTEs and nested SELECT queries are not supported.'
            );
        }

        preg_match_all(
            '~\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?~i',
            $sql,
            $matches
        );

        $referencedTables = array_unique($matches[1] ?? []);

        if (empty($referencedTables)) {
            throw new \RuntimeException(
                'No valid application table was referenced.'
            );
        }

        $allowedLookup = array_map('strtolower', $allowedTables);

        foreach ($referencedTables as $table) {
            if (!in_array(strtolower($table), $allowedLookup, true)) {
                throw new \RuntimeException(
                    'The query references an unavailable table: ' . $table
                );
            }
        }

        // Check if LIMIT already exists
        if (!preg_match('/\bLIMIT\s+\d+\b/i', $sql)) {
            // Default limit if AI didn't add one
            $sql .= ' LIMIT 100';
        }

        // Validate existing limit range
        if (preg_match('/\bLIMIT\s+(\d+)\b/i', $sql, $match)) {
            $limit = (int) $match[1];

            // Allow limits between 1 and 100. 
            // If AI put LIMIT 5, it stays 5. 
            // If AI put nothing, we added 100 above.
            if ($limit < 1 || $limit > 100) {
                $sql = preg_replace(
                    '/\bLIMIT\s+\d+\b/i',
                    'LIMIT 100',
                    $sql
                );
            }
        }

        return $sql;
    }

    /**
     * Remove sensitive fields from database results.
     */
    private function sanitizeRecords(array $records): array
    {
        $result = [];

        foreach ($records as $record) {
            $row = (array) $record;

            foreach (array_keys($row) as $field) {
                if ($this->isSensitiveField($field)) {
                    unset($row[$field]);
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Identify fields that must not be exposed.
     */
    private function isSensitiveField(string $field): bool
    {
        return preg_match(
            '/password|token|secret|credential|private.?key|'
            . 'api.?key|remember.?token|authorization|'
            . 'email|phone|mobile|telephone/i',
            $field
        ) === 1;
    }

    /**
     * Convert stored media paths into absolute URLs.
     */
    private function formatMediaRecords(array $records): array
    {
        // Expanded list to catch various media column names used by AI
        $urlFields = [
            'image_url',
            'media_url',
            'profile_picture',
            'cover_image',
            'path',
            'file_path',
            'url',
        ];

        foreach ($records as &$record) {
            foreach ($record as $field => &$value) {
                if (
                    $value !== null &&
                    is_string($value) &&
                    in_array(strtolower($field), $urlFields, true)
                ) {
                    $convertedUrl = $this->makeMediaUrl($value);
                    
                    // Always normalize to 'image_url' key for frontend consistency
                    if ($field !== 'image_url') {
                        unset($record[$field]);
                        $record['image_url'] = $convertedUrl;
                    } else {
                        $value = $convertedUrl;
                    }
                }
            }

            unset($value);
        }

        unset($record);

        return $records;
    }

    private function makeMediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (Str::startsWith($path, ['https://', 'http://'])) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            return url($path);
        }

        return url(Storage::disk('public')->url($path));
    }

    /**
     * Generate an answer grounded in actual database results.
     */
    private function generateAnswer(
        string $question,
        array $records,
        array $history
    ): array {
        $systemPrompt = <<<'PROMPT'
You are the TrailBound database and recommendation assistant.

Answer using only the actual database records supplied below.

Return valid JSON:
{
  "answer": "A clear answer in the user's language",
  "records_found": 0,
  "details": []
}

Rules:
- Answer the user's question directly.
- Recommend actual trips or trails from the supplied records.
- Explain briefly why a result may suit the user's stated
  preferences, using only real available fields.
- If multiple records exist, recommend the most relevant ones.
- Do not invent trips, trails, dates, locations, ratings,
  images, distances, or other facts.
- Do not claim something is best-rated unless the data supports it.
- If no records are returned, explain that no matching records
  were found. Do not invent alternatives.
- records_found must equal the number of supplied result rows.
- details must contain the actual supplied database records.
- If an image field is null, do not fabricate an image URL.
- Never reveal sensitive information.
- Return JSON only, without Markdown fences.
PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
                    . "\n\nActual database results:\n"
                    . json_encode(
                        $records,
                        JSON_UNESCAPED_SLASHES |
                        JSON_UNESCAPED_UNICODE
                    ),
            ],
        ];

        foreach (array_slice($history, -8) as $item) {
            if (in_array($item['role'], ['user', 'assistant'], true)) {
                $messages[] = $item;
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $question,
        ];

        return $this->decodeJsonAnswer(
            $this->groqChat($messages, true),
            count($records),
            $records
        );
    }

    /**
     * TrailBound app documentation.
     */
    private function getAppKnowledge(): string
    {
        return <<<'KNOWLEDGE'
Application: TrailBound

Purpose:
A hiking companion finder and trip-planning application.

Known features:
- User registration and login.
- Laravel Sanctum authentication.
- User profiles and location information.
- Trip creation, listing, details, updating and deletion.
- Joining and leaving trips.
- Trip dates, group capacity, hiking pace and descriptions.
- Trip media uploads.
- Hiking trails and trail information.
- Nearby and recommended trips.
- Companion suggestions and friend requests.
- Posts, media, comments, comment likes and replies.
- AI chat and saved conversations.

Trip creation workflow:
1. Authenticate the user.
2. Send POST /api/trips.
3. Include title, start_date, end_date, group_capacity and pace.
4. Include optional supported description, location,
   coordinates and media upload fields.
5. The API validates and saves the trip.
6. Read the API response to confirm the result.

Recommendations:
- Recommendations about actual trips and trails should use
  the database rather than general knowledge.
- Do not invent undocumented endpoints or fields.
- Do not claim an action succeeded without confirmation.
- Exact validation depends on the current application code.
KNOWLEDGE;
    }

    /**
     * Answer questions about TrailBound features.
     */
    private function generateAppAnswer(
        string $question,
        array $history
    ): array {
        $systemPrompt = <<<'PROMPT'
You are the TrailBound app assistant.

Only answer questions about TrailBound features, workflows,
and documented API usage.

Return valid JSON:
{
  "answer": "A clear answer",
  "steps": []
}

Rules:
- Directly answer the user's question.
- Use steps when instructions are useful.
- Use the supplied documentation.
- Do not invent undocumented endpoints or features.
- State when exact implementation details are unknown.
- Do not answer unrelated general-knowledge questions.
- Answer in the user's language where possible.
- Return JSON only, without Markdown fences.
PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
                    . "\n\nTrailBound documentation:\n"
                    . $this->getAppKnowledge(),
            ],
        ];

        $history = array_slice($history, -10);

        if (
            !empty($history) &&
            end($history)['role'] === 'user' &&
            end($history)['content'] === $question
        ) {
            array_pop($history);
        }

        foreach ($history as $item) {
            if (in_array($item['role'], ['user', 'assistant'], true)) {
                $messages[] = $item;
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $question,
        ];

        return $this->decodeJsonAnswer(
            $this->groqChat($messages, true)
        );
    }

    /**
     * Normalize the AI's JSON response.
     */
    private function decodeJsonAnswer(
        string $content,
        int $recordsFound = 0,
        array $records = []
    ): array {
        $decoded = json_decode(trim($content), true);

        if (!is_array($decoded)) {
            return [
                'answer' => trim($content),
                'records_found' => $recordsFound,
                'details' => $records,
            ];
        }

        if (
            !isset($decoded['answer']) ||
            !is_string($decoded['answer'])
        ) {
            $decoded['answer'] = 'Response generated successfully.';
        }

        if ($records !== [] || array_key_exists('details', $decoded)) {
            $decoded['records_found'] = $recordsFound;
            $decoded['details'] = $records;
        } elseif (
            !isset($decoded['steps']) ||
            !is_array($decoded['steps'])
        ) {
            $decoded['steps'] = [];
        }

        return $decoded;
    }

    /**
     * Call Groq chat completions.
     */
    private function groqChat(
        array $messages,
        bool $jsonResponse = false
    ): string {
        $apiKey = config('services.groq.key');

        $model = config(
            'services.groq.model',
            'openai/gpt-oss-120b'
        );

        if (!$apiKey) {
            throw new \RuntimeException(
                'Groq API key is not configured.'
            );
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
        ];

        if ($jsonResponse) {
            $payload['response_format'] = [
                'type' => 'json_object',
            ];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(60)
            ->post(
                'https://api.groq.com/openai/v1/chat/completions',
                $payload
            );

        if ($response->failed()) {
            report(new \RuntimeException(
                'Groq request failed: HTTP ' . $response->status()
            ));

            throw new \RuntimeException(
                'The AI provider request failed.'
            );
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException(
                'The AI provider returned an empty response.'
            );
        }

        return $content;
    }

    /**
     * GET /api/ai/conversations
     */
    public function conversations(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $conversations = AiConversation::where('user_id', $user->id)
            ->withCount('messages')
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $conversations,
        ]);
    }

     /**
        /**
       /**
     * GET /api/ai/conversations/{id}
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);

        $conversation = AiConversation::where('user_id', $user->id)
            ->with(['messages' => fn ($query) => $query->orderBy('id')])
            ->find($id);

        if (!$conversation) return response()->json(['status' => false, 'message' => 'Conversation not found.'], 404);

        // ============================================================
        // AUTOMATIC IMAGE REFRESH & JSON OBJECT CONVERSION
        // ============================================================
        foreach ($conversation->messages as $message) {
            if ($message->role === 'assistant') {
                try {
                    // Decode the stored string content into an array/object
                    $decoded = json_decode($message->content, true);
                    
                    // If it's valid JSON structure with details
                    if (is_array($decoded) && isset($decoded['details'])) {
                        
                        $needsUpdate = false;
                        $freshDetails = [];

                        // Loop through each trail/trip to refresh images if missing
                        foreach ($decoded['details'] as $detail) {
                            if (empty($detail['image_url'])) {
                                $needsUpdate = true;
                                
                                $isTrip = isset($detail['start_date']) || isset($detail['pace']);
                                $itemId = $detail['id'] ?? null;

                                if ($itemId) {
                                    if ($isTrip) {
                                        $imgPath = DB::table('trip_media')
                                            ->where('trip_id', $itemId)
                                            ->where('type', 'image')
                                            ->pluck('url')
                                            ->first();
                                        
                                        $detail['image_url'] = $imgPath ? $this->makeMediaUrl($imgPath) : null;
                                    } else {
                                        $imgPath = DB::table('trail_media')
                                            ->where('trail_id', $itemId)
                                            ->where('type', 'image')
                                            ->pluck('url')
                                            ->first();
                                            
                                        $detail['image_url'] = $imgPath ? $this->makeMediaUrl($imgPath) : null;
                                    }
                                }
                            }
                            $freshDetails[] = $detail;
                        }

                        if ($needsUpdate) {
                            $decoded['details'] = $freshDetails;
                        }

                        // CRITICAL CHANGE: Assign the ARRAY directly to content
                        // Laravel will automatically serialize this as a JSON Object in the response
                        $message->content = $decoded;

                    } 
                    // Handle legacy plain text messages
                    elseif (is_string($message->content)) {
                        $message->content = [
                            'answer' => $message->content,
                            'records_found' => 0,
                            'details' => []
                        ];
                    }
                } catch (\Exception $e) {
                    // If parsing fails, leave content as is or set default structure
                    $message->content = [
                        'answer' => 'Error loading message.',
                        'records_found' => 0,
                        'details' => []
                    ];
                }
            }
        }

        return response()->json(['status' => true, 'data' => $conversation]);
    }

    /**
     * DELETE /api/ai/conversations/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $conversation = AiConversation::where('user_id', $user->id)
            ->find($id);

        if (!$conversation) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        AiMessage::where(
            'conversation_id',
            $conversation->id
        )->delete();

        $conversation->delete();

        return response()->json([
            'status' => true,
            'message' => 'Conversation deleted successfully.',
        ]);
    }
}