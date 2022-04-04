<?
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
include_once(GetLangFileName($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/sale/payment/paygine/", "/payment.php"));

try {
	if (!CModule::IncludeModule("sale"))
		throw new Exception("Error when initializing sale module");

	$arOrder = CSaleOrder::GetByID(intval($_REQUEST["reference"]));
	if (!$arOrder)
		throw new Exception("No such order id");
	CSalePaySystemAction::InitParamArrays($arOrder, $arOrder["ID"]);

	$sector = CSalePaySystemAction::GetParamValue("Sector");
	$password = CSalePaySystemAction::GetParamValue("Password");
	$test_mode = (strlen(CSalePaySystemAction::GetParamValue("TestMode")) > 0) ?
		intval(CSalePaySystemAction::GetParamValue("TestMode")) :
		1;
        $success_url = CSalePaySystemAction::GetParamValue("SuccessURL");
        $success_url = $success_url."?InvId=".strval($arOrder["ID"]);
        $fail_url = CSalePaySystemAction::GetParamValue("FailURL");
        $fail_url = $fail_url."?InvId=".strval($arOrder["ID"]);

	$paygine_order_id = intval($_REQUEST["id"]);
	if (!$paygine_order_id)
		throw new Exception("Invalid order id");

	$paygine_operation_id = intval($_REQUEST["operation"]);
	if (!$paygine_operation_id)
		throw new Exception("Invalid operation id");

	// check payment operation state
	$signature = base64_encode(md5($sector . $paygine_order_id . $paygine_operation_id . $password));

	$paygine_url = "https://pay.paygine.com";
	if ($test_mode == 1)
		$paygine_url = "https://test.paygine.com";

	$context  = stream_context_create(array(
		'http' => array(
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			'method'  => 'POST',
			'content' => http_build_query(array(
				'sector' => $sector,
				'id' => $paygine_order_id,
				'operation' => $paygine_operation_id,
				'signature' => $signature
			)),
		)
	));

	$repeat = 3;

	while ($repeat) {

		$repeat--;

		// pause because of possible background processing in the Paygine
		sleep(2);

		$xml = file_get_contents($paygine_url . '/webapi/Operation', false, $context);
		if (!$xml)
			throw new Exception("Empty data");
		$xml = simplexml_load_string($xml);
		if (!$xml)
			throw new Exception("Non valid XML was received");
		$response = json_decode(json_encode($xml));
		if (!$response)
			throw new Exception("Non valid XML was received");

		if (!orderAsPayed($response))
			continue;

		header("Location: {$success_url}", true, 302);
		exit();

	}
	
	header("Location: {$fail_url}", true, 302);
	exit();

} catch (Exception $ex) {
	error_log($ex->getMessage());
	header("Location: {$fail_url}", true, 302);
	exit();
}

function orderAsPayed($response) {

	// looking for an order
	$order_id = intval($response->reference);
	if ($order_id == 0)
		throw new Exception("Invalid order id: {$order_id}");

	$arOrder = CSaleOrder::GetByID($order_id);
	if (!$arOrder)
		throw new Exception("No such order id: {$order_id}");
		
	
	CSalePaySystemAction::InitParamArrays($arOrder, $arOrder["ID"]);

	// check server signature
	$tmp_response = (array)$response;
	unset($tmp_response["signature"]);
	unset($tmp_response["ofd_state"]);
	unset($tmp_response["protocol_message"]);
	// echo '<pre>';
	// print_r($tmp_response);	
	$signature = base64_encode(md5(implode('', $tmp_response) . CSalePaySystemAction::GetParamValue("Password")));
	if ($signature !== $response->signature)
		throw new Exception("Invalid signature");

	// check order state
	if (($response->type != 'PURCHASE' && $response->type != 'EPAYMENT' && $response->type != 'AUTHORIZE') || $response->state != 'APPROVED')
		return false;

	// extract payed order properties
	$amount = $response->amount / 100.0;
	switch (intval($response->currency)) {
		case 643:
			$currency = "RUB";
			break;
		case 978:
			$currency = "EUR";
			break;
		case 840:
			$currency = "USD";
			break;
		default:
			throw new Exception("Unknown currency (only RUB, EUR, USD are allowed)");
			break;
	}

	if ($amount <= 0 || doubleval($arOrder["PRICE"]) > $amount + 0.01)
		throw new Exception("The payed price ({$amount}) is lower than a order price ({$arOrder['PRICE']})");

	if ($currency !== $arOrder["CURRENCY"])
		throw new Exception("The order currency ({$arOrder['CURRENCY']}) is not equal the payed one ({$currency})");

	$arFields = array(
		"PS_STATUS" => "Y",
		"PS_STATUS_CODE" => $response->state,
		"PS_STATUS_DESCRIPTION" => $response->message,
		"PS_STATUS_MESSAGE" => "Paygine transaction id {$response->id}",
		"PS_SUM" => $amount,
		"PS_CURRENCY" => $currency,
		"PS_RESPONSE_DATE" => Date(CDatabase::DateFormatToPHP(CLang::GetDateFormat("FULL", LANG))),
		"USER_ID" => $arOrder["USER_ID"],
		"PAYED" => "Y",
		"DATE_PAYED" => Date(CDatabase::DateFormatToPHP(CLang::GetDateFormat("FULL", LANG))),
		"EMP_PAYED_ID" => false
	);

	if (!CSaleOrder::Update($arOrder["ID"], $arFields))
		throw new Exception("Error occured when updating order {$arOrder['ID']} status");
	
	return true;

}

