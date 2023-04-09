<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles Via API Using Airtable API
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://apiadept.com
 */

// Your Airtable endpoint url (CHANGE WITH YOUR OWN URL IF YOU WISH)
$dataSourceUrl = 'https://api.airtable.com/v0/apptBHEIQEEuQZDUg/tblVWSWobBIo5q6bX';

// Your Airtable Api token
$dataSourceToken = 'yourairtableapikey';


// Your Joomla! 4.x website base url
$baseUrl = [
	'app-001' => 'https://app-001.example.org',
	'app-002' => 'https://app-002.example.org',
	'app-003' => 'https://app-003.example.org',
];
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token    = [
	'app-001' => 'yourapp001joomlaapitoken',
	'app-002' => 'yourapp002joomlaapitoken',
	'app-003' => 'yourapp003joomlaapitoken',
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
		yield new RuntimeException('Url MUST NOT be empty', 422);
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
		'picture',
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
		if (is_array($resource->records) && empty($resource->records))
		{
			throw new RuntimeException('No records found in your Airtable table. Cannot continue.', 422);
		}
		$picturePath = __DIR__ . '/images/';

		$downloadPicture = function (stdClass $givenPicture, string $destination) {
			if (!property_exists($givenPicture, 'url'))
			{
				throw new DomainException('Cannot download current picture. Malformed datastructure url and filename MUST be present in givenPicture class parameter', 422);
			}
			if (empty($destination))
			{
				throw new DomainException('Destination path for pictures download MUST not be empty', 422);
			}
			$contentType = explode(';', get_headers($givenPicture->url, true)['Content-Type'])[0];
			if (!in_array($contentType, ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'], true))
			{
				throw new DomainException('Picture format not allowed at the moment', 415);
			}
			$imageData = file_get_contents($givenPicture->url);
			if (!file_exists($destination))
			{
				mkdir($destination, 0700, true);
			}
			$destinationFile = sprintf('%s/%s.%s', $destination, hash('sha3-512', $imageData, false), explode('/', $contentType)[1]);
			if (!file_exists($destinationFile))
			{
				file_put_contents($destinationFile, $imageData);
			}
		};

		foreach ($resource->records as $record)
		{
			if (empty((array) $record->fields))
			{
				echo 'No fields found in this record' . PHP_EOL;
				continue;
			}
			foreach ($record->fields as $fieldKey => $fieldValue)
			{
				if (!in_array($fieldKey, $mergedKeys, true))
				{
					unset($record->fields->$fieldKey);
				}
				if (!isset($record->fields->$fieldKey))
				{
					continue;
				}
				if (is_string($fieldValue) && mb_strpos($fieldValue, '{') === 0)
				{
					//IMPORTANT: This one line allows to see intro/fulltext images and urla,urlb,urlc
					$record->fields->$fieldKey = json_decode(str_replace(["\n", "\r", "\t"], '', trim($fieldValue)));

					if ($fieldKey === 'images')
					{
						$moreThanOnePicture = [];
						foreach ($record->fields->picture as $index => $currentPicture)
						{
							try
							{
								// In all case we want to download the full thumbnail
								$downloadPicture($currentPicture->thumbnails->large, $picturePath);
								if ($index === 0)
								{
									//Just for the first image we want to download both full and large
									$downloadPicture($currentPicture->thumbnails->full, $picturePath);

									$record->fields->images->image_intro    = $currentPicture->thumbnails->large->url;
									$record->fields->images->image_fulltext = $currentPicture->thumbnails->full->url;
								}
								$moreThanOnePicture[] = <<<MEDIA
<figure>
<img src="{$currentPicture->thumbnails->large->url}" alt="">
<figcaption>Picture from Datasource - Airtable</figcaption>
</figure>
MEDIA;
							}
							catch (Throwable $pictureDownloadThrowable)
							{
								// On failure to download images try to add them as url anyway
								$record->fields->images->image_intro    = $currentPicture->thumbnails->large->url;
								$record->fields->images->image_fulltext = $currentPicture->thumbnails->full->url;
								echo $pictureDownloadThrowable->getTraceAsString() . PHP_EOL;
								continue;
							}
						}

						//Prepend articletext and fulltext when there is more than 1 picture provided in the Airtable attachments fields
						$record->fields->articletext = sprintf('%s<br><hr>%s', implode('', $moreThanOnePicture), $record->fields->articletext);
						$record->fields->fulltext    = sprintf('%s<br><hr>%s', implode('', $moreThanOnePicture), $record->fields->fulltext);

					}
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
			echo $streamDataThrowable->getTraceAsString() . PHP_EOL;
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
			echo $storageThrowable->getTraceAsString() . PHP_EOL;
			continue;
		} finally
		{
			curl_close($storageCurl);
		}
	}
}
catch (Throwable $e)
{
	echo $e->getTraceAsString() . PHP_EOL;
} finally
{
	curl_close($dataSourceTransport);
}
