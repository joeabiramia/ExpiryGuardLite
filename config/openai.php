<?php
// Fetch the OpenAI API Key from the environment variable
$openaiApiKey = getenv('OPENAI_API_KEY');

// Check if the API key is set properly
if (!$openaiApiKey) {
    die("Error: OPENAI_API_KEY is not set.");
}

// Your code to interact with OpenAI API using the $openaiApiKey