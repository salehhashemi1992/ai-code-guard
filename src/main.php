<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require '/app/vendor/autoload.php';

function main(): void
{
    $pullRequestId = getPrNumber();
    $repoFullName = getenv('GITHUB_REPOSITORY') ?: '';

    if (hasLabel($pullRequestId, $repoFullName, getenv('GITHUB_TOKEN'), 'ai')) {
        $model = getenv('OPENAI_MODEL') ?: 'gpt-3.5-turbo'; // Default to gpt-3.5-turbo if no environment variable is set

        if (!in_array($model, ['gpt-4', 'gpt-4-32k', 'gpt-3.5-turbo'])) {
            echo "::error::Invalid model specified. Please use either 'gpt-3.5-turbo', 'gpt-4' or 'gpt-4-32k'." .
                PHP_EOL;
            exit(1);
        }

        $prChanges = fetchPrChanges($pullRequestId, $repoFullName, getenv('GITHUB_TOKEN'));
        $suggestions = fetchAiSuggestions($prChanges, getenv('OPENAI_API_KEY'), $model);

        postCommentToPr($suggestions, $pullRequestId, $repoFullName, getenv('GITHUB_TOKEN'));
    }
}

main();

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

function hasLabel(string $pullRequestId, string $repoFullName, string $githubToken, string $targetLabel): bool
{
    $client = new Client([
        'base_uri' => 'https://api.github.com',
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


function fetchPrChanges(string $pullRequestId, string $repoFullName, string $githubToken): string
{
    // The GitHub API endpoint to get the details of a pull request, including the files changed
    $apiEndpoint = "https://api.github.com/repos/{$repoFullName}/pulls/{$pullRequestId}/files";

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

function generatePrompt(string $prChanges): string
{
    return "Please review the following changes made in a pull request and suggest improvements:
     \nPull request changes:
     \n{$prChanges}
     \nFormat your response as follows:
     \nSuggested improvements: [Generated suggestions]";
}

function postCommentToPr(string $comment, string $pullRequestId, string $repoFullName, string $githubToken): void
{
    $apiEndpoint = "https://api.github.com/repos/{$repoFullName}/issues/{$pullRequestId}/comments";

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

