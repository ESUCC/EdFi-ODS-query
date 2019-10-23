<?php
//
// pull-1819.php
//
//  This script reads ADVISER records from the 18-19 ODS and inserts them into
//    the edfi1 table in the SRS database.
//
//  10/21/2019 SI

// config
$dbHost = "172.16.3.36";
$dbPort = 5434;
$dbUser = "psql-primary";
$dbName = "nebraska_srs";
$edfiBaseUrl = "https://adviserods.nebraskacloud.org/api";

// persistent variables
$dbConn = null;

// Object classes
class districtObj
{
  public $id_county;
  public $id_district;
  public $name_district;
  public $key;
  public $secret;
}

class edfi2Obj
{
  public $id_edfi2_entry;
  public $EducationOrganizationId;
  public $StudentUniqueId;
  public $EdfiPublishTime;
  public $EdfiPublishStatus;
  public $EdfiErrorMessage;
  public $idForm002;
  public $idForm023;
  public $idForm004;
  public $idForm013;
  public $nameFirst;
  public $nameLast;
  public $ToTakeAlternateAssessment;
  public $BeginDate;
  public $Disability;
  public $EndDate;
  public $InitialSpecialEducationEntryDate;
  public $PlacementType;
  public $ReasonExited;
  public $SchoolHoursPerWeek;
  public $SpecialEducationProgramsService;
  public $SpecialEducationHoursPerWeek;
  public $SpecialEducationProgram;
  public $SpecialEducationSetting;
  public $dateOfBirth;
  public $age;
  public $idForm022;
}

class studentObj
{
  public $srsId;
  public $nameFirst;
  public $nameLast;
}

// command-line arguments used for county and district
if ($argc != 3)
{
  printf("\nusage: php ./pull-1819.php <county ID> <district ID>\n");
  exit();
}



date_default_timezone_set('America/Chicago');
printf("\n%s: INFO: pull-1819 starting up\n", date("Y-m-d H:i:s T"));

// Connect to DB
$dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
  " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
if (!$dbConn)
{
  printf("ERROR: Database connection failed\n");
  exit();
}

// list all districts using ADVISER publishing
$districts = [];
$districtCount = 0;
$sql = "SELECT id_county, id_district, name_district, edfi_key, edfi_secret " .
  "FROM iep_district " .
  "WHERE use_edfi ='t' AND " .
  "status = 'Active' AND id_county='$argv[1]' AND id_district='$argv[2]' " .
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
  printf ("ERROR: A database error occurred: %s\n", pg_last_error($dbConn));
}

printf("\nINFO: %d ADVISER district(s) found\n", $districtCount);

foreach ($districts as $d)
{
  if (empty($d->key) || empty($d->secret))
  {
    printf ("ERROR: missing secret/key for %s (%s-%s)\n",
      $d->name_district, $d->id_county, $d->id_district);
  }
  else
  {
    printf ("INFO: pulling records for %s (%s-%s)\n",
      $d->name_district, $d->id_county, $d->id_district);
    $adviserKey = $d->key;
    $adviserSecret = $d->secret;

    // read all SSPA records from the 18-19 ODS
    $authCode = getAuthCode($adviserKey);
    $authToken = getAuthToken($adviserKey, $adviserSecret, $authCode);
    // Get StudentSpecialEducationAssociations
    $authorization = "Authorization: Bearer " . $authToken;


    $moreRecords = true;
    $offset = 0;
    while ($moreRecords)
    {
      $url = "$edfiBaseUrl/api/v2.0/2019/studentSpecialEducationProgramAssociations?offset=$offset&limit=25";
      $curl = curl_init();

      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
      curl_setopt($curl, CURLOPT_URL, "$url");

      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

      $result = curl_exec($curl);
      $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

      curl_close($curl);
      // printf("\nresult code: %s\nJSON result:\n", $rCode);
      // var_dump($result);
      $arrResult = json_decode($result, true);
      $sspaCount = 0;
      foreach ($arrResult as $s)
      {
        $sspaCount++;
        insertEdfi1Record($s, $d);
      }
      if ($sspaCount < 25)
      {
        $moreRecords = false;
      }
      $offset += 25;
    }
    printf("inserted %d records for %s (%s-%s)\n", $sspaCount + $offset - 25,
      $d->name_district, $d->id_county, $d->id_district);
  }
}

