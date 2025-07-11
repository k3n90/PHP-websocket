<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/dbconfig.php';

use Workerman\Worker;

$avaibleActions = ["auth", "sendMessage", "getAllChat", "ping"];
$connectedIDs = [];

$url = 'sample.org';
$port = 443;
$context = [
  'ssl' => [
    'local_cert' => 'PATH_TO_CERT',
    'local_pk' => 'PATH_TO_KEY',
    'verify_peer' => false,
  ]
];

// Create a Websocket server
$worker = new Worker('websocket://'.$url.':'.$port, $context);
$worker->transport = 'ssl';

$worker->onWorkerStart = function (Worker $worker) {
  global $db;
  $db = new \Workerman\MySQL\Connection($DB_HOST, '', $DB_USER, $DB_PASSWORD, $DB_NAME);
};

// Emitted when new connection come
$worker->onConnect = function ($connection) {
  $connection->closedByServer = false;
};

// Emitted when data received
$worker->onMessage = function ($connection, $data) use ($worker) {
  global $connectedIDs;
  $dataArray = json_decode($data);
  if (isset($dataArray->action)) {
    global $avaibleActions;
    $action = $dataArray->action;
    if (in_array($action, $avaibleActions)) {
      if ($action == $avaibleActions[0]) { // "auth"
        if (!isset($dataArray->login) || !isset($dataArray->hash) || !isset($dataArray->userType) || !isset($dataArray->dbName) || !isset($dataArray->chatID)) {
          $response_text = json_encode(array('error' => 1, 'message' => 'bad login'));
          $connection->closedByServer = true;
          $connection->close($response_text);
        } else {
          $userID = md5($dataArray->login . $dataArray->hash . $dataArray->dbName);
          if (array_key_exists($userID, $connectedIDs)) {
            $response_text = json_encode(array('error' => 2, 'message' => 'your session has been closed due to login on another device'));
            $connection->closedByServer = true;
            $connectedIDs[$userID]->close($response_text);
            unset($connectedIDs[$userID]);
          }
          if ($dataArray->userType == "user") {
            global $db;
            $query_result = $db->row(sprintf("SELECT `fls_fio` as `name` FROM `fls_all` WHERE `fls_id` = '%s' AND `fls_hash` = '%s'", $dataArray->login, $dataArray->hash));
          } elseif ($dataArray->userType == "worker") {
            $DB_ORGNAME = $dataArray->dbName;
            $dbWorker = new \Workerman\MySQL\Connection($DB_HOST, '', $DB_USER, $DB_PASSWORD, $DB_ORGNAME);
            $query_result = $dbWorker->row(sprintf("SELECT `name` FROM `usersmp` WHERE `login` = '%s' AND `pass` = '%s'", $dataArray->login, $dataArray->hash));
          }
          if ($query_result) {
            $num_rows = count($query_result);
          } else {
            $num_rows = false;
          }
          if (!$num_rows) {
            $response_text = json_encode(array('error' => 3, 'message' => 'missing user into db'));
            $connection->closedByServer = true;
            $connection->close($response_text);
          } else {
            if ($num_rows == 1) {
              $displayName = $query_result["name"];
              $connection->userID = $userID;
              $connection->userDBname = $dataArray->dbName;
              $connection->displayName = $displayName;
              $connection->userType = $dataArray->userType;
              $connection->chatID = $dataArray->chatID;
              $connectedIDs[$userID] = $connection;

              $response_text = json_encode(array('authSuccess' => 1, 'message' => 'correct auth'));
              $connection->send($response_text);
            } else {
              $response_text = json_encode(array('error' => 4, 'message' => 'too much matches login into db'));
              $connection->closedByServer = true;
              $connection->close($response_text);
            }
          }
        }
      } elseif ($action == $avaibleActions[3]) { // "ping"
        $response_text = json_encode(array('conection' => 'ok'));
        $connection->send($response_text);
      } elseif (isset($connection->userID)) {
        if ($action == $avaibleActions[1]) { // "sendMessage"
          if (isset($dataArray->login) && isset($dataArray->message) && isset($dataArray->chatID) && isset($dataArray->dbName)) {
            $user_time = strval(time());
            $displayName = $connection->displayName;
            $DB_ORGNAME = $dataArray->dbName;
            $dbWorker = new \Workerman\MySQL\Connection($DB_HOST, '', $DB_USER, $DB_PASSWORD, $DB_ORGNAME);

            $insert_id = $dbWorker->insert('requests_answers')->cols(array(
              'uid' => $dataArray->login,
              'toWho' => $dataArray->chatID,
              'content' => $dataArray->message,
              'time' => $user_time
            ))->query();

            foreach ($worker->connections as $clientConnection) {
              if (($clientConnection->chatID == $dataArray->chatID) && ($clientConnection->userDBname == $dataArray->dbName)) {
                $user_name = $displayName;
                $user_message = $dataArray->message;
                $response_text = json_encode(array('getMessage' => array('content' => $user_message, 'id' => $insert_id, 'time' => $user_time, 'toWho' => $dataArray->chatID, 'uid' => $dataArray->login, 'name' => $user_name)));
                $clientConnection->send($response_text);
              }
            }
          } else {
            $response_text = json_encode(array('error' => 5, 'message' => 'some params is missing'));
            $connection->closedByServer = true;
            $connection->close($response_text);
          }
        } elseif ($action == $avaibleActions[2]) { // "getAllChat"
          if (isset($dataArray->login) && isset($dataArray->chatID) && isset($dataArray->dbName)) {
            $DB_ORGNAME = $dataArray->dbName;
            $dbWorker = new \Workerman\MySQL\Connection($DB_HOST, '', $DB_USER, $DB_PASSWORD, $DB_ORGNAME);
            
            $getAllChat = $dbWorker->query("SELECT * FROM `requests_answers`
              WHERE `toWho` = '" . $dataArray->chatID . "'");
            $allChat = [];
            foreach ($getAllChat as $message) {
              $login = $message["uid"];
              if ((preg_match('/^\d{10}$/', $login))) {
                $username = $dbWorker->single("SELECT `fls_fio` FROM `fls`
                  WHERE `fls_id` = '" . $login . "'");
              } else {
                $username = $dbWorker->single("SELECT `name` FROM `usersmp`
                  WHERE `login` = '" . $login . "'");
              }
              $message['name'] = $username;
              array_push($allChat, $message);
            }

            // get files
            $j = 0;
            $files = [];
            $request_files = $dbWorker->query("SELECT * FROM `requests_files`
              WHERE `rf_req_id` = '" . $dataArray->chatID . "'");

            foreach ($request_files as $file) {
              $files[$j]["link"] = 'https://' . $url . '/uploads/' . $DB_ORGNAME . '/requests/' . $dataArray->chatID . '/' . $file["rf_filelink"];
              $files[$j]["name"] = $file["rf_filename"];
              $j++;
            }

            $response_text = json_encode(array('allChat' => $allChat, 'files' => $files));
            $connection->send($response_text);
          } else {
            $response_text = json_encode(array('error' => 6, 'message' => 'some params is missing'));
            $connection->close($response_text);
          }
        }
      } else {
        $response_text = json_encode(array('error' => 7, 'message' => 'not auth'));
        $connection->closedByServer = true;
        $connection->close($response_text);
      }
    } else {
      $response_text = json_encode(array('error' => 8, 'message' => 'unknown action'));
      $connection->closedByServer = true;
      $connection->close($response_text);
    }
  } else {
    $response_text = json_encode(array('error' => 9, 'message' => 'action is missing'));
    $connection->closedByServer = true;
    $connection->close($response_text);
  }
};

// Emitted when connection closed
$worker->onClose = function ($connection) {
  // Проверяем, разрыв соединения по инициативе клиента/сервера
  if (isset($connection->closedByServer) && $connection->closedByServer) {
    // echo "Connection was closed by the server.\n";
  } else {
    global $connectedIDs;
    if (array_key_exists($connection->userID, $connectedIDs)) {
      unset($connectedIDs[$connection->userID]);
    }
    // echo "Connection was closed by the client.\n";
  }
  // echo "Close connection\n";
};

// Run worker
Worker::runAll();