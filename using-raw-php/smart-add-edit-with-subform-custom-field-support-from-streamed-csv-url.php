<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles Via API Using Streamed CSV
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 * - Now supports:
 *   - subform custom fields in article
 *   - images: intro / fulltext images in article
 *   - urls:  urla,urlb,urlc in article
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license GNU Affero General Public License version 3 (AGPLv3)
 * @link          https://apiadept.com
 */

// Public url of the sample csv used in this example (CHANGE WITH YOUR OWN CSV URL IF YOU WISH)
$csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTtV7Bnj-E3mwnBgXbkZlzS476aHVp6vtZ7qdI5vxlPUUqNe_85S3ozT5_gzNkBDih4dL1f8uDeeh_g/pub?gid=522567559&single=true&output=csv';

// Your Joomla! 4.x website base url
$baseUrl = '';
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token    = '';
$basePath = 'api/index.php/v1';


// Request timeout
$timeout = 10;

// Add custom fields support (shout-out to Marc DECHÈVRE : CUSTOM KING)
// The keys are the columns in the csv with the custom fields names (that's how Joomla! Web Services Api work as of today)
// For the custom fields to work they need to be added in the csv and to exists in the Joomla! site.
$customFieldKeys = ['']; //['with-coffee','with-dessert','extra-water-bottle'];


// This time we need endpoint to be a function to make it more dynamic
$endpoint = fn(string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string => $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
    : sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');

// PHP Generator to efficiently read the csv file
$generator = function (string $url, array $keys = []): Generator {

	if (empty($url))
	{
		yield new RuntimeException('Url MUST NOT be empty', 422);
	}

	$defaultKeys = [
		'id',
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
		'publish_up',
		'publish_down',
		'featured_up',
		'featured_down',
		'images',
		'urls',
	];

	$mergedKeys = array_unique(array_merge($defaultKeys, $keys));

	// Assess robustness of the code by trying random key order
	//shuffle($mergedKeys);

	$resource = fopen($url, 'r');

	if ($resource === false)
	{
		yield new RuntimeException('Could not read csv file', 500);
	}

	try
	{
		//NON-BLOCKING I/O (Does not wait before processing next line.)
		stream_set_blocking($resource, false);

		$firstLine = stream_get_line(
			$resource,
			0,
			"\r\n"
		);

		if (empty($firstLine))
		{
			yield new RuntimeException('First line MUST NOT be empty. It is the header', 422);
		}

		$csvHeaderKeys = str_getcsv($firstLine);
		$commonKeys    = array_intersect($csvHeaderKeys, $mergedKeys);

		do
		{
			$currentLine = stream_get_line(
				$resource,
				0,
				"\r\n"
			);

			if (empty($currentLine))
			{
				yield new RuntimeException('Current line MUST NOT be empty', 422);
			}

			$extractedContent = str_getcsv($currentLine);

			// Allow using csv keys in any order
			$commonValues = array_intersect_key($extractedContent, $commonKeys);

			// Iteration on leafs AND nodes
			$handleComplexValues = [];
			$iterator            = new RecursiveIteratorIterator(new RecursiveArrayIterator($commonValues), RecursiveIteratorIterator::CATCH_GET_CHILD);
			foreach ($iterator as $key => $value)
			{
				if (json_decode($value) === false)
				{
					$handleComplexValues[$key] = json_encode($value);
				}
				else
				{
					$handleComplexValues[$key] = $value;
				}
				echo 'current item key: ' . $key . ' with value ' . $handleComplexValues[$key] . PHP_EOL;
			}

			$encodedContent = json_encode(array_combine($commonKeys, $handleComplexValues));
			if ($encodedContent !== false)
			{
				yield $encodedContent;
			}

			yield new RuntimeException('Current line seem to be invalid', 422);
		} while (!feof($resource));
	} finally
	{
		fclose($resource);
	}
};

// Process data returned by the PHP Generator
$process = function (string $givenHttpVerb, string $endpoint, string $dataString, array $headers, int $timeout, $transport) {
	curl_setopt_array($transport, [
			CURLOPT_URL            => $endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => 'utf-8',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
			CURLOPT_CUSTOMREQUEST  => $givenHttpVerb,
			CURLOPT_POSTFIELDS     => $dataString,
			CURLOPT_HTTPHEADER     => $headers,
		]
	);

	$response = curl_exec($transport);
	// Continue even on partial failure
	if (empty($response))
	{
		throw new RuntimeException('Empty output', 422);
	}

	return $response;
};
// Read CSV in a PHP Generator using streams in non-blocking I/O mode
$streamCsv = $generator($csvUrl, $customFieldKeys);
$storage   = [];
foreach ($streamCsv as $dataKey => $dataString)
{
	if (!is_string($dataString))
	{
		continue;
	}
	$curl = curl_init();
	try
	{
		// HTTP request headers
		$headers           = [
			'Accept: application/vnd.api+json',
			'Content-Type: application/json',
			'Content-Length: ' . mb_strlen($dataString),
			sprintf('X-Joomla-Token: %s', trim($token)),
		];
		$decodedDataString = json_decode($dataString, true);
		// Article primary key. Usually 'id'
		$pk     = (int) $decodedDataString['id'];
		$output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl, $basePath, $pk), $dataString, $headers, $timeout, $curl);

		$decodedJsonOutput = json_decode($output, true);

		// don't show errors, handle them gracefully
		if (isset($decodedJsonOutput['errors']))
		{
			// If article is potentially a duplicate (already exists with same alias)
			$storage[$dataKey] = ['mightExists' => $decodedJsonOutput['errors'][0]['code'] === 400, 'decodedDataString' => $decodedDataString];
			continue;
		}
		echo $output . PHP_EOL;
	}
	catch (Throwable $e)
	{
		echo $e->getMessage() . PHP_EOL;
		continue;
	} finally
	{
		curl_close($curl);
	}
}
// Handle errors and retries
foreach ($storage as $item)
{
	$curl = curl_init();
	try
	{
		if ($item['mightExists'])
		{
			$pk                                 = (int) $item['decodedDataString']['id'];
			$item['decodedDataString']['alias'] = sprintf('%s-%s', $item['decodedDataString']['alias'], bin2hex(random_bytes(4)));
			// back to json string after changing alias
			$dataString = json_encode($item['decodedDataString']);

			// HTTP request headers
			$headers = [
				'Accept: application/vnd.api+json',
				'Content-Type: application/json',
				'Content-Length: ' . mb_strlen($dataString),
				sprintf('X-Joomla-Token: %s', trim($token)),
			];

			$output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl, $basePath, $pk), $dataString, $headers, $timeout, $curl);
			echo $output . PHP_EOL;
		}
	}
	catch (Throwable $e)
	{
		echo $e->getMessage() . PHP_EOL;
		continue;
	} finally
	{
		curl_close($curl);
	}
}
