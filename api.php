<?php
//print_r($_REQUEST);
//print_r($_SERVER);
//exit;
$google_api_key = 'AIzaSyAdpOP3a2eYOJCMzSrDBJp6gWq45ZMBpuY';
function getGeoPosition($origin,$destination){
    $response = array('status'=>0,'data'=>array());
    global $google_api_key;
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?units=imperial&origins=".$origin."&destinations=".$destination."&key=".$google_api_key;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPGET,true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $curl_result = curl_exec($ch);
    
    if (curl_error($ch)) {
        $response['status'] = 0;
        //return curl_error($ch);
        //exit;
    }
    //print_r($response);
    $route_response_data = json_decode($curl_result,true);

    if($route_response_data['status']=="OK"){
        $response['status'] = 1;
        $response['data'] = $route_response_data;
    }
    
    // If there is some error. return error message
    if($route_response_data && isset($route_response_data['error_message'])){
        $response['data'] = $route_response_data['error_message'];
    }
    return $response;
}



// connect to the mysql database
$host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'order_api';

$mysqli = new mysqli($host, $db_user, $db_password, $db_name);

/* check connection */
if (mysqli_connect_errno()) {
    printf("Connect failed: %s\n", mysqli_connect_error());
    exit();
}

function place_order($origin,$destination){
    global $mysqli;
    $response = array('http_code'=>500,'data'=>array());
    
    // if origin and destination is not array
    if(!is_array($origin) && !is_array($destination)){
        $response['data'] = array('error'=>'Invalid arguments provided');
        return $response;
    }
    
    $origin_lat_long = implode(',',$origin);
    $destination_lat_long = implode(',',$destination);
    $route_response = getGeoPosition($origin_lat_long,$destination_lat_long);
    
    //print_r($route_response);exit;
    
    // If it failed to get response from google api
    if(!$route_response['status']){
        $response['data']['error'] = $route_response['data'] ? $route_response['data'] : 'Unable to get response from Google route api. Please check your request';
        return $response;
    }
    
    $distance = $route_response['data']['rows'][0]['elements'][0]['distance']['value'];
    
    // Add order in database
    $origin_lat_long = $mysqli->real_escape_string($origin_lat_long);
    $destination_lat_long = $mysqli->real_escape_string($destination_lat_long);
    $order_status = 'UNASSIGN';
    
    $query = "INSERT INTO orders (origin, destination,distance,status) VALUES ('$origin_lat_long' , '$destination_lat_long', $distance , '$order_status')";
    $res = $mysqli->query($query);
    
    // if qery failed to insert
    if(!$res){
        $response['data']['error'] = 'Failed to insert record in DB';
        return $response;
    }
    
    $response['http_code'] = 200;
    $response['data']['order_id'] = $mysqli->insert_id;
    $response['data']['distance'] = $distance;
    $response['data']['status'] = $order_status;
    
    return $response;
}

function take_order($order_id,$order_status){
    global $mysqli;
    $response = array('http_code'=>500,'data'=>array());
    
    // if origin and destination is not array
    if(!$order_id){
        $response['data'] = array('error'=>'Invalid arguments provided');
        return $response;
    }
    
    if($order_status != 'taken'){
        $response['data']['error'] = 'Invalid order status provided. Only status "taken" is accepted';
        return $response;
    }
    
    $query = "SELECT status FROM orders WHERE id = $order_id";
    $res = $mysqli->query($query);
    $row= mysqli_fetch_assoc($res);
    if(!$row){
        $response['data']['error'] = 'Invalid order id provided';
        return $response;
    }else if($row['status'] == 'TAKEN'){
        $response['http_code'] = 409;
        $response['data']['error'] = 'ORDER_ALREADY_BEEN_TAKEN';
        return $response;
    }
    
    $query = "UPDATE orders SET status = 'TAKEN' WHERE id = $order_id";
    $res = $mysqli->query($query);
    
    // if qery failed to insert
    if(!$res){
        $response['data']['error'] = 'Failed to update order';
        return $response;
    }
    $response['http_code'] = 200;
    $response['data']['status'] = 'SUCCESS';
    return $response;
}

function get_order_list($page,$limit){
    global $mysqli;
    $response = array('http_code'=>500,'data'=>array());
    
    $query = "SELECT id,distance,status FROM orders LIMIT $limit OFFSET ".$page * $limit;
    
    $result = $mysqli->query($query);
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $response['data'][] = $row;
        }
    }
    
    return $response;
}

function return_api_response($api_response){
    header('Content-Type: application/json');
    http_response_code($api_response['http_code']);
    echo json_encode($api_response['data']);
}

/*
 *  Request router
 */
$request = $_REQUEST['request'];
$method = $_SERVER['REQUEST_METHOD'];
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : ''; //  should be application/json

$api_response = array('http_code'=>500,'data'=>array('error'=>'Unknown request'));
$request_body = json_decode(file_get_contents('php://input'), true);

// if its /order request
if($request == 'order' && $method == 'POST'){
    $origin = $request_body['origin'];
    $destination = $request_body['destination'];
    $api_response = place_order($origin,$destination);
}else if(preg_match('/order\/(\d+)/', $request,$matches) && $method == 'PUT'){ // Its order update request
    $order_id = intval($matches[1]);
    $order_status = $request_body['status'];
    $api_response = take_order($order_id,$order_status);
}else if($request == 'orders' && $method == 'GET'){ // Its get order list request
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 0;
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 0;
    $api_response = get_order_list($page,$limit);
}

return_api_response($api_response);

