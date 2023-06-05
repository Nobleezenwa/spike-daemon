<?php
/**
	*
	*
		A PHP daemon for monitoring spikes in Crypto value. Currently supports the Binance and Hotbit Exchange.
	*
	*
*/

error_reporting(0);
//run 'forever'
set_time_limit(0);
ignore_user_abort(true);


//helper functions
function filePutContents($f, $c){
	$handle = fopen($f, "c+");
	$ws = false;
	do {
		if (flock($handle, LOCK_EX)) {
			ftruncate($handle, 0);
			fwrite($handle, $c);
			fflush($handle);
			flock($handle, LOCK_UN);
			$ws = true;
		}
	} while ($ws == false);	
	fclose($handle);
}
function fileGetContents($f) {
	if (!file_exists($f)) { return null; }
	$handle = fopen($f, "r");
	$rs = false;
	do {
		if (flock($handle, LOCK_EX)) {
			clearstatcache();
			$c = fread($handle, filesize($f));
			flock($handle, LOCK_UN);
			$rs = true;
		}
	} while ($rs == false);	
	fclose($handle);
	return $c;
}
function stdio($l, $c = null) {
	$storage = fileGetContents('stream-'.$l.'.stream');
	if (empty($storage) and $c === null) { return null; }
	$storage = (empty($storage))? '' : unserialize($storage);
	if ($c !== null) {
		$storage = $c;
	} else { 
		$c = $storage;
	}
	filePutContents('stream-'.$l.'.stream', serialize($storage));
	return $c;
}
function console_log($m) {
	static $LAST_LOG;
	if ($LAST_LOG == $m) { return; }
	$LAST_LOG = $m;
	$handle = fopen('stream-logs.stream', "a");
	$ws = false;
	do {
		if (flock($handle, LOCK_EX)) {
			fwrite($handle, serialize([microtime(true), $m]).PHP_EOL);
			$ws = true;
		}
	} while ($ws == false);	
	fclose($handle);
	$ls = stdio('log_count');
	if ($ls == null) { $ls = 0; }
	stdio('log_count', ($ls + 1));
}
function quit($m = null) {
	GLOBAL $QUIT;
	if ($QUIT) { return; }
	if ($m != null) { console_log($m); }
	stdio('is_running', false);
	console_log('Daemon terminated successfully.');
	$QUIT = true;
	exit();
}
function is_killed() {
	if (stdio('kill') == true) {
		quit('Killing daemon now...');
	}
}
function fetch($endpoint, $data = false, $method = 'POST', $headers = false) {
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $endpoint);
	curl_setopt($curl, CURLOPT_FAILONERROR, false);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
	curl_setopt($curl, CURLOPT_TIMEOUT, 14);
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
	if ($data and $method == 'POST') { curl_setopt($curl, CURLOPT_POST, 1); curl_setopt($curl, CURLOPT_POSTFIELDS, $data); }
	if ($headers) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); }	
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //*****for localhost	
	$response = curl_exec($curl);
	$err = '';
	if ($response === false or curl_errno($curl)) { $err .= 'CURL: '.curl_error($curl); }
	$rescode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	//if ($rescode >= 400) { $err .= ' HTTP error occurred - '.$rescode; }
	curl_close($curl);
	if (!empty($err)) { return [false, $err]; }
	return [true, $response];
}
function multi_fetch($endpoints, $data = false, $method = 'POST', $headers = false) {
	$mh = curl_multi_init();
	foreach ($endpoints as $endpoint) {
		$curl = curl_init($endpoint); 
		curl_setopt($curl, CURLOPT_URL, $endpoint);
		curl_setopt($curl, CURLOPT_FAILONERROR, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 14);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		if ($data and $method == 'POST') { curl_setopt($curl, CURLOPT_POST, 1); curl_setopt($curl, CURLOPT_POSTFIELDS, $data); }
		if ($headers) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); }	
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //*****for localhost	
		curl_multi_add_handle($mh, $curl); 
	}	
	for (;;) { 
		$still_running = null;
		do { $err = curl_multi_exec($mh, $still_running); } while ($err === CURLM_CALL_MULTI_PERFORM); 
		if ($err !== CURLM_OK) { throw new Exception('CURL: '.curl_multi_strerror(curl_multi_errno($mh))); }
		if ($still_running < 1) { break; }
		curl_multi_select($mh, 1);
	}
	$res = []; $errs = []; $handle = [];
	while (false !== ($info = curl_multi_info_read($mh))) {
		if ($info["msg"] !== CURLMSG_DONE) { continue; }
		if ($info["result"] !== CURLE_OK) {
			$type = 'ERROR';
			$e = 'CURL: '.curl_strerror($info["result"]).'. code '.$info["result"];
			if ($info["result"] === CURLE_HTTP_RETURNED_ERROR) { $e .= '. HTTP error code '.curl_getinfo($info["handle"], CURLINFO_HTTP_CODE); }
			array_push($errs, $e);
			$id = count($errs) - 1;
		}
		elseif (CURLE_OK !== ($err = curl_errno($info["handle"]))) { $type = 'ERROR'; array_push($errs, 'CURL: '.curl_strerror($err)); $id = count($errs) - 1; }
		else { $type = 'OK'; array_push($res, curl_multi_getcontent($info["handle"])); $id = count($res) - 1; }
		array_push($handle, array(
						'url' => curl_getinfo($info["handle"], CURLINFO_EFFECTIVE_URL),
						'code' => curl_getinfo($info["handle"], CURLINFO_HTTP_CODE),
						'type' => $type,
						'id' => $id,
					));
		curl_multi_remove_handle($mh, $info["handle"]); 
		curl_close($info["handle"]); 
	}
	curl_multi_close($mh); 
	return array(
		'responses' => $res,
		'errors' => $errs,
		'handle' => $handle
	);
}


//initialize internal states
$QUIT = false;
stdio('kill', false);
stdio('is_running', getmypid());
register_shutdown_function('quit');


