<?php

class OHLCController extends DooController {

	function ohlc() {
		//predis class
		require 'Predis/lib/Predis/Autoloader.php';
		Predis\Autoloader::register();
		
		//callback : required
		$callback = $_GET['callback'];
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $callback)) {
			die('Invalid callback name');
		}

		//pair : required
		$pair = $_GET['pair'];
		if (!isset($pair) || !preg_match('/^[A-Z]{3}\/[A-Z]{3}$/', $pair)) {
			die("Invalid pair parameter: $pair");
		}

		//start : optional (default : now - 1 day)
		$start = $_GET['start'];
		if ($start && !preg_match('/^[0-9]+$/', $start)) {
			die("Invalid start parameter: $start");
		}
		if (!$start) $start = (time() - 86400) * 1000;

		//end : optional (default : now())
		$end = $_GET['end'];
		if ($end && !preg_match('/^[0-9]+$/', $end)) {
			die("Invalid end parameter: $end");
		}
		if (!$end) $end = time() * 1000;
	
		//bid_offer : optional (default : 'bid')
		$bid_offer = $_GET['bid_offer'];
		if ($bid_offer && !preg_match('/^(bid|offer)$/', $bid_offer)) {
			die("Invalid bid_offer parameter: $bid_offer");
		}
		if (!$bid_offer) $bid_offer = 'bid';

		//force timeslice [s,m,h,d,w,M]
		$timeslice = $_GET['timeslice'];

		// set some utility variables
		$range = $end - $start;
		$startTime = gmstrftime('%Y-%m-%d %H:%M:%S', $start / 1000);
		$endTime = gmstrftime('%Y-%m-%d %H:%M:%S', $end / 1000);

		//set suggested timeslice depending on range
		//[1s,5s,10s,20s,30s,1m,5m,10m,20m,30m,1h,2h,3h,6h,1d,1w,1M]
		if(!isset($timeslice)){

			$stick_threshold = 300; //on screen count threshold

			//seconds
			if($range < $stick_threshold * 1000){
				$timeslice = '1s';
			}elseif($range < ($stick_threshold * 5 * 1000)){
				$timeslice = '5s';
			}elseif($range < ($stick_threshold * 10 * 1000)){
				$timeslice = '10s';
			}elseif($range < ($stick_threshold * 20 * 1000)){
				$timeslice = '20s';
			}elseif($range < ($stick_threshold * 30 * 1000)){
				$timeslice = '30s';

			//minutes
			}elseif($range < ($stick_threshold * 60 * 1000)){
				$timeslice = '1m';
			}elseif($range < ($stick_threshold * 60 * 5 * 1000)){
				$timeslice = '5m';
			}elseif($range < ($stick_threshold * 60 * 10 * 1000)){
				$timeslice = '10m';
			}elseif($range < ($stick_threshold * 60 * 20 * 1000)){
				$timeslice = '20m';
			}elseif($range < ($stick_threshold * 60 * 30 * 1000)){
				$timeslice = '30m';

			//hour
			}elseif($range < ($stick_threshold * 60 * 60 * 1000)){
				$timeslice = '1h';
			}elseif($range < ($stick_threshold * 60 * 60 * 2 * 1000)){
				$timeslice = '2h';
			}elseif($range < ($stick_threshold * 60 * 60 * 3 * 1000)){
				$timeslice = '3h';
			}elseif($range < ($stick_threshold * 60 * 60 * 6 * 1000)){
				$timeslice = '6h';

			//day
			}elseif($range < ($stick_threshold * 60 * 60 * 24 * 1000)){
				$timeslice = '1d';

			//week
			}elseif($range < ($stick_threshold * 60 * 60 * 7 * 1000)){
				$timeslice = '1w';

			//month 
			}else{
				$timeslice = '1M';
			}
		}

		//validate and parse timeslice
		if(!preg_match('/^(?P<dur>\d+)(?P<len>s|m|h|d|w|M)$/', $timeslice, $matches)){
			die("Invalid timeslice parameter: $timeslice");
		}

		//timeslice duration (1,2,3,4,..)
		$ts_duration = $matches['dur'];
		//timeslice length (s,m,h,d,w,M)
		$ts_len = $matches['len'];

		//check durations modulus of parent
		//60 seconds in a minute and 60 minute in an hour
		if(in_array($ts_len, array('s', 'm'))){
			if((60 % $ts_duration) != 0)
				//not evenly divisable by 60
				die("Invalid 1timeslice parameter: $ts_duration$ts_len");
		}
		//24 hours in day
		if(in_array($ts_len, array('h'))){
			if((24 % $ts_duration) != 0)
				//not evenly divisable by 24
				die("Invalid timeslice parameter: $ts_duration$ts_len");
		}

		//max 2 day|week|month
		if(in_array($ts_len, array('d', 'w', 'M'))){
			if($ts_duration > 2)
				//not valid duration
				die("Invalid timeslice parameter: $ts_duration$ts_len");
		}

		//check are not cacheing week and month
		$is_caching = in_array($ts_len, array('w', 'M')) ? false : true;

		//init vars
		$result = array();
		$cache = array();
		$time_slices = array();
		$empty_time_slices = array();
		$is_cache_miss = false;
		$redis = new Predis\Client();
		$redis_zset = "$pair:$bid_offer:$timeslice";
		
		//query redis cache
		if($is_caching){
			//check redis cache
			$return = $redis->zrangebyscore($redis_zset, $start/1000, $end/1000, array(
			    'withscores' => true
			));
			//var_dump($return);

			//build cache datastructure
			foreach($return as $row){
				$cache[$row[1]] = $row[0];
			}
			
			//save
			$results = $cache;
					
			//get list of expected time slices
			$time_slices = $this->buildTimeSlices($ts_len, $ts_duration, $start/1000, $end/1000);

			//get empty timeslices
			$return = $redis->zrangebyscore('empty:' . $redis_zset, $start/1000, $end/1000, array(
			    'withscores' => true
			));

			//remove empty timeslices from time_slices
			foreach($return as $row){
				unset($time_slices[$row[1]]);
			}

			//find missing slices
			$cache_miss_slices = array_diff_key($time_slices, $cache);

			//var_dump($cache_miss_slices);
			//var_dump($time_slices);

			//check for cache misses
			if(count($cache_miss_slices) > 0 ){
				$is_cache_miss = true;
				$redis->incr('cache_misses');
			}else{
				$redis->incr('cache_hits');
			}
				
		}


		//hit database if we are not caching or if we have a cache miss
		if(!$is_caching || $is_cache_miss){

			//build sql
			$sql = "";
			//if daily or above, use aggregated daily table otherwize use raw quotes
			if(in_array($ts_len, array('d', 'w', 'M'))){
		
				$sql = "SELECT "; 

	    			if($ts_len == 'd')
	    				$sql .= "ts, UNIX_TIMESTAMP(ts) * 1000 as 'datetime',";
	 				else if($ts_len == 'w')
						$sql .= "ADDDATE(ts, INTERVAL 1-DAYOFWEEK(ts) DAY) timeslice, UNIX_TIMESTAMP(ADDDATE(ts, INTERVAL 1-DAYOFWEEK(ts) DAY)) * 1000 as 'datetime',";
	 					else if($ts_len == 'M')
							$sql .= "ADDDATE(ts, INTERVAL 1-DAYOFMONTH(ts) DAY) timeslice, UNIX_TIMESTAMP(ADDDATE(ts, INTERVAL 1-DAYOFMONTH(ts) DAY)) * 1000 as 'datetime',";

				$sql .= "
				    MIN(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_low) as 'low',
				    MAX(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_high) as 'high',
				    SUBSTRING_INDEX(GROUP_CONCAT(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_open ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            + 1) AS 'open',
				    SUBSTRING_INDEX(GROUP_CONCAT(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_close ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            - 1) AS 'close',
				    SUM(vol) as 'vol'
				FROM
				    agg_day
				WHERE
					pair = '$pair' AND ts BETWEEN '$startTime' and '$endTime'
				GROUP BY 1
				ORDER BY datetime";

			//seconds and minutes use raw quotes
			}else if(in_array($ts_len, array('s', 'm'))){

				//convert timeslice duration to seconds
				if($ts_len ==  'm')
					$ts_duration *= 60;

				//sql quotes
				$sql = "SELECT 
				    pair,
				    FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / $ts_duration) * $ts_duration) AS 'timeslice',
				    UNIX_TIMESTAMP( FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / $ts_duration) * $ts_duration) ) * 1000 as 'datetime',
				    MIN($bid_offer) as 'low',
				    MAX($bid_offer) as 'high',
				    SUBSTRING_INDEX(GROUP_CONCAT($bid_offer ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            + 1) AS 'open',
				    SUBSTRING_INDEX(GROUP_CONCAT($bid_offer ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            - 1) AS 'close',
				    count(*) as 'vol'
				FROM
				    quotes
				WHERE
				    pair = '$pair' AND ts BETWEEN '$startTime' and '$endTime'
				GROUP BY timeslice
				ORDER BY datetime";

			//hour table
			}else if($ts_len == 'h'){
				//3600 seconds in a hour
				$ts_duration *= 3600;

				$sql = "SELECT 
					pair,
					FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / $ts_duration) * $ts_duration) AS 'timeslice',
				  	UNIX_TIMESTAMP(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / $ts_duration) * $ts_duration) ) * 1000 as 'datetime',
				    MIN(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_low) as 'low',
				    MAX(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_high) as 'high',
				    SUBSTRING_INDEX(GROUP_CONCAT(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_open ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            + 1) AS 'open',
				    SUBSTRING_INDEX(GROUP_CONCAT(" . ($bid_offer == 'bid' ? 'bid' : 'offer') . "_close ORDER BY ts
				                SEPARATOR ','),
				            ',',
				            - 1) AS 'close',
				    SUM(vol) as 'vol'
				FROM
				    agg_hour
				WHERE
				     pair = '$pair' AND ts BETWEEN '$startTime' and '$endTime'
				GROUP BY timeslice
				ORDER BY datetime";
			}

			//var_dump($sql);
			//query database
			$rows = array();
			$res = Doo::db()->fetchAll($sql);

			//save to local array and 
			$pipe = $redis->pipeline();
			foreach($res as $row){
				extract($row);
				$rows[] = "[$datetime,$open,$high,$low,$close,$vol]";
				$pipe->zadd($redis_zset, $datetime/1000, "[$datetime,$open,$high,$low,$close,$vol]");

				//remove calculated timeslices
				unset($time_slices[$datetime/1000]);
			}
			$replies = $pipe->execute();

			//save remaining timeslices
			$pipe = $redis->pipeline();
			foreach($time_slices as $key => $val){
				$pipe->zadd('empty:' . $redis_zset, $key, $key);
			}
			$replies = $pipe->execute();

			//save
			$results = $rows;
		}	

		// print it
		header('Content-Type: text/javascript');

		echo "/* console.log(' start = $start, end = $end, startTime = $startTime, endTime = $endTime '); */";
		echo $callback ."([\n" . join(",\n", $results)."\n]);";

	}

	//$start and $end in unixtimestamp
	private function buildTimeSlices($ts_len, $ts_duration, $start, $end){
		//to seconds
		$to_seconds_factor['s'] = 1;
		$to_seconds_factor['m'] = 60;
		$to_seconds_factor['h'] = 60 * 60;
		$to_seconds_factor['d'] = 60 * 60 * 24;

		//TODO round up to find the first valid time slice
		$val = floor($start / $ts_duration) * $ts_duration;
		
		$data = array();
		while($val < $end){

			if($val >= $start)
				$data[$val] =  date( 'Y-m-d H:i:s', $val);
			$val += $to_seconds_factor[$ts_len] * $ts_duration;
		}

		return $data;
	}

}
?>