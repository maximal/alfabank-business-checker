<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;


// Инициализация
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required([
	'ALFA_BUSINESS_URL',
	'ALFA_BUSINESS_USERNAME',
	'ALFA_BUSINESS_PASSWORD',
	'SHOW_BROWSER_WINDOW',
	'TELEGRAM_BOT_TOKEN',
	'TELEGRAM_BOT_CHAT',
]);

$url = $_ENV['ALFA_BUSINESS_URL'];
$login = $_ENV['ALFA_BUSINESS_USERNAME'];
$password = $_ENV['ALFA_BUSINESS_PASSWORD'];
$showBrowserWindow = filter_var($_ENV['SHOW_BROWSER_WINDOW'], FILTER_VALIDATE_BOOLEAN);
$telegramBotToken = $_ENV['TELEGRAM_BOT_TOKEN'];
$telegramBotChat = $_ENV['TELEGRAM_BOT_CHAT'];

$loginSelector = 'input[name=username]';
$passwordSelector = 'input[name=password]';
$balanceSelector = '.account-plate__amount';
$tableSelector = 'table.arui-table';
$cacheFile = __DIR__ . '/cache/transactions.json';


//// Поехали!

$capabilities = DesiredCapabilities::chrome();
$options = new ChromeOptions();
// Хром/Хромиум
$capabilities = DesiredCapabilities::chrome();
$options = new ChromeOptions();
// Отключаем картинки: без них страница загружается быстрее
$options->setExperimentalOption(
	'prefs',
	[
		'profile.default_content_setting_values' => ['images' => 2, 'stylesheets' => 2, 'stylesheet' => 2],
		'profile.managed_default_content_settings' => ['stylesheets' => 2],
		'profile.managed_default_content_settings.stylesheets' => 2,
		'profile.managed_default_content_settings' => ['stylesheet' => 2],
		'profile.managed_default_content_settings.stylesheet' => 2,
	]
);
$options->setExperimentalOption('useAutomationExtension', false);
$options->setExperimentalOption('excludeSwitches', ['enable-automation']);
$options->addArguments([
	'--incognito',
	//'--window-size=' . $this->config['browserWindowSize'],
	//'--window-position=' . $this->config['browserWindowPosition'],
	'--disable-infobars',
	'--no-proxy-server',
	'--no-default-browser-check',
	'--no-first-run',
	'--disable-boot-animation',
	'--disable-default-apps',
	'--disable-extensions',
	'--disable-translate',
	'--disable-desktop-notifications',
]);
if (!$showBrowserWindow) {
	// Скрываем окно браузера, оно не нужно
	$options->addArguments([
		'--headless',
		'--no-startup-window',
	]);
}
$capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

// Создаём вебдрайвер (в Windows у файла есть расширение `.exe`)
putenv(
	'WEBDRIVER_CHROME_DRIVER=' . __DIR__ . '/selenium/chromedriver' .
	(strncasecmp(PHP_OS, 'WIN', 3) === 0 ? '.exe' : '')
);
$driver = ChromeDriver::start($capabilities);
$driver->get($url);
$driver->wait()->until(
	WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector(
		$loginSelector
	))
);
// Вход в систему и ожидание загрузки данных
$driver->findElement(WebDriverBy::cssSelector($loginSelector))->sendKeys($login . "\n");
$driver->wait()->until(
	WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector(
		$passwordSelector
	))
);
$driver->findElement(WebDriverBy::cssSelector($passwordSelector))->sendKeys($password . "\n");
$driver->wait()->until(
	WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector(
		$balanceSelector
	))
);
$driver->wait()->until(
	WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector(
		$tableSelector
	))
);

//$driver->wait(1);
sleep(1);

// Есть ли текущие отправления средств?
$inProgress = $driver->findElement(WebDriverBy::cssSelector(
	'[data-test-id=button_payments-processing]'
));
if (preg_match('/(\d+)$/', trim($inProgress->getText()), $match)) {
	$inProgressCount = (int)$match[1];
	if ($inProgress > 0) {
		// Есть текущие отправляемые транзакции: баланс и таблица неполные
		$driver->close();
		exit(0);
	}
}

// Кеш последних операций и баланса
$cache = json_decode(@file_get_contents($cacheFile), true) ?? ['balance' => 0, 'items' => []];
$oldTransactions = $cache['items'];
$oldBalance = doubleval($cache['balance']);

// Новый баланс
$balanceText = $driver->findElement(WebDriverBy::cssSelector($balanceSelector))->getText();
$balance = stringToAmount($balanceText);

// Разница балансов текстом
$balanceDiff = $balance !== $oldBalance
	? (
		' · <del>' . amountToString($oldBalance, 2) . '</del> ' .
		preg_replace('/^[+−]/u', '$0 ', amountToString($balance - $oldBalance, 2))
	) : '';