//BinanceSpikeAlgo 
class BinanceSpikeAlgo {
	//public $baseURL = 'https://testnet.binance.vision';
	//public $apiKey = '';
	//public $apiSecret = '';
	public $baseURL = 'https://api.binance.com';
	public $apiKey = '';
	public $apiSecret = '';
	public $tpc = null;		//target percentage change
	public $mct = null;		//maximum time to change
	public $capital = null;
	public $maxOrders = null;
	public $qouteCurrency = null;
	public $rtp = null;
	public $verbose = false;
	public $safeModeOn = true;
	public $balances = [];
	//public $oldbalances = []; //for manually detecting trades as trade data requests can be weight-expensive
	public $tickerPairs = null;
	public $pricePrecision = [];
	public $quantityPrecision = [];
	public $minNotional = [];
	public $rollingWindows = [];
	public $priceHistory = [];
	public $orderCountLeft = 18;
	public $orderCountTimer = false;
	public $pairsOrdered = [];

	public function __construct($setup) {
		if ($setup['mct'] < 1) { throw new Exception('Minimum change time is 1 min.'); return false; }
		$this->tpc = (float)$setup['tpc'];
		$this->mct = (float)$setup['mct'];
		$this->capital = (float)$setup['split_capital'];
		$this->qouteCurrency = $setup['currency'];
		$this->rtp = (float)$setup['rtp'];
		$this->verbose = (bool)$setup['debug_mode'];
		$this->safeModeOn = (bool)$setup['safe_mode'];
		$this->orderCountTimer = time();
	}

	private function console_log($m) {
		if (!$this->verbose) { return; }
		console_log($m);
	}

	public function getTickerPairs() {
		$endpoint = $this->baseURL.'/api/v3/exchangeInfo';
		$content = fetch($endpoint, false, 'GET', ["Content-Type: application/x-www-form-urlencoded"]);
		if (!$content[0]) { throw new Exception($content[1]); return false; }
		$content = json_decode($content[1], true);
		if (isset($content['code']) and isset($content['msg'])) { throw new Exception($content['msg']); return false; }
		if (!array_key_exists('symbols', $content)) {
			if (array_key_exists('code', $content) and array_key_exists('msg', $content)) { throw new Exception($content['msg']); }
			else { throw new Exception('An unknown error occurred. Unable to get ticker pairs for Binance. '.json_encode($content)); }
			return false;
		}
		$getArray = function($in_arrs, $where, $equals) {
			foreach($in_arrs as $arr) {
				if ($arr[$where] == $equals) {
					return $arr;
				}
			}
		};
		$this->tickerPairs = []; $ql = strlen($this->qouteCurrency) * -1;
		$minQuote = -INF; $minTrailStop = -INF;
		foreach ($content['symbols'] as $symbol_info) {
			if (substr($symbol_info['symbol'], $ql) == $this->qouteCurrency) {
				$this->pricePrecision[$symbol_info['symbol']] = (float)$getArray($symbol_info['filters'], 'filterType', 'PRICE_FILTER')['tickSize'];
				$this->quantityPrecision[$symbol_info['symbol']] = (float)$getArray($symbol_info['filters'], 'filterType', 'LOT_SIZE')['stepSize'];
				$this->minNotional[$symbol_info['symbol']] = (float)$getArray($symbol_info['filters'], 'filterType', 'MIN_NOTIONAL')['minNotional'];
				$minQuote = max($minQuote, $this->minNotional[$symbol_info['symbol']]);
				$minTrailStop = max($minTrailStop, (float)$getArray($symbol_info['filters'], 'filterType', 'TRAILING_DELTA')['minTrailingBelowDelta']);
				array_push($this->tickerPairs, $symbol_info['symbol']);
			}
		}
		if (($this->rtp * 100) < $minTrailStop) {
			throw new Exception('Risk tolerance is too low for exchange. RTP should be greater than '.($minTrailStop / 100).'%');
			return false;
		}
		$minQuote = $minQuote + ($minQuote * ($this->rtp / 100));
		if (!$this->safeModeOn and $this->capital <= $minQuote) {
			$this->tickerPairs = [];
			throw new Exception('Capital provided will be insufficient to create orders in some markets. Capital should be greater than '.$minQuote.' '.$this->qouteCurrency);
			return false;
		}
		/*
		//for test mode only
		$this->getBalances();
		$this->oldbalances = $this->balances;
		$this->oldbalances['USDT'] = 0;
		*/
		return count($this->tickerPairs);
	}

	public function getBalances() {
		$this->balances = [];
		$payload = 'recvWindow=5000';
		$payload .= '&timestamp='.round(microtime(true) * 1000);
		$payload .= '&signature='.hash_hmac('sha256', $payload, $this->apiSecret);
		$content = fetch($this->baseURL.'/api/v3/account?'.$payload, false, 'GET', ["Content-Type: application/x-www-form-urlencoded", "X-MBX-APIKEY: ".$this->apiKey]);
		$ptime = time();
		if (!$content[0]) { throw new Exception($content[1]); return false; }
		$content = json_decode($content[1], true);
		if (isset($content['code']) and isset($content['msg'])) { throw new Exception($content['msg']); return false; }
		foreach($content['balances'] as $balance) { $this->balances[$balance['asset']] = $balance['free']; }
		//foreach($content['balances'] as $balance) { $this->balances[$balance['asset']] = $balance['free'] - $this->oldbalances[$balance['asset']]; }
		arsort($this->balances);
		return count($this->balances);
	}

