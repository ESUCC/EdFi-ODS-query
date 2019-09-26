<?php
//
// publish-1920.php
//
//   SRS main ADVISER publishing process for the 2019-2020 ODS. This runs in a
//   continuous loop, evaluating which districts are set to publish ADVISER SPED
//   data through SRS and then processing records needing to be published for
//   each of those districts.
//
//  9/24/2019 SI
//

// Config
$edfiBaseUrl = "https://sandbox.nebraskacloud.org/1920/api";
$dbHost = "172.16.3.38";
$dbPort = 5434;
$dbUser = "psql-primary";
$dbName = "nebraska_srs";

// persistent variables
$logFile = null;

// Object classes
class districtObj
{
  public $id_county;
  public $id_district;
  public $name_district;
  public $key;
  public $secret;
}

// No command-line arguments required -- data comes from the SRS database

date_default_timezone_set('America/Chicago');
printf("\n%s: INFO: publish-1920 starting up\n", date("Y-m-d H:i:s T"));
// Run forever until stopped
while (true)
{
  // Open log file
  $logFile = openPublishLog();

  // Connect to DB
  $dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
    " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
  if (!$dbConn)
  {
    writePublishLog ($logFile, "ERROR: Database connection failed");
  }

  if ($logFile && $dbConn)
  {
    writePublishLog($logFile, "INFO: starting publishing cycle");

    // find districts which are enabled to use SRS ADVISER publishing
    writePublishLog($logFile, "INFO: finding districts to publish");
    $districts = [];
    $districtCount = 0;
    $sql = "SELECT id_county, id_district, name_district, edfi_key, edfi_secret " .
      "FROM iep_district " .
      "WHERE use_edfi ='t' AND " .
      "status = 'Active' " .
      "ORDER BY id_county, id_district";

    $dbResult = pg_query($dbConn, $sql);

    if ($dbResult)
    {
      while ($dbRow = pg_fetch_row($dbResult))
      {
        $d = new districtObj();
        $d->id_county = $dbRow[0];
        $d->id_district = $dbRow[1];
        $d->name_district = $dbRow[2];
        $d->key = $dbRow[3];
        $d->secret = $dbRow[4];
        $districts[] = $d;
        $districtCount++;
      }
    }
    else
    {
      writePublishLog($logFile, sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
    }

    writePublishLog($logFile, sprintf("INFO: %d ADVISER districts found", $districtCount));

    // find records to publish for each district
    foreach ($districts as $d)
    {
      
    }
  }

  // breathe before starting the cycle over again
  writePublishLog($logFile, "INFO: finished publishing cycle");
  closePublishLog($logFile);
  sleep(60);
}

function closePublishLog($logFile)
{
  writePublishLog($logFile, "INFO: closing log file");
  fclose($logFile);
}

function openPublishLog()
{
  $logFile = fopen("publish-1920.log", "a");
  if (!$logFile)
  {
    printf("\n%s: ERROR: Failed to open log file\n", date("Y-m-d H:i:s T"));
    return (null);
  }
  writePublishLog($logFile, "INFO: Log file opened");
  return ($logFile);
}

function writePublishLog($logFile, string $logEntry)
{
  printf("\n%s: %s", date("Y-m-d H:i:s T"), $logEntry);
  if (!fwrite($logFile, sprintf("\n%s: %s", date("Y-m-d H:i:s T"), $logEntry)))
  {
    printf("\n%s: ERROR: Failed writing to log file\n", date("Y-m-d H:i:s T"));
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

function getGradeDescriptor ($grade)
{
  // build GradeLevelDescriptor from our grade string
  // translate special cases
  if (preg_match('/^[1-9]$/', $grade))
  {
    return "uri://education.ne.gov/GradeLevelDescriptor#0" . $grade;
  }
  if ($grade == '12+')
  {
    return "uri://education.ne.gov/GradeLevelDescriptor#12";
  }
  if ($grade == 'K')
  {
    return "uri://education.ne.gov/GradeLevelDescriptor#KG";
  }
  if ($grade == 'ECSE' || $grade == 'EI 0-2')
  {
    return "uri://education.ne.gov/GradeLevelDescriptor#HP";
  }
  // all others just
  return "uri://education.ne.gov/GradeLevelDescriptor#" . $grade;
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
