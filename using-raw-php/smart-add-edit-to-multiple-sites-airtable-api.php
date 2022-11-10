<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles to multiple Joomla! sites using Airtable API
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <contact@alexandree.io>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://alexandree.io
 */

// Your Airtable endpoint url (CHANGE WITH YOUR OWN URL IF YOU WISH)
$dataSourceUrl = 'https://api.airtable.com/v0/apptBHEIQEEuQZDUg/tblVWSWobBIo5q6bX';

// Your Airtable Api token
$dataSourceToken = 'yourairtableapitoken';

// Your Joomla! 4.x website base url
$baseUrl = [
	'app-001' => 'https://app-001.example.org',
	'app-002' => 'https://app-002.example.org',
	'app-003' => 'https://app-003.example.org',
];
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token    = [
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


// This time we need endpoint to be a function to make it more dynamic
$endpoint = function (string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string {
	return $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
		: sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');
};

// PHP Generator to efficiently process Airtable Api response
$generator = function (string $airtableResponse, array $keys): Generator {
	
	if (empty($airtableResponse))
	{
		yield new RuntimeException('Airtable Response MUST NOT be empty', 422);
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
	
	$resource = json_decode($airtableResponse);
	
	if ($resource === false)
	{
		yield new RuntimeException('Could not read airtable response', 500);
	}
	
	try
	{
		foreach ($resource->records as $record)
		{
			foreach ($record->fields as $fieldKey => $fieldValue)
			{
				if (!in_array($fieldKey, $mergedKeys, true))
				{
					unset($record->fields->$fieldKey);
				}
				if (is_string($fieldValue) && mb_strpos($fieldValue, '{') === 0)
				{
					//IMPORTANT: This one line allows to see intro/fulltext images and urla,urlb,urlc
					$record->fields->$fieldKey = json_decode(str_replace(["\n", "\r", "\t"], '', trim($fieldValue)));
				}
			}
			// Re-encode the fields to send it back as JSON
			yield json_encode($record->fields);
		}
	} finally
	{
		echo 'DONE processing data' . PHP_EOL;
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
			CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
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

// Airtable Api response (First call to GET all records of specific table)
$dataSourceHttpVerb = 'GET';
$dataSourceHeaders  = [
	'Accept: application/json',
	sprintf('Authorization: Bearer %s', trim($dataSourceToken)),
];
$dataSourceTimeout  = 30;

// Response from Airtable Api
$dataSourceTransport = curl_init();
try
{
	$dataSourceResponse = $process($dataSourceHttpVerb, $dataSourceUrl, '', $dataSourceHeaders, $dataSourceTimeout, $dataSourceTransport);
	
	$streamData = $generator($dataSourceResponse, $customFieldKeys);
	$storage    = [];
	foreach ($streamData as $dataKey => $dataString)
	{
		if (!is_string($dataString))
		{
			continue;
		}
		$curl = curl_init();
		try
		{
			$decodedDataString = json_decode($dataString);
			if ($decodedDataString === false)
			{
				continue;
			}
			
			// HTTP request headers
			$headers = [
				'Accept: application/vnd.api+json',
				'Content-Type: application/json',
				'Content-Length: ' . mb_strlen($dataString),
				sprintf('X-Joomla-Token: %s', trim($token[$decodedDataString->tokenindex])),
			];
			
			// Article primary key. Usually 'id'
			$pk     = (int) $decodedDataString->id;
			$output = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$decodedDataString->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $curl);
			
			$decodedJsonOutput = json_decode($output);
			
			// don't show errors, handle them gracefully
			if (isset($decodedJsonOutput->errors))
			{
				// If article is potentially a duplicate (already exists with same alias)
				$storage[$dataKey] = ['mightExists' => $decodedJsonOutput->errors[0]->code === 400, 'decodedDataString' => $decodedDataString];
				continue;
			}
			echo $output . PHP_EOL;
		}
		catch (Throwable $streamDataThrowable)
		{
			echo $streamDataThrowable->getMessage() . PHP_EOL;
			continue;
		} finally
		{
			curl_close($curl);
		}
	}
// Handle errors and retries
	foreach ($storage as $item)
	{
		$storageCurl = curl_init();
		try
		{
			if ($item['mightExists'])
			{
				$pk                               = (int) $item['decodedDataString']->id;
				$item['decodedDataString']->alias = sprintf('%s-%s', $item['decodedDataString']->alias, bin2hex(random_bytes(4)));
				// No need to do another json_encode anymore
				$dataString = json_encode($item['decodedDataString']);
				// HTTP request headers
				$headers = [
					'Accept: application/vnd.api+json',
					'Content-Type: application/json',
					'Content-Length: ' . mb_strlen($dataString),
					sprintf('X-Joomla-Token: %s', trim($token[$item['decodedDataString']->tokenindex])),
				];
				$output  = $process($pk ? 'PATCH' : 'POST', $endpoint($baseUrl[$item['decodedDataString']->tokenindex], $basePath, $pk), $dataString, $headers, $timeout, $storageCurl);
				echo $output . PHP_EOL;
			}
		}
		catch (Throwable $storageThrowable)
		{
			echo $storageThrowable->getMessage() . PHP_EOL;
			continue;
		} finally
		{
			curl_close($storageCurl);
		}
	}
}
catch (Throwable $e)
{
	echo $e->getMessage() . PHP_EOL;
} finally
{
	curl_close($dataSourceTransport);
}
