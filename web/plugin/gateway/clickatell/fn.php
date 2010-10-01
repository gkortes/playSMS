<?php
function clickatell_hook_getsmsstatus($gpid=0,$uid="",$smslog_id="",$p_datetime="",$p_update="") {
    global $clickatell_param;
    list($c_sms_credit,$c_sms_status) = clickatell_getsmsstatus($smslog_id);
    // pending
    $p_status = 0;
    if ($c_sms_status) {
	$p_status = $c_sms_status;
    }
    setsmsdeliverystatus($smslog_id,$uid,$p_status);
}

function clickatell_hook_playsmsd() {
    // force to check p_status=1 (sent) as getsmsstatus only check for p_status=0 (pending)
    //$db_query = "SELECT * FROM "._DB_PREF_."_tblSMSOutgoing WHERE p_status=0 OR p_status=1";
    $db_query = "SELECT * FROM "._DB_PREF_."_tblSMSOutgoing WHERE p_status='1' AND p_gateway='clickatell'";
    $db_result = dba_query($db_query);
    while ($db_row = dba_fetch_array($db_result)) {
	$uid = $db_row['uid'];
	$smslog_id = $db_row['smslog_id'];
	$p_datetime = $db_row['p_datetime'];
	$p_update = $db_row['p_update'];
	$gpid = $db_row['p_gpid'];
	x_hook('clickatell','getsmsstatus',array($gpid,$uid,$smslog_id,$p_datetime,$p_update));
    }
}

function clickatell_hook_sendsms($mobile_sender,$sms_sender,$sms_to,$sms_msg,$uid='',$gpid=0,$smslog_id=0,$sms_type='text',$unicode=0) {
    global $clickatell_param;
    global $gateway_number;
    if ($clickatell_param['sender']) {
	$sms_from = $clickatell_param['sender'];
    } else if ($gateway_number) {
	$sms_from = $gateway_number;
    } else {
	$sms_from = $mobile_sender;
    }
    if ($sms_sender) {
	$sms_msg = $sms_msg.$sms_sender;
    }
    switch ($msg_type) {
	case "flash": $sms_type = "SMS_FLASH"; break;
	case "logo": $sms_type = "SMS_NOKIA_OLOGO"; break;
	case "picture": $sms_type = "SMS_NOKIA_PICTURE"; break;
	case "ringtone": 
	case "rtttl": $sms_type = "SMS_NOKIA_RTTTL"; break;
	case "text": 
	default: $sms_type = "SMS_TEXT";
    }
    if ($unicode) {
	$sms_msg = utf8_to_unicode($sms_msg);
    }
    // no concat
    
    // fixme anton - if sms_from is not set in gateway_number and global number, we cannot pass it to clickatell
    $set_sms_from = ( $sms_from == $mobile_sender ? '' : "&from=".rawurlencode($sms_from) );
    
    $query_string = "sendmsg?api_id=".$clickatell_param['api_id']."&user=".$clickatell_param['username']."&password=".$clickatell_param['password']."&to=".rawurlencode($sms_to)."&msg_type=$sms_type&text=".rawurlencode($sms_msg)."&deliv_ack=1&callback=3&unicode=$unicode".$set_sms_from;
    $url = $clickatell_param['send_url']."/".$query_string;
    logger_print($url, 3, "clickatell outgoing");
    $fd = @implode ('', file ($url));
    $ok = false;
    // failed
    $p_status = 2;
    setsmsdeliverystatus($smslog_id,$uid,$p_status);
    if ($fd) {
        $response = split (":", $fd);
        $err_code = trim ($response[1]);
        if ((strtoupper($response[0]) == "ID")) {
	    if ($apimsgid = trim($response[1])) {
		clickatell_setsmsapimsgid($smslog_id,$apimsgid);
		list($c_sms_credit,$c_sms_status) = clickatell_getsmsstatus($smslog_id);
		// pending
		$p_status = 0;
		if ($c_sms_status) {
		    $p_status = $c_sms_status;
		}
	    } else {
		// sent
		$p_status = 1;
	    }
	    logger_print("smslog_id:".$smslog_id." response:".$response[0]." ".$response[1], 3, "clickatell outgoing");
	    setsmsdeliverystatus($smslog_id,$uid,$p_status);
	}
	$ok = true;
    }
    return $ok;
}

function clickatell_hook_getsmsinbox() {
    global $clickatell_param;
    $handle = @opendir($clickatell_param['incoming_path']);
    while ($sms_in_file = @readdir($handle)) {
	if (eregi("^ERR.in",$sms_in_file) && !eregi("^[.]",$sms_in_file)) {
	    $fn = $clickatell_param['incoming_path']."/$sms_in_file";
	    $tobe_deleted = $fn;
	    $lines = @file ($fn);
	    $sms_datetime = trim($lines[0]);
	    $sms_sender = trim($lines[1]);
	    $message = "";
	    for ($lc=2;$lc<count($lines);$lc++) {
		$message .= trim($lines['$lc']);
	    }
	    // collected:
	    // $sms_datetime, $sms_sender, $message
	    setsmsincomingaction($sms_datetime,$sms_sender,$message);
	    @unlink($tobe_deleted);
	}
    }
}

function clickatell_getsmsstatus($smslog_id) {
    global $clickatell_param;
    $c_sms_status = 0;
    $c_sms_credit = 0;
    $db_query = "SELECT apimsgid FROM "._DB_PREF_."_gatewayClickatell_apidata WHERE smslog_id='$smslog_id'";
    $db_result = dba_query($db_query);
    $db_row = dba_fetch_array($db_result);
    if ($apimsgid = $db_row['apimsgid']) {
	$query_string = "getmsgcharge?api_id=".$clickatell_param['api_id']."&user=".$clickatell_param['username']."&password=".$clickatell_param['password']."&apimsgid=$apimsgid";
	$url = $clickatell_param['send_url']."/".$query_string;
	$fd = @implode ('', file ($url));
	if ($fd) {
    	    $response = split (" ", $fd);
    	    $err_code = trim ($response[1]);
	    $credit = 0;
    	    if ((strtoupper(trim($response[2])) == "CHARGE:")) {
		$credit = intval(trim($response[3]));
	    }
	    $c_sms_credit = $credit;
	    if ((strtoupper(trim($response[4])) == "STATUS:")) {
		$status = trim($response[5]);
		switch ($status) {
		    case "001":
		    case "002":
		    case "011": $c_sms_status = 0; break; // pending
		    case "003":
		    case "008": $c_sms_status = 1; break; // sent
		    case "005":
		    case "006":
		    case "007":
		    case "009":
		    case "010":
		    case "012": $c_sms_status = 2; break; // failed
		    case "004": $c_sms_status = 3; break; // delivered
		}
	    }
	}
    }
    return array ($c_sms_credit, $c_sms_status);
}

function clickatell_setsmsapimsgid($smslog_id,$apimsgid) {
    if ($smslog_id && $apimsgid) {
	$db_query = "INSERT INTO "._DB_PREF_."_gatewayClickatell_apidata (smslog_id,apimsgid) VALUES ('$smslog_id','$apimsgid')";
	$db_result = dba_query($db_query);
    }
}

?>