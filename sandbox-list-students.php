<?php
//
// ods-query.php
//
//   Accept a secret and key pair and studentID on the command line, then use
//   those to extract StudentSpecialEducationAssociations from the ADVISER ODS.
//
//  10/30/2018 SI
//
// sandbox-list-students.php
//  List student IDs with specialEducationProgramAssocations

// Config
$edfiBaseUrl = "https://sandbox.nebraskacloud.org/1920/api";



// Check arguments
if ($argc == 3)
{
  printf ("\nKey: %s", $argv[1]);
  printf ("\nSecret: %s", $argv[2]);
  // printf ("\nStudent State ID: %s\n\n", $argv[3]);

  $adviserKey = $argv[1];
  $adviserSecret = $argv[2];
  // $studentId = $argv[3];

  // $authCode = getAuthCode($adviserKey);
  $authToken = getAuthToken($adviserKey, $adviserSecret);
}
// else if ($argc == 3)
// {
//   $authToken = $argv[1];
//   $studentId = $argv[2];
// }
// else if ($argc == 2)
// {
//   printf("\nstrtotime: %s -> %d\n\n", $argv[1], strtotime($argv[1]));
//   exit();
// }
else
{
  printf ("\nusage: <key> <secret>\n\n");
  exit();
}

// Get StudentSpecialEducationAssociations
$authorization = "Authorization: Bearer " . $authToken;

$url = $edfiBaseUrl . "/data/v3/ed-fi/students?offset=100&limit=100&totalCount=false";

$curl = curl_init();

curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
curl_setopt($curl, CURLOPT_URL, "$url");

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($curl);
$rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

curl_close($curl);
printf("\nresult code: %s\nJSON result:\n", $rCode);

printf("\nStudents in Sandbox:\n\n");
$arrResult = json_decode($result, true);
foreach ($arrResult as $s)
{
  printf("%s\n", $s["studentUniqueId"]);
}


function getAuthCode($adviserKey)
{
  // Get ODS authorization code
  global $edfiBaseUrl;

  $edfiApiCodeUrl = "$edfiBaseUrl/oauth/authcode";
  $data = "Client_id=$adviserKey&Response_type=code";
  try
  {
      $curl = curl_init();

      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_URL, $edfiApiCodeUrl);
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

      $result = curl_exec($curl);
      printf("\ncurl_error: %s", curl_error($curl));
      $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      printf ("\ncurl response code: %s", $rCode);
      $jsonResult = json_decode($result);
      curl_close($curl);

      $authCode = $jsonResult->code;
      printf ("\nOAuth code: %s", $authCode);
      return ($authCode);
  }
  catch(Exception $e) {
      printf ("\nError getting oAuth code: %s\n", $e->getMessage());
      exit();
  }
}

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
