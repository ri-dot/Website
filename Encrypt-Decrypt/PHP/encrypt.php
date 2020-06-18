<?php

function encrypt($textToEncrypt, $base64Key) {
    $key = base64_decode($base64Key);
    $method = 'aes-256-gcm';
    $iv = openssl_random_pseudo_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt($textToEncrypt, $method, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv.$ciphertext.$tag);
}

function fail($reason, $status = 400) {
    http_response_code($status);
    echo json_encode(array('status' => 'error', 'reason' => $reason));
    exit;
}

function success($data, $status = 200) {
    http_response_code($status);
    echo json_encode(array('status' => 'ok', 'data' => $data));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'POST') {
    header('Content-Type: application/json');

    $requestData = json_decode(file_get_contents('php://input'), TRUE);
    if($requestData === NULL) {
        fail('Invalid json data');
    }

    try {
        success(encrypt($requestData['folder'], 'ia5F8X87kETlE8Be7VD4KtmTwuKdNZsuKLfLTizNkd4='));
    }
    catch(Exception $e) {
        fail($e.getMessage());
    }
}

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
  <title>Google Drive - encrypted folder copy</title>
</head>
<body>
  <div class="container"><div class="row"><div class="col">
    <div class="card my-4">
      <div class="card-body">
        <h3>Encrypt link</h3>
        <div class="form-group form-row align-items-center">
          <div class="col">
            <input id="folder_link" type="text" class="form-control" placeholder="Google Drive folder link">
          </div>
          <div class="col-3 col-md-2">
            <button id="encrypt" class="btn btn-primary btn-block">Encrypt</button>
          </div>
        </div>
        <input id="encrypted_link" type="text" class="form-control" placeholder="encrypted ID">
        <p class="mt-3">You can share the decryption website and encrypted ID separately or combine them together with <code>#</code> like this: <code>https://example.com/#ABCD.1234</code></p>
      </div>
      
    </div>
  </div></div></div>

  <!-- jQuery first, then Popper.js, then Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js" integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>
  <script type="text/javascript">

async function encryptId(folderId) {
  const result = await fetch("encrypt", {method: "POST", body: JSON.stringify({folder: folderId})});
  const data = await result.json();
  if(result.ok) {
    return data.data;
  }
  else {
    throw Error(data.reason);
  }
}

async function encryptFolder() {
  const link = document.querySelector("#folder_link").value;
  const m = link.match(/^https:\/\/drive\.google\.com\/(?:folderview\?id=|open\?id=|drive\/(?:u\/\d+\/)?folders\/|file\/(?:u\/\d+\/)?d\/)([0-9a-zA-Z\-_]+)/);
  if(m === null) {
    throw Error("bad link!");
  }

  return encryptId(m[1]);
}

document.querySelector("#encrypt").addEventListener("click", async function(event) {
  event.preventDefault();

  document.querySelector("#folder_link").setAttribute("disabled", "");
  document.querySelector("#encrypt").setAttribute("disabled", "");
  try {
    document.querySelector("#encrypted_link").value = "enltZS5nYS9FbmNyeXB0LURlY3J5cHQvaW5kZXguaHRtbDtnOnJpLWRvdC8zOTdmOWY2MzRmZmQ2MGE5NWViNjU5YzM1YWI4YTAwZTxUZXN0." + (await encryptFolder());
  }
  catch(e) {
    alert(e.message);
  }
  finally {
    document.querySelector("#folder_link").removeAttribute("disabled");
    document.querySelector("#encrypt").removeAttribute("disabled");
  }
});

  </script>
</body>
</html>

