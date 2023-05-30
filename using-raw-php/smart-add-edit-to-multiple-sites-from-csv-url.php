<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles to multiple Joomla Sites Via API Using Streamed CSV
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license GNU Affero General Public License version 3 (AGPLv3)
 * @link          https://apiadept.com
 */
// This code is an implementation that attempts to comply to RFC 4180 spec on text/csv Media Type
// Your CSV file MUST have line endings configured as CRLF as the spec suggests for this script to work correctly
try {
    // Public url of the sample csv used in this example (CHANGE WITH YOUR OWN CSV URL OR LOCAL CSV FILE)
    $isLocal = true;
    // THIS URL DOES NOT EXIST IT WILL CRASH THE SCRIPT CHANGE THIS TO YOUR OWN URL
    $csvUrl = ''; // For example: https://example.org/sample-data.csv';
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
        'app-001' => 'yourapp001token',
        'app-002' => 'yourapp002token',
        'app-003' => 'yourapp003token',
    ];

    $basePath = 'api/index.php/v1';

    // Request timeout
    $timeout = 10;

    // Add custom fields support (shout-out to Marc DECHÈVRE : CUSTOM KING)
    // The keys are the columns in the csv with the custom fields names (that's how Joomla! Web Services Api work as of today)
    // For the custom fields to work they need to be added in the csv and to exists in the Joomla! site.
    $customFieldKeys = []; //['with-coffee','with-dessert','extra-water-bottle'];

    // Silent mode
    // 0: hide both response result and key value pairs
    // 1: show response result only
    // 2: show key value pairs only
    $silent = 1;

    // Line numbers we want in any order (e.g 9,7-7,2-4,10,17-14,21). Leave empty '' to process all lines (beginning at line 2. Same as csv file)
    $whatLineNumbersYouWant = '';

    defined('IS_CLI') || define('IS_CLI', PHP_SAPI == 'cli');
    defined('CUSTOM_LINE_END') || define('CUSTOM_LINE_END', PHP_SAPI == 'cli' ? PHP_EOL . '===================' . PHP_EOL : '<br>===================<br>');
    defined('ANSI_COLOR_RED') || define('ANSI_COLOR_RED', PHP_SAPI == 'cli' ? "\033[31m" : '');
    defined('ANSI_COLOR_GREEN') || define('ANSI_COLOR_GREEN', PHP_SAPI == 'cli' ? "\033[32m" : '');
    defined('ANSI_COLOR_BLUE') || define('ANSI_COLOR_BLUE', PHP_SAPI == 'cli' ? "\033[34m" : '');
    defined('ANSI_COLOR_NORMAL') || define('ANSI_COLOR_NORMAL', PHP_SAPI == 'cli' ? "\033[0m" : '');

    defined('CSV_SEPARATOR') || define('CSV_SEPARATOR', chr(0x2C));
    defined('CSV_ENCLOSURE') || define('CSV_ENCLOSURE', chr(0x22));
    defined('CSV_ESCAPE') || define('CSV_ESCAPE', chr(0x22));
    defined('CSV_ENDING') || define('CSV_ENDING', sprintf('%s%s', chr(0x0D), chr(0x0A)));

    //Csv starts at line number : 2
    defined('CSV_START') || define('CSV_START', 2);

    $computedLineNumbers = function (string $wantedLineNumbers = '') {
        if ($wantedLineNumbers === '') {
            return [];
        }
        $commaParts = explode(',', $wantedLineNumbers);
        if (empty($commaParts)) {
            return [];
        }
        sort($commaParts, SORT_NATURAL);
        $output = [];
        foreach ($commaParts as $commaPart) {
            if (strpos($commaPart, '-') === false) {
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
    $nested = function (array $arr, int $isSilent = 0): array {
        $handleComplexValues = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($arr), RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($iterator as $key => $value) {
            if (mb_strpos($value, '{') === 0) {
                echo $isSilent == 2 ? sprintf('%s item with key: %s with value: %s%s%s', ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
                // Doesn't seem to make sense at first but this one line allows to show intro/fulltext images and urla,urlb,urlc
                $handleComplexValues[$key] = json_decode(str_replace(["\n", "\r", "\t"], '', trim($value)));
            } elseif (json_decode($value) === false) {
                $handleComplexValues[$key] = json_encode($value);
                echo $isSilent == 2 ? sprintf('%s item with key: %s with value: %s%s%s', ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
            } else {
                $handleComplexValues[$key] = $value;
                echo $isSilent == 2 ? sprintf('%s item with key: %s with value: %s%s%s', ANSI_COLOR_BLUE, $key, $value, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
            }
        }

        return $handleComplexValues;
    };

    $csvReader = function (string $url, array $keys, callable $givenNested, int $isSilent = 1, array $lineRange = [], ?callable $handler = null) {
        try {
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
                throw new RuntimeException('Could not read csv file', 422);
            }


            stream_set_blocking($resource, false);

            $csvStreamContent = stream_get_contents($resource);

            if ($csvStreamContent === false) {
                throw new RuntimeException('CSV content seems to be empty. Cannot continue.', 422);
            }

            $csvStreamContentExtracted = explode(CSV_ENDING, $csvStreamContent);

            if (empty($csvStreamContentExtracted)) {
                throw new RuntimeException('CSV could not extract csv content. Cannot continue.', 422);
            }

            // Remove empty lines
            $csvStreamContentFiltered = array_values(array_filter($csvStreamContentExtracted));

            $firstLine = array_shift($csvStreamContentFiltered);

            if (!is_string($firstLine) || empty($firstLine)) {
                throw new RuntimeException('First line MUST NOT be empty. It is the header', 422);
            }

            //TODO: Might not be useful anymore. Might consider removing this line of code
            $csvHeaderKeys = str_getcsv($firstLine, CSV_SEPARATOR, CSV_ENCLOSURE, CSV_ESCAPE);

            $commonKeys = array_intersect($csvHeaderKeys, $mergedKeys);

            $isExpanded = ($lineRange !== []);
            $computedCsvContentFilteredByLineRange = $csvStreamContentFiltered;
            if ($isExpanded) {
                // This temporary index remapping is important to not break real world usage of line range algo
                // and at the same time use the new way of handling csv parsing through
                // a pattern similar to what is use in an ETL (Extract Transform Load)
                $temporaryLineRangeRemapping = array_map(fn($i) => max(0, $i - CSV_START), $lineRange);
                $computedCsvContentFilteredByLineRange = array_intersect_key($csvStreamContentFiltered, $temporaryLineRangeRemapping);
            }

            foreach ($computedCsvContentFilteredByLineRange as $currentCsvLineIndex => $afterFirstLine) {
                $currentCsvLineNumber = $currentCsvLineIndex + CSV_START;
                $extractedContent = str_getcsv($afterFirstLine, CSV_SEPARATOR, CSV_ENCLOSURE, CSV_ESCAPE);

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
                $handleComplexValues = $givenNested($commonValues, $isSilent);
                $encodedContent = json_encode(array_combine($commonKeys, $handleComplexValues), JSON_THROW_ON_ERROR);

                if ($encodedContent === false) {
                    throw new RuntimeException('Current line seem to be invalid', 422);
                } elseif (is_string($encodedContent) && is_callable($handler)) {
                    $handler(['line' => $currentCsvLineNumber, 'content' => $encodedContent]);
                }
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            if (isset($resource) && is_resource($resource)) {
                fclose($resource);
            }
        }
    };

// Process data returned by the PHP Generator
    $process = function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, $transport) {
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
    $expandedLineNumbers = $computedLineNumbers($whatLineNumbersYouWant);
    $storage = [];
    try {
        $csvReader($csvUrl, $customFieldKeys, $nested, $silent, $expandedLineNumbers, function ($dataValue) use (&$storage, $endpoint, $baseUrl, $basePath, $token, $silent, $timeout, $process) {

            $dataCurrentCSVline = $dataValue['line'];
            $dataString = $dataValue['content'];

            try {
                $curl = curl_init();

                if (!is_string($dataString)) {
                    throw new InvalidArgumentException('CSV content does not seem to be a string. Cannot continue.', 422);
                }

                $decodedDataString = json_decode($dataString, false, 512, JSON_THROW_ON_ERROR);
                if ($decodedDataString === false) {
                    throw new InvalidArgumentException('Could not decode CSV content. Cannot continue.', 501);
                }

                if (!isset($token[$decodedDataString->tokenindex])) {
                    throw new InvalidArgumentException('Token index representing where to deploy data, seems to be invalid. Cannot continue.', 422);
                }

                // HTTP request headers
                $headers = [
                    'Accept: application/vnd.api+json',
                    'Content-Type: application/json',
                    'Content-Length: ' . mb_strlen($dataString),
                    sprintf('X-Joomla-Token: %s', trim($token[$decodedDataString->tokenindex])),
                ];

                // Article primary key. Usually 'id'
                $pk = (int)$decodedDataString->id;

                $output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);
                $decodedJsonOutput = json_decode($output, false, 512, JSON_THROW_ON_ERROR);

                // don't show errors, handle them gracefully
                if (isset($decodedJsonOutput->errors) && (!isset($storage[$dataCurrentCSVline]))) {
                    // If article is potentially a duplicate (already exists with same alias)
                    $storage[$dataCurrentCSVline] = ['mightExists' => ($decodedJsonOutput->errors[0]->code ?? '') == 400, 'decodedDataString' => $decodedDataString,];
                    return;
                }
                echo $silent == 1 ? sprintf('%s Deployed to: %s, CSV Line: %d, type: %s, id: %d, title: %s, alias: %s, created: %s%s%s',
                    ANSI_COLOR_GREEN,
                    $decodedDataString->tokenindex,
                    $dataCurrentCSVline,
                    $decodedJsonOutput->data->type ?? '',
                    $decodedJsonOutput->data->id ?? 0,
                    $decodedJsonOutput->data->attributes->title ?? '',
                    $decodedJsonOutput->data->attributes->alias ?? '',
                    $decodedJsonOutput->data->attributes->created ?? '',
                    ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
            } catch (Throwable $e) {
                echo $silent == 1 ? sprintf('%s Error message: %s, Error code line: %d, Error CSV Line: %d%s%s', ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), $dataCurrentCSVline, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
                return;
            } finally {
                curl_close($curl);
            }
        });// Execute the function
    } catch (Throwable $e) {
        echo $silent == 1 ? sprintf('%s Error message: %s, Error code line: %d, %s%s', ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
    }

// Handle errors and retries
    foreach ($storage as $dataCurrentCSVlineToRetry => $item) {
        $curlRetry = curl_init();
        try {
            if ($item['mightExists']) {
                $pk = (int)$item['decodedDataString']->id;
                $item['decodedDataString']->alias = sprintf('%s-%s', $item['decodedDataString']->alias, bin2hex(random_bytes(4)));

                $dataString = json_encode($item['decodedDataString'], JSON_THROW_ON_ERROR);

                if (!is_string($dataString)) {
                    continue;
                }

                if (!($token[$item['decodedDataString']->tokenindex] ?? false)) {
                    continue;
                }

                // HTTP request headers
                $headers = [
                    'Accept: application/vnd.api+json',
                    'Content-Type: application/json',
                    'Content-Length: ' . mb_strlen($dataString),
                    sprintf('X-Joomla-Token: %s', trim($token[$item['decodedDataString']->tokenindex])),
                ];
                $output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curlRetry);
                $result = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
                echo $silent == 1 ? sprintf('%s Deployed to: %s, CSV Line: %d, type: %s, id: %d, title: %s, alias: %s, created: %s%s%s',
                    ANSI_COLOR_GREEN,
                    $item['decodedDataString']->tokenindex,
                    $dataCurrentCSVlineToRetry,
                    $result->data->type ?? '',
                    $result->data->id ?? 0,
                    $result->data->attributes->title ?? '',
                    $result->data->attributes->alias ?? '',
                    $result->data->attributes->created ?? '',
                    ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
            }
        } catch (Throwable $e) {
            echo $silent == 1 ? sprintf('%s Error message: %s, Error code line: %d, Error CSV Line: %d%s%s', ANSI_COLOR_RED, $e->getMessage(), $e->getLine(), $dataCurrentCSVlineToRetry, ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
            continue;
        } finally {
            curl_close($curlRetry);
        }
    }
} catch (Throwable $fallbackCatchAllUncaughtException) {
    echo $silent == 1 ? sprintf('%s Error message: %s, Error code line: %d%s%s', ANSI_COLOR_RED, $fallbackCatchAllUncaughtException->getMessage(), $fallbackCatchAllUncaughtException->getLine(), ANSI_COLOR_NORMAL, CUSTOM_LINE_END) : '';
}
echo sprintf('Done%s', CUSTOM_LINE_END);
