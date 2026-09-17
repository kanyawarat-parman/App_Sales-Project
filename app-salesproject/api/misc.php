<?php

// headermisc.php

// header('Access-Control-Allow-Origin: https://thaitaiyo.co.th');

// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
// header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-Requested-With');


$environment="dev";
// $environment="production";

// $backendserver="http://localhost:55478/";
$backendserver="http://taiyo.dyndns.info:8098/webmobile_backend/";

// TODO: Replace with your actual Line Official Account Channel Access Token
//$tokenline = "5ZBuPNEQYSuhKOfRsIHJZ7fYLGTYRONaOvIttFbjBQcRjRrZne8UkNiyVbUwJkVuA5mmres/qMBCICZ+nON5tEYmHfmciNzplPODLt6MR3qpxS3W+kVS0cgTsqYIUdiyrPOr1n/wUUSXZG2/ghbNBwdB04t89/1O/w1cDnyilFU=";

//mormoor test
$tokenline="1Q2P2ghrBatOf33tSZhgM/SeP5yE6shFkfn+fTTVbtjneTyX8SftPn9USDq7WE4oxk3Q9PZRcTiVBZPTJdEby3KR3bG+IikLMTrGwwHrZQRbrH/BMjy/9nqx60BVleNwNDEbuQlN1aB0j0vzhVMu9gdB04t89/1O/w1cDnyilFU=";




date_default_timezone_set('Asia/Bangkok');


// Defining function
function getSum($num1, $num2){
    $total = $num1 + $num2;
    return $total;
}

function savestring($str){
    $str = str_replace('"','\"',$str);
    $str = str_replace(",","\,",$str);
    $str = str_replace("'","\'",$str);
    $str =trim($str);
    return $str;
}

function saveint($str) {
    
    $result =0;

    try {
        $result = intval($str);
    } catch (\Throwable $th) {
        //throw $th;
         $result =0;
    }

    return $result;
}

function savefloat($str) {
    
    $result =0;

    try {
        $result = floatval($str);
    } catch (\Throwable $th) {
        //throw $th;
         $result =0;
    }

    return $result;
}



function savedatevalid($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date ? "'" . "$date"."'" : NULL;
}

function savedate($date) {
    $result="";

    $datelen = strlen($date);

    if(intval($datelen)==10){
        $result= "'".$date."'";
    }
    else {
        $result="NULL";
    }

    return $result;
}





function calpage($sallrecord, $srecordperPage){
    $sresult = 0;
    $smod = 0;

    try 
    {
        $smod = $sallrecord % $srecordperPage;
        $sresult =floor( $sallrecord / $srecordperPage);
    } 
    catch (Exception $e) 
    {
        $sresult=1;
    }

    
    if ($smod != 0)
    {
        $sresult = $sresult + 1;
    }

    if ($sresult <= 0) { $sresult = 1; }
    return $sresult;

    /*
    int sresult = 0;
        int smod = 0;

        try
        {
            smod = sallrecord % srecordperPage;
            sresult = sallrecord / srecordperPage;

            if (smod != 0)
            {
                sresult = sresult + 1;
            }


        }
        catch (Exception)
        {

            sresult = 1;
        }


        if (sresult <= 0) { sresult = 1; }

        return sresult;
        */

}


/**
 * Sends a push message using the Line Messaging API.
 *
 * @param string $userId The recipient's Line User ID.
 * @param string $messageText The message to send.
 * @return bool|string Returns true on success, or an error message string on failure.
 */
function Sendlinenotify($userId, $messageText,$uri) {
    global $tokenline; // Access the global token variable

    if (empty($userId) || empty($messageText)) {
        return "User ID or Message cannot be empty.";
    }

    if (empty($tokenline) || $tokenline === "YOUR_CHANNEL_ACCESS_TOKEN_HERE") {
        error_log("Line API token is not configured in savenotification_confirm.php");
        return "Line API token is not configured.";
    }
    
    $url = 'https://api.line.me/v2/bot/message/push';

    //$messages = [['type' => 'text', 'text' => $messageText]];
    //$data = ['to' => $userId, 'messages' => $messages];
    $data = gettypesendline('text',$userId,$messageText,$uri);
    $post_data = json_encode($data);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $tokenline,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return "cURL Error: " . $curl_error;
    }

    if ($http_status == 200) {
        return true;
    } else {
        $response_data = json_decode($result, true);
        $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown error.';
        if (!empty($response_data['details'])) {
            foreach ($response_data['details'] as $detail) {
                $error_message .= ' (' . $detail['property'] . ': ' . $detail['message'] . ')';
            } 
        }
        return "Line Push API Error: HTTP Status " . $http_status . " - " . $error_message;
    }
}


