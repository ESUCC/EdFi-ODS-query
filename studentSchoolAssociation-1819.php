<?php
//
// studentSchoolAssociation-1819.php
//
//   Accept a secret and key pair and school CDN on the command line, then use
//   those to publish studentSchoolAssociations to the ADVISER ODS. This is for
//   sandbox testing only.  Normally the SIS publishes these associations.
//
//  10/22/2019 SI
//

// Config
$edfiBaseUrl = "https://sandbox.nebraskacloud.org/1819/api";
$dbHost = "172.16.3.36";
$dbPort = 5434;
$dbUser = "psql-primary";
$dbName = "nebraska_srs";

// Check arguments
if ($argc == 4)
{
  printf ("\nKey: %s", $argv[1]);
  printf ("\nSecret: %s", $argv[2]);
  printf ("\nSchool CDN: %s", $argv[3]);

  $adviserKey = $argv[1];
  $adviserSecret = $argv[2];
  $schoolId = $argv[3];

}
else
{
  printf ("\nusage: <key> <secret> <school CDN w/no dashes>\n\n");
  exit();
}

// find active students for the specified school
if (strlen($schoolId) == 9)
{
  $idCounty = substr($schoolId,0,2);
  $idDistrict = substr($schoolId,2,4);
  $idSchool = substr($schoolId,6,3);
}
else if (strlen($schoolId) == 8)
{
  $idCounty = "0" . substr($schoolId,0,1);
  $idDistrict = substr($schoolId,1,4);
  $idSchool = substr($schoolId,5,3);
}
else
{
  printf("\nSchool CDN must be 8 or 9 digits\n");
  exit();
}

printf("\nCounty: %s", $idCounty);
printf("\nDistrict: %s", $idDistrict);
printf("\nSchool: %s", $idSchool);

// Connect to DB
$dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
  " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
if (!$dbConn)
{
  printf("\nDatabase connection failed\n\n");
  exit();
}

// Select students
$sql = "SELECT unique_id_state, grade " .
  "FROM iep_student " .
  "WHERE id_county ='" . $idCounty . "' AND " .
  "id_district = '" . $idDistrict . "' AND " .
  "id_school = '" . $idSchool . "' AND " .
  "status = 'Active' ";

printf("\nSQL: %s", $sql);
$dbResult = pg_query($dbConn, $sql);

if (!$dbResult)
{
  printf ("\nA database error occurred: %s\n\n", pg_last_error($dbConn));
  exit();
}

// setup to add studentSchoolAssociations
printf("\nActive students found...\nAdding studentSchoolAssociations...");

$authCode = getAuthCode($adviserKey);
$authToken = getAuthToken($adviserKey, $adviserSecret, $authCode);
$authorization = "Authorization: Bearer " . $authToken;
$url = $edfiBaseUrl . "/api/v2.0/2019/studentSchoolAssociations";
$curl = curl_init();
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_URL, "$url");
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$intSchoolId = schoolIdToInt($schoolId);
$errorCount = 0;
$successCount = 0;

while ($dbRow = pg_fetch_row($dbResult))
{
  // add a studentSchoolAssociation for each student found
  printf("\nState ID: %d, Grade: %s", $dbRow[0], $dbRow[1]);
  $data = '{
  "studentReference": {
    "studentUniqueId": "' . $dbRow[0] .'",
  },
  "schoolReference": {
    "schoolId": ' . $intSchoolId . ',
  },
  "entryDate": "2019-07-01",
  "entryGradeLevelDescriptor": "' . getGradeDescriptor($dbRow[1]) . '",
  "districtOfResidence": "00-0000",
  "fullTimeEquivalency": 100
}';
printf("data: %s", $data);
  $payloadLength = 'Content-Length: ' . strlen($data);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' ,
    $authorization, $payloadLength ));
  curl_setopt($curl, CURLOPT_URL, "$url");
  curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

  $result = curl_exec($curl);
  $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
  printf("\nODS returned: %s", $rCode);
  if ($rCode == 201 || $rCode == 200)
  {
    $successCount++;
  }
  else
  {
    $errorCount++;
    printf("\nresult: %s\n", $result);
  }
  exit();
}

curl_close($curl);
printf("\n\n%d students processed: %d successful, %d errors.\n\n",
  $successCount + $errorCount, $successCount, $errorCount);
exit();

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
      // printf("\ncurl_error: %s", curl_error($curl));
      $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      // printf ("\ncurl response code: %s", $rCode);
      $jsonResult = json_decode($result);
      curl_close($curl);

      $authCode = $jsonResult->code;
      // printf ("\nOAuth code: %s", $authCode);
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
      // printf ("\nresult code: %s", $rCode);
      // printf ("\nAccess token: %s\n", $authToken);
      return ($authToken);

  }
  catch(Exception $e) {
      printf ("\nError getting auth token: %s", $e->getMessage());
      exit();
  }
}

function getGradeDescriptor ($grade)
{
  // build GradeLevelDescriptor from our grade string
  // translate special cases
  if (preg_match('/^[1-9]$/', $grade))
  {
    return "0" . $grade;
  }
  if ($grade == '12+')
  {
    return "12";
  }
  if ($grade == 'K')
  {
    return "KG";
  }
  if ($grade == 'ECSE' || $grade == 'EI 0-2')
  {
    return "HP";
  }
  // all others just
  return $grade;
}

function schoolIdToInt ($stringSchoolId)
{
  if (strlen($stringSchoolId) == 9 && substr($stringSchoolId,0,1) == '0')
  {
    return (substr($stringSchoolId,1,8));
  }
  else
  {
    return ($stringSchoolId);
  }
}