	public function getRollingWindows() {
		static $lastRollingWindows = 0;
		if ((time() - $lastRollingWindows) <= 900) { $this->console_log('Rolling window not yet expired.'); return count($this->tickerPairs); } //run function only every 15 mins
		$this->rollingWindows = [];
		$groups = array_chunk($this->tickerPairs, 100);
		$errs = [];
		foreach ($groups as $group) {
			$endpoint = $this->baseURL.'/api/v3/ticker?type=FULL&windowSize=15m&symbols='.urlencode(json_encode($group));
			$content = fetch($endpoint, false, 'GET', ["Content-Type: application/x-www-form-urlencoded"]);
			if (!$content[0]) { array_push($errs, $content[1]); continue; }
			$content = json_decode($content[1], true);
			if (isset($content['code']) and isset($content['msg'])) { array_push($errs, $content['msg']); continue; }
			foreach ($content as $symbol_info) {
				$s = $symbol_info['symbol'];
				$this->rollingWindows[$s] = $symbol_info;
			}
		}
		
		$deleted = [];
		foreach ($this->pairsOrdered as $s => $order) { if ($this->pairsOrdered[$s]['delete'] == true) { array_push($deleted, $s); } }
		foreach ($deleted as $s) { $this->pairsOrdered[$s] = null; unset($this->pairsOrdered[$s]['cancel']); $this->priceHistory[$s] = []; } 
		
		if (count($this->rollingWindows) > 0) {
			$lastRollingWindows = time();
			$sort = array_column($this->rollingWindows, 'quoteVolume');
			array_multisort($sort, SORT_ASC, $this->rollingWindows);
			return count($this->rollingWindows);
		} else {
			array_push($errs, 'Unable to get rolling windows'); 
			throw new Exception(implode(' | ', $errs));
		}
	}

	public function getPriceHistory() {
		$content = fetch($this->baseURL.'/api/v3/ticker/price', false, 'GET', ["Content-Type: application/x-www-form-urlencoded"]);
		$ptime = time();
		$mct = $this->mct * 60;
		if (!$content[0]) { throw new Exception($content[1]); return false; }
		$content = json_decode($content[1], true);
		if (isset($content['code']) and isset($content['msg'])) { throw new Exception($content['msg']); return false; }
		foreach ($content as $symbol_info) {
			if (!in_array($symbol_info['symbol'], $this->tickerPairs)) { continue; }
			$s = $symbol_info['symbol'];
			if (!isset($this->priceHistory[$s])) { $this->priceHistory[$s] = []; }
			array_push($this->priceHistory[$s], [$ptime, $symbol_info['price']]);
			//remove outdated qoutes
			if (!isset($this->pairsOrdered[$s]) or (isset($this->pairsOrdered[$s]) and $this->pairsOrdered[$s]['type'] != 'virtual')) { while (($ptime - $this->priceHistory[$s][0][0]) > ($mct+15)) { array_shift($this->priceHistory[$s]); } }
		}
		return count($this->priceHistory);
	}

