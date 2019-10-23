<?php
//
// transfer-1920.php
//
//  This script builds ADVISER records for students who transferred to another
//  school on or after 7/1/2019.
//  Phase 1 - build records for an individual student by state ID
//
//  10/14/2019 SI

// config
include 'transfer-config.php';

// persistent variables
$dbConn = null;
$logFile = null;

// Object classes
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

class exitObj
{
  public $idCounty;
  public $idDistrict;
  public $exitDate;
  public $exitReason;
}

class schoolObj
{
  public $nameSchool;
  public $hoursPerWeek;
}

class transferObj
{
  public $timestampCreated;
  public $timestampLastMod;
  public $idCountyFrom;
  public $idDistrictFrom;
  public $idSchoolFrom;
  public $idCountyTo;
  public $idDistrictTo;
}

// check command-line argument - student state ID
if ($argc != 2)
{
  printf("\nusage: php ./transfer-2910.php <student state ID>\n");
  exit();
}

$stateId = $argv[1];

// Connect to DB
$dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
  " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
if (!$dbConn)
{
  printf("ERROR: Database connection failed\n");
  exit();
}

$srsId = getSRSId($stateId);

if ($srsId)
{
  $transferRec = getTransferRecords($srsId);
  $exitRec = getExitDetails($srsId);
  $adviserRec = getAdviserRecord($srsId, $exitRec->idCounty, $exitRec->idDistrict);

  if (empty($transferRec))
  {
    printf("transfer record missing for student %d\n", $stateId);
    exit();
  }
  if (empty($exitRec))
  {
    printf("exit record missing for student %d\n", $stateId);
    exit();
  }
  if (empty($adviserRec))
  {
    $oldAdviserRec = true;
    $adviserRec = getOldAdviserRecord($srsId, $transferRec->idCountyFrom,
      $transferRec->idDistrictFrom);
    if (empty($adviserRec))
    {
      printf("ADVISER records missing for student %d\n", $stateId);
      exit();
    }
  }

  $endTimestamp = strtotime($exitRec->exitDate);
  if (($endTimestamp >= strtotime('2019-07-01')) &&
    ($endTimestamp < strtotime('2020-07-01')))
  {
    // check for existing ADVISER records in the FROM district
    if (!checkExistingExits($srsId, $transferRec->idCountyFrom, $transferRec->idDistrictFrom))
    {
      // create an exit record for the previous district
      addExitRecord($stateId, $srsId, $adviserRec, $transferRec, $exitRec);

      // adjust the begin date on the current record to align (if one exists)
      if (($endTimestamp > strtotime($adviserRec->BeginDate)) && !oldAdviserRec)
      {
        $beginDate = new DateTime($exitRec->exitDate);
        $beginDate->modify('+1 day');
        updateExistingRecord($adviserRec, $beginDate->format('Y-m-d'));
      }
    }
    else
    {
      printf("an exit record already exists for student %d in district %s-%s\n",
        $stateId, $transferRec->idCountyFrom, $transferRec->idDistrictFrom);
      if ($oldAdviserRec)
      {
        createAdviserRecord($srsId);
      }
    }
  }
  else
  {
    printf("exit date %s is out of range for student %d\n", $exitRec->exitDate, $stateId);
  }
}

