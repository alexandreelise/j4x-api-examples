<?php
declare(strict_types=1);

/**
 * Add or Edit Joomla! Articles Via API To Multiple Sites Chosen Randomly in from a predefined list Using GitHub GraphQL API
 * - When id = 0 in csv it's doing a POST. If alias exists it add a random slug at the end of your alias and do POST again
 * - When id > 0 in csv it's doing a PATCH. If alias exists it add a random slug at the end of your alias and do PATCH again
 *
 * @author        Alexandre ELISÉ <code@apiadept.com>
 * @copyright (c) 2009 - present. Alexandre ELISÉ. All rights reserved.
 * @license       GPL-2.0-and-later GNU General Public License v2.0 or later
 * @link          https://apiadept.com
 */

// Your GitHub GraphQL API endpoint url
$dataSourceUrl = 'https://api.github.com/graphql';

// Your GitHub Personal Token (classic) (CHANGE WITH YOUR OWN TOKEN)
$dataSourceToken = 'ghp_yourowngithubpersonalclassictoken';

// Your repository owner (CHANGE THIS WITH YOUR OWN)
$yourRepositoryOwner = 'alexandreelise';

// Your repository name (CHANGE THIS WITH YOUR OWN)
$yourRepositoryName = 'j4x-api-examples';

// Your Joomla! 4.x website base url
$baseUrl = [
	'app-001' => 'https://app-001.example.org',
	'app-002' => 'https://app-002.example.org',
	'app-003' => 'https://app-003.example.org',
];
// Your Joomla! 4.x Api Token (DO NOT STORE IT IN YOUR REPO USE A VAULT OR A PASSWORD MANAGER)
$token    = [
	'app-001' => 'yourownjoomlaapitoken',
	'app-002' => 'yourownjoomlaapitoken',
	'app-003' => 'yourownjoomlaapitoken',
];
$basePath = 'api/index.php/v1';


// Request timeout
$timeout = 10;

// PHP Generator to efficiently process GitHub GraphQL Api response
$generator = function (string $dataSourceResponse, array $appIndexes): Generator {

	if (empty($dataSourceResponse))
	{
		yield new RuntimeException('Github GraphQL Api response MUST NOT be empty', 422);
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
		$repositoryName = $resource->data->repository->name;
		$repositoryUrl  = $resource->data->repository->url;

		$id       = 0;
		$title    = sprintf('Stargazers of your %s Github repository', $repositoryName);
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

		$introtext = <<<HTML
<p>Want to know who are your repo stargazers? Here they are</p>
HTML;

		$stargazerList = '';
		foreach ($resource->data->repository->stargazers->edges as $stargazer)
		{
			$stargazerList .= sprintf('<li>%s</li>', $stargazer->node->name);
		}

		$fulltext    = <<<HTML
<p>The stargazers for your GitHub repository named: <a href="$repositoryUrl" title="Your Github repository $repositoryName stargazers" target="_blank" rel="noopener nofollow">$repositoryName</a></p>
<ul>
$stargazerList
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

// Airtable Api response (First call to GET all records of specific table)
$dataSourceHttpVerb = 'POST';
$graphQL            = <<<GRAPHQL
{"query": "query {
  repository(owner:\"$yourRepositoryOwner\", name:\"$yourRepositoryName\") {
    name
    url
    stargazerCount
    stargazers(last:10){
      edges {
        node {
          name
        }
      }
    }
  }
}"}
GRAPHQL;

$dataSourceDataString = str_replace(["\n", "\r", "\t"], '', trim($graphQL));

$dataSourceHeaders = [
	'Accept: application/json',
	'Accept-Encoding: deflate, gzip, br',
	'Content-Type: application/json',
	'Connection: keep-alive',
	'User-Agent: Alexeli/1.0.0',
	sprintf('Content-Length: %d', mb_strlen($dataSourceDataString)),
	sprintf('Authorization: Bearer %s', trim($dataSourceToken)),
];
$dataSourceTimeout = 3;

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
