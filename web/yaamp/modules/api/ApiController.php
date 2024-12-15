<?php

class ApiController extends CommonController
{
    public $defaultAction = 'status';

    /////////////////////////////////////////////////

    public function actionStatus()
    {
        $client_ip   = arraySafeVal($_SERVER, 'REMOTE_ADDR');
        $whitelisted = isAdminIP($client_ip);
        if (!$whitelisted && is_file(YAAMP_LOGS . '/overloaded')) {
            header('HTTP/1.0 503 Disabled, server overloaded');
            return;
        }
        if (!$whitelisted && !LimitRequest('api-status', 10)) {
            return;
        }

        $json = controller()->memcache->get("api_status");

        if (!empty($json)) {
            echo $json;
            return;
        }

        $stats = array();
        foreach (yaamp_get_algos() as $i => $algo) {
            $coins = (int) controller()->memcache->get_database_count_ex("api_status_coins-$algo", 'db_coins', "enable and visible and auto_ready and algo=:algo", array(
                ':algo' => $algo
            ));

            if (!$coins)
                continue;

            $workers = (int) controller()->memcache->get_database_scalar("api_status_workers-$algo", "select COUNT(id) FROM workers WHERE algo=:algo", array(
                ':algo' => $algo
            ));

            $workers_shared = (int) controller()->memcache->get_database_scalar("api_status_workers_shared-$algo", "select COUNT(id) FROM workers WHERE algo=:algo and not password like '%m=solo%'", array(
                ':algo' => $algo
            ));

            $workers_solo = (int) controller()->memcache->get_database_scalar("api_status_workers_solo-$algo", "select COUNT(id) FROM workers WHERE algo=:algo and password like '%m=solo%'", array(
                ':algo' => $algo
            ));

			if (yaamp_pool_rate($algo)) $pool_hash = yaamp_pool_rate($algo);
			else $pool_hash = '0';

			if (yaamp_pool_shared_rate($algo)) $pool_shared_hash = yaamp_pool_shared_rate($algo);
			else $pool_shared_hash = '0';

			if (yaamp_pool_solo_rate($algo)) $pool_shared_hash = yaamp_pool_solo_rate($algo);
			else $pool_solo_hash = '0';

            $price = controller()->memcache->get_database_scalar("api_status_price-$algo", "select price from hashrate where algo=:algo order by time desc limit 1", array(
                ':algo' => $algo
            ));

            $price = bitcoinvaluetoa(take_yaamp_fee($price / 1000, $algo));

            $rental = controller()->memcache->get_database_scalar("api_status_rental-$algo", "select rent from hashrate where algo=:algo order by time desc limit 1", array(
                ':algo' => $algo
            ));

            $rental = bitcoinvaluetoa($rental);

            $t = time() - 24 * 60 * 60;

            $avgprice = controller()->memcache->get_database_scalar("api_status_avgprice-$algo", "select avg(price) from hashrate where algo=:algo and time>$t", array(
                ':algo' => $algo
            ));

            $avgprice = bitcoinvaluetoa(take_yaamp_fee($avgprice / 1000, $algo));

            $total1 = controller()->memcache->get_database_scalar("api_status_total-$algo", "select sum(amount*price) from blocks where category!='orphan' and time>$t and algo=:algo", array(
                ':algo' => $algo
            ));

            $hashrate1 = (double) controller()->memcache->get_database_scalar("api_status_avghashrate-$algo", "select avg(hashrate) from hashrate where time>$t and algo=:algo", array(
                ':algo' => $algo
            ));

            $algo_unit_factor = yaamp_algo_mBTC_factor($algo);
            $btcmhday1        = $hashrate1 > 0 ? mbitcoinvaluetoa($total1 / $hashrate1 * 1000000 * 1000 * $algo_unit_factor) : 0;

            $fees = yaamp_fee($algo);
            $fees_solo = yaamp_fee_solo($algo);
            $port = getAlgoPort($algo);

            $stat = array(
                "name" => $algo,
                "port" => (int) $port,
                "coins" => $coins,
                "fees" => (double) $fees,
                "fees_solo" => (double) $fees_solo,
                "hashrate" => (int) $pool_hash,
                "hashrate_shared" => (int) $pool_shared_hash,
                "hashrate_solo" => (int) $pool_solo_hash,
                "workers" => (int) $workers,
                "workers_shared" => (int) $workers_shared,
                "workers_solo" => (int) $workers_solo,
                "estimate_current" => $price,
                "estimate_last24h" => $avgprice,
                "actual_last24h" => $btcmhday1,
                "mbtc_mh_factor" => $algo_unit_factor,
                "hashrate_last24h" => (double) $hashrate1
            );
            if (YAAMP_RENTAL) {
                $stat["rental_current"] = $rental;
            }

            $stats[$algo] = $stat;
        }

        ksort($stats);

        header('Content-Type: application/json');
        $json = json_encode($stats);
        echo $json;

        controller()->memcache->set("api_status", $json, 30, MEMCACHE_COMPRESSED);
    }