	public function createOrders() {
		if ((time() - $this->orderCountTimer) > 10) { $this->orderCountTimer = time(); $this->orderCountLeft = 18; }

		if ($this->orderCountLeft <= 0) { return [null, 'Out of order count!!']; }
		
		$ptime = time();
		$mct = $this->mct * 60;

		//select all pairs with best chances from rollingWindows
		$bestpairs = [];
		foreach($this->rollingWindows as $s => $symbol_info) {
			$this->console_log('Analysing '.$s);
			//check if already ordered
			if (isset($this->pairsOrdered[$s])) { $this->console_log($s.' has been ordered previously'); continue; }
			//check for outdated qoutes
			if (($ptime - $this->priceHistory[$s][0][0]) > ($mct+15)) { $this->console_log($s.' price history is outdated'); continue; }
			if (($ptime - $this->priceHistory[$s][0][0]) < ($mct)) { $this->console_log($s.' price history is incomplete'); continue; }
			$pn = count($this->priceHistory[$s]);
			$pc = (($this->priceHistory[$s][($pn - 1)][1] - $this->priceHistory[$s][0][1]) / $this->priceHistory[$s][0][1]) * 100;
			$this->console_log($s.' current pchange is '.$pc);
			if ($pc >= $this->tpc) {
				$symbol_info['priceChangePercent'] = $pc;
				$symbol_info['historytime'] = round((($ptime - $this->priceHistory[$s][0][0]) / 60), 2);
				$bestpairs[$s] = $symbol_info;
				$this->console_log($s.' placed in best pairs');
			} else {
				$this->console_log($s.' not the best trade for now');
			}
		}

		$this->console_log('Best pairs => '.json_encode($bestpairs));
		if (count($bestpairs) == 0) { return [null, 'No pontential trades found']; }
		
		//sort pairs by priceChangePercent DESC
		$sort = array_column($bestpairs, 'priceChangePercent');
		array_multisort($sort, SORT_DESC, $bestpairs);
		$this->console_log('Best pairs after sorting => '.json_encode(array_keys($bestpairs)));
		
		//select top x pairs and send buy orders
		$precise = function($n, $p) { return (floor($n / $p) * $p); };
		$balance = $this->balances[$this->qouteCurrency];
		$bestpairs = array_slice($bestpairs, 0, $this->orderCountLeft);
		$orders = []; $errs = [];
		foreach ($bestpairs as $symbol_info) {
			$symbol = $symbol_info['symbol'];
			$p = $this->priceHistory[$symbol][(count($this->priceHistory[$symbol]) - 1)][1];
			$pc = $this->tpc - $symbol_info['priceChangePercent'];
			$stop_price = $p + (($pc / 100) * $p);
			$limit_price = $stop_price + (($this->rtp / 100) * $stop_price);
			$payload = 'symbol='.$symbol;
			$payload .= '&side=BUY';
			$payload .= '&type=STOP_LOSS_LIMIT';
			$payload .= '&timeInForce=GTC';
			$payload .= '&quantity='.$precise(($this->capital / $limit_price), $this->quantityPrecision[$symbol_info['symbol']]);
			$payload .= '&price='.$precise($limit_price, $this->pricePrecision[$symbol_info['symbol']]);
			$payload .= '&stopPrice='.$precise($stop_price, $this->pricePrecision[$symbol_info['symbol']]);
			$payload .= '&newOrderRespType=RESULT';
			$payload .= '&recvWindow=9000';
			$payload .= '&timestamp='.round(microtime(true) * 1000);
			if ($balance >= $this->capital) {
				$payload .= '&signature='.hash_hmac('sha256', $payload, $this->apiSecret);
				$orders[$symbol] = $this->baseURL.'/api/v3/order?'.$payload;
				$balance -= $this->capital;
			} else {
				//send replacement order
				//use current symbol to replace any symbol in pairsOrdered and which is not in $bestpairs
				$pairToReplace = false;
				foreach ($this->pairsOrdered as $s => $buyorder) {
					if (!in_array($s, array_keys($bestpairs)) and $buyorder['cancel'] == false) {
						$pairToReplace = $s;
					}
				}
				if ($pairToReplace == false) { array_push($errs, 'More orders to place but out of balance'); break; }
				$payload .= '&cancelReplaceMode=STOP_ON_FAILURE';
				$payload .= '&cancelOrderId='.$this->pairsOrdered[$pairToReplace]['order_id'];
				$payload .= '&signature='.hash_hmac('sha256', $payload, $this->apiSecret);
				$orders[$symbol] = $this->baseURL.'/api/v3/order/cancelReplace?'.$payload;
			}
		}
		$this->console_log('Orders to be placed => '.json_encode($orders));
		$data = [];
		if (count($orders) > 0) {
			if ($this->safeModeOn) {
				foreach($orders as $s => $url) {
					$pn = count($this->priceHistory[$s]);
					array_push($data, $s.'(entered @ '.round($bestpairs[$s]['priceChangePercent'], 2).'%)');
					$this->pairsOrdered[$s] = array(
														'timeout' => $ptime + ($this->mct * 60),
														'type' => 'virtual',
														'delete' => false,
														'entry' => $bestpairs[$s]['priceChangePercent'],
														'peak' => $bestpairs[$s]['priceChangePercent'],
														'peaktime' => $bestpairs[$s]['historytime'],
														'peakprice' => $this->priceHistory[$s][($pn - 1)][1]
													);
				}
				return [null, 'VIRTUAL ORDERS: '.implode(' | ', $data)];
			}
			$content = multi_fetch(array_values($orders), false, 'POST', ["Content-Type: application/x-www-form-urlencoded", "X-MBX-APIKEY: ".$this->apiKey]);
			$pairs = array_keys($orders);
			for($i = 0; $i < count($pairs); $i++) {
				$id = $content['handle'][$i]['id'];
				if ($content['handle'][$i]['type'] == 'OK') {
					$res = json_decode($content['responses'][$id], true);
					if (isset($res['code']) and isset($res['msg'])) { array_push($errs, $pairs[$i].'(Error '.$res['code'].': '.$res['msg'].')'); continue; }
					if (isset($res['cancelResult']) and $res['newOrderResult'] == 'SUCCESS') {
						//replacement order
						$s = $res['newOrderResponse']['symbol'];
						array_push($data, $s.'('.$res['newOrderResponse']['origQty'].')');
						$this->pairsOrdered[$s] = array(
														'timeout' => $ptime + ($this->mct * 60),
														'order_id' => $res['newOrderResponse']['orderId'],
														'quantity' => $res['newOrderResponse']['origQty'],
														'reversed_quantity' => 0,
														'cancel' => false,
														'type' => 'real',
														'delete' => false
													);
						$this->orderCountLeft -= 1;
					}
					elseif (!isset($res['cancelResult']) and !empty($res)) {
						//new order
						$s = $res['symbol'];
						array_push($data, $s.'('.$res['origQty'].')');
						$this->pairsOrdered[$s] = array(
														'timeout' => $ptime + ($this->mct * 60),
														'order_id' => $res['orderId'],
														'quantity' => $res['origQty'],
														'reversed_quantity' => 0,
														'cancel' => false,
														'type' => 'real',
														'delete' => false
													);
						$this->orderCountLeft -= 1;
					}
				} else {
					array_push($errs, $pairs[$i].'('.$content['errors'][$id].')');
				}
			}
			return [$data, implode(' | ', $errs)];
		} else {
			array_push($errs, 'No order sent');
			return [null, implode(' | ', $errs)];
		}
	}

	public function clearTimeouts() {
		if (count($this->pairsOrdered) == 0) { return [null, 'No expired orders']; }		
		//cancel all open buy orders -> unfilled and partially filled
		$cancels = [];
		foreach($this->pairsOrdered as $s => $buyorder) {
			$this->console_log('Removing '.$s);
			if ($buyorder['type'] == 'virtual') { $this->console_log($s.' is a virtual order. Nothing to cancel.'); continue; }
			if (time() < $buyorder['timeout']) { $this->console_log($s.' is not yet expired'); continue; }
			if ($buyorder['cancel'] == true) { $this->console_log($s.' is already canceled'); continue; }
			$payload = 'symbol='.$s;
			$payload .= '&orderId='.$buyorder['order_id'];
			$payload .= '&recvWindow=9000';
			$payload .= '&timestamp='.round(microtime(true) * 1000);
			$payload .= '&signature='.hash_hmac('sha256', $payload, $this->apiSecret);
			$cancels[$s] = $this->baseURL.'/api/v3/order?'.$payload;
		}
		$this->console_log('Cancel Orders to be placed => '.json_encode($cancels));
		$data = []; $errs = [];
		if (count($cancels) > 0) {
			$content = multi_fetch(array_values($cancels), false, 'DELETE', ["Content-Type: application/x-www-form-urlencoded", "X-MBX-APIKEY: ".$this->apiKey]);
			$pairs = array_keys($cancels);
			for($i = 0; $i < count($pairs); $i++) {
				if ($content['handle'][$i]['type'] == 'OK') {
					$id = $content['handle'][$i]['id'];
					$res = json_decode($content['responses'][$id], true); 
					//allow $res['code'] == -2011 since it may mean that order has been filled already
					if (isset($res['code']) and isset($res['msg']) and $res['code'] != -2011) { array_push($errs, $pairs[$i].'(Error '.$res['code'].': '.$res['msg'].')'); continue; }
				} elseif ($content['handle'][$i]['type'] == 'ERROR' and $content['handle'][$i]['http_code'] > 400) { //allow 400 error since it may mean that order has been filled already
					$id = $content['handle'][$i]['id'];
					array_push($errs, $pairs[$i].'('.$content['errors'][$id].')');
					continue;
				}
				$s = $pairs[$i];
				$this->pairsOrdered[$s]['cancel'] = true;
				array_push($data, $s);
				$this->console_log('Canceled '.$s);
			}
			if (count($errs) > 0) { $errs = implode(' | ', $errs); }
		} else {
			$errs = 'No expired orders yet';
		}
		return [$data, $errs];
	}

