<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require '/app/vendor/autoload.php';

/**
 * @return void
 */
function main(): void
{
    $pullRequestId = getPrNumber();
    $repoFullName = getenv('GITHUB_REPOSITORY') ?: '';

    if (hasLabel($pullRequestId, $repoFullName, getenv('INPUT_GITHUB-TOKEN'), 'ai')) {
        $model = getenv('INPUT_OPENAI-MODEL') ?: 'gpt-3.5-turbo';

        if (!in_array($model, ['gpt-4', 'gpt-4-32k', 'gpt-3.5-turbo'])) {
            echo "::error::Invalid model specified. Please use either 'gpt-3.5-turbo', 'gpt-4' or 'gpt-4-32k'." .
                PHP_EOL;
            exit(1);
        }

        $prChanges = fetchPrChanges($pullRequestId, $repoFullName, getenv('INPUT_GITHUB-TOKEN'));
        $suggestions = fetchAiSuggestions($prChanges, getenv('INPUT_OPENAI-API-KEY'), $model);

        postCommentToPr($suggestions, $pullRequestId, $repoFullName, getenv('INPUT_GITHUB-TOKEN'));
    }
}

main();

/**
 * Gets the pull request number from the GitHub event payload.
 *
 * @return string The pull request number
 */
function getPrNumber(): string
{
    $githubEventPath = getenv('GITHUB_EVENT_PATH');
    $eventData = json_decode(file_get_contents($githubEventPath), true);

    if (isset($eventData['pull_request']['number'])) {
        return (string)$eventData['pull_request']['number'];
    } else {
        echo "::error::Pull request number not found in event data." . PHP_EOL;
        exit(1);
    }
}

/**
 * Checks if a pull request has a given label.
 *
 * @param string $pullRequestId The ID of the pull request
 * @param string $repoFullName The full name of the repo (owner/repo)
 * @param string $githubToken A GitHub API token
 * @param string $targetLabel The name of the label to check for
 *
 * @return bool True if the label exists on the PR, false otherwise
 */