    public function actionCurrencies()
    {
        $client_ip   = arraySafeVal($_SERVER, 'REMOTE_ADDR');
        $whitelisted = isAdminIP($client_ip);
        if (!$whitelisted && is_file(YAAMP_LOGS . '/overloaded')) {
            header('HTTP/1.0 503 Disabled, server overloaded');
            return;
        }
        if (!$whitelisted && !LimitRequest('api-currencies', 10)) {
            return;
        }

        $json = controller()->memcache->get("api_currencies");
        if (empty($json)) 
		{

            $data  = array();
            $coins = getdbolist('db_coins', "enable AND visible AND auto_ready AND IFNULL(algo,'PoS')!='PoS' ORDER BY symbol");
            foreach ($coins as $coin) 
			{
				$symbol = $coin->symbol;
				
				$last          = dborow("SELECT height, time FROM blocks " . "WHERE coin_id=:id AND category IN ('immature','generate') ORDER BY height DESC LIMIT 1", array(':id' => $coin->id));
                $last_shared   = dborow("SELECT height, time FROM blocks " . "WHERE coin_id=:id AND solo=0 AND category IN ('immature','generate') ORDER BY height DESC LIMIT 1", array(':id' => $coin->id));
                $last_solo     = dborow("SELECT height, time FROM blocks " . "WHERE coin_id=:id AND solo=1 AND category IN ('immature','generate') ORDER BY height DESC LIMIT 1", array(':id' => $coin->id));
				
				$lastblock     = (int) arraySafeVal($last, 'height');
				$lastblock_shared   = (int) arraySafeVal($last_shared, 'height');
				$lastblock_solo     = (int) arraySafeVal($last_solo, 'height');
				
				$timesincelast = $timelast = (int) arraySafeVal($last, 'time');
				if ($timelast > 0)
					$timesincelast = time() - $timelast;
				
				$timesincelast_shared = $timelast_shared = (int) arraySafeVal($last_shared, 'time');
				if ($timelast_shared > 0)
					$timesincelast_shared = time() - $timelast_shared;
				
				$timesincelast_solo = $timelast_solo = (int) arraySafeVal($last_solo, 'time');
				if ($timelast_solo > 0)
					$timesincelast_solo = time() - $timelast_solo;

				$miners = getdbocount('db_accounts', "coinid=:coinid and (id IN (SELECT DISTINCT userid FROM workers))", array(':coinid' => $coin->id));
				$workers = (int) dboscalar("SELECT count(W.userid) AS workers FROM workers W " . "INNER JOIN accounts A ON A.id = W.userid " . "WHERE W.algo=:algo AND A.coinid IN (:id, 6)",array(':algo' => $coin->algo,':id' => $coin->id));
				$workers_shared = (int) dboscalar("SELECT count(W.userid) AS workers FROM workers W " . "INNER JOIN accounts A ON A.id = W.userid " . "WHERE W.algo=:algo AND A.coinid IN (:id, 6) and not password like '%m=solo%'",array(':algo' => $coin->algo,':id' => $coin->id));
				$workers_solo = (int) dboscalar("SELECT count(W.userid) AS workers FROM workers W " . "INNER JOIN accounts A ON A.id = W.userid " . "WHERE W.algo=:algo AND A.coinid IN (:id, 6)and password like '%m=solo%'",array(':algo' => $coin->algo,':id' => $coin->id));
				
				$since  = $timelast ? $timelast : time() - 60 * 60;
				$shares = dborow("SELECT count(id) AS shares, SUM(difficulty) AS coin_hr FROM shares WHERE time>$since AND algo=:algo AND coinid IN (0,:id)", array(':id' => $coin->id,':algo' => $coin->algo));
				
				$t24    = time() - 24 * 60 * 60;
				$res24h = controller()->memcache->get_database_row("history_item2-{$coin->id}-{$coin->algo}", "SELECT COUNT(id) as a, SUM(amount*price) as b FROM blocks " . "WHERE coin_id=:id AND NOT category IN ('orphan','stake','generated') AND time>$t24 AND algo=:algo", array(':id' => $coin->id,':algo' => $coin->algo));
				$res24h_shared = controller()->memcache->get_database_row("history_item2-shared-{$coin->id}-{$coin->algo}", "SELECT COUNT(id) as a, SUM(amount*price) as b FROM blocks " . "WHERE coin_id=:id AND solo=0 AND NOT category IN ('orphan','stake','generated') AND time>$t24 AND algo=:algo", array(':id' => $coin->id,':algo' => $coin->algo));
				$res24h_solo = controller()->memcache->get_database_row("history_item2-solo-{$coin->id}-{$coin->algo}", "SELECT COUNT(id) as a, SUM(amount*price) as b FROM blocks " . "WHERE coin_id=:id AND solo=1 AND NOT category IN ('orphan','stake','generated') AND time>$t24 AND algo=:algo ", array(':id' => $coin->id,':algo' => $coin->algo));
				
				// Coin hashrate, we only store the hashrate per algo in the db,
				if (yaamp_coin_rate($coin->id)) $pool_hash = yaamp_coin_rate($coin->id);
				else $pool_hash = '0';
		
				if (yaamp_coin_shared_rate($coin->id)) $pool_shared_hash = yaamp_coin_shared_rate($coin->id);
				else $pool_shared_hash = '0';
		

				if (yaamp_coin_solo_rate($coin->id)) $pool_solo_hash = yaamp_coin_solo_rate($coin->id);
				else $pool_solo_hash = '0';
	
				$btcmhd = yaamp_profitability($coin);
				$btcmhd = mbitcoinvaluetoa($btcmhd);
				
				//Add network hash difficulty and symbol
				$min_ttf      = $coin->network_ttf > 0 ? min($coin->actual_ttf, $coin->network_ttf) : $coin->actual_ttf;
				$network_hash = $coin->difficulty * 0x100000000 / ($min_ttf ? $min_ttf : 60);

				$fees = yaamp_fee($coin->algo);
				$fees_solo = yaamp_fee_solo($coin->algo);
				$port_db = getdbosql('db_stratums', "algo=:algo and symbol=:symbol", array(':algo' => $coin->algo,':symbol' => $coin->symbol));

				if ($port_db) 
					$port = $port_db->port;
				else 
					$port = getAlgoPort($coin->algo);

				$min_payout = max(floatval(YAAMP_PAYMENTS_MINI), floatval($coin->payout_min));
		
				$data[$symbol] = array
				(
					'name' => $coin->name,
					'algo' => $coin->algo,
					'port' => $port,
					'reward' => $coin->reward,
					'blocktime' => $coin->block_time,
					'height' => (int) $coin->block_height,
					'difficulty' => $coin->difficulty,
					'minimumPayment' => $min_payout,
					'fees' => (double) $fees,
					'fees_solo' => (double) $fees_solo,
					'miners' => $miners,
					'workers' => $workers,
					'workers_shared' => $workers_shared,
					'workers_solo' => $workers_solo,
					'shares' => (int) arraySafeVal($shares, 'shares'),
					'hashrate' => $pool_hash,
					'hashrate_shared' => $pool_shared_hash,
					'hashrate_solo' => $pool_solo_hash,
					'network_hashrate' => $network_hash,
					'estimate' => $btcmhd,
					'24h_blocks' => (int) arraySafeVal($res24h, 'a'),
					'24h_blocks_shared' => (int) arraySafeVal($res24h_shared, 'a'),
					'24h_blocks_solo' => (int) arraySafeVal($res24h_solo, 'a'),
					'24h_btc' => round(arraySafeVal($res24h, 'b', 0), 8),
					'lastblock' => $lastblock,
					'lastblock_shared' => $lastblock_shared,
					'lastblock_solo' => $lastblock_solo,
					'timesincelast' => $timesincelast,
					'timesincelast_shared' => $timesincelast_shared,
					'timesincelast_solo' => $timesincelast_solo
				);

                if (!empty($coin->symbol2))
                    $data[$symbol]['symbol'] = $coin->symbol2;
            }
            $json = json_encode($data);
            controller()->memcache->set("api_currencies", $json, 15, MEMCACHE_COMPRESSED);
        }

        header('Content-Type: application/json');
        echo str_replace("},", "},\n", $json);
    }