	public function trackOrders() {
		if ((time() - $this->orderCountTimer) > 10) { $this->orderCountTimer = time(); $this->orderCountLeft = 18; }

		if ($this->orderCountLeft <= 0) { return [null, 'Out of order count!!']; }

		if (count($this->pairsOrdered) == 0) { return [null, 'No new orders yet.']; }		

		$this->getBalances();
		if (count($this->balances) == 0) { return [null, 'Balances unavailable.']; }		

		$f_precise = function($n, $p) { return (floor($n / $p) * $p); };
		$r_precise = function($n, $p) { return (round($n / $p) * $p); };
		$reverse_orders = [];
		$ql = strlen($this->qouteCurrency) * -1;
		$pairsOrdered = $this->pairsOrdered;
		$log = [];
		foreach($pairsOrdered as $symbol => $buyorder) {
			$s = substr($symbol, 0, $ql);
			if ($buyorder['type'] == 'virtual') {
				$pn = count($this->priceHistory[$symbol]);
				$t = round((($this->priceHistory[$symbol][($pn - 1)][0] - $this->priceHistory[$symbol][0][0]) / 60), 2);
				$pc = (($this->priceHistory[$symbol][($pn - 1)][1] - $this->priceHistory[$symbol][0][1]) / $this->priceHistory[$symbol][0][1]) * 100;
				if ($pc > $this->pairsOrdered[$symbol]['peak']) {
					array_push($log, $symbol.'(new peak @ '.round($pc, 2).'% in '.$t.' mins)');
					$this->pairsOrdered[$symbol]['peak'] = $pc;
					$this->pairsOrdered[$symbol]['peaktime'] = $t;
					$this->pairsOrdered[$symbol]['peakprice'] = $this->priceHistory[$symbol][($pn - 1)][1];
					continue;
				}
				$lpc = abs((($this->priceHistory[$symbol][($pn - 1)][1] - $this->pairsOrdered[$symbol]['peakprice']) / $this->pairsOrdered[$symbol]['peakprice']) * 100);
				if ($lpc >= $this->rtp) {
					$profit = $pc - $this->pairsOrdered[$symbol]['entry'];
					array_push($log, $symbol.'(exited @ '.round($lpc, 2).'% loss from '.round($this->pairsOrdered[$symbol]['peak'], 2).'% peak in '.$t.' mins with '.round($profit, 2).'% profit)');
					unset($this->pairsOrdered[$symbol]);
					$this->priceHistory[$symbol] = [];
					GLOBAL $_SETUP;
					$_SETUP['profits'] = (isset($_SETUP['profits']))? $_SETUP['profits'] : 0;
					$_SETUP['losses'] = (isset($_SETUP['losses']))? $_SETUP['losses'] : 0;
					$_SETUP['total'] = (isset($_SETUP['total']))? $_SETUP['total'] : 0;
					if ($profit > 0) {
						$_SETUP['profits'] += $profit;
					} else {
						$_SETUP['losses'] += $profit;
					}
					$_SETUP['total'] += $profit;
					stdio('setup', $_SETUP);
					continue;
				}
				array_push($log, $symbol.'(current peak @ '.round($this->pairsOrdered[$symbol]['peak'], 2).'% in '.$this->pairsOrdered[$symbol]['peaktime'].' mins. current loss @ '.round($lpc, 2).')');
				continue;
			}
			$this->console_log('Reversing '.$symbol);
			$payload = 'symbol='.$symbol;
			$payload .= '&side=SELL';
			$payload .= '&type=STOP_LOSS_LIMIT';
			$payload .= '&timeInForce=GTC';
			$p = $this->priceHistory[$symbol][(count($this->priceHistory[$symbol]) - 1)][1];
			$probable_minqty = $this->minNotional[$symbol] / $p;
			$q = 0;
			if ($buyorder['cancel'] == true) {
				$this->console_log($symbol.' already canceled');
				if ($this->balances[$s] > 0) {
					//reverse $this->balances[$s]
					$this->console_log('Reversing outstanding balances for '.$symbol);
					$q = $f_precise($this->balances[$s], $this->quantityPrecision[$symbol]);
					$payload .= '&quantity='.$q;
				} else {
					$this->console_log($symbol.' has no quantity executed yet. Now deleted.');
					$this->pairsOrdered[$symbol]['delete'] = true;
					//unset($this->pairsOrdered[$symbol]);
					continue;
				}
			}
			elseif ($r_precise($this->balances[$s], $this->quantityPrecision[$symbol]) >= $probable_minqty and $r_precise(($buyorder['quantity'] - ($buyorder['reversed_quantity'] + $this->balances[$s])), $this->quantityPrecision[$symbol]) >= ($probable_minqty * 2)) {
				//reverse $this->balances[$s]  --- update $buyorder['reversed_quantity']
				$this->console_log('Reversing for '.$symbol.'. With unfilled parts still greater than '.($probable_minqty * 2).' '.$s.' remaining');
				$q = $f_precise($this->balances[$s], $this->quantityPrecision[$symbol]);
				$payload .= '&quantity='.$q;
			}
			elseif ($r_precise($this->balances[$s], $this->quantityPrecision[$symbol]) >= ($buyorder['quantity'] - $buyorder['reversed_quantity'])) {
				//reverse ($buyorder['quantity'] - $buyorder['reversed_quantity'])
				$this->console_log('Reversing for '.$symbol.'. No unfilled parts left.');
				$q = $f_precise($this->balances[$s], $this->quantityPrecision[$symbol]);
				if ($q == 0) {
					$this->console_log($symbol.' has all quantity reversed. Now deleted.');
					$this->pairsOrdered[$symbol]['delete'] = true;
					//unset($this->pairsOrdered[$symbol]);
					continue;
				} else {
					$payload .= '&quantity='.$q;
				}
			}
			if ($q == 0) {
				$this->console_log($symbol.' quantity is insufficient for reversal'); 
				continue; 
			}
			$payload .= '&price='.$r_precise((($this->minNotional[$symbol] + $this->pricePrecision[$symbol]) / $q), $this->pricePrecision[$symbol]);
			$payload .= '&trailingDelta='.round($this->rtp * 100);
			$payload .= '&newOrderRespType=RESULT';
			$payload .= '&recvWindow=9000';
			$payload .= '&timestamp='.round(microtime(true) * 1000);
			$payload .= '&signature='.hash_hmac('sha256', $payload, $this->apiSecret);
			$reverse_orders[$symbol] = $this->baseURL.'/api/v3/order?'.$payload;
			if (count($reverse_orders) == $this->orderCountLeft) { break; }
		}

		if (!empty($log)) { return [null, 'VIRTUAL TRACKS: '.implode(' | ', $log)]; }		

		$this->console_log('Reverse orders to be placed => '.json_encode($reverse_orders));
		$data = []; $errs = [];
		if (count($reverse_orders) > 0) {
			$content = multi_fetch(array_values($reverse_orders), false, 'POST', ["Content-Type: application/x-www-form-urlencoded", "X-MBX-APIKEY: ".$this->apiKey]);
			$pairs = array_keys($orders);
			for($i = 0; $i < count($pairs); $i++) {
				$id = $content['handle'][$i]['id'];
				if ($content['handle'][$i]['type'] == 'OK') {
					$res = json_decode($content['responses'][$id], true);
					if (isset($res['code']) and isset($res['msg'])) { array_push($errs, $pairs[$i].'(Error '.$res['code'].': '.$res['msg'].')'); continue; }
					$s = $res['symbol'];
					array_push($data, $s.'('.$res['origQty'].')');
					$this->orderCountLeft -= 1;
					$this->pairsOrdered[$s]['reversed_quantity'] += $res['origQty'];
					if ($r_precise($this->pairsOrdered[$s]['quantity'], $this->quantityPrecision[$symbol]) <= $r_precise($this->pairsOrdered[$s]['reversed_quantity'], $this->quantityPrecision[$symbol])) {
						$this->pairsOrdered[$s]['delete'] = true;
					}
				} else {
					array_push($errs, $pairs[$i].'('.$content['errors'][$id].')');
				}
			}
			if (count($content['errors']) > 0) { $errs = implode(' | ', $errs); }
		}
		return [$data, $errs];
	}

