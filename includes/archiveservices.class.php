<?php
function get_archive_request_url ($part = null) {
	return my_request_url($part, 'archive');
}

function get_all_archive_services_links ($url = null, $text = null) {
	if (is_object($url) and method_exists($url, 'source_url')) :
		$host = $url->source_url('host');
		$url = $url->source_url();
	elseif (is_string($url)) :
		$parts = parse_url($url);
		$host = $parts['host'];
	elseif (is_null($url)) :
		$host = 'this page';
	else :
		throw new Exception("This parameter sucks: ".json_encode($url));
	endif;

	$links = [];
	$links[] = get_archive_service_link(get_archive_request_url(), $url, 'all');
	$links[] = get_archive_service_link('https://archive.today/', $url, 'archive.today: '.$host);
	$links[] = get_archive_service_link('https://archive.org/', $url, 'Internet Archive: '.$host);
	
	$wrapperHtml = '<span class="archive-services">%s</span>';
	$linksHtml = implode(" ", $links);
	return sprintf($wrapperHtml, $linksHtml);
} /* get_all_archive_services_links () */

function get_archive_service_link ($serviceUrl, $url = null, $text = null) {
	$html = '';
	$imgSrc = "/assets/images/icon_savePage.png";
	$imgAlt = 'add to';
	$aHref = $serviceUrl;
	
	$serviceParts = parse_url($serviceUrl);
	$url = (is_null($url) ? '' : urlencode($url));
	
	$aHrefPattern="%s#%s";
	if ($serviceUrl == 'https://archive.today/') :
		if (is_null($text)) :
			$text = $serviceParts['host'];
		endif;
	elseif ($serviceUrl == 'https://archive.org/') :
		if (is_null($text)) :
			$text = 'Internet Archive';
		endif;
	elseif ($serviceUrl == get_archive_request_url()) :
		if (is_null($text)) :
			$text = 'all';
		endif;
		$aHrefPattern="%s/%s";
		$url = urlencode($url); // double-encode to end-around apache
	else :
		if (is_null($text)) :
			$text = $serviceParts['host'];
		endif;
	endif;
	
	$aHref = htmlspecialchars(sprintf($aHrefPattern, $serviceUrl, $url));
	$aText = htmlspecialchars($text);
	$imgSrc = htmlspecialchars($imgSrc);
	$imgAlt = htmlspecialchars($imgAlt);
	$html = "<a class='archive-service' style='font-size: 10px; vertical-align: bottom;' href=\"${aHref}\"><img style='vertical-align: bottom; height: 16px; width: auto;' src='${imgSrc}' alt='${imgAlt}' /> ${aText}</a>";
	return $html;
}

