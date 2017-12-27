<?php

function encode_resource_url($path) {
	global $data_url;
	if (substr($path, 0, 4) != 'http') {
		$need = "$data_url[scheme]://$data_url[host]";
	} else {
		$need = '';
	}
	return "$_SERVER[REQUEST_SCHEME]://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]?url=".base64_encode($need.$path);
}

function fix_css($url) {
	$url = trim($url);
    $delim = strpos($url, '"') === 0 ? '"' : (strpos($url, "'") === 0 ? "'" : '');
    return $delim.preg_replace('#([\(\),\s\'"\\\])#', '\\$1', encode_resource_url(trim(preg_replace('#\\\(.)#', '$1', trim($url, $delim))))).$delim;
}

$data_url = parse_url(base64_decode($_GET['url']));

if (!$data_url['host']) {
	exit('URL указан неверно');
}

if (!$data_url['path']) {
	$data_url['path'] = '/';
}

if ($data_url['query']) {
	$data_url['query'] = "?$data_url[query]";
} else {
	$data_url['query'] = '';
}

if ($data_url['scheme'] == 'http') {
	$fp = fsockopen("tcp://$data_url[host]", 80);
} else {
	$fp = fsockopen("ssl://$data_url[host]", 443);
}

if (!$fp) {
	exit('Сервер не отвечает');
}

$out = "$_SERVER[REQUEST_METHOD] $data_url[path]$data_url[query] $_SERVER[SERVER_PROTOCOL]\r\n";
$out .= "Host: $data_url[host]\r\n";
$out .= "User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n";
$out .= "Accept: $_SERVER[HTTP_ACCEPT]\r\n";

if ($_COOKIE) {
	$out .= sprintf('Cookie: %s', http_build_query($_COOKIE, null, '; '))."\r\n";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if ($_FILES) {
		$boundary = '----'.md5(time());

		foreach ($_POST as $key => $value) {
			$post .= "--{$boundary}\r\n";
			$post .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
			$post .= urldecode($value)."\r\n";
		}

		foreach ($_FILES as $key => $file_info) {
			$post .= "--{$boundary}\r\n";
			$post .= "Content-Disposition: form-data; name=\"$key\"; filename=\"{$file_info['name']}\"\r\n";
			$post .= 'Content-Type: '.(empty($file_info['type']) ? 'application/octet-stream' : $file_info['type']) . "\r\n\r\n";
			if (is_readable($file_info['tmp_name'])) {
				$handle = fopen($file_info['tmp_name'], 'rb');
				$post .= fread($handle, filesize($file_info['tmp_name']));
				fclose($handle);
			}
			$post .= "\r\n";
		}

		$post .= "--{$boundary}--\r\n";
		$out .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
	} else {
		$post = http_build_query($_POST);
		$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
	}

	$out .= "Content-Length: ".strlen($post)."\r\n";
	$out .= "Connection: Close\r\n\r\n";
	$out .= $post;
}


else {
	$out .= "Connection: Close\r\n\r\n";
}

fwrite($fp, $out);

while (!feof($fp)) {
	$body .= fgets($fp, 128);
}

fclose($fp);

list($header, $body) = explode("\r\n\r\n", $body);
$header = http_parse_headers($header);
list($content_type, $content_charset) = explode('; ', $header['Content-Type']);

foreach ($header as $key => $val) {

	if ($key == 'Set-Cookie' and $header[$key]) {
		if (is_array($header[$key])) {
			foreach ($header[$key] as $cookie) {
				$cookie = http_parse_cookie($cookie);
				$key = key($cookie->cookies);
				setcookie($key, $cookie->cookies[$key], $cookie->expire, $cookie->path, $_SERVER['SERVER_NAME']);
			}
		}
	}

	else if ($key == 'Location') {
		header("$key: ".encode_resource_url($val));
	}

	else {
		header("$key: $val");
	}

}

if ($content_type == 'text/html') {
	// Обработка HTML
	libxml_use_internal_errors(true);
	$html = new DOMDocument();
	$html->loadHTML($body);
	$html_resource = array(
		'img' => 'src',
		'input' => 'src',
		'script' => 'src',
		'link' => 'href',
		'a' => 'href',
		'form' => 'action',
	);

	foreach ($html_resource as $tag => $attribute) {
		foreach ($html->getElementsByTagName($tag) as $element) {
			if ($element->hasAttribute($attribute)) {
				$element->setAttribute($attribute, encode_resource_url($element->getAttribute($attribute)));
			}
		}
	}

	$body = $html->saveHTML();
	$body = str_replace(',location.replace(location.toString())', '', $body);
}


else if ($content_type == 'text/css') {
	// Обработка CSS
	preg_match_all('#url\s*\(\s*(([^)]*(\\\))*[^)]*)(\)|$)?#i', $body, $matches, PREG_SET_ORDER);
    for ($i = 0, $count = count($matches); $i < $count; ++$i) {
        $body = str_replace($matches[$i][0], 'url('.fix_css($matches[$i][1]).')', $body);
    }
}

echo $body;