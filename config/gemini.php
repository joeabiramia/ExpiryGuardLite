<?php
// Fetch the Gemini API Key from the environment variable
$geminiApiKey = getenv('GEMINI_API_KEY');

// Check if the API key is set properly
if (!$geminiApiKey) {
    die(json_encode(['error' => 'GEMINI_API_KEY is not set.']));
}
