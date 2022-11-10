<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles Via API To Multiple Sites Chosen Randomly in from a predefined list Using OMDb API
 * - When id = 0 it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <contact@alexandree.io>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://alexandree.io
 */

// Your OMDb API Key (GET YOUR OWN here : https://www.omdbapi.com/apikey.aspx)
$dataSourceToken = 'youromdbapikey';
// CHANGE TO YOUR OWN TOP 5 FAVOURITE MOVIES BY PUTTING THEIR IMDb id in the seeds array
// Movies used in seed are: Steve Jobs 2015, The Matrix Reloaded, Good Will Hunting, The Man Who Knew Infinity, Finding Forrester
$seeds                    = ['tt2080374', 'tt0234215', 'tt0119217', 'tt0787524', 'tt0181536'];
$dataSourceEndpointImdbId = $seeds[random_int(0, (count($seeds) - 1))]; //
//$dataSourceEndpointYear    = 2019; // Year of the release of the item
$dataSourceEndpointType    = 'movie'; // 'movie', 'series', 'episode'
$dataSourceEndpointPlot    = 'short'; // 'short or 'full'
$dataSourceEndpointVersion = 1;
$dataSourceEndpointFormat  = 'json'; // 'json' or 'xml'

// Your Omdb API Endpoint Url
$dataSourceUrl = sprintf('https://www.omdbapi.com/?apikey=%s&i=%s&type=%s&plot=%s&v=%d&r=%s', $dataSourceToken, $dataSourceEndpointImdbId, $dataSourceEndpointType, $dataSourceEndpointPlot, $dataSourceEndpointVersion, $dataSourceEndpointFormat);

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

// PHP Generator to efficiently process Omdb Movie Api response
$generator = function (string $dataSourceResponse, array $appIndexes): Generator {
	
	if (empty($dataSourceResponse))
	{
		yield new RuntimeException('Omdb Movie Api response MUST NOT be empty', 422);
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
	
	// Assess robustness of the code by trying random key order
	//shuffle($mergedKeys);
	
	$resource = json_decode($dataSourceResponse);
	
	if ($resource === false)
	{
		yield new RuntimeException('Could not read response', 500);
	}
	
	try
	{
		
		$id       = 0;
		$title    = $resource->Title;
		$alias    = '';
		$catid    = 2;
		$language = '*';
		$metadesc = '';
		$metakey  = '';
		$state    = 1;
		$featured = 0;
		$access   = 1;
		
		
		//choosen random tokenindex to deploy result to random url matched by this tokenindex
		$tokenindex = array_rand($appIndexes);
		
		$poster = $resource->Poster;
		
		$images = <<<JSON
{"image_intro": "$poster", "image_intro_caption": "$title", "image_intro_alt":"$title", "float_intro":"","image_fulltext": "$poster", "image_fulltext_caption": "$title", "image_fulltext_alt":"$title", "float_fulltext":""}
JSON;
		$urls   = <<<JSON
{"urla":"https://alexandree.io","urlatext":"Website","targeta":"","urlb":"https://github.com/alexandreelise","urlbtext":"Github","targetb":"","urlc":"https://www.linkedin.com/in/alexandree","urlctext":"Twitter","targetc":""}

JSON;
		
		$introtext = $resource->Plot;
		
		$contentList = '';
		foreach ($resource as $key => $value)
		{
			if (in_array($key, ['Title', 'Plot', 'Poster', 'Ratings', 'Response',], true))
			{
				continue;
			}
			$contentList .= sprintf('<p><strong>%s</strong> : <em>%s</em></p>', $key, $value);
		}
		
		$ratingList = '';
		foreach ($resource->Ratings as $rating)
		{
			$ratingList .= sprintf('<li><dl><dt>%s</dt><dd>%s</dd></dl></li>', $rating->Source, $rating->Value);
		}
		
		$fulltext    = <<<HTML
<p>Here is what we know about this movie according to ImDb</p>
$contentList
<ul>
$ratingList
</ul>
HTML;
		$articletext = <<<HTML
        $introtext
		<hr id="system-readmore" />
        $fulltext
HTML;
		
		yield json_encode(
			array_combine(
				$defaultKeys,
				[
					$id,
					$access,
					$title,
					$alias,
					$catid,
					$articletext,
					$introtext,
					$fulltext,
					$language,
					$metadesc,
					$metakey,
					$state,
					$featured,
					json_decode(str_replace(["\n", "\r", "\t"], '', trim($images))),
					json_decode(str_replace(["\n", "\r", "\t"], '', trim($urls))),
					$tokenindex,
				]
			)
		);
	} finally
	{
		echo 'DONE processing data' . PHP_EOL;
	}
};

// This time we need endpoint to be a function to make it more dynamic
$endpoint = function (string $givenBaseUrl, string $givenBasePath, int $givenResourceId = 0): string {
	return $givenResourceId ? sprintf('%s/%s/%s/%d', $givenBaseUrl, $givenBasePath, 'content/articles', $givenResourceId)
		: sprintf('%s/%s/%s', $givenBaseUrl, $givenBasePath, 'content/articles');
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

// Omdb Api response (First call to GET items)
$dataSourceHttpVerb = 'GET';

$dataSourceDataString = '';

$dataSourceHeaders = [
	'Accept: application/json',
	'Accept-Encoding: deflate, gzip, br',
	'Content-Type: application/json',
	'Connection: keep-alive',
	'User-Agent: Alexeli/1.0.0',
];
$dataSourceTimeout = 1;

$dataSourceTransport = curl_init();
try
{
	$dataSourceResponse = $process($dataSourceHttpVerb, $dataSourceUrl, $dataSourceDataString, $dataSourceHeaders, $dataSourceTimeout, $dataSourceTransport);
	$streamData         = $generator($dataSourceResponse, $baseUrl);
	
	$storage = [];
	foreach ($streamData as $dataString)
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
				$storage[] = ['mightExists' => $decodedJsonOutput->errors[0]->code === 400, 'decodedDataString' => $decodedDataString];
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
