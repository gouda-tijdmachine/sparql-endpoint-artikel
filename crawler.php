#!/usr/bin/php
<?php

/*

composer.json:

{
    "require": {
        "easyrdf/easyrdf": "^1.1"
    }
}


*/


require_once realpath(__DIR__.'/..')."/vendor/autoload.php";

$resources_seen=array();
$resources_todo=array();

$next='https://www.goudatijdmachine.nl/data/api/items?per_page=100&sort_by=id&sort_order=asc&page=1';

while (!empty($next)) {
	$next=get_content_and_next($next);
}

while (!empty($resources_todo)) {
	$resource = array_shift($resources_todo);
	if (!isset($resources_seen[$resource])) {
		get_content($resource);
		$resources_seen[$resource]=1;
	}
}

function get_content($url) {

	error_log($url);

	list($headers,$body)=get_headers_body($url);

	if (preg_match("/HTTP\/2 404/",$headers)) {
		error_log("ERROR: 404 on $url");
	} elseif (preg_match("/HTTP\/2 500/",$headers)) {
		error_log("ERROR: 500 on $url");
		exit;
	} else {
		process_content($body);
	}
}

function get_content_and_next($url) {

	list($headers,$body)=get_headers_body($url);

	if (preg_match("/HTTP\/2 404/",$headers)) {
		error_log("ERROR: 404 on $url");
	} elseif (preg_match("/HTTP\/2 403/",$headers)) {
		error_log("ERROR: 403 on $url");
	} elseif (preg_match("/HTTP\/2 500/",$headers)) {
		error_log("ERROR: 500 on $url");
		exit;
	} else {
		process_contents($body);
		return get_next($headers);
	}
}

function process_jsonld($json) {
	global $resources_todo,$resources_seen;

	if (isset($json["@id"])) {
		$id=$json["@id"];
		$resources_seen[$id]=1;
		
		$body=json_encode($json);
		$body=preg_replace('#\\\/#',"/",$body);
		preg_match_all('#https:\/\/www\.goudatijdmachine\.nl\/data\/[a-z_\-\/]+[0-9]+#',$body,$uris);
		
		foreach($uris[0] as $uri) {
			if (!isset($resources_seen[$uri])) {
				$resources_todo[]=$uri;
			}
		}
		
		#
		# de URI's van de resources worden gebaseerd op ARK (niet Omeka ID) als het kan
		#
		if (isset($json['dcterms:identifier'])) {
			$ark="https://n2t.net/".$json['dcterms:identifier'][0]['@value'];
		}

		if (isset($ark)) {
			$sameAs=array();;
			$sameAs['@id']=$id;
			$sameAs['http://www.w3.org/2002/07/owl#sameAs']=array('@id'=>$ark);
			$json['@id']=$ark;
			save_if_changed($ark,'['.json_encode($json).','.json_encode($sameAs).']');
		} else {
			save_if_changed($id,$body);
		}
	}
}

function save_if_changed($id,$contents) {
	$mid=md5($id);
	$mcs=md5($contents);
			
	if (file_exists("nt/$mid.hash")) {
		$mco=file_get_contents("nt/$mid.hash");
		if ($mco==$mcs) {
			#error_log("no change");
			return;
		}
	}

	$graph = new \EasyRdf\Graph($id);
	$graph->parse($contents, "jsonld", $id);
	$output = $graph->serialise("nt");

	file_put_contents("nt/$mid.nt",$output);
	file_put_contents("nt/$mid.hash",$mcs);
}


function process_content($body) {
	$json=json_decode($body,true);
	process_jsonld($json);
}

function process_contents($body) {
	$jsonarray=json_decode($body,true);
	foreach($jsonarray as $json) {
		process_jsonld($json);
	}
}

function get_next($headers) {
	# link: <https://www.goudatijdmachine.nl/data/api/items?item_set_id=13004&sort_by=id&sort_order=asc&page=1>; rel="first", <https://www.goudatijdmachine.nl/data/api/items?item_set_id=13004&sort_by=id&sort_order=asc&page=2>; rel="next", <https://www.goudatijdmachine.nl/data/api/items?item_set_id=13004&sort_by=id&sort_order=asc&page=238>; rel="last"

	if (preg_match("/\<([^\>\;]+)\>\; rel\=\"next\"/",$headers,$matches)) {
		return $matches[1];
	}
	return '';
}	
	
function get_headers_body($url) {
	error_log("INFO: Fetching $url");
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);

	$response = curl_exec($ch);
	if (curl_errno($ch)) {
		echo 'Error:' . curl_error($ch);
		exit();
	}

	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$headers = substr($response, 0, $header_size);
	$body = substr($response, $header_size);

	curl_close($ch);

	return array($headers,$body);
}