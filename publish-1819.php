<?php
//
// ods-query.php
//
//   Accept a secret and key pair and studentID on the command line, then use
//   those to extract StudentSpecialEducationAssociations from the ADVISER ODS.
//
//  10/30/2018 SI

// Config
// $edfiBaseUrl = "https://adviserods.nebraskacloud.org/api";
$edfiBaseUrl = "https://sandbox.nebraskacloud.org/1819/api";

// Check arguments
if ($argc == 3)
{
  printf ("\nKey: %s", $argv[1]);
  printf ("\nSecret: %s", $argv[2]);
  // printf ("\nStudent State ID: %s\n\n", $argv[3]);

  $adviserKey = $argv[1];
  $adviserSecret = $argv[2];
  // $studentId = $argv[3];

  $authCode = getAuthCode($adviserKey);
  $authToken = getAuthToken($adviserKey, $adviserSecret, $authCode);
}
else if ($argc == 2)
{
  $authToken = $argv[1];
  // $studentId = $argv[2];
}
// else if ($argc == 2)
// {
//   printf("\nstrtotime: %s -> %d\n\n", $argv[1], strtotime($argv[1]));
//   exit();
// }
else
{
  printf ("\nusage: <key> <secret> \nOR: <auth token> \n\n");
  exit();
}

// Publish a StudentSpecialEducationAssociation
$authorization = "Authorization: Bearer " . $authToken;

$url = $edfiBaseUrl . "/api/v2.0/2019/studentSpecialEducationProgramAssociations";

$curl = curl_init();

$data = '{
  "id": "",
  "educationOrganizationReference": {
    "educationOrganizationId": 550145000,
    "link": {
      "rel": "EducationOrganization",
      "href": "/educationOrganizations?educationOrganizationId=550145000
    }
  },
  "programReference": {
    "educationOrganizationId": 550145000,
    "type": "Special Education",
    "name": "Special Education",
    "link": {
      "rel": "Program",
      "href": "/programs?educationOrganizationId=%eoid%&type=Special+Education&name=Special+Education"
    }
  },
  "studentReference": {
    "studentUniqueId": "7277267427",
    "link": {
      "rel": "Student",
      "href": "/students?studentUniqueId=7277267427"
    }
  },
  "beginDate": "2011-03-10T00:00:00",
  "endDate": "",
  "reasonExitedDescriptor": "",
  "specialEducationSettingDescriptor": "10",
  "levelOfProgramParticipationDescriptor": "05",
  "placementTypeDescriptor": "0",
  "specialEducationPercentage": 5,
  "toTakeAlternateAssessment": 0,
  "services": [
    [ “1”, “T00:00:00”],
    [ “3”, “T00:00:00”],
  ],
  "disabilities": [
    {"disabilityDescriptor": "13"}
  ],
  "serviceProviders": []
}';
$payloadLength = 'Content-Length: ' . strlen($data);

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
printf("\nresult code: %s\nJSON result:\n", $rCode);
var_dump($result);


function getAuthCode($adviserKey)
{
  // Get ODS authorization code
  global $edfiBaseUrl;

  $edfiApiCodeUrl = "$edfiBaseUrl/oauth/authorize";
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

function getAuthToken($adviserKey, $adviserSecret, $authCode)
{
  // Get ODS access token
  global $edfiBaseUrl;

  $edfiApiTokenUrl = "$edfiBaseUrl/oauth/token";
  $paramsToPost = "Client_id=$adviserKey&Client_secret=$adviserSecret&Code=$authCode&Grant_type=authorization_code";

  try
  {
      $curl = curl_init();

      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_URL, "$edfiApiTokenUrl");
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $paramsToPost);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

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
