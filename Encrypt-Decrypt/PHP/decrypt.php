<?php

function encrypt($textToEncrypt, $base64Key) {
    $key = base64_decode($base64Key);
    $method = 'aes-256-gcm';
    $iv = openssl_random_pseudo_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt($textToEncrypt, $method, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv.$ciphertext.$tag);
}

function decrypt($base64IvCiphertext, $base64Key) {
    $ivCiphertext = base64_decode($base64IvCiphertext);
    $key = base64_decode($base64Key);
    $method = 'aes-256-gcm';
    $iv = substr($ivCiphertext, 0, 12);
    $tag = substr($ivCiphertext, -16);
    $ciphertext = substr($ivCiphertext, 12, -16);

    $decrypted = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if($decrypted === FALSE) {
        throw new Exception('Can\'t decrypt, bad data.');
    }

    return $decrypted;
}

$KEY = 'ia5F8X87kETlE8Be7VD4KtmTwuKdNZsuKLfLTizNkd4=';

function encode($text) {
    global $KEY;
    return encrypt($text, $KEY);
}

function decode($encoded) {
    global $KEY;
    return decrypt($encoded, $KEY);
}

/* END OF DECRYPT FUNCTIONS */

$VERSION = 4;

function fail($reason, $status = 400) {
    global $VERSION;
    http_response_code($status);
    echo json_encode(array('status' => 'error', 'reason' => $reason, 'version' => $VERSION), JSON_UNESCAPED_SLASHES);
    exit;
}

function success($data, $status = 200) {
    global $VERSION;
    http_response_code($status);
    echo json_encode(array('status' => 'ok', 'data' => $data, 'version' => $VERSION), JSON_UNESCAPED_SLASHES);
    exit;
}

function apiRequest($auth, $path, $options=array()) {
    $headersDict = isset($options['headers']) ? $options['headers'] : array();
    if(!isset($headersDict['Authorization'])) {
        $headersDict['Authorization'] = 'Bearer ' . $auth;
    }
    if(!isset($headersDict['Accept'])) {
        $headersDict['Accept'] = 'application/json';
    }
    $headers = array();
    foreach($headersDict as $headerName => $headerValue) {
        array_push($headers, $headerName . ': ' . $headerValue);
    }

    $ch = curl_init('https://www.googleapis.com/drive/v3/' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if(isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }

    $text = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($text, TRUE);
    if($responseData === NULL) {
        throw new Exception($text);
    }

    if(isset($responseData['error'])) {
        throw new Exception($responseData['error']['message']);
    }

    return $responseData;
}

$info = function($data) {
    $folderId = decode($data['folder']);

    $folderInfo = apiRequest(
        $data['auth'],
        'files/' . $folderId . '?supportsAllDrives=true&fields=name,mimeType,shortcutDetails/*'
    );

    // if it's a folder, grab the contents
    if($folderInfo['mimeType'] === 'application/vnd.google-apps.folder') {
        $folderContents = apiRequest(
            $data['auth'],
            'files?q="' . $folderId . '"+in+parents'
            . '&fields=nextPageToken,files(id,size,name,mimeType,md5Checksum,shortcutDetails/*)'
            . '&orderBy=name_natural&supportsAllDrives=true&includeItemsFromAllDrives=true&pageSize=100'
            . (isset($data['pageToken']) ? '&pageToken=' . $data['pageToken'] : '')
        );
    }
    // if it's shortcut/file, set notLoaded to true and grab the info later
    elseif($folderInfo['mimeType'] === 'application/vnd.google-apps.shortcut') {
        $folderContents = array(
            'files' => array(array(
                'notLoaded' => TRUE,
                'id' => $folderInfo['shortcutDetails']['targetId'],
                'mimeType' => $folderInfo['shortcutDetails']['targetMimeType'],
                'name' => $folderInfo['name']
            ))
        );
        unset($folderInfo['shortcutDetails']);
    }
    else {
        $folderContents = array(
            'files' => array(array(
                'notLoaded' => TRUE,
                'id' => $folderId,
                'mimeType' => $folderInfo['mimeType'],
                'name' => $folderInfo['name']
            ))
        );
        unset($folderInfo['shortcutDetails']);
    }
    unset($folderInfo['mimeType']);

    $files = array();
    foreach($folderContents['files'] as $file) {
        if($file['mimeType'] === 'application/vnd.google-apps.shortcut') {
            $file['notLoaded'] = TRUE;
            $file['id'] = $file['shortcutDetails']['targetId'];
            $file['mimeType'] = $file['shortcutDetails']['targetMimeType'];
        }

        if(isset($file['notLoaded']) && $file['notLoaded'] === TRUE) {
            // ignore shortcuts to folders
            if($file['mimeType'] === 'application/vnd.google-apps.folder') {
                continue;
            }
            $fileInfo = apiRequest(
                $data['auth'],
                'files/' . $file['id'] . '?supportsAllDrives=true&fields=size,md5Checksum'
            );
            $fileInfo['id'] = $file['id'];
            $fileInfo['mimeType'] = $file['mimeType'];
            $fileInfo['name'] = $file['name'];
        }
        else {
            $fileInfo = $file;
        }

        $fileInfo['id'] = encode($fileInfo['id']);
        array_push($files, $fileInfo);
    }

    $folderContents['files'] = $files;

    return array_merge($folderContents, $folderInfo);
};

function cloneOne($auth, $fileId, $folder) {
    return apiRequest(
        $auth,
        'files/' . $fileId . '/copy?supportsAllDrives=true',
        array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'parents' => array($folder),
                'appProperties' => array(
                    'createdWithDdEfc' => 1
                )
            ), JSON_UNESCAPED_SLASHES)
        )
    );
}

$clone = function($data) {
    $result = array();
    foreach($data['files'] as $id) {
        $realId = decode($id);
        $cloneResult = cloneOne($data['auth'], $realId, $data['destination']);
        array_push($result, array(
            'id' => $id,
            'data' => $cloneResult
        ));
    }

    return $result;
};

$method = $_SERVER['REQUEST_METHOD'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if($method === 'OPTIONS') {
    exit;
}

header('Content-Type: application/json');

if($method !== 'POST') {
    fail('Method not allowed', 405);
}

$page = isset($_GET['page']) ? $_GET['page'] : '';

if($page === 'info') {
    $parser = $info;
}
elseif($page === 'clone') {
    $parser = $clone;
}
else {
    fail('Page not found', 404);
}

$requestData = json_decode(file_get_contents('php://input'), TRUE);
if($requestData === NULL) {
    fail('Invalid json data');
}

try {
    success($parser($requestData));
}
catch(Exception $e) {
    fail($e->getMessage());
}

?>
