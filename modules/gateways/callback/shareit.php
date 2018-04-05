<?php


// FOR 6.x version
$ROOTDIR=__DIR__."/../../../";

include_once $ROOTDIR."init.php";
include_once $ROOTDIR.'includes/functions.php';
include_once $ROOTDIR.'includes/gatewayfunctions.php';
include_once $ROOTDIR . 'includes/invoicefunctions.php';

include_once __DIR__.'../shareit.php';


$GATEWAY = getGatewayVariables('shareit');
if(!isset($HTTP_RAW_POST_DATA)) {
    $HTTP_RAW_POST_DATA = file_get_contents('php://input');
}


if($_REQUEST['success']){
    $invoiceid=$_REQUEST['success'];
    checkCbInvoiceID($invoiceid,$GATEWAY["name"]);

    $db = new ShareITDB();
    $result = $db->mysqlQuery("SELECT status FROM tblorders WHERE invoiceid=%s",
                                        $invoiceid);
    $order = $db->fetch_assoc($result);
    if($order['status'] != 'Active'){
        $url = $_SERVER['PHP_SELF'].'?success='.$invoiceid;
        header("Refresh: 20; url=$url");
        echo('Your order is being processed.<br>
Please wait .....<br>
if your browser does not support auto refresh click this <a href="'.$url.'">link</a>.');
    }
    else{
        header('Location: ../../../viewinvoice.php?id='.$invoiceid.'&paymentsuccess=true');
    }
    exit();
}
else {

    $e5Notification = json_decode($HTTP_RAW_POST_DATA);
    $e5Notification = $e5Notification->e5Notification;

    if (isset($e5Notification->orderNotification)) {
        $purchase = $e5Notification->orderNotification->purchase;
        switch ($purchase->paymentStatus) {
            case "complete":case "test payment arrived":{

                $invoiceInfo = $purchase->purchaseItem->additionalInformation->additionalValue; // ????????????????????
                list($invID,$invHash)=explode('#',$invoiceInfo);
                $OriginInvoiceHash = md5($invID.'#'.$GATEWAY['SHAREIT_MD5_SECRET_KEY']);
                if($OriginInvoiceHash !== $invHash){
                    logTransaction($GATEWAY["name"],$HTTP_RAW_POST_DATA,'ERROR:INVOICE HASH mismatch::'.$invHash);
                    break;
                }

                $transactionid = $purchase->purchaseId;
                checkCbInvoiceID($invID, $GATEWAY["name"]);
                checkCbTransID($transactionid);

                $values['invoiceid'] = $invID;
                $invoice = localAPI("getinvoice", $values, $GATEWAY['whmcs_admin']);
                $inv_amount = $invoice['total'];
                $paid_currency = $purchase->customerPaymentData->currency;
                $paid_amount = 0;
                for($i=0;;$i++)
                {
                    $purchaseItem = "purchaseItem";
                    if($i>0){
                        $purchaseItem .= '_'.$i;
                        if(!isset($purchase->$purchaseItem)){
                            break;
                        }
                    }

                    $paid_amount += $purchase->$purchaseItem->quantity *
                                        $purchase->$purchaseItem->productSinglePrice;
                }


                if((float)$paid_currency !== (float)$invoice['currency']) {
                    $currency = shareit_getCurrency($GATEWAY['whmcs_admin'], $invoice['currency']);
                    $CurrencyRate = (float)$currency['rate'];

                    $inv_amount = round($inv_amount / $CurrencyRate, 0);
                    $inv_amount = number_format($inv_amount, 2, '.', "");
                }


                if((float)$inv_amount !== (float)$paid_amount) {
                    logTransaction($GATEWAY["name"], $HTTP_RAW_POST_DATA, 'ERROR ammount not equal');
                    break;
                }

                addInvoicePayment($invID, $transactionid, $inv_amount, null, $GATEWAY["name"]);
                $db = new ShareITDB();
                $result = $db->mysqlQuery('SELECT id FROM tblorders WHERE invoiceid=%s',$invID);
                $order = $db->fetch_assoc($result);
                $results = localAPI("acceptorder", array(
                                            "orderid" => $order['id'],
                                            "autosetup" => true,
                                            "sendemail" => true),
                                    $GATEWAY['whmcs_admin']);

                logTransaction($GATEWAY["name"], $HTTP_RAW_POST_DATA, 'Payment recieved');
                exit();
            }
        }
    }

}


function shareit_getCurrency($whmcs_admin,$curCode=null)
{
	$currencies = localAPI("GetCurrencies",array(),$whmcs_admin);
	if ($currencies && $currencies['result'] === 'success')
	{
		foreach ($currencies['currencies']['currency'] as $currency)
		{
			if($curCode){
				if($curCode === $currency['code']){ return $currency;}
			}
			else if($currency['rate'] === '1.00000') {return $currency;}
		}
	}
	else {
		throw new Exception('getcurrencies ERROR:: '.  print_r($currencies));
	}
}