function addExitRecord($stateId, $srsId, $adviserRec, $transferRec, $exitRec)
{
  global $dbConn;

  $edOrgId = $transferRec->idCountyFrom . $transferRec->idDistrictFrom . "000";
  $schoolRec = getSchoolRecord($transferRec->idCountyFrom, $transferRec->idDistrictFrom,
    $transferRec->idSchoolFrom);
  $nameDistrict = getDistrictName($transferRec->idCountyFrom, $transferRec->idDistrictFrom);

  printf("adding ADVISER exit record for student %d in district %s (%s-%s)\n",
    $srsId, $nameDistrict, $transferRec->idCountyFrom, $transferRec->idDistrictFrom);
  $sql = "INSERT INTO edfi2 (id_author, id_author_last_mod, " .
    "\"EducationOrganizationId\", \"StudentUniqueId\", \"EdfiPublishStatus\", " .
    "id_form_002, id_form_023, id_form_004, id_form_013, id_student, name_first, " .
    "name_last, id_county, id_district, id_school, name_school, name_district, " .
    "\"ToTakeAlternateAssessment\", \"BeginDate\", \"Disability\", \"EndDate\", " .
    "\"InitialSpecialEducationEntryDate\", \"PlacementType\", \"ReasonExited\", " .
    "\"SchoolHoursPerWeek\", \"SpecialEducationProgramService\", " .
    "\"SpecialEducationHoursPerWeek\", \"SpecialEducationProgram\", " .
    "\"SpecialEducationSetting\", dob, age, id_form_022) " .
    "VALUES (9999999, 9999999, " . (int) $edOrgId . ", " . (int) $stateId . ", 'W', " .
    (int) $adviserRec->idForm002 . ", " . (int) $adviserRec->idForm023 . ", " .
    (int) $adviserRec->idForm004 . ", " . (int) $adviserRec->idForm013 . ", " .
    (int) $srsId . ", '$adviserRec->nameFirst', '$adviserRec->nameLast', '$transferRec->idCountyFrom', " .
    "'$transferRec->idDistrictFrom', '$transferRec->idSchoolFrom', '$schoolRec->nameSchool', " .
    "'$nameDistrict', '$adviserRec->ToTakeAlternateAssessment', " .
    "'2019-07-01', '$adviserRec->Disability', '$exitRec->exitDate', " .
    "'$adviserRec->InitialSpecialEducationEntryDate', " . (int) $adviserRec->PlacementType . ", " .
    "'$exitRec->exitReason', " . floatval($schoolRec->hoursPerWeek) . ", " .
    (int) $adviserRec->SpecialEducationProgramService . ", " .
    floatval($adviserRec->SpecialEducationHoursPerWeek) . ", '$adviserRec->SpecialEducationProgram', " .
    "'$adviserRec->SpecialEducationSetting', '$adviserRec->dateOfBirth', " . (int) $adviserRec->age . ", " .
    (int) $adviserRec->idForm022 . ")";

  $dbResult = pg_query($dbConn, $sql);
}

function calcAge($dateOfBirth)
{
  $dateToday = date_create(date('Y-m-d'));
  $dateBDay = date_create($dateOfBirth);

  $interval = date_diff($dateBDay, $dateToday);

  return ($interval->format('%y'));
}

function checkExistingExits($srsId, $idCounty, $idDistrict)
{
  global $dbConn;

  // check for existing ADVISER record
  $sql = "SELECT COUNT(*) " .
    "FROM edfi2 " .
    "WHERE id_student = $srsId " .
    "AND id_county = '$idCounty' " .
    "AND id_district = '$idDistrict' ";

  $dbResult = pg_query($dbConn, $sql);

  $count = 0;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    $count = $dbRow[0];
  }
  return ($count);
}

function createAdviserRecord($srsId)
{
  // similar to ods2/advisorset but for one student
}

function fillEdfi2Obj($dbRow)
{
  $edfiRec = new edfi2Obj();
  $edfiRec->id_edfi2_entry = $dbRow[4];
  $edfiRec->EducationOrganizationId = $dbRow[5];
  $edfiRec->StudentUniqueId = $dbRow[6];
  $edfiRec->EdfiPublishTime = $dbRow[7];
  $edfiRec->EdfiPublishStatus = $dbRow[8];
  $edfiRec->EdfiErrorMessage = $dbRow[9];
  $edfiRec->idForm002 = $dbRow[10];
  $edfiRec->idForm023 = $dbRow[11];
  $edfiRec->idForm004 = $dbRow[12];
  $edfiRec->idForm013 = $dbRow[13];
  $edfiRec->nameFirst = $dbRow[15];
  $edfiRec->nameLast = $dbRow[16];
  $edfiRec->ToTakeAlternateAssessment = $dbRow[22];
  $edfiRec->BeginDate = $dbRow[23];
  $edfiRec->Disability = $dbRow[24];
  $edfiRec->EndDate = $dbRow[25];
  $edfiRec->InitialSpecialEducationEntryDate = $dbRow[26];
  $edfiRec->PlacementType = $dbRow[27];
  $edfiRec->ReasonExited = $dbRow[28];
  $edfiRec->SchoolHoursPerWeek = $dbRow[29];
  $edfiRec->SpecialEducationProgramService = $dbRow[30];
  $edfiRec->SpecialEducationHoursPerWeek = $dbRow[31];
  $edfiRec->SpecialEducationProgram = $dbRow[32];
  $edfiRec->SpecialEducationSetting = $dbRow[33];
  $edfiRec->dateOfBirth = $dbRow[34];
  $edfiRec->age = $dbRow[35];
  $edfiRec->idForm022 = $dbRow[36];

  return ($edfiRec);
}