$message = [
	'<strong><u>' . amountToString($balance, 2) . '</u></strong> ₽ · баланс' . $balanceDiff
];
//echo 'Баланс: ', amountToString($balance, 2), ' ₽', PHP_EOL;

// Таблица последних операций (обычно до 25 транзакций)
$table = $driver->findElement(WebDriverBy::cssSelector($tableSelector));
$trs = $table->findElements(WebDriverBy::cssSelector('tbody tr'));
$transactions = [];
$newTransactions = [];
foreach ($trs as $row) {
	// Получаем значения из строк таблицы доходов-расходов на странице
	$tds = $row->findElements(WebDriverBy::cssSelector('td'));
	if (count($tds) < 5) {
		continue;
	}
	$date = $tds[0]->getText();
	list($agent, $description) = explode("\n", $tds[1]->getText(), 2);
	$number = $tds[2]->getText();
	$sum = $tds[3]->getText();
	$currency = $tds[4]->getText();
	$sumText = amountToString(stringToAmount($sum));

	// Транзакция
	$item = [
		'date' => $date,
		'agent' => $agent,
		'description' => $description,
		'number' => $number,
		'sum' => stringToAmount($sum),
		'currency' => $currency,
	];
	$transactions []= $item;
	if (!transactionExists($oldTransactions, $item)) {
		// Если транзакции нет в списке сохранённых, добавляем её в сообщение
		$newTransactions []= $item;
		$message[] = sprintf(
			'<strong><u>%s</u></strong> · %s · <strong>%s</strong> · %s',
			htmlspecialchars($sumText),
			htmlspecialchars($date),
			htmlspecialchars($agent),
			htmlspecialchars($description),
		);
	}
}

$driver->close();

// Если новых транзакций нет, то не посылаем обновления даже при разнице балансов.
// Так делаем, потому что иногда баланс обновляется быстрее, чем появляются транзакции.
if (count($newTransactions) > 0) {
	sendBotMessage($telegramBotToken, $telegramBotChat, implode(PHP_EOL . PHP_EOL, $message));
	file_put_contents($cacheFile, json_encode(
		['balance' => $balance, 'items' => $transactions],
		JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
	));
}

exit(0);


/**
 * Текстовое представление в число.
 * @param string $string
 * @return float
 */
function stringToAmount(string $string): float
{
	return doubleval(str_replace(
		['−', ' ', ' ', ' ', ',', '₽'],
		['-',  '',  '',  '', '.',  ''],
		$string
	));
}

/**
 * Число в тестовое представление.
 * @param float $amount Число
 * @param integer $decimals Количество знаков после запятой
 * @return string
 */
function amountToString(float $amount, int $decimals = 0): string
{
	if ($amount < 0) {
		$prefix = '−';
		$amount = -$amount;
	} else {
		$prefix = '+';
	}
	return $prefix . number_format($amount, $decimals, ',', ' ');
}

/**
 * Существует ли транзакция в списке транзакций.
 * @param array $items Список транзакций
 * @param array $transaction Искомая транзакция
 * @return bool
 */
function transactionExists(array $items, array $transaction): bool
{
	foreach ($items as $item) {
		if ($item['date'] === $transaction['date'] &&
			$item['agent'] === $transaction['agent'] &&
			$item['description'] === $transaction['description'] &&
			$item['number'] === $transaction['number'] &&
			doubleval($item['sum']) === doubleval($transaction['sum']) &&
			$item['currency'] === $transaction['currency']) {
			return true;
		}
	}
	return false;
}

/**
 * Послать сообщение Телеграм-ботом в заданный чат.
 * @param string $token Токен бота
 * @param string|int $chatId Идентификатор чата
 * @param string $text Текст сообщения
 */
function sendBotMessage(string $token, $chatId, string $text): void
{
	if (mb_strlen($text) > 5000) {
		$text = mb_substr($text, 0, 5000) . '…';
	}
	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => 'https://api.telegram.org/bot' . $token . '/sendMessage',
		CURLOPT_POST => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_RETURNTRANSFER => true,
		//CURLOPT_SSL_VERIFYPEER => false, // Опасно!
		CURLOPT_POSTFIELDS => [
			'chat_id' => $chatId,
			'text' => $text,
			'parse_mode' => 'HTML',
			'disable_web_page_preview' => true,
		],
	]);

	// Если отправить ПОСТ не удалось, выбрасываем ошибку
	$result = curl_exec($curl);
	curl_close($curl);
	if (is_string($result)) {
		$object = json_decode($result);
		if (!$object->ok) {
			throw new \Exception(
				'Error sending Telegram message (#' . $object->error_code . '): ' .
				$object->description
			);
		}
	}
}
