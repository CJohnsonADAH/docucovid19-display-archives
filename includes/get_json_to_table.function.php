<?php
function get_json_to_table ($hash, $slug) {
	$data = ['THEAD' => [], 'TBODY' => []];
	
	$props[$slug] = null;
	$props = array_merge($props, [
		"capture" => ["CNTYNAME", "CNTYFIPS", "ADPHDistrict", "CONFIRMED", "DIED", "REPORTED_DEATH"],
		"testsites" => null,
	]);
	
	if (is_object($hash) and property_exists($hash, "fields")) :
		foreach ($hash->fields as $field) :
		if (is_null($props[$slug]) or in_array($field->name, $props[$slug])) :
			$data['THEAD'][] = [$field->name, $field->alias];
		endif;
		endforeach;

		foreach ($hash->features as $feat) :
		$tr = [];
		foreach ($feat->attributes as $key => $value) :
			if (is_null($props[$slug]) or in_array($key, $props[$slug])) :
				$tr[$key] = $value;
			endif;
		endforeach;		
		$data['TBODY'][] = $tr;
		endforeach;
	elseif (is_object($hash) and property_exists($hash, 'type') and "FeatureCollection" == $hash->type) :
	
		if (property_exists($hash, "features")) :
			$props = array_reduce($hash->features, function ($carry, $feat) {
				$props = $carry;
				if (property_exists($feat, "properties")) :
					$props = array_merge($props, array_map(function ($e) { return "properties." . $e; }, array_keys((array) $feat->properties)));
				endif;
				if (property_exists($feat, "geometry")) :
					$props[] = 'geometry';
				endif;
				return array_unique($props);
			}, []);

			$data['THEAD'] = array_map(function ($e) { $al = explode(".", $e, 2); return [$e, end($al)]; }, $props);

			$data['TBODY'] = array_reduce($hash->features, function ($table, $feat) use ($props) {
				foreach ($props as $prop) :
					$obj = $feat;
					$path = explode(".", $prop, 2);
					foreach ($path as $part) :
						$obj = $obj->{$part};
					endforeach;
					
					$row[$prop] = (is_object($obj) ? json_encode($obj) : $obj);
				endforeach;
				$table[] = $row;
				return $table;
			}, []);
		endif;
	
	else :
		
		$data['THEAD'] = ["Property", "Value"];
		foreach ($hash as $prop => $value) :
			$data['TBODY'][] = ["Property" => $prop, "Value" => "<pre>".htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT))."</pre>"];
		endforeach;

	endif;
	
	return $data;

} /* get_json_to_table () */