function fillEdfi2ObjFromOld($dbRow)
{
  $schoolRec = getSchoolRecord($dbRow[36], $dbRow[37], $dbRow[38]);
  $edfiRec = new edfi2Obj();
  $edfiRec->id_edfi2_entry = 0;
  $edfiRec->EducationOrganizationId = $dbRow[4];
  $edfiRec->StudentUniqueId = $dbRow[8];
  $edfiRec->EdfiPublishTime = $dbRow[26];
  $edfiRec->EdfiPublishStatus = $dbRow[27];
  $edfiRec->EdfiErrorMessage = $dbRow[25];
  $edfiRec->idForm002 = 0;
  $edfiRec->idForm023 = 0;
  $edfiRec->idForm004 = 0;
  $edfiRec->idForm013 = 0;
  $edfiRec->nameFirst = $dbRow[32];
  $edfiRec->nameLast = $dbRow[33];
  $edfiRec->ToTakeAlternateAssessment = $dbRow[16];
  $edfiRec->BeginDate = $dbRow[9];
  $edfiRec->Disability = $dbRow[20];
  $edfiRec->EndDate = $dbRow[10];
  $edfiRec->InitialSpecialEducationEntryDate = $dbRow[9];
  $edfiRec->PlacementType = $dbRow[14];
  $edfiRec->ReasonExited = $dbRow[11];
  $edfiRec->SchoolHoursPerWeek = $schoolRec->hoursPerWeek;

  if ($dbRow[21] == 2)
  {
    $pt = true;
  }
  if ($dbRow[22] == 1)
  {
    $ot = true;
  }
  if ($dbRow[23])
  {
    $slt = true;
  }

  if ($pt && $ot && $slt)
  {
    $edfiRec->SpecialEducationProgramService = 6;
  }
  else if ($pt && $ot && !$slt)
  {
    $edfiRec->SpecialEducationProgramService = 7;
  }
  else if ($pt && !$ot && $slt)
  {
    $edfiRec->SpecialEducationProgramService = 5;
  }
  else if (!$pt && $ot && $slt)
  {
    $edfiRec->SpecialEducationProgramService = 4;
  }
  else if ($slt)
  {
    $edfiRec->SpecialEducationProgramService = 3;
  }
  else if ($pt)
  {
    $edfiRec->SpecialEducationProgramService = 2;
  }
  else if ($ot)
  {
    $edfiRec->SpecialEducationProgramService = 1;
  }

  $edfiRec->SpecialEducationHoursPerWeek = $edfiRec->SchoolHoursPerWeek * ($dbRow[15] / 100);
  $edfiRec->SpecialEducationProgram = $dbRow[13];
  $edfiRec->SpecialEducationSetting = $dbRow[12];
  $edfiRec->dateOfBirth = getDateOfBirth($dbRow[7]);
  $edfiRec->age = calcAge($edfiRec->dateOfBirth);
  $edfiRec->idForm022 = 0;

  return ($edfiRec);
}

function getAdviserRecord($srsId, $idCounty, $idDistrict)
{
  global $dbConn;

  $sql = "SELECT * " .
    "FROM edfi2 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "id_student = " . $srsId . " " .
    "ORDER BY \"BeginDate\", \"EdfiErrorMessage\"";

  $dbResult = pg_query($dbConn, $sql);

  $transferRec = null;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $adviserRec = fillEdfi2Obj($dbRow);
    }
  }

  return($adviserRec);
}

