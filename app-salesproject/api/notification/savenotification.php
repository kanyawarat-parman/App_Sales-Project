<?php
// header("Access-Control-Allow-Origin: *");
// header("Content-Type: application/json; charset=UTF-8");
// header("Access-Control-Allow-Methods: POST");
// header("Access-Control-Max-Age: 3600");
// header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// include database and object files
require_once __DIR__ . '/../config/database.php';
require("../misc.php");


//require("../headerconfig.php");


// get posted data
$data = json_decode(file_get_contents("php://input"));

// make sure data is not empty
if(
    !empty($data->salesid) &&
    !empty($data->customerid) &&
    !empty($data->customername) &&
    !empty($data->message)
){

    // set notification property values
    $salesid = $data->salesid;
    $customerid = $data->customerid;
    $customername = $data->customername;
    $message = $data->message;
    $linkurl = isset($data->linkurl) ? $data->linkurl : '';
    $linkname = isset($data->linkname) ? $data->linkname : '';
    $messagetype = isset($data->messagetype) ? $data->messagetype : '';
    $orderid = isset($data->orderid) ? $data->orderid : '';
    $orderamount = isset($data->orderamount) ? $data->orderamount : 0;
    $readstatus = 'N';
    $senddate = new DateTime();
    $typedoc = $data->typedoc;
    $shiptoamount = isset($data->shiptoamount) ? $data->shiptoamount : 0;
    $typeorder = isset($data->typeorder) ? $data->typeorder : '';


    
    $message_to_use = getNotificationMessage($typedoc,$orderid,$customername,$senddate,$orderamount,$shiptoamount);
    $url = "https://sales.thaitaiyo.co.th/app-salesproject/my-assignments.html";   // link จากปุ่ม ในไลน์
    


    if($typeorder == "Internal"){

        $tokenlineid = gettokensales($dbcon, $salesid);
        if (!empty($tokenlineid)) {
            
            $line_result = Sendlinenotify($tokenlineid, $message_to_use,$url);
            if ($line_result !== true) {
                error_log("Failed to send Line notification from savenotification.php: " . $line_result);
            }
        }

    }else{
        // create the notification
        $stmt = $dbcon->prepare("INSERT INTO notification (salesid, customerid, customername,orderid, message, linkurl, linkname, messagetype, readstatus, createdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssss", $salesid, $customerid, $customername,$orderid, $message, $linkurl, $linkname, $messagetype, $readstatus);
        
        if($stmt->execute()){
            // First, call gettokensales to get the tokenlineid
            $tokenlineid = gettokensales($dbcon, $salesid);
            
            // You can then use $tokenlineid here, e.g., to send a Line notification
            // For example:
            if (!empty($tokenlineid)) {
                
                $line_result = Sendlinenotify($tokenlineid, $message_to_use,$url);
                if ($line_result !== true) {
                    error_log("Failed to send Line notification from savenotification.php: " . $line_result);
                }
            }

            // set response code - 201 created
            http_response_code(201);

            // tell the user
            echo json_encode(array("message" => "Notification was created."));
        }
        else{

            // set response code - 503 service unavailable
            http_response_code(503);

            // tell the user
            echo json_encode(array("message" => "Unable to create notification."));
        }
    }
}

// tell the user data is incomplete
else{

    // set response code - 400 bad request
    http_response_code(400);

    // tell the user
    echo json_encode(array("message" => "Unable to create notification. Data is incomplete."));
}
?>