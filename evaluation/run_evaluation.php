<?php
declare(strict_types=1);

const TOP_K = 5;

$baseUrl = 'http://127.0.0.1:8080/api.php';
$requireAi = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--require-ai') {
        $requireAi = true;
    } elseif (str_starts_with($argument, '--url=')) {
        $baseUrl = substr($argument, strlen('--url='));
    } else {
        fwrite(STDERR, "Usage: php evaluation/run_evaluation.php [--url=http://127.0.0.1:8080/api.php] [--require-ai]\n");
        exit(2);
    }
}

$casesFile = __DIR__ . '/test_queries.json';
$cases = json_decode((string)file_get_contents($casesFile), true);
if (!is_array($cases) || !$cases) {
    fwrite(STDERR, "Evaluation dataset is missing or invalid: {$casesFile}\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP cURL extension is required to call the app API.\n");
    exit(2);
}

$latencies = [];
$precisionScores = [];
$constraintPasses = 0;
$aiReadyPasses = 0;
$requestFailures = 0;

echo "Davao Food Finder evaluation\n";
echo "API: {$baseUrl}\n";
echo 'Cases: ' . count($cases) . ' | Precision@' . TOP_K . " uses local dataset results\n\n";

foreach ($cases as $index => $case) {
    $query = trim((string)($case['query'] ?? ''));
    $expectedNames = $case['expected_relevant_names'] ?? null;
    if ($query === '' || !is_array($expectedNames) || !$expectedNames) {
        fwrite(STDERR, 'Invalid evaluation case at index ' . $index . ".\n");
        exit(2);
    }

    $payload = json_encode(
        ['query' => $query, 'history' => [], 'shownNames' => []],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $curl = curl_init($baseUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120
    ]);

    $startedAt = hrtime(true);
    $rawResponse = curl_exec($curl);
    $latency = (hrtime(true) - $startedAt) / 1_000_000_000;
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $latencies[] = $latency;

    if (!is_string($rawResponse) || $httpStatus < 200 || $httpStatus >= 300) {
        $requestFailures++;
        echo sprintf(
            "[%d/%d] FAIL request | %s | %.2f s | %s\n",
            $index + 1,
            count($cases),
            $query,
            $latency,
            $curlError !== '' ? $curlError : "HTTP {$httpStatus}: {$rawResponse}"
        );
        continue;
    }

    $response = json_decode($rawResponse, true);
    if (!is_array($response)) {
        $requestFailures++;
        echo sprintf("[%d/%d] FAIL invalid JSON | %s | %.2f s\n", $index + 1, count($cases), $query, $latency);
        continue;
    }

    $localResults = $response['results'] ?? [];
    $webResults = $response['webResults'] ?? [];
    $returnedNames = [];
    foreach (array_slice(is_array($localResults) ? $localResults : [], 0, TOP_K) as $place) {
        if (is_array($place)) $returnedNames[] = normalizeName((string)($place['name'] ?? ''));
    }
    $expected = array_fill_keys(array_map('normalizeName', $expectedNames), true);
    $hits = count(array_filter($returnedNames, fn($name) => $name !== '' && isset($expected[$name])));
    $precision = $hits / TOP_K;
    $precisionScores[] = $precision;

    $constraintsPass = responseAdheres($response);
    if ($constraintsPass) $constraintPasses++;
    if (($response['assistantStatus'] ?? '') === 'ready') $aiReadyPasses++;
    $constraintLabel = $constraintsPass ? 'PASS' : 'FAIL';

    echo sprintf(
        "[%d/%d] %s | %s | P@%d %.2f (%d/%d) | %.2f s | AI %s\n",
        $index + 1,
        count($cases),
        $constraintLabel,
        $query,
        TOP_K,
        $precision,
        $hits,
        TOP_K,
        $latency,
        (string)($response['assistantStatus'] ?? 'missing')
    );
}

if (!$latencies) {
    fwrite(STDERR, "No evaluation requests completed.\n");
    exit(1);
}

$successful = count($precisionScores);
$averagePrecision = $successful ? array_sum($precisionScores) / $successful : 0.0;
$averageLatency = array_sum($latencies) / count($latencies);
$sortedLatencies = $latencies;
sort($sortedLatencies, SORT_NUMERIC);
$medianLatency = $sortedLatencies[(int)floor((count($sortedLatencies) - 1) / 2)];
$constraintRate = $successful ? $constraintPasses / $successful : 0.0;

echo "\n--- SUMMARY ---\n";
echo sprintf("Completed requests: %d/%d\n", $successful, count($cases));
echo sprintf("Mean Precision@%d (local results): %.2f%%\n", TOP_K, $averagePrecision * 100);
echo sprintf("Constraint adherence: %.2f%% (%d/%d)\n", $constraintRate * 100, $constraintPasses, $successful);
echo sprintf("Successful AI responses: %d/%d\n", $aiReadyPasses, $successful);
echo sprintf("Mean end-to-end latency: %.2f s\n", $averageLatency);
echo sprintf("Median end-to-end latency: %.2f s\n", $medianLatency);
echo sprintf("Request failures: %d\n", $requestFailures);

exit(
    $requestFailures === 0
    && $successful === count($cases)
    && $constraintPasses === $successful
    && (!$requireAi || $aiReadyPasses === $successful)
        ? 0
        : 1
);

function normalizeName(string $name): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
}

function responseAdheres(array $response): bool {
    if (!is_string($response['answer'] ?? null)
        || !in_array($response['assistantStatus'] ?? null, ['ready', 'not_configured', 'error'], true)
        || !is_bool($response['outOfScope'] ?? null)
        || !is_array($response['results'] ?? null)
        || !is_array($response['webResults'] ?? null)
        || !is_int($response['count'] ?? null)
        || $response['count'] !== count($response['results'])
        || count($response['results']) > 20
        || count($response['webResults']) > 8
        || preg_match('~(?:https?://|www\.)~i', $response['answer']) === 1) {
        return false;
    }

    foreach (array_merge($response['results'], $response['webResults']) as $place) {
        if (!is_array($place)
            || trim((string)($place['name'] ?? '')) === ''
            || preg_match('/\bdavao\s+city\b/i', (string)($place['city'] ?? '')) !== 1) {
            return false;
        }
    }

    return true;
}
