<?php
//
// calendar-1920.php
//
//   Accept a secret and key pair and school CDN on the command line, then use
//   those to publish a calendar to the ADVISER ODS.  In testing, calendars
//   don't seem to be required for our purposes, so this isn't needed.
//
//  9/23/2019 SI
//

// Config
$edfiBaseUrl = "https://sandbox.nebraskacloud.org/1920/api";

// Check arguments
if ($argc == 4)
{
  printf ("\nKey: %s", $argv[1]);
  printf ("\nSecret: %s", $argv[2]);
  printf ("\nSchool CDN: %s", $argv[3]);

  $adviserKey = $argv[1];
  $adviserSecret = $argv[2];
  $schoolId = $argv[3];

  $authToken = getAuthToken($adviserKey, $adviserSecret);
}
else
{
  printf ("\nusage: <key> <secret> <school CDN w/no dashes>\n\n");
  exit();
}

// build and publish calendar
$authorization = "Authorization: Bearer " . $authToken;

$url = $edfiBaseUrl . "/data/v3/ed-fi/calendars";

$data = '{
  "id": "string",
  "calendarCode": "1920",
  "schoolReference": {
    "schoolId": ' . $schoolId . ',
  },
  "schoolYearTypeReference": {
    "schoolYear": 2020,
  },
  "calendarTypeDescriptor": "uri://ed-fi.org/CalendarTypeDescriptor#IEP"
}';
$payloadLength = 'Content-Length: ' . strlen($data);

$curl = curl_init();
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' ,
  $authorization, $payloadLength ));
curl_setopt($curl, CURLOPT_URL, "$url");
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

$result = curl_exec($curl);
$rCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);
printf("\nresult code: %s\nresult:\n", $rCode);
var_dump($result);

function getAuthToken($adviserKey, $adviserSecret)
{
  // Get ODS access token
  global $edfiBaseUrl;

  $edfiApiTokenUrl = "$edfiBaseUrl/oauth/token";
  $paramsToPost = "grant_type=client_credentials";

  try
  {
      $curl = curl_init();

      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_URL, "$edfiApiTokenUrl");
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $paramsToPost);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_USERPWD, $adviserKey . ":" . $adviserSecret);

      $result = curl_exec($curl);
      $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      $jsonResult = json_decode($result);
      curl_close($curl);
      $authToken=$jsonResult->access_token;
      printf ("\nresult code: %s", $rCode);
      printf ("\nAccess token: %s\n", $authToken);
      return ($authToken);

  }
  catch(Exception $e) {
      printf ("\nError getting auth token: %s", $e->getMessage());
      exit();
  }
}