    public function actionWallet()
    {
        if (!LimitRequest('api-wallet', 10)) {
            return;
        }
        if (is_file(YAAMP_LOGS . '/overloaded')) {
            header('HTTP/1.0 503 Disabled, server overloaded');
            return;
        }
        $wallet = getparam('address');

        $user = getuserparam($wallet);
        if (!$user || $user->is_locked)
            return;

        $total_unsold = yaamp_convert_earnings_user($user, "status!=2");

        $t          = time() - 24 * 60 * 60;
        $total_paid = bitcoinvaluetoa(controller()->memcache->get_database_scalar("api_wallet_paid-" . $user->id, "SELECT SUM(amount) FROM payouts WHERE time >= $t AND account_id=" . $user->id));

        $balance      = bitcoinvaluetoa($user->balance);
        $total_unpaid = bitcoinvaluetoa($balance + $total_unsold);
        $total_earned = bitcoinvaluetoa($total_unpaid + $total_paid);

        $coin = getdbo('db_coins', $user->coinid);
        if (!$coin)
            return;

        header('Content-Type: application/json');
        echo "{";
        echo "\"currency\": \"{$coin->symbol}\", ";
        echo "\"unsold\": $total_unsold, ";
        echo "\"balance\": $balance, ";
        echo "\"unpaid\": $total_unpaid, ";
        echo "\"paid24h\": $total_paid, ";
        echo "\"total\": $total_earned";
        echo "}";
    }

