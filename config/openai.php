<?php
// Fetch the OpenAI API Key from the environment variable
$openai_api_key = getenv('OPENAI_API_KEY');

// Check if the API key is set properly
if (!$openai_api_key) {
    die("Error: OPENAI_API_KEY is not set.");
}

// Your code to interact with OpenAI API using the $openai_api_key