function gettokensales($dbcon, $salesid) {
    $tokenlineid = null;

    if (isset($dbcon) && !empty($salesid)) {
        // Assuming 'id' is the primary key in the 'salesman' table for salesid
        // and 'tokenlineid' is the column storing the Line token
        $stmt = $dbcon->prepare("SELECT line_user_id FROM users WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $salesid); // Assuming salesid is a string, adjust if it's an integer
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $tokenlineid = $row['line_user_id'];
            }
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for gettokensales: " . $dbcon->error);
        }
    }
    return $tokenlineid;
}

function getNotificationMessage($type,$orderid,$custonername,$senddate,$orderamount,$shiptoamount){
    $message = "";
    switch ($type) {
        case 'new_order':
            $message = "มี Order ใหม่เข้า เลขที่: $orderid";
            break;
        case 'confirm_order':
            $message = "คำสั่งซื้อเลขที่: $orderid ถูกยืนยันแล้ว";
            break;
        case 'save_order':
            //$message = "Admin บันทึก Order เลขที่: $orderid เรียบร้อย";
            $message = "Admin บันทึก Orders \n";
            $message = $message . "เลขที่: $orderid \n";
            $message = $message . "ลูกค้า: $custonername \n";
            $message = $message . "ยอดรวม: " . number_format($orderamount, 2) . " บาท \n";
            $message = $message . "วันที่: " . $senddate->format('d/m/Y H:i');
            break;
        case 'open_invoice':
            $message = "มีการเปิดบิล Invoice ";
            $message = $message."เลขที่: $orderid ";
            $message = $message . "ลูกค้า: $custonername ";
            $message = $message . "ยอดรวม: " . number_format($orderamount, 2) . " บาท ";
            $message = $message . "วันที่: " . $senddate->format('d/m/Y H:i');
            break;
        case 'order_warning':
            $message = "คำเตือน \n";
            $message = $message."คำสั่งซื้อเลขที่: $orderid \n";
            $message = $message."ลูกค้า: $custonername \n";
            $message = $message."ถ้าไม่มีการยืนยันภายใน 1 ชั่วโมง คำสั่งซื้อจะถูกยกเลิก \n";
            break;
        case 'order_deleted':
            $message = "ยกเลิกรายการ \n";
            $message = $message."คำสั่งซื้อเลขที่: $orderid \n";
            $message = $message."ลูกค้า: $custonername \n";
            $message = $message."ถูกยกเลิกเนื่องจากไม่มีการยืนยันครบ 24 ชั่วโมง \n";
            break; 
        case 'order_shipto':
            $message = "ตอบกลับค่าส่งต่อ \n";
            $message = $message."คำสั่งซื้อเลขที่: $orderid \n";
            $message = $message."ลูกค้า: $custonername \n";
            $message = $message . "ยอดรวม: " . number_format($orderamount, 2) . " บาท \n";
            $message = $message . "ยอดค่าส่งต่อ: " . number_format($shiptoamount, 2) . " บาท \n";
            $message = $message . "วันที่: " . $senddate->format('d/m/Y H:i') . "\n";
            break;
        case 'confirm_shipto':
            $message = "ยืนยันค่าส่งต่อ ";
            $message = $message."คำสั่งซื้อเลขที่: $orderid";
            break;
        case 'order_delete':
            $message = "ยกเลิกรายการ ";
            $message = $message."คำสั่งซื้อเลขที่: $orderid";
            break;
        case 'shipto_delete':
            $message = "ยกเลิกรายการค่าส่งต่อ ";
            $message = $message."คำสั่งซื้อเลขที่: $orderid";
            break;
    }
    return $message;
}





function gettypesendline($type,$userid,$message,$url){
    $data = "";

    
    switch ($type) {
        case 'button':
            $data = [
                'to' => $userid,
                'messages' => [
                    [
                        'type' => 'template',
                        'altText' => 'ข้อความแจ้งเตือน', // Alternative text for notifications
                        'template' => [
                            'type' => 'buttons',
                            'title' => 'แจ้งเตือน',
                            'text' => $message, // Message text
                            'actions' => [
                                [
                                    'type' => 'uri',
                                    'label' => 'รายละเอียด',
                                    'uri' => $url
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            break;
        case 'text':
            $data = [
                'to' => $userid,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message
                    ]
                ]
            ];
            break;
    }
    


    return $data;
}




function messagetype($type){
    $messagetype = "";

    switch ($type) {
        case 'new_order':
            $messagetype = "order_status";
            break;
        case 'confirm_order':
            $messagetype = "order_status";
            break;
        case 'updateorder':
            $messagetype = "order_status";
            break;
        case 'confirmshipto':
            $messagetype = "order_status";
            break;
        case 'order_delete':
            $messagetype = "order_deleted";
            break;
        case 'shipto_delete':
            $messagetype = "order_deleted";
            break;

    }

    return $messagetype;
    
}

?>