    public function actionWalletEx()
    {
        $wallet = getparam('address');
        if (is_file(YAAMP_LOGS . '/overloaded')) {
            header('HTTP/1.0 503 Disabled, server overloaded');
            return;
        }
        if (!LimitRequest('api-wallet', 60)) {
            return;
        }

        $user = getuserparam($wallet);
        if (!$user || $user->is_locked)
            return;

        $total_unsold = yaamp_convert_earnings_user($user, "status!=2");

        $t          = time() - 24 * 60 * 60;
        $total_paid = bitcoinvaluetoa(controller()->memcache->get_database_scalar("api_wallet_paid-" . $user->id, "SELECT SUM(amount) FROM payouts WHERE time >= $t AND account_id=" . $user->id));

        $balance      = bitcoinvaluetoa($user->balance);
        $total_unpaid = bitcoinvaluetoa($balance + $total_unsold);
        $total_earned = bitcoinvaluetoa($total_unpaid + $total_paid);

        $coin = getdbo('db_coins', $user->coinid);
        if (!$coin)
            return;

        header('Content-Type: application/json');

        echo "{";
        echo "\"currency\": " . json_encode($coin->symbol) . ", ";
        echo "\"unsold\": $total_unsold, ";
        echo "\"balance\": $balance, ";
        echo "\"unpaid\": $total_unpaid, ";
        echo "\"paid24h\": $total_paid, ";
        echo "\"total\": $total_earned, ";

        echo "\"miners\": ";
        echo "[";

        $workers = getdbolist('db_workers', "userid={$user->id} ORDER BY password");
        foreach ($workers as $i => $worker) {
            $user_rate1     = yaamp_worker_rate($worker->id, $worker->algo);
            $user_rate1_bad = yaamp_worker_rate_bad($worker->id, $worker->algo);

            if ($i)
                echo ", ";

            echo "{";
            echo "\"version\": " . json_encode($worker->version) . ", ";
            echo "\"password\": " . json_encode($worker->password) . ", ";
            echo "\"ID\": " . json_encode($worker->worker) . ", ";
            echo "\"algo\": \"{$worker->algo}\", ";
            echo "\"difficulty\": " . doubleval($worker->difficulty) . ", ";
            echo "\"subscribe\": " . intval($worker->subscribe) . ", ";
            echo "\"accepted\": " . round($user_rate1, 3) . ", ";
            echo "\"rejected\": " . round($user_rate1_bad, 3);
            echo "}";
        }

        echo "]";

        if (YAAMP_API_PAYOUTS) {
            $json_payouts = controller()->memcache->get("api_payouts-" . $user->id);
            if (empty($json_payouts)) {
                $json_payouts = ",\"payouts\": ";
                $json_payouts .= "[";
                $list = getdbolist('db_payouts', "account_id={$user->id} AND completed>0 AND tx IS NOT NULL AND time >= " . (time() - YAAMP_API_PAYOUTS_PERIOD) . " ORDER BY time DESC");
                foreach ($list as $j => $payout) {
                    if ($j)
                    $json_payouts .= ", ";
                    $json_payouts .= "{";
                    $json_payouts .= "\"time\": " . (0 + $payout->time) . ",";
                    $json_payouts .= "\"amount\": \"{$payout->amount}\",";
                    $json_payouts .= "\"tx\": \"{$payout->tx}\"";
                    $json_payouts .= "}";
                }
                $json_payouts .= "]";
                controller()->memcache->set("api_payouts-" . $user->id, $json_payouts, 60, MEMCACHE_COMPRESSED);
            }
            echo str_replace("},", "},\n", $json_payouts);
        }

        echo "}";
    }

