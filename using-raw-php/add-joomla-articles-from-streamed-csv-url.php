<?php
declare(strict_types=1);

/**
 * Add Joomla! articles from streamed csv url (with POST)
 *
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://apiadept.com
 */

// Public url of the sample csv used in this example (CHANGE WITH YOUR OWN CSV URL IF YOU WISH)
$csvUrl = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSIWDcsYMucDxSWB3kqqpzxnAxHfrimLUXcOa3mBWn312HXa8VnwatfGoSWoJsFn1Fpq8r65Uqi8MoG/pub?output=csv';

// HTTP Verb
$httpVerb = 'POST';

// Your Joomla! 4.x website base url
$baseUrl  = 'https://example.org';
$basePath = 'api/index.php/v1';

// This time we need endpoint to be a function to make it more dynamic
$endpoint = function (string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string {
	return $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
		: sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');
};
$timeout  = 10;

// Add custom fields support (shout-out to Marc DECHÈVRE : CUSTOM KING)
// The keys are the columns in the csv with the custom fields names (that's how Joomla! Web Services Api work as of today)
// For the custom fields to work they need to be added in the csv and to exists in the Joomla! site.
$customFieldKeys = []; //['with-coffee','with-dessert','extra-water-bottle'];

// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token = '';

// PHP Generator to efficiently read the csv file
$generator = function (string $url, array $keys = []): Generator {
	
	if (empty($url))
	{
		yield new RuntimeException('Url MUST NOT be empty', 422);
	}
	
	$defaultKeys = [
		'title',
		'alias',
		'catid',
		'articletext',
		'language',
		'metadesc',
		'metakey',
		'state',
		'featured',
	];
	
	$mergedKeys = array_unique(array_merge($defaultKeys, $keys));
	
	$resource = fopen($url, 'r');
	
	if ($resource === false)
	{
		yield new RuntimeException('Could not read csv file', 500);
	}
	
	try
	{
		//NON-BLOCKING I/O (Does not wait before processing next line.)
		stream_set_blocking($resource, false);
		
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
			
			// Remove first element of csv line as it is usually the id of the article (since for POST it's not used, we remove it)
			array_shift($extractedContent);
			
			if ($mergedKeys != $extractedContent)
			{
				$encodedContent = json_encode(array_combine($mergedKeys, $extractedContent));
				
				yield $encodedContent;
			}
			yield new RuntimeException('Current line seem to be invalid', 422);
		} while (!feof($resource));
	} finally
	{
		fclose($resource);
	}
};

// Read CSV in a PHP Generator using streams in non-blocking I/O mode
$streamCsv = $generator($csvUrl, $customFieldKeys);

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
	// Might slow down the script but at least shows what's going on
	echo $response . PHP_EOL;
	return $response;
};

foreach ($streamCsv as $dataString)
{
	if (!is_string($dataString))
	{
		continue;
	}
	$curl = curl_init();
	try
	{
		// HTTP request headers
		$headers = [
			'Accept: application/vnd.api+json',
			'Content-Type: application/json',
			'Content-Length: ' . mb_strlen($dataString),
			sprintf('X-Joomla-Token: %s', trim($token)),
		];
		
		$output = $process($httpVerb, $endpoint($baseUrl, $basePath, 0), $dataString, $headers, $timeout, $curl);
		// Continue even on partial failure
		if (empty($output) || array_key_exists('errors', json_decode($output, true)))
		{
			continue;
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