function dateOrNull($strDate)
{
  $d1 = substr($strDate, 0, 10);
  if (strlen($d1) < 10)
  {
    return ("NULL");
  }
  else
  {
    return ("'$d1'");
  }
}

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

function getStudent($stateId)
{
  global $dbConn;

  $student = new studentObj();

  $sql = "SELECT id_student, name_first, name_last " .
    "FROM iep_student " .
    "WHERE unique_id_state = $stateId";

  $dbResult = pg_query($dbConn, $sql);

  $srsId = 0;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $student->srsId = $dbRow[0];
      $student->nameFirst = $dbRow[1];
      $student->nameLast = $dbRow[2];
    }
  }

  return($student);
}

function insertEdfi1Record($s, $d)
{
  global $dbConn;

  $student = getStudent($s["studentReference"]["studentUniqueId"]);

  $beginOt = "NULL";
  $beginPt = "NULL";
  $beginSlt = "NULL";
  $descOt = 0;
  $descPt = 0;
  $descSlt = 0;

  if ($s["toTakeAlternateAssessment"])
  {
    $alternateAssessment = 't';
  }
  else
  {
    $alternateAssessment = 'f';
  }

  foreach ($s["services"] as $service)
  {
    switch ($service["serviceDescriptor"])
    {
      case '1':
        $beginOt = dateOrNull($service["serviceBeginDate"]);
        $descOt = "1";
        break;
      case '2':
        $beginPt = dateOrNull($service["serviceBeginDate"]);
        $descPt = "2";
        break;
      case '3':
        $beginSlt = dateOrNull($service["serviceBeginDate"]);
        $descSlt = "3";
        break;
    }
  }

  $sql = "INSERT INTO edfi1 " .
    "(educationorganzationid, id_student, studentuniqueid, begindate, enddate, " .
    "reasonexiteddescriptor, specialeducationsettingdescriptor, levelofprogramparticipationdescriptor, " .
    "placementtypedescriptor, specialeducationpercentage, totakealternateassessment, " .
    "servicebegindate_pt, servicebegindate_ot, servicebegindate_slt, disabilities, " .
    "servicedescriptor_pt, servicedescriptor_ot, servicedescriptor_slt, edfiresultcode, " .
    "edfipublishstatus, name_first, name_last, name_school, id_county, id_district, " .
    "id_school) " .
    "VALUES ('" . $s["educationOrganizationReference"]["educationOrganizationId"] . "', " .
    "$student->srsId, " . $s["studentReference"]["studentUniqueId"] . ", " .
    dateOrNull($s["beginDate"]) . ", " . dateOrNull($s["endDate"]) . ", '" .
    $s["reasonExitedDescriptor"] . "', '" . $s["specialEducationSettingDescriptor"] . "', '" .
    $s["levelOfProgramParticipationDescriptor"] . "', '" . $s["placementTypeDescriptor"] . "', " .
    $s["specialEducationPercentage"] . ", '$alternateAssessment', " .
    "$beginPt, $beginOt, $beginSlt, '" . $s["disabilities"][0]["disabilityDescriptor"] . "', " .
    $descPt . ", " . $descOt . ", " . $descSlt . ", 200, 'S', '". addslashes($student->nameFirst) . "', " .
    "'" . addslashes($student->nameLast) . "', '$d->name_district', '$d->id_county', '$d->id_district', '000')";

  $dbResult = pg_query($dbConn, $sql);
}