function getDateOfBirth($srsId)
{
  global $dbConn;

  $sql = "SELECT dob " .
    "FROM iep_student " .
    "WHERE id_student = $srsId";

  $dbResult = pg_query($dbConn, $sql);

  $nameDistrict = '';
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $dob = $dbRow[0];
    }
  }

  return($dob);

}

function getDistrictName($idCounty, $idDistrict)
{
  global $dbConn;

  $sql = "SELECT name_district " .
    "FROM iep_district " .
    "WHERE id_county = '$idCounty' AND id_district='$idDistrict'";

  $dbResult = pg_query($dbConn, $sql);

  $nameDistrict = '';
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $nameDistrict = $dbRow[0];
    }
  }

  return($nameDistrict);
}

function getExitDetails($srsId)
{
  global $exitFilePath;

  $exitFile = fopen($exitFilePath,"r");
  $lineArr = [];
  while (!feof($exitFile))
  {
    $lineArr = explode("|", fgets($exitFile));
    if (trim($lineArr[2]) == $srsId)
    {
      break;
    }
  }
  $exitRec = new ExitObj();
  $exitRec->idCounty = trim($lineArr[0]);
  $exitRec->idDistrict = trim($lineArr[1]);
  $exitRec->exitDate = trim($lineArr[4]);
  $exitRec->exitReason = sprintf("SPED%02d", trim($lineArr[5]));

  return($exitRec);

}

function getOldAdviserRecord($srsId, $idCounty, $idDistrict)
{
  global $dbConn;

  $sql = "SELECT * " .
    "FROM edfi " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "id_student = " . $srsId . " " .
    "ORDER BY begindate";

  $dbResult = pg_query($dbConn, $sql);

  $transferRec = null;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $adviserRec = fillEdfi2ObjFromOld($dbRow);
    }
  }

  return($adviserRec);
}

function getSchoolRecord($idCounty, $idDistrict, $idSchool)
{
  global $dbConn;

  $sql = "SELECT name_school, minutes_per_week " .
    "FROM iep_school " .
    "WHERE id_county='$idCounty' AND id_district='$idDistrict' AND id_school = '$idSchool'";

  $dbResult = pg_query($dbConn, $sql);

  $schoolRec = null;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $schoolRec = new schoolObj();
      $schoolRec->nameSchool = $dbRow[0];
      $schoolRec->hoursPerWeek = $dbRow[1] / 60;
    }
  }

  return ($schoolRec);

}

function getSRSId($stateId)
{
  global $dbConn;

  $sql = "SELECT id_student " .
    "FROM iep_student " .
    "WHERE unique_id_state = $stateId";

  $dbResult = pg_query($dbConn, $sql);

  $srsId = 0;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $srsId = $dbRow[0];
    }
  }

  return($srsId);
}

function getTransferRecords($srsId)
{
  global $dbConn;

  $sql = "SELECT timestamp_created, timestamp_last_mod, id_county_from, id_district_from, " .
    "id_school_from " .
    "FROM iep_transfer_request " .
    "WHERE student_id_list = '$srsId' " .
    " AND transfer_type='Confirmed' " .
    " AND timestamp_created >= '2019-07-01' " .
    "ORDER BY timestamp_created DESC";

  $dbResult = pg_query($dbConn, $sql);

  $transferRec = null;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $transferRec = new transferObj();
      $transferRec->timestampCreated = $dbRow[0];
      $transferRec->timestampLastMod = $dbRow[1];
      $transferRec->idCountyFrom = $dbRow[2];
      $transferRec->idDistrictFrom = $dbRow[3];
      $transferRec->idSchoolFrom = $dbRow[4];
    }
  }

  return ($transferRec);
}

function updateExistingRecord($adviserRec, $startDate)
{
  global $dbConn;

  printf("updating rec %d with start date: %s\n", $adviserRec->id_edfi2_entry, $startDate);
  $sql = "UPDATE edfi2 SET \"BeginDate\"='$startDate', \"EdfiPublishStatus\"='W' " .
    "WHERE id_edfi2_entry = $adviserRec->id_edfi2_entry";

  $dbResult = pg_query($dbConn, $sql);
}