	public function __destruct() {
		quit();
	}
}


//partial replica of the BinanceSpikeAlgo for Hotbit Exchange
class HotbitSpikeAlgo {
	public $baseURL = 'https://api.hotbit.io';
	public $tpc = null;		//target percentage change
	public $mct = null;		//maximum time to change
	public $rtp = null;
	public $priceHistory = [];
	public $pairsOrdered = [];
	public $tickerData = [];

	public function __construct($setup) {
		if ($setup['mct'] <= 0) { throw new Exception('Invalid minimum change time.'); return false; }
		$this->tpc = (float)$setup['tpc'];
		$this->mct = (float)$setup['mct'];
		if (!$setup['debug_mode']) { throw new Exception('Hotbit algo can only run in debug mode.'); return false; }
		if (!$setup['safe_mode']) { throw new Exception('Hotbit algo can only run in safe mode.'); return false; }
		$this->rtp = (float)$setup['rtp'];
	}

	public function getPriceHistory() {
		$content = fetch($this->baseURL.'/api/v1/allticker', false, 'GET', ["Content-Type: application/x-www-form-urlencoded"]);
		$ptime = time();
		$mct = $this->mct * 60;
		if (!$content[0]) { throw new Exception($content[1]); return false; }
		$content = json_decode($content[1], true);
		if (isset($content['error'])) { throw new Exception('Error: '.$content['error']['message']); return false; }
		foreach ($content['ticker'] as $symbol_info) {
			$s = $symbol_info['symbol'];
			$this->tickerData[$s] = $symbol_info;
			if (!isset($this->priceHistory[$s])) { $this->priceHistory[$s] = []; }
			array_push($this->priceHistory[$s], [$ptime, $symbol_info['last']]);
			//remove outdated qoutes
			if (!isset($this->pairsOrdered[$s])) { while (($ptime - $this->priceHistory[$s][0][0]) > ($mct+15)) { array_shift($this->priceHistory[$s]); } }
		}
		$sort = array_column($this->tickerData, 'quote_volume');
		array_multisort($sort, SORT_ASC, $this->tickerData);		
		return count($this->priceHistory);
	}

