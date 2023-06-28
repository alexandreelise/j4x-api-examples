<?php
declare(strict_types=1);
ini_set('error_reporting', E_ALL & ~E_DEPRECATED);
ini_set('auto_detect_line_endings', true);
/**
 *                              .::::ANNIVERSARY EDITION::::.
 *
 * Add or Edit Joomla! Articles to multiple Joomla Sites Via API Using Streamed CSV
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 * - Requires PHP 8.1 minimum. Now uses PHP Fibers.
 *
 * This is the last version of the script. Future development will shift focus on the new Joomla Console script.
 * Will develop future version using a Joomla Console Custom Plugin. Crafted specially for CLI-based interaction.
 *
 * @author        Mr Alexandre J-S William ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Mr Alexandre J-S William ELISÉ. All rights reserved.
 * @license GNU Affero General Public License version 3 (AGPLv3)
 * @link          https://apiadept.com
 */

$asciiBanner = <<<TEXT
    __  __     ____         _____                              __                      __              
   / / / ___  / / ____     / ___/__  ______  ___  _____       / ____  ____  ____ ___  / ___  __________
  / /_/ / _ \/ / / __ \    \__ \/ / / / __ \/ _ \/ ___/  __  / / __ \/ __ \/ __ `__ \/ / _ \/ ___/ ___/
 / __  /  __/ / / /_/ /   ___/ / /_/ / /_/ /  __/ /     / /_/ / /_/ / /_/ / / / / / / /  __/ /  (__  ) 
/_/ /_/\___/_/_/\____/   /____/\__,_/ .___/\___/_/      \____/\____/\____/_/ /_/ /_/_/\___/_/  /____/  
                                   /_/                                                                 
TEXT;
// Wether or not to show ASCII banner true to show , false otherwise. Default is to show the ASCII art banner
$showAsciiBanner = true;

// Public url of the sample csv used in this example (CHANGE WITH YOUR OWN CSV URL OR LOCAL CSV FILE)
$isLocal = false;

// IF THIS URL DOES NOT EXIST IT WILL CRASH THE SCRIPT. CHANGE THIS TO YOUR OWN URL
$csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTWXek__Kmw4ala5mT5abNBuYonY4XGIsLMJ3zTCEt8d1j3ddsOS686iaszsNuoHBqgqcoKZbSEEzdk/pub?gid=1334814103&single=true&output=csv'; // For example: https://example.org/sample-data.csv';
if ($isLocal) {
    $localCsvFile = __DIR__ . '/sample-data.csv';
    if (is_readable($localCsvFile)) {
        $csvUrl = $localCsvFile;
    }
}

// Your Joomla! 4.x website base url
$baseUrl = [
    'app-001' => 'https://app-001.example.org',
    'app-002' => 'https://app-002.example.org',
    'app-003' => 'https://app-003.example.org',
];
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token = [
    'app-001' => '',
    'app-002' => '',
    'app-003' => '',
];
$basePath = 'api/index.php/v1';

// Request timeout
$timeout = 3; // Shorter request timeout. Usually it won't take more than 1 sec to connect. Otherwise, you might have a bigger problem to tackle.

// Add custom fields support (shout-out to Marc DECHÈVRE : CUSTOM KING)
// The keys are the columns in the csv with the custom fields names (that's how Joomla! Web Services Api work as of today)
// For the custom fields to work they need to be added in the csv and to exists in the Joomla! site.
$customFieldKeys = [];

// Silent mode
// 0: hide both response result and key value pairs
// 1: show response result only
// 2: show key value pairs only
// Set to 0 if you want to squeeze out performance of this script to the maximum
$silent = 1;

// Line numbers we want in any order (e.g 9,7-7,2-4,10,17-14,21). Leave empty '' to process all lines (beginning at line 2. Same as csv file)
$whatLineNumbersYouWant = '';

defined('IS_CLI') || define('IS_CLI', PHP_SAPI == 'cli' ? true : false);
defined('CUSTOM_LINE_END') || define('CUSTOM_LINE_END', PHP_SAPI == 'cli' ? PHP_EOL : '<br>');
defined('ANSI_COLOR_RED') || define('ANSI_COLOR_RED', PHP_SAPI == 'cli' ? "\033[31m" : '');
defined('ANSI_COLOR_GREEN') || define('ANSI_COLOR_GREEN', PHP_SAPI == 'cli' ? "\033[32m" : '');
defined('ANSI_COLOR_BLUE') || define('ANSI_COLOR_BLUE', PHP_SAPI == 'cli' ? "\033[34m" : '');
defined('ANSI_COLOR_NORMAL') || define('ANSI_COLOR_NORMAL', PHP_SAPI == 'cli' ? "\033[0m" : '');

defined('CSV_SEPARATOR') || define('CSV_SEPARATOR', "\x2C");
defined('CSV_ENCLOSURE') || define('CSV_ENCLOSURE', "\x22");
defined('CSV_ESCAPE') || define('CSV_ESCAPE', "\x22");
defined('CSV_ENDING') || define('CSV_ENDING', "\x0D\x0A");

//Csv starts at line number : 2
defined('CSV_START') || define('CSV_START', 2);

// This MUST be a json file otherwise it might fail
defined('CSV_PROCESSING_REPORT_FILEPATH') || define('CSV_PROCESSING_REPORT_FILEPATH', __DIR__ . '/output.json');

// Do you want a report after processing?
// 0: no report, 1: success & errors, 2: errors only
// When using report feature. Silent mode MUST be set to 1. Otherwise you might have unexpected results.
// Set to 0 if you want to squeeze out performance of this script to the maximum
// If enabled, this will create a output.json file
$saveReportToFile = 0;

// Show the ASCII Art banner or not
$enviromentAwareDisplay = (IS_CLI ? $asciiBanner : sprintf('<pre>%s</pre>', $asciiBanner));

$failedCsvLines = [];
$successfulCsvLines = [];
$isDone = false;

$enqueueMessage = function (string $message, string $type = 'message'): void {
    // Ignore empty messages
    if (empty($message)) {
        return;
    }
    echo $message;
};

$enqueueMessage($showAsciiBanner ? sprintf('%s %s %s%s', ANSI_COLOR_BLUE, $enviromentAwareDisplay, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '');

$computedLineNumbers = function (string $wantedLineNumbers = '') {
    // When strictly empty process every Csv lines (Full range)
    if ($wantedLineNumbers === '') {
        return [];
    }

    // Cut-off useless processing when single digit range
    if (strlen($wantedLineNumbers) === 1) {
        return (((int)$wantedLineNumbers) < CSV_START) ? [CSV_START] : [((int)$wantedLineNumbers)];
    }

    $commaParts = explode(',', $wantedLineNumbers);
    if (empty($commaParts)) {
        return [];
    }
    sort($commaParts, SORT_NATURAL);
    $output = [];
    foreach ($commaParts as $commaPart) {
        if (!str_contains($commaPart, '-')) {
            // First line is the header, so we MUST start at least at line 2. Hence, 2 or more
            $result1 = ((int)$commaPart) > 1 ? ((int)$commaPart) : CSV_START;
            // Makes it unique in output array
            if (!in_array($result1, $output, true)) {
                $output[] = $result1;
            }
            // Skip to next comma part
            continue;
        }
        // maximum 1 dash "group" per comma separated "groups"
        $dashParts = explode('-', $commaPart, 2);
        if (empty($dashParts)) {
            // First line is the header, so we MUST start at least at line 2. Hence, 2 or more
            $result2 = ((int)$commaPart) > 1 ? ((int)$commaPart) : CSV_START;
            if (!in_array($result2, $output, true)) {
                $output[] = $result2;
            }
            // Skip to next comma part
            continue;
        }
        // First line is the header, so we MUST start at least at line 2. Hence, 2 or more
        $dashParts[0] = ((int)$dashParts[0]) > 1 ? ((int)$dashParts[0]) : CSV_START;

        // First line is the header, so we MUST start at least at line 2. Hence, 2 or more
        $dashParts[1] = ((int)$dashParts[1]) > 1 ? ((int)$dashParts[1]) : CSV_START;

        // Only store one digit if both are the same in the range
        if (($dashParts[0] === $dashParts[1]) && (!in_array($dashParts[0], $output, true))) {
            $output[] = $dashParts[0];
        } elseif ($dashParts[0] > $dashParts[1]) {
            // Store expanded range of numbers
            $output = array_merge($output, range($dashParts[1], $dashParts[0]));
        } else {
            // Store expanded range of numbers
            $output = array_merge($output, range($dashParts[0], $dashParts[1]));
        }
    }
    // De-dupe and sort again at the end to tidy up everything
    $unique = array_unique($output);
    // For some reason out of my understanding sort feature in array_unique won't work as expected for me, so I do sort separately
    sort($unique, SORT_NATURAL | SORT_ASC);
    return $unique;
};

// This time we need endpoint to be a function to make it more dynamic
$endpoint = function (string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string {
    return $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
        : sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');
};

// handle nested json
$nested = function (array $arr, callable $enqueueMessageCallable, int $isSilent = 0): array {
    $handleComplexValues = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($arr), RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($iterator as $key => $value) {
        if (strpos($value, '{') === 0) {
            if ($isSilent == 2) {
                $enqueueMessageCallable(sprintf("%s item with key: %s with value: %s%s%s", ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END));
            }
            // Doesn't seem to make sense at first but this one line allows to show intro/fulltext images and urla,urlb,urlc
            $handleComplexValues[$key] = json_decode(str_replace(["\n", "\r", "\t"], '', trim($value)));
        } elseif (json_decode($value) === false) {
            $handleComplexValues[$key] = json_encode($value);
            if ($isSilent == 2) {
                $enqueueMessageCallable(sprintf("%s item with key: %s with value: %s%s%s", ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END));
            }
        } else {
            $handleComplexValues[$key] = $value;
            if ($isSilent == 2) {
                $enqueueMessageCallable(sprintf("%s item with key: %s with value: %s%s%s", ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END));
            }
        }
    }

    return $handleComplexValues;
};

$csvReader = function (string $url, array $keys, callable $givenNested, callable $enqueueMessageCallable, int $isSilent = 1, array $lineRange = [], ?callable $handler = null, array &$failed = [], array &$successful = [], bool &$isFinished = false) {
    if (empty($url)) {
        throw new RuntimeException('Url MUST NOT be empty', 422);
    }

    $defaultKeys = [
        'id',
        'access',
        'title',
        'alias',
        'catid',
        'articletext',
        'introtext',
        'fulltext',
        'language',
        'metadesc',
        'metakey',
        'state',
        'featured',
        'images',
        'urls',
        'tokenindex',
    ];

    $mergedKeys = empty($keys) ? $defaultKeys : array_unique(array_merge($defaultKeys, $keys));

    // Assess robustness of the code by trying random key order
    //shuffle($mergedKeys);

    $resource = fopen($url, 'r');

    if ($resource === false) {
        throw new RuntimeException('Could not read csv file', 500);
    }

    try {
        stream_set_blocking($resource, false);

        $firstLine = stream_get_line(
            $resource,
            0,
            "\r\n"
        );

        if (!is_string($firstLine) || empty($firstLine)) {
            throw new RuntimeException('First line MUST NOT be empty. It is the header', 422);
        }

        $csvHeaderKeys = str_getcsv($firstLine);
        $commonKeys = array_intersect($csvHeaderKeys, $mergedKeys);
        $currentCsvLineNumber = 1;
        $isExpanded = ($lineRange !== []);

        if ($isExpanded) {
            if (count($lineRange) === 1) {
                $minLineNumber = $lineRange[0];
                $maxLineNumber = $lineRange[0];
            } else {
                // Rather than starting from 1 which is not that efficient, start from minimum value in CSV line range
                $minLineNumber = min($lineRange);
                $maxLineNumber = max($lineRange);
            }
        }

        while (!$isFinished && !feof($resource)) {
            $currentLine = stream_get_line(
                $resource,
                0,
                "\r\n"
            );
            if (!is_string($currentLine) || empty($currentLine)) {
                continue;
            }
            // Again, for a more efficient algorithm. Do not do unecessary processing, unless we have to.
            $isEdgeCaseSingleLineInRange = ($isExpanded && (count($lineRange) === 1));
            if (!$isExpanded || ($isExpanded && count($lineRange) > 1) || $isEdgeCaseSingleLineInRange) {
                $currentCsvLineNumber += 1;

                if ($isEdgeCaseSingleLineInRange && ($currentCsvLineNumber < $minLineNumber)) {
                    continue; // Continue until we reach the line we want
                }
            }

            $extractedContent = str_getcsv($currentLine, CSV_SEPARATOR, CSV_ENCLOSURE, CSV_ESCAPE);

            // Skip empty lines
            if (empty($extractedContent)) {
                continue;
            }

            // Allow using csv keys in any order
            $commonValues = array_intersect_key($extractedContent, $commonKeys);

            // Skip invalid lines
            if (empty($commonValues)) {
                continue;
            }

            // Iteration on leafs AND nodes
            $handleComplexValues = $givenNested($commonValues, $enqueueMessageCallable, $isSilent);

            try {
                $encodedContent = json_encode(array_combine($commonKeys, $handleComplexValues), JSON_THROW_ON_ERROR);

                // Stop processing immediately if it goes beyond range
                if (($isExpanded && count($lineRange) > 1) && ($currentCsvLineNumber > $maxLineNumber)) {
                    $isFinished = true;
                    throw new DomainException(sprintf('Processing of CSV file done. Last line processed was line %d', $currentCsvLineNumber), 200);
                }

                if ($encodedContent === false) {
                    throw new RuntimeException('Current line seem to be invalid', 422);
                } elseif (!$isFinished && ((is_string($encodedContent) && (($isExpanded && in_array($currentCsvLineNumber, $lineRange, true)) || !$isExpanded)) && is_callable($handler))) {
                    $handler(['line' => $currentCsvLineNumber, 'content' => $encodedContent]);

                    // Only 1 element in range. Don't do useless processing after first round.
                    if ($isExpanded && (count($lineRange) === 1 && ($currentCsvLineNumber === $maxLineNumber))) {
                        $isFinished = true;
                        throw new DomainException(sprintf('Processing of CSV file done. Last line processed was line %d', $currentCsvLineNumber), 200);
                    }
                }
            } catch (DomainException $domainException) {
                $successful[$currentCsvLineNumber] = $domainException->getMessage();
                throw $domainException;
            } catch (Throwable $encodeContentException) {
                $failed[$currentCsvLineNumber] = ['error' => $encodeContentException->getMessage(), 'error_line' => $encodeContentException->getLine()]; // Store failed CSV line numbers for end report.
                continue; // Ignore failed CSV lines
            }

        }
    } catch (DomainException $domainException) {
        if (isset($resource) && is_resource($resource)) {
            fclose($resource);
        }
        throw $domainException;
    } catch (Throwable $e) {
        if ($isSilent == 1) {
            $enqueueMessageCallable(sprintf("%s Error message: %s, Error code line: %d, Error CSV Line: %d%s%s", ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), $currentCsvLineNumber, ANSI_COLOR_NORMAL, CUSTOM_LINE_END), 'error');
        }
        if (isset($resource) && is_resource($resource)) {
            fclose($resource);
        }
        throw $e;
    } finally {
        if (isset($resource) && is_resource($resource)) {
            fclose($resource);
        }
    }


};

// Process data returned by the PHP Generator
$process = function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, CurlHandle $transport) {
    curl_setopt_array($transport, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_CUSTOMREQUEST => $givenHttpVerb,
            CURLOPT_POSTFIELDS => $dataString,
            CURLOPT_HTTPHEADER => $headers,
        ]
    );

    $response = curl_exec($transport);
    // Continue even on partial failure
    if (empty($response)) {
        throw new RuntimeException('Empty output', 422);
    }

    return $response;
};

$cpuCounter = function (): int {
    static $cpus = 0;
    $cpuCount = 0;
    if (($cpuCount > 0) && ($cpuCount === $cpus)) {
        return $cpuCount;
    }
    $procCpuInfo = file_get_contents('/proc/cpuinfo');
    if (!$procCpuInfo) {
        throw new RuntimeException('Could not read /proc/cpuinfo', 422);
    }
    $cpuCount = (int)preg_match_all('/(processor)/', $procCpuInfo);
    $cpus = $cpuCount;
    return $cpuCount;
};

$expandedLineNumbers = $computedLineNumbers($whatLineNumbersYouWant);
$isExpanded = ($expandedLineNumbers !== []);
$storage = [];

// Compute number of CPUs to attempt parallel run of PHP Fibers
$currentCpuCount = $cpuCounter();
$pool = new SplFixedArray($currentCpuCount);
$rollingPoolIndex = 0;

$poolRetry = new SplFixedArray($currentCpuCount);
$rollingPoolIndexRetry = 0;

$combinedHttpResponse = [];
try {
    try {

        $csvReader($csvUrl, $customFieldKeys, $nested, $enqueueMessage, $silent, $expandedLineNumbers, function ($dataValue) use (&$storage, $endpoint, $baseUrl, $basePath, $token, $silent, $timeout, $process, $enqueueMessage, &$successfulCsvLines, $pool, $combinedHttpResponse, $rollingPoolIndex, $currentCpuCount, &$isDone) {

            if (empty($dataValue)) {
                return;
            }

            $dataCurrentCSVline = $dataValue['line'];
            $dataString = $dataValue['content'];

            $curl = curl_init();

            if (!is_string($dataString)) {
                return;
            }

            $decodedDataString = json_decode($dataString, false, 512, JSON_THROW_ON_ERROR);

            try {
                if (($decodedDataString === false) || (!isset($token[$decodedDataString->tokenindex]))
                ) {
                    return;
                }

                // HTTP request headers
                $headers = [
                    'Accept: application/vnd.api+json',
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($dataString),
                    sprintf('X-Joomla-Token: %s', trim($token[$decodedDataString->tokenindex])),
                ];

                // Article primary key. Usually 'id'
                $pk = (int)$decodedDataString->id;

                // Circular pool of PHP Fibers based on number of "cpus detected".
                if (!isset($pool[$rollingPoolIndex % $currentCpuCount]) || (!($pool[$rollingPoolIndex % $currentCpuCount] instanceof Fiber))) {
                    $pool[$rollingPoolIndex % $currentCpuCount] = new Fiber(function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, CurlHandle $transport) use ($process, $combinedHttpResponse, $dataCurrentCSVline): void {
                        $combinedHttpResponse[$dataCurrentCSVline] ??= $process($givenHttpVerb, $endpoint, $dataString, $headers, $timeout, $transport);
                        $args = Fiber::suspend($combinedHttpResponse[$dataCurrentCSVline]);
                        $combinedHttpResponse[$dataCurrentCSVline] ??= $process(...$args);
                    });

                    $combinedHttpResponse[$dataCurrentCSVline] ??= $pool[$rollingPoolIndex % $currentCpuCount]->start($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);
                } elseif ($pool[$rollingPoolIndex % $currentCpuCount]->isTerminated()) {
                    unset($pool[$rollingPoolIndex % $currentCpuCount]); // Recycle terminated PHP Fibers

                    $pool[$rollingPoolIndex % $currentCpuCount] = new Fiber(function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, CurlHandle $transport) use ($process, $combinedHttpResponse, $dataCurrentCSVline): void {
                        $combinedHttpResponse[$dataCurrentCSVline] ??= $process($givenHttpVerb, $endpoint, $dataString, $headers, $timeout, $transport);
                        $args = Fiber::suspend($combinedHttpResponse[$dataCurrentCSVline]);
                        $combinedHttpResponse[$dataCurrentCSVline] ??= $process(...$args);
                    });

                    $combinedHttpResponse[$dataCurrentCSVline] ??= $pool[$rollingPoolIndex % $currentCpuCount]->start($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);
                }

                while (!$pool[$rollingPoolIndex % $currentCpuCount]->isTerminated()) {
                    $pool[$rollingPoolIndex % $currentCpuCount]->resume([($pk ? 'PATCH' : 'POST'), $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl]);

                    $decodedJsonOutput = json_decode($combinedHttpResponse[$dataCurrentCSVline], false, 512, JSON_THROW_ON_ERROR);

                    // don't show errors, handle them gracefully
                    if (isset($decodedJsonOutput->errors) && !isset($storage[$dataCurrentCSVline])) {
                        // If article is potentially a duplicate (already exists with same alias)
                        $storage[$dataCurrentCSVline] = ['mightExists' => isset($decodedJsonOutput->errors[0]->code) && $decodedJsonOutput->errors[0]->code === 400, 'decodedDataString' => $decodedDataString,];
                    }
                    if (isset($decodedJsonOutput->data) && isset($decodedJsonOutput->data->attributes) && !isset($successfulCsvLines[$dataCurrentCSVline])) {
                        if ($silent == 1) {
                            $successfulCsvLines[$dataCurrentCSVline] = sprintf("%s Deployed to: %s, CSV Line: %d, id: %d, created: %s, title: %s, alias: %s%s%s",
                                ANSI_COLOR_GREEN,
                                $decodedDataString->tokenindex,
                                $dataCurrentCSVline,
                                $decodedJsonOutput->data->id,
                                $decodedJsonOutput->data->attributes->created,
                                $decodedJsonOutput->data->attributes->title,
                                $decodedJsonOutput->data->attributes->alias,
                                ANSI_COLOR_NORMAL, CUSTOM_LINE_END);

                            $enqueueMessage($successfulCsvLines[$dataCurrentCSVline]);
                        }
                    }
                    if ($isDone) {
                        break;
                    }
                }
                if (!$isDone) {
                    ++$rollingPoolIndex; // Important for circular pool of PHP Fibers
                }
            } catch (Throwable $e) {
                if ($silent == 1) {
                    $failedCsvLines[$dataCurrentCSVline] = sprintf("%s Error message: %s, Error code line: %d, Error CSV Line: %d%s%s", ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), $dataCurrentCSVline, ANSI_COLOR_NORMAL, CUSTOM_LINE_END);
                    $enqueueMessage($failedCsvLines[$dataCurrentCSVline], 'error');
                }

            } finally {
                if (isset($curl) && ($curl instanceof CurlHandle)) {
                    curl_close($curl);
                }
            }
        }, $failedCsvLines, $successfulCsvLines, $isDone);// Execute the function
    } catch (DomainException $domainException) {
        // NO-OP
    } catch (Throwable $e) {
        if ($silent == 1) {
            $enqueueMessage(sprintf("%s Error message: %s, Error code line: %d, %s%s", ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), ANSI_COLOR_NORMAL, CUSTOM_LINE_END), 'error');
        }
    }


// Handle errors and retries
    foreach ($storage as $dataCurrentCSVlineToRetry => $item) {
        $curlRetry = curl_init();

        try {
            if ($item['mightExists']) {
                // Fail early
                if (!isset($item['decodedDataString']->tokenindex)) {
                    continue; // ...and handle it gracefully
                }

                $pk = (int)$item['decodedDataString']->id;
                $item['decodedDataString']->alias = sprintf('%s-%s', $item['decodedDataString']->alias, bin2hex(random_bytes(4)));

                $dataString = json_encode($item['decodedDataString'], JSON_THROW_ON_ERROR);

                if (!is_string($dataString) || !isset($token[$item['decodedDataString']->tokenindex])) {
                    continue;
                }

                // HTTP request headers
                $headers = [
                    'Accept: application/vnd.api+json',
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($dataString),
                    sprintf('X-Joomla-Token: %s', trim($token[$item['decodedDataString']->tokenindex])),
                ];

                if (!isset($poolOutputRetry[$rollingPoolIndexRetry % $currentCpuCount])) {
                    $poolOutputRetry[$rollingPoolIndexRetry % $currentCpuCount] = '';
                }

                // Circular pool of PHP Fibers based on number of "cpus detected".
                if (!isset($poolRetry[$rollingPoolIndexRetry % $currentCpuCount]) || (!($poolRetry[$rollingPoolIndexRetry % $currentCpuCount] instanceof Fiber))) {
                    $poolRetry[$rollingPoolIndexRetry % $currentCpuCount] = new Fiber(function (string $givenHttpVerb, string $givenEndpoint, string $givenDataString, array $givenHeaders, int $givenTimeout, CurlHandle $givenTransport) use ($process, $combinedHttpResponse, $dataCurrentCSVlineToRetry): void {
                        $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $process($givenHttpVerb, $givenEndpoint, $givenDataString, $givenHeaders, $givenTimeout, $givenTransport);
                        $args = Fiber::suspend($combinedHttpResponse[$dataCurrentCSVlineToRetry]);
                        $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $process(...$args);
                    });

                    $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $poolRetry[$rollingPoolIndexRetry % $currentCpuCount]->start($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curlRetry);
                } elseif ($poolRetry[$rollingPoolIndexRetry % $currentCpuCount]->isTerminated()) {
                    unset($poolRetry[$rollingPoolIndexRetry % $currentCpuCount]); // Recycle terminated PHP Fibers

                    $poolRetry[$rollingPoolIndexRetry % $currentCpuCount] = new Fiber(function (string $givenHttpVerb, string $givenEndpoint, string $givenDataString, array $givenHeaders, int $givenTimeout, CurlHandle $givenTransport) use ($process, $combinedHttpResponse, $dataCurrentCSVlineToRetry): void {
                        $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $process($givenHttpVerb, $givenEndpoint, $givenDataString, $givenHeaders, $givenTimeout, $givenTransport);
                        $args = Fiber::suspend($combinedHttpResponse[$dataCurrentCSVlineToRetry]);
                        $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $process(...$args);
                    });

                    $combinedHttpResponse[$dataCurrentCSVlineToRetry] ??= $poolRetry[$rollingPoolIndexRetry % $currentCpuCount]->start($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curlRetry);
                }

                // While overall script is not finished or PHP Fibers pool not empty
                while (!$poolRetry[$rollingPoolIndexRetry % $currentCpuCount]->isTerminated()) {
                    $poolRetry[$rollingPoolIndexRetry % $currentCpuCount]->resume([$pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curlRetry]);

                    $decodedJsonOutputRetry = json_decode($combinedHttpResponse[$dataCurrentCSVlineToRetry], false, 512, JSON_THROW_ON_ERROR);
                    // don't show errors, handle them gracefully
                    if (isset($decodedJsonOutputRetry->errors)) {
                        continue;
                    }
                    if (isset($decodedJsonOutputRetry->data) && isset($decodedJsonOutputRetry->data->attributes) && !isset($successfulCsvLines[$dataCurrentCSVlineToRetry])) {
                        if ($silent == 1) {
                            $successfulCsvLines[$dataCurrentCSVlineToRetry] = sprintf("%s Deployed to: %s, CSV Line: %d, id: %d,  created: %s, title: %s, alias: %s%s%s",
                                ANSI_COLOR_GREEN,
                                $item['decodedDataString']->tokenindex,
                                $dataCurrentCSVlineToRetry,
                                $decodedJsonOutputRetry->data->id,
                                $decodedJsonOutputRetry->data->attributes->created,
                                $decodedJsonOutputRetry->data->attributes->title,
                                $decodedJsonOutputRetry->data->attributes->alias,
                                ANSI_COLOR_NORMAL, CUSTOM_LINE_END);
                            $enqueueMessage($successfulCsvLines[$dataCurrentCSVlineToRetry]);
                        }

                    }
                    if ($isDone) {
                        break;
                    }
                }
                if (!$isDone) {
                    ++$rollingPoolIndexRetry; // Important for circular pool of PHP Fibers
                }
            }
        } catch (DomainException $domainException) {
            throw $domainException;
        } catch (Throwable $e) {
            if ($silent == 1) {
                $failedCsvLines[$dataCurrentCSVlineToRetry] = sprintf("%s Error message: %s, Error code line: %d, Error CSV Line: %d%s%s", ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), $dataCurrentCSVlineToRetry, ANSI_COLOR_NORMAL, CUSTOM_LINE_END);
                $enqueueMessage($failedCsvLines[$dataCurrentCSVlineToRetry], 'error');
            }
            continue;
        } finally {
            if (isset($curlRetry) && ($curlRetry instanceof CurlHandle)) {
                curl_close($curlRetry);
            }
        }
    }
} catch (DomainException $domainException) {
    if ($silent == 1) {
        $enqueueMessage($domainException->getMessage());
    }

} catch (Throwable $fallbackCatchAllUncaughtException) {
    // Ignore silent mode when stumbling upon fallback exception
    $enqueueMessage(sprintf('%s Error message: %s, Error code line: %d%s%s', ANSI_COLOR_RED, $fallbackCatchAllUncaughtException->getMessage(), $fallbackCatchAllUncaughtException->getLine(), ANSI_COLOR_NORMAL, CUSTOM_LINE_END), 'error');
} finally {
    $isDone = true;

    // Cleanup references
    unset($pool);
    unset($poolOutput);

    if (in_array($saveReportToFile, [1, 2], true)) {
        $errors = [];
        $success = [];
        if (!file_exists(CSV_PROCESSING_REPORT_FILEPATH)) {
            touch(CSV_PROCESSING_REPORT_FILEPATH);
        }
        if (!empty($failedCsvLines)) {
            $errors = ['errors' => $failedCsvLines];
            if ($saveReportToFile === 2) {
                file_put_contents(CSV_PROCESSING_REPORT_FILEPATH, json_encode($errors));
            }
        }
        if (($saveReportToFile === 1) && !empty($successfulCsvLines)) {
            $success = ['success' => $successfulCsvLines];
            file_put_contents(CSV_PROCESSING_REPORT_FILEPATH, json_encode(array_merge($errors, $success)));
        }
    }

    $enqueueMessage(sprintf('Done%s', CUSTOM_LINE_END));
}
