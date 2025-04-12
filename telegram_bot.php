<?php
// ===== CONFIG ===== //
$BOT_TOKEN = TELEGRAM_BOT_TOKEN;
$OPENAI_KEY = OPEN_AI_API_KEY;
$STABILITY_KEY = STABILITY_API_KEY; // for image gen
$ADMIN_ID = TELEGRAM_ID; // To verify admin commands
$API_URL = "https://api.telegram.org/bot$BOT_TOKEN/";

$questions = json_decode(file_get_contents('questions.json'), true);
$tips = json_decode(file_get_contents('tips.json'), true);
$scores = file_exists('scores.json') ? json_decode(file_get_contents('scores.json'), true) : [];

$offset = 0;

while (true) {
    $updates = json_decode(file_get_contents($API_URL . "getUpdates?offset=$offset&timeout=30"), true);

    if (!empty($updates["result"])) {
        foreach ($updates["result"] as $update) {
            $offset = $update["update_id"] + 1;

            $chat_id = $update["message"]["chat"]["id"];
            $user_id = $update["message"]["from"]["id"];
            $message_text = $update["message"]["text"] ?? '';
            $voice_note = $update["message"]["voice"] ?? null;
            $document = $update["message"]["document"] ?? null;

            if (isset($scores[$chat_id]['last_msg_time']) && (time() - $scores[$chat_id]['last_msg_time'] < 3)) {
                sendMessage($chat_id, "⏳ Please wait before sending another command.");
                continue;
            }
            $scores[$chat_id]['last_msg_time'] = time();

            switch (true) {
                case strtolower($message_text) === "/start":
                    sendMessage($chat_id, "🤖 *Welcome to CyberSec Bot!* \n\nAvailable commands:\n/quiz - Start a quiz\n/tip - Get a tip\n/ask - Chat with AI\n/image - Generate art\n/voice - Send a voice note\n/score - Check your rank");
                    break;

                case strtolower($message_text) === "/quiz":
                    $q = $questions[array_rand($questions)];
                    $scores[$chat_id]['current_question'] = $q;
                    $options = implode("\n", array_map(fn($opt, $idx) => chr(65 + $idx) . ") $opt", $q['options'], array_keys($q['options'])));
                    sendMessage($chat_id, "🧠 *Quiz:* {$q['question']}\n\n{$options}\n\nReply with A, B, C, or D");
                    break;

                case strtolower($message_text) === "/ask":
                    sendMessage($chat_id, "💬 Ask me anything! Type your question after /ask.");
                    break;
                case strpos(strtolower($message_text), "/ask") === 0:
                    $prompt = trim(substr($message_text, 4));
                    $ai_response = askOpenAI($prompt);
                    sendMessage($chat_id, "🤖 *AI:* {$ai_response}");
                    break;

                case strtolower($message_text) === "/image":
                    sendMessage($chat_id, "🎨 Describe the image you want (e.g., `/image cyberpunk city`)");
                    break;
                case strpos(strtolower($message_text), "/image") === 0:
                    $prompt = trim(substr($message_text, 6));
                    $image_url = generateImage($prompt);
                    sendPhoto($chat_id, $image_url);
                    break;

                case $voice_note:
                    $voice_text = transcribeVoiceNote($voice_note['file_id']);
                    sendMessage($chat_id, "🔊 *You said:* {$voice_text}\n\n_Replying with AI..._");
                    $ai_response = askOpenAI($voice_text);
                    sendMessage($chat_id, "🤖 *AI Response:* {$ai_response}");
                    break;

                case $document:
                    $file_url = getFileUrl($document['file_id']);
                    sendMessage($chat_id, "📄 *File received!*\nDownload: {$file_url}");
                    break;

                case $user_id == $ADMIN_ID && strpos($message_text, "/broadcast") === 0:
                    $message = trim(substr($message_text, 10));
                    foreach ($scores as $cid => $data) {
                        sendMessage($cid, "📢 *Admin Broadcast:*\n{$message}");
                    }
                    break;

                default:
                    if (strlen($message_text) > 3) {
                        $ai_response = askOpenAI($message_text);
                        sendMessage($chat_id, "🤖 *AI:* {$ai_response}");
                    }
            }

            file_put_contents('scores.json', json_encode($scores));
        }
    }

    sleep(1); // Be polite to Telegram servers
}

// ===== HELPER FUNCTIONS ===== //
function sendMessage($chat_id, $text) {
    global $API_URL;
    $text = urlencode($text);
    file_get_contents("{$API_URL}sendMessage?chat_id={$chat_id}&text={$text}&parse_mode=Markdown");
}

function sendPhoto($chat_id, $photo_url) {
    global $API_URL;
    file_get_contents("{$API_URL}sendPhoto?chat_id={$chat_id}&photo=" . urlencode($photo_url));
}

function askOpenAI($prompt) {
    global $OPENAI_KEY;
    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [["role" => "user", "content" => $prompt]]
    ];
    $response = file_get_contents(
        "https://api.openai.com/v1/chat/completions",
        false,
        stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/json\r\nAuthorization: Bearer {$OPENAI_KEY}\r\n",
                "content" => json_encode($data)
            ]
        ])
    );
    return json_decode($response)->choices[0]->message->content ?? "No response.";
}

function transcribeVoiceNote($file_id) {
    global $BOT_TOKEN, $OPENAI_KEY;
    $file_info = json_decode(file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/getFile?file_id={$file_id}"), true);
    $file_path = $file_info['result']['file_path'];
    $file_url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/{$file_path}";
    $audio_content = file_get_contents($file_url);
    file_put_contents("voice.ogg", $audio_content);

    shell_exec("ffmpeg -y -i voice.ogg voice.mp3"); // requires ffmpeg in Termux

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/audio/transcriptions");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$OPENAI_KEY}"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        "file" => new CURLFile("voice.mp3"),
        "model" => "whisper-1"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = json_decode(curl_exec($ch));
    return $result->text ?? "Could not transcribe.";
}

function getFileUrl($file_id) {
    global $BOT_TOKEN;
    $file_info = json_decode(file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/getFile?file_id={$file_id}"), true);
    return "https://api.telegram.org/file/bot{$BOT_TOKEN}/" . $file_info['result']['file_path'];
}

function generateImage($prompt) {
    global $STABILITY_KEY;
    $data = [
        "text_prompts" => [["text" => $prompt]],
        "cfg_scale" => 7,
        "clip_guidance_preset" => "FAST_BLUE",
        "height" => 512,
        "width" => 512,
        "samples" => 1,
        "steps" => 30
    ];

    $response = file_get_contents(
        "https://api.stability.ai/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image",
        false,
        stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/json\r\nAuthorization: Bearer {$STABILITY_KEY}\r\nAccept: application/json",
                "content" => json_encode($data)
            ]
        ])
    );

    $json = json_decode($response);
    if (isset($json->artifacts[0]->base64)) {
        file_put_contents("image.png", base64_decode($json->artifacts[0]->base64));
        return "https://yourdomain.com/image.png"; // You must upload the image somewhere accessible
    }
    return "Image generation failed.";
}