	public function createOrders() {
		$ptime = time();
		$mct = $this->mct * 60;

		//select all pairs with best chances from rollingWindows
		$orders = [];
		foreach($this->tickerData as $s => $symbol_info) {
			//check if already ordered
			if (isset($this->pairsOrdered[$s])) { continue; }
			//check for outdated qoutes
			if (($ptime - $this->priceHistory[$s][0][0]) > ($mct+15)) { continue; }
			if (($ptime - $this->priceHistory[$s][0][0]) < ($mct)) { continue; }
			$pn = count($this->priceHistory[$s]);
			$pc = (($this->priceHistory[$s][($pn - 1)][1] - $this->priceHistory[$s][0][1]) / $this->priceHistory[$s][0][1]) * 100;
			if ($pc >= $this->tpc) {
				$symbol_info['price_change_percent'] = $pc;
				$symbol_info['history_time'] = round((($ptime - $this->priceHistory[$s][0][0]) / 60), 2);
				$orders[$s] = $symbol_info;
			}
		}

		if (count($orders) == 0) { return [null, 'No pontential trades found']; }
		
		//sort pairs by priceChangePercent DESC
		$sort = array_column($orders, 'price_change_percent');
		array_multisort($sort, SORT_DESC, $orders);

		if (count($orders) > 0) {
			$data = [];
			foreach($orders as $s => $symbol_info) {
				$pn = count($this->priceHistory[$s]);
				array_push($data, '<a target="_blank" href="?hotbit_order=1&&type=buy&payload='.$s.'">'.$s.'</a>(entered @ '.round($orders[$s]['price_change_percent'], 2).'%)');
				$this->pairsOrdered[$s] = array(
													'entry' => $orders[$s]['price_change_percent'],
													'peak' => $orders[$s]['price_change_percent'],
													'peaktime' => $orders[$s]['history_time'],
													'peakprice' => $this->priceHistory[$s][($pn - 1)][1]
												);
			}
			return [null, 'VIRTUAL ORDERS: '.implode(' | ', $data), true];
		} else {
			array_push($errs, 'No order sent');
			return [null, implode(' | ', $errs)];
		}
	}

	public function trackOrders() {
		if (count($this->pairsOrdered) == 0) { return [null, 'No new orders yet.']; }		

		$f_precise = function($n, $p) { return (floor($n / $p) * $p); };
		$r_precise = function($n, $p) { return (round($n / $p) * $p); };
		$reverse_orders = [];
		$pairsOrdered = $this->pairsOrdered;
		$log = [];
		foreach($pairsOrdered as $symbol => $buyorder) {
				$pn = count($this->priceHistory[$symbol]);
				$t = round((($this->priceHistory[$symbol][($pn - 1)][0] - $this->priceHistory[$symbol][0][0]) / 60), 2);
				$pc = (($this->priceHistory[$symbol][($pn - 1)][1] - $this->priceHistory[$symbol][0][1]) / $this->priceHistory[$symbol][0][1]) * 100;
				if ($pc > $this->pairsOrdered[$symbol]['peak']) {
					array_push($log, '<a target="_blank" href="?hotbit_order=1&type=sell&payload='.$symbol.'">'.$symbol.'</a>(new peak @ '.round($pc, 2).'% in '.$t.' mins)');
					$this->pairsOrdered[$symbol]['peak'] = $pc;
					$this->pairsOrdered[$symbol]['peaktime'] = $t;
					$this->pairsOrdered[$symbol]['peakprice'] = $this->priceHistory[$symbol][($pn - 1)][1];
					continue;
				}
				$profit = $pc - $this->pairsOrdered[$symbol]['entry'];
				$lpc = abs((($this->priceHistory[$symbol][($pn - 1)][1] - $this->pairsOrdered[$symbol]['peakprice']) / $this->pairsOrdered[$symbol]['peakprice']) * 100);
				if ($lpc >= $this->rtp) {
					array_push($log, '<a target="_blank" href="?hotbit_order=1&type=sell&payload='.$symbol.'">'.$symbol.'</a>(exited @ '.round($lpc, 2).'% loss from '.round($this->pairsOrdered[$symbol]['peak'], 2).'% peak in '.$t.' mins with '.round($profit, 2).'% profit)');
					unset($this->pairsOrdered[$symbol]);
					$this->priceHistory[$symbol] = [];
					GLOBAL $_SETUP;
					$_SETUP['profits'] = (isset($_SETUP['profits']))? $_SETUP['profits'] : 0;
					$_SETUP['losses'] = (isset($_SETUP['losses']))? $_SETUP['losses'] : 0;
					$_SETUP['total'] = (isset($_SETUP['total']))? $_SETUP['total'] : 0;
					if ($profit > 0) {
						$_SETUP['profits'] += $profit;
					} else {
						$_SETUP['losses'] += $profit;
					}
					$_SETUP['total'] += $profit;
					stdio('setup', $_SETUP);
					continue;
				}
				array_push($log, '<a target="_blank" href="?hotbit_order=1&type=sell&payload='.$symbol.'">'.$symbol.'</a>(current peak @ '.round($this->pairsOrdered[$symbol]['peak'], 2).'% in '.$this->pairsOrdered[$symbol]['peaktime'].' mins. current loss @ '.round($lpc, 2).'%. current profit @ '.round($profit, 2).'%)');
		}

		return [null, 'VIRTUAL TRACKS: '.implode(' | ', $log), true];	
	}

	public function __destruct() {
		quit();
	}
}


/*
if (!isset($_REQUEST['start']) or $_REQUEST['start'] != 'password-to-protect-daemon-from-unauthorised-initialization-on-the-web') {
	echo 'Unauthorised request';
	quit('Unauthorised request denied.');
}
*/
$_SETUP = [];
$_SETUP['exchange'] = (isset($_REQUEST['exchange']))? strtolower($_REQUEST['exchange']) : 'hotbit';
$_SETUP['tpc'] = (isset($_REQUEST['tpc']))? (float)$_REQUEST['tpc'] : 10;
$_SETUP['mct'] = (isset($_REQUEST['mct']))? (float)$_REQUEST['mct'] : 1;
$_SETUP['split_capital'] = (isset($_REQUEST['split_capital']))? (float)$_REQUEST['split_capital'] : 20;
$_SETUP['currency'] = (isset($_REQUEST['currency']))? $_REQUEST['currency'] : 'USDT';
$_SETUP['rtp'] = (isset($_REQUEST['rtp']))? (float)$_REQUEST['rtp'] : 1;
$_SETUP['debug_mode'] = (isset($_REQUEST['debug_mode']))? (int)$_REQUEST['debug_mode'] : 1;
$_SETUP['safe_mode'] = (isset($_REQUEST['safe_mode']))? (int)$_REQUEST['safe_mode'] : 1;
stdio('setup', $_SETUP);


