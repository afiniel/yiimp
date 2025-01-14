<?php
// https://safetrade.com/api/v2

function safetrade_api_query($method, $params='', $returnType='object')
{
	$uri = "https://safe.trade/api/v2/{$method}";
	if (!empty($params)) $uri .= "?{$params}";
	
	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; HitBTC API PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$execResult = strip_tags(curl_exec($ch));

	if ($returnType == 'object')
		$ret = json_decode($execResult);
	else
		$ret = json_decode($execResult,true);

	return $ret;
}

// just a template, needs to modify to work with api
function safetrade_api_user($method, $url_params = [], $request_method='GET', $returnType='object') {
	$timedrift = 3;
	
	if (empty(EXCH_SAFETRADE_KEY) || empty(EXCH_SAFETRADE_SECRET)) return false;

	$nonce = (time() + $timedrift).rand(100,999); # tonce should be different from previous one
	
	$base = 'https://safe.trade'; $path = '/api/v2/'.$method;

	$request = '';

	if (is_array($url_params)) {
		ksort($url_params);
		$request = http_build_query($url_params, '', '&');
	} elseif (is_string($url_params)) {
		$request = $url_params;
	}

	$payload = '';
	if ($request_method == 'POST') {
		$uri = $base.$path;
		$payload = $request;
	}
	else {
		if ($request != '')
			$uri = $base.$path.'?'.$request;
		else
			$uri = $base.$path;
	}

	$message = EXCH_SAFETRADE_KEY.$uri.$payload. $nonce;
	$signature = hash_hmac('sha256', $message, EXCH_SAFETRADE_SECRET);
	// debuglog('safetrade-api: '.var_export($message, true));
	$http_headers = [
				'Content-Type: application/json',
				'X-API-KEY: '.EXCH_SAFETRADE_KEY,
				'X-API-NONCE: '.$nonce,
				'X-API-SIGN: '.$signature
	];

	$http_request = new cHTTP();
	$http_request->setURL($uri);
	$http_request->setHeaders($http_headers);

	if ($request_method == 'POST') {
		$http_request->setPostfields($payload);
	}
	$http_request->setUserAgentString('Mozilla/4.0 (compatible; safetrade API client; '.php_uname('s').'; PHP/'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.')');
	$http_request->setFailOnError(false);
	$data = $http_request->execRequest();
	if ($returnType == 'object')
		$res = json_decode($data);
	else
		$res = json_decode($data,true);
	
	$status = $http_request->fResult['HTTP_Code'];
	
	if($status >= 300) {
		debuglog("safetrade: $method failed ($status) ".strip_data($data));
		$res = false;
	}

	return $res;
}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////