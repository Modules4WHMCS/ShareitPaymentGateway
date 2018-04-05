<?php


function shareit_config()
{
    $configarray = array(
			"FriendlyName" => array("Type" => "System", "Value"=>"MyCommerce (Shareit)"),
			"SHAREIT_VENDOR_ID" => array("FriendlyName" => "Vendor ID","Type" => "text","Rows"=>20),
			"SHAREIT_MD5_SECRET_KEY" => array("FriendlyName" => "MD5 Secret Key","Type" => "text","Rows"=>20),
            "SHAREIT_PRODUCTS_MAPS" => array("FriendlyName" => "Product ID mapping ( WHMCS_PID:SHAREIT_PID; )","Type" => "textarea","Rows"=>20),
            "whmcs_admin" => array("FriendlyName" => "WHMCS Admin Login", "Type" => "text", 'Rows'=>20)
		);
    return $configarray;
}




function shareit_link($params)
{
    file_put_contents('/tmp/shareit_link.log', print_r($params,true)."\r\n");

    $tmpStr=str_replace(array("\n","\r"," "), array(""),$params['SHAREIT_PRODUCTS_MAPS']);
    $tmp = explode(';',$tmpStr);

    foreach ($tmp as $map){
        list($key,$val) = explode(':',$map);
        $prodMap[$key] = $val;
    }

    $db = new ShareITDB();
	$result=$db->mysqlQuery('SELECT tblhost.packageid,tblhost.firstpaymentamount FROM tblinvoiceitems AS item 
                                      LEFT JOIN tblhosting AS tblhost ON tblhost.id=item.relid 
                                      WHERE item.type="Hosting" AND item.invoiceid=%s',
                                        $params['invoiceid']);
    $formData='';
    $invoiceHash = md5($params['invoiceid'].'#'.$params['SHAREIT_MD5_SECRET_KEY']);
    $prodInfo=array();
    while($row=$db->fetch_assoc($result)){
        $prodInfo[$row['packageid']] += $row['firstpaymentamount'];
    }

    foreach($prodInfo as $prodid => $ammount){
        $shareitProdID=$prodMap[$prodid];
        $price=$ammount.$params['currency'].',N';
        $priceStr=$shareitProdID.'#'.$price.'#'.$params['SHAREIT_MD5_SECRET_KEY'];
        $priceHash = md5($priceStr);
        $priceVal = $price.';'.$priceHash;//urlencode($price.';'.$priceHash);

        //123456#12.95EUR,14.50USD,N#yourpasswordhere

        $formData .= '<input type="hidden" name="HADD['.$shareitProdID.'][invoiceid]" value="'.$params['invoiceid'].'#'.$invoiceHash.'" />
                <input type="hidden" name="PRODUCT['.$shareitProdID.']" value="1" />
                <input type="hidden" name="PRODUCTPRICE['.$shareitProdID.']" value="'.$priceVal.'" />';
	}


	//$checkoutUri.='&BACK_REF='.'https://'.$_SERVER['HTTP_HOST'].'/modules/gateways/callback/avangate.php?success='.$params['invoiceid'];

	$code = '<form method="GET" action="https://order.shareit.com/cart/new">		   		
 				<input type="hidden" name="vendorid" value="'.$params['SHAREIT_VENDOR_ID'].'" /> 
 				<input type="hidden" name="cartcoupon" value="false" />	
 				<input type="hidden" name="pc" value="smb44"/>		
                <input type="hidden" name="backlink" value="'.$params['returnurl'].'" />
                <input type="hidden" name="currency" value="all" />
                
                
                
                '
	
	
	;

		
	$code .= $formData.'<input type="submit" value="'.$params['langpaynow'].'" /></form>';



    file_put_contents('/tmp/shareit_link.log', $code."\r\n",FILE_APPEND);



    return $code;
}




class ShareITDB
{
    public static $FETCH_ASSOC = 'assoc';
    public static $FETCH_ARRAY = 'array';
    private $db_host;
    private $db_username;
    private $db_password;
    private $db_name;

    public function __construct()
    {
        include __DIR__."/../../configuration.php";
        global $db_host, $db_username, $db_password, $db_name;
        $this->db_host = $db_host;
        $this->db_username = $db_username;
        $this->db_password = $db_password;
        $this->db_name = $db_name;
        $this->db_link = mysqli_connect($this->db_host,$this->db_username,$this->db_password,$this->db_name);
    }

    public function mysqlQuery($query)
    {
        $argcount = func_num_args();
        if($argcount > 1){
            $args = func_get_args();
            unset($args[0]);
            for ($i = 1; $i <= $argcount - 1; $i++) {
                $args[$i] = $args[$i]=='NULL'?'NULL':$this->quote_smart($args[$i]);
            }
            $query = vsprintf($query,$args);
        }
        $result=mysqli_query($this->db_link,$query);
        $err=mysqli_errno($this->db_link);

        if($err === 2006 || $err === 2013){
            //RECONNECT TO THE MYSQL DB
            $this->db_link=mysqli_connect($this->db_host,$this->db_username,$this->db_password,$this->db_name);
            return $this->mysqlQuery($query);
        }

        return $result;
    }

    public function fetch_assoc($result)
    {
        return mysqli_fetch_assoc($result);
    }

    private function quote_smart($value)
    {
        // Stripslashes
        if (get_magic_quotes_gpc()){
            $value = stripslashes($value);
        }
        // Quote if not a number or a numeric string
        if (!is_numeric($value)){
            $value = "'" . mysqli_real_escape_string($this->db_link,$value) . "'";
        }
        return $value;
    }



}