if ($_SETUP['exchange'] == 'binance') {
	try {
		$log = [];
		foreach($_SETUP as $k => $v) { array_push($log, strtoupper($k).'('.(string)$v.')'); }
		$log = implode(' | ', $log);
		console_log('Starting Binance daemon... '.$log);
		$xchng = new BinanceSpikeAlgo($_SETUP);
		console_log('Loading ticker pairs for '.$_SETUP['currency']);
		$tickers = $xchng->getTickerPairs();
		if ($tickers) { console_log('Loaded '.$tickers.' pairs: '.implode(' | ', $xchng->tickerPairs)); }
		else { throw new Exception('No tickers available for '.$_SETUP['currency']); }
		$_SETUP['market_watch'] = implode(' | ', $xchng->tickerPairs);
		stdio('setup', $_SETUP);
	} catch(Exception $e) {
		quit('Unable to start daemon... '.$e->getMessage());
	}
	while (true) {
		try {
			console_log('Updating balances...');
			$result = $xchng->getBalances();
			$log = [];
			foreach($xchng->balances as $c => $b) { array_push($log, $c.'('.$b.')'); }
			$log = implode(' | ', $log);
			console_log('Balances: '.$log);
			$_SETUP['balances'] = $log;
			stdio('setup', $_SETUP);
			is_killed();
			console_log('Updating rolling windows...');
			$result = $xchng->getRollingWindows();
			if ($result) {
				$log = [];
				foreach($xchng->rollingWindows as $s => $w) { array_push($log, $s.'('.$w['quoteVolume'].')'); }
				$log = 'Volumes: '.implode(' | ', $log);
			} else {
				$log = 'None found';
			}
			console_log($log);
			is_killed();
			console_log('Updating price histories...');
			$result = $xchng->getPriceHistory();
			$log = [];
			foreach($xchng->priceHistory as $s => $h) {
				$t = ($h[(count($h) - 1)][0] - $h[0][0]) / 60;
				$p = (($h[(count($h) - 1)][1] - $h[0][1]) / $h[0][1]) * 100;
				array_push($log, array(
					'p' => $p,
					'l' => $s.'('.round($p, 2).'% in '.round($t, 2).'mins)',
				));
			}
			if (count($log)) {
				$sort = array_column($log, 'p');
				array_multisort($sort, SORT_DESC, $log);
				$log = array_column($log, 'l');
			}
			console_log('Histories: '.implode(' | ', $log));
			is_killed();
			console_log('Analysing markets and creating orders...');		
			$r = $xchng->createOrders();
			if (!empty($r[0])) { console_log('Orders: '.implode(' | ', $r[0])); }
			if (!empty($r[1])) { console_log('Info: '.$r[1]); }
			is_killed();
		} catch(Exception $e) { console_log('Error: '.$e->getMessage()); }
		is_killed();
		try {
			console_log('Removing expired orders...');
			$r = $xchng->clearTimeouts();
			if (!empty($r[0])) { console_log('Cancel orders: '.implode(' | ', $r[0])); }
			if (!empty($r[1])) { console_log('Info: ' . $r[1]); }
		} catch(Exception $e) { console_log('Error: '.$e->getMessage()); }
		is_killed();
		try {
			console_log('Tracking executed orders for reversal...');
			$r = $xchng->trackOrders();
			if (!empty($r[0])) { console_log('Reverse orders: '.implode(' | ', $r[0])); }
			if (!empty($r[1])) { console_log('Info: '.$r[1]); }
		} catch(Exception $e) { console_log('Error: '.$e->getMessage()); }
		is_killed();
		sleep(1);
	}
}
elseif ($_SETUP['exchange'] == 'hotbit') {
	try {
		$log = [];
		foreach($_SETUP as $k => $v) { array_push($log, strtoupper($k).'('.(string)$v.')'); }
		$log = implode(' | ', $log);
		console_log('Starting Hotbit daemon... '.$log);
		$xchng = new HotbitSpikeAlgo($_SETUP);
	} catch(Exception $e) {
		quit('Unable to start daemon... '.$e->getMessage());
	}
	console_log('Running Hotbit daemon...');
	while (true) {
		try {
			console_log('Updating price histories...');
			$result = $xchng->getPriceHistory();
			$log = [];
			foreach($xchng->priceHistory as $s => $h) {
				$t = ($h[(count($h) - 1)][0] - $h[0][0]) / 60;
				$p = (($h[(count($h) - 1)][1] - $h[0][1]) / $h[0][1]) * 100;
				array_push($log, array(
					'p' => $p,
					'l' => $s.'('.round($p, 2).'% in '.round($t, 2).'mins)',
				));
			}
			if (count($log)) {
				$sort = array_column($log, 'p');
				array_multisort($sort, SORT_DESC, $log);
				$log = array_column($log, 'l');
			}
			console_log('Histories: '.implode(' | ', $log));
			is_killed();
			console_log('Analysing markets and creating orders...');
			$r = $xchng->createOrders();
			if (!empty($r[0])) { console_log('Orders: '.implode(' | ', $r[0])); }
			if (!empty($r[1])) { console_log('Info: '.$r[1]); }       //add  and isset($r[2])
			is_killed();
		} catch(Exception $e) { console_log('Error: '.$e->getMessage()); }
		is_killed();
		try {
			console_log('Tracking executed orders for reversal...');
			$r = $xchng->trackOrders();
			if (!empty($r[0])) { console_log('Reverse orders: '.implode(' | ', $r[0])); }
			if (!empty($r[1])) { console_log('Info: '.$r[1]); }       //add  and isset($r[2])
		} catch(Exception $e) { console_log('Error: '.$e->getMessage()); }
		is_killed();
		sleep(1);
	}	
}
else {
	console_log('Unable to start daemon... No valid exchange specified.');
}
?>