    /////////////////////////////////////////////////

    public function actionRental()
    {
        if (!LimitRequest('api-rental', 10))
            return;
        if (!YAAMP_RENTAL)
            return;

        $key    = getparam('key');
        $renter = getdbosql('db_renters', "apikey=:apikey", array(
            ':apikey' => $key
        ));
        if (!$renter)
            return;

        $balance     = bitcoinvaluetoa($renter->balance);
        $unconfirmed = bitcoinvaluetoa($renter->unconfirmed);

        header('Content-Type: application/json');

        echo "{";
        echo "\"balance\": $balance, ";
        echo "\"unconfirmed\": $unconfirmed, ";

        echo "\"jobs\": [";
        $list = getdbolist('db_jobs', "renterid=$renter->id");
        foreach ($list as $i => $job) {
            if ($i)
                echo ", ";

            $hashrate     = yaamp_job_rate($job->id);
            $hashrate_bad = yaamp_job_rate_bad($job->id);

            echo '{';
            echo "\"jobid\": \"$job->id\", ";
            echo "\"algo\": \"$job->algo\", ";
            echo "\"price\": \"$job->price\", ";
            echo "\"hashrate\": \"$job->speed\", ";
            echo "\"server\": \"$job->host\", ";
            echo "\"port\": \"$job->port\", ";
            echo "\"username\": \"$job->username\", ";
            echo "\"password\": \"$job->password\", ";
            echo "\"started\": \"$job->ready\", ";
            echo "\"active\": \"$job->active\", ";
            echo "\"accepted\": \"$hashrate\", ";
            echo "\"rejected\": \"$hashrate_bad\", ";
            echo "\"diff\": \"$job->difficulty\"";

            echo '}';
        }

        echo "]}";
    }

    public function actionRental_price()
    {
        if (!YAAMP_RENTAL)
            return;

        $key    = getparam('key');
        $renter = getdbosql('db_renters', "apikey=:apikey", array(
            ':apikey' => $key
        ));
        if (!$renter)
            return;

        $jobid = getparam('jobid');
        $price = getparam('price');

        $job = getdbo('db_jobs', $jobid);
        if ($job->renterid != $renter->id)
            return;

        $job->price = $price;
        $job->time  = time();
        $job->save();
    }

    public function actionRental_hashrate()
    {
        if (!YAAMP_RENTAL)
            return;

        $key    = getparam('key');
        $renter = getdbosql('db_renters', "apikey=:apikey", array(
            ':apikey' => $key
        ));
        if (!$renter)
            return;

        $jobid    = getparam('jobid');
        $hashrate = getparam('hashrate');

        $job = getdbo('db_jobs', $jobid);
        if ($job->renterid != $renter->id)
            return;

        $job->speed = $hashrate;
        $job->time  = time();
        $job->save();
    }

    public function actionRental_start()
    {
        if (!YAAMP_RENTAL)
            return;

        $key    = getparam('key');
        $renter = getdbosql('db_renters', "apikey=:apikey", array(
            ':apikey' => $key
        ));
        if (!$renter || $renter->balance <= 0)
            return;

        $jobid = getparam('jobid');

        $job = getdbo('db_jobs', $jobid);
        if ($job->renterid != $renter->id)
            return;

        $job->ready = true;
        $job->time  = time();
        $job->save();
    }

    public function actionRental_stop()
    {
        if (!YAAMP_RENTAL)
            return;

        $key    = getparam('key');
        $renter = getdbosql('db_renters', "apikey=:apikey", array(
            ':apikey' => $key
        ));
        if (!$renter)
            return;

        $jobid = getparam('jobid');

        $job = getdbo('db_jobs', $jobid);
        if ($job->renterid != $renter->id)
            return;

        $job->ready = false;
        $job->time  = time();
        $job->save();
    }

}