<?php
require './vendor/autoload.php';

use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;

const STATES_CODE=500;
const TIMEOUT_SECONDS=60;
const SLEEP_TIME=2;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dns = 'mysql:dbname=testdb;host=localhost;charset=utf8mb4';
try {
	$dbh = new PDO($dns, $_ENV['USER_NAME'], $_ENV['USER_PASS']);
	$dbh->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

} catch(PDOException $e) {
	header('Content-Type: text/plain; charset=UTF-8', true, STATES_CODE);
	echo $e->getMessage();
}

$client = new Client(HttpClient::create(['timeout' => TIMEOUT_SECONDS]));
$count = 1;
$crawl_url = 'https://www.codeal.work/jobs?categories=15-1199.00&target_scopes=in-house&work_days_per_week=3,4,5&area_with_full_remote=true&is_application_allowed=true&sort=random_rank_desc';
$insert_sql = 'INSERT INTO codeal_project(name, min_value, max_value) VALUE(:name, :min_value, :max_value)';
$stmt = $dbh->prepare($insert_sql);
do {
	if ($count === 1) {
		$crawler = $client->request('GET', $crawl_url);
	} else {
		$crawler = $client->request('GET', $crawl_url . '&p=' . $count);
	}
		
	$result = $crawler->filter('.list-job-card')->each(function ($node) {
		$title = $node->filter('.size-lg > a')->text();
		$value = $node->filter('.value > span')->text();
		$week_work = $node->filter('.value > span')->last()->text() . "\n";

		$value = str_replace(' ', '', $value);
		$value = str_replace(',', '', $value);
		
		$place_yen = mb_strpos($value, '円');
		$place_efbd9e = mb_strpos($value, hex2bin("EFBD9E"));
		if ($place_efbd9e) {
			$min_hourly_wage = mb_substr($value, 0, $place_efbd9e);
			$max_hourly_wage = (mb_substr($value, $place_efbd9e + 1, $place_yen - ($place_efbd9e + 1)));
		} else {
			$min_hourly_wage = mb_substr($value, 0, $place_yen);	
			$max_hourly_wage = mb_substr($value, 0, $place_yen);	

		}

		$place_week = mb_strpos($week_work, '週');
		$place_date = mb_strrpos($week_work, '日');

		$min_week_work = mb_substr($week_work, $place_week + 1, 1);
		$max_week_work = mb_substr($week_work, $place_date - 1, 1);

		//最低時間単価と最低労働時間を掛けたもを最低単価、最高時間単価と最高労働時間を掛けたものを最高単価としました。
		$min_value = $min_hourly_wage * 8 * $min_week_work * 4;
		$max_value = $max_hourly_wage * 8 * $max_week_work * 4;


		if ($title == null || $min_value == null || $max_value == null) return null;

		return [$title, $min_value, $max_value];
	});

	foreach ($result as $row) {
		if (empty($row)) continue;
		$array = array(
			':name' => $row[0],
			':min_value' => $row[1],
			':max_value' => $row[2]
		);
		$stmt->execute($array);
	}
	$count++;
	sleep(SLEEP_TIME);
} while (!empty($result));

$dbh = null;

echo '完了' . PHP_EOL;