function hasLabel(string $pullRequestId, string $repoFullName, string $githubToken, string $targetLabel): bool
{
    $client = new Client([
        'base_uri' => getenv('INPUT_GITHUB-API-BASE-URL'),
        'headers' => [
            'Authorization' => 'Bearer ' . $githubToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/vnd.github.v3+json',
        ],
    ]);

    // Fetch the pull request
    try {
        $response = $client->get("/repos/$repoFullName/pulls/$pullRequestId");
    } catch (GuzzleException $e) {
        echo "::error::Error fetching pull request: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }

    // Decode the JSON response
    $prData = json_decode($response->getBody()->getContents(), true);

    // Check for any labels
    if (empty($prData['labels'])) {
        return false;
    }

    // Check each label to see if it matches the target
    foreach ($prData['labels'] as $label) {
        if ($label['name'] === $targetLabel) {
            return true;
        }
    }

    return false;
}

/**
 * Fetches the changes made in a pull request.
 *
 * @param string $pullRequestId The ID of the pull request
 * @param string $repoFullName The full name of the repo
 * @param string $githubToken A GitHub API token
 *
 * @return string The pull request changes
 */
function fetchPrChanges(string $pullRequestId, string $repoFullName, string $githubToken): string
{
    // The GitHub API endpoint to get the details of a pull request, including the files changed
    $apiEndpoint = getenv('INPUT_GITHUB-API-BASE-URL') . "/repos/{$repoFullName}/pulls/{$pullRequestId}/files";

    $client = new Client();

    try {
        $response = $client->request('GET', $apiEndpoint, [
            'headers' => [
                'Authorization' => "Bearer {$githubToken}",
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);

        $filesChanged = json_decode($response->getBody()->getContents(), true);

        // Collect all the changes in the pull request
        $changes = '';
        foreach ($filesChanged as $file) {
            $changes .= "File: {$file['filename']}\nChanges:\n{$file['patch']}\n";
        }

        return $changes;
    } catch (GuzzleException $e) {
        echo "::error::Error fetching changes for PR {$pullRequestId}: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

/**
 * Gets AI suggestions for the given pull request changes.
 *
 * @param string $prChanges The changes in markdown format
 * @param string $openAiApiKey The OpenAI API key
 * @param string $model The AI model to use
 *
 * @return string The generated suggestions
 */
function fetchAiSuggestions(string $prChanges, string $openAiApiKey, string $model): string
{
    $prompt = generatePrompt($prChanges);

    $input_data = [
        "temperature" => 0.7,
        "max_tokens" => 300,
        "frequency_penalty" => 0,
        'model' => $model,
        "messages" => [
            [
                'role' => 'user',
                'content' => $prompt
            ],
        ]
    ];

    try {
        $client = new Client([
            'base_uri' => 'https://api.openai.com',
            'headers' => [
                'Authorization' => 'Bearer ' . $openAiApiKey,
                'Content-Type' => 'application/json'
            ]
        ]);

        $response = $client->post('/v1/chat/completions', [
            'json' => $input_data
        ]);

        $complete = json_decode($response->getBody()->getContents(), true);

        return $complete['choices'][0]['message']['content'];

    } catch (GuzzleException $e) {
        echo "::error::Error fetching AI suggestions: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

/**
 * Generates the prompt to send to the AI.
 *
 * @param string $prChanges The pull request changes
 *
 * @return string The prompt text
 */
function generatePrompt(string $prChanges): string
{
    $prompt = "As a valued member of our code review team, ";
    $prompt .= "we'd like you to review the following changes made in a pull request. ";
    $prompt .= "Please provide us with insightful advice to enhance the quality, maintainability, and readability of our code. ";
    $prompt .= "Keep in mind that we're not looking for comments about deleted files ";
    $prompt .= "or demands for additional comments and documentation in the code.\n\n";

    $prompt .= "Here are the pull request changes:\n";
    $prompt .= "```\n";
    $prompt .= "$prChanges";
    $prompt .= "```\n\n";

    $prompt .= "We need your input in two parts:\n\n";
    $prompt .= "1. Score the changes from 0 (worst) to 100 (best) based on your assessment.\n";
    $prompt .= "2. Provide a short list of improvements. If code changes are needed, ";
    $prompt .= "include them in your response. Remember to use markdown to format the code blocks appropriately.\n\n";

    $prompt .= "Please format your response as follows:\n";
    $prompt .= "**Score**: [Your score here and an emoji]\n\n";
    $prompt .= "**AI Suggested Improvements:**\n";
    $prompt .= "1. [First improvement suggestion]\n";
    $prompt .= "2. [Second improvement suggestion]\n";
    $prompt .= "...";

    return $prompt;
}


/**
 * Posts the AI suggestions as a comment on the PR.
 *
 * @param string $comment The comment body
 * @param string $pullRequestId The PR ID
 * @param string $repoFullName The full repo name
 * @param string $githubToken A GitHub API token
 */
function postCommentToPr(string $comment, string $pullRequestId, string $repoFullName, string $githubToken): void
{
    $apiEndpoint = getenv('INPUT_GITHUB-API-BASE-URL') . "/repos/{$repoFullName}/issues/{$pullRequestId}/comments";

    $client = new Client();

    try {
        $response = $client->request('POST', $apiEndpoint, [
            'headers' => [
                'Authorization' => "Bearer {$githubToken}",
                'Accept' => 'application/vnd.github.v3+json',
            ],
            'json' => [
                'body' => $comment,
            ],
        ]);

        // Check if the comment was successfully posted
        if ($response->getStatusCode() == 201) {
            echo "::info::Successfully posted comment to PR {$pullRequestId}.\n";
        } else {
            echo "::error::Failed to post comment to PR {$pullRequestId}. Status code: {$response->getStatusCode()}\n";
        }

    } catch (GuzzleException $e) {
        echo "::error::Error posting comment to PR {$pullRequestId}: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

