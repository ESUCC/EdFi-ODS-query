<?php
//
// fix-duplicates-1920.php
//
//  This script checks overlapping ADVISER records and deletes duplicates.
//
//  10/28/2019 SI

// config
include 'transfer-config.php';

// persistent variables
$dbConn = null;
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

// no command-line argument

date_default_timezone_set('America/Chicago');
printf("\n%s: INFO: fix-duplicates-1920 starting up\n", date("Y-m-d H:i:s T"));

// Connect to DB
$dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
  " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
if (!$dbConn)
{
  printf("ERROR: Database connection failed\n");
  exit();
}

if ($dbConn)
{
  writePublishLog("INFO: looking for duplicates");

  // find districts which are enabled to use SRS ADVISER publishing
  writePublishLog("INFO: finding districts to publish");
  $districts = [];
  $districtCount = 0;
  $sql = "SELECT id_county, id_district, name_district, edfi_key, edfi_secret " .
    "FROM iep_district " .
    "WHERE use_edfi ='t' AND " .
    // "  id_county='40' AND id_district='0002' AND " .
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
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }

  writePublishLog(sprintf("INFO: %d ADVISER district(s) found", $districtCount));

  foreach ($districts as $d)
  {
    if (empty($d->key) || empty($d->secret))
    {
      // writePublishLog(sprintf ("ERROR: missing secret/key for %s (%s-%s)",
      //  $d->name_district, $d->id_county, $d->id_district));
    }
    else
    {
      $adviserKey = $d->key;
      $adviserSecret = $d->secret;

    }

    $studentIds = getStudentsWithDuplicates($d->id_county, $d->id_district);

    if (count($studentIds) > 0)
    {
      writePublishLog(sprintf ("INFO: processing %d student(s) for %s (%s-%s)\n",
        count($studentIds), $d->name_district, $d->id_county, $d->id_district));
    }

    foreach ($studentIds as $sId)
    {
      $edfiRecs = getEdfi2RecsForStudent($sId, $d->id_county, $d->id_district);

      $originalRec = 0;
      $recCount = count($edfiRecs);

      for ($i=1; $i < $recCount; $i++)
      {
        if (substr($edfiRecs[$i]->EdfiErrorMessage, 0, 4) === "603:")
        {
          printf("checking records %d and %d\n", $edfiRecs[$i]->id_edfi2_entry,
            $edfiRecs[$originalRec]->id_edfi2_entry);
          if (isDup($edfiRecs[$i], $edfiRecs[$originalRec]))
          {
            printf("record is duplicate\n");
            deleteDup($edfiRecs[$i]->id_edfi2_entry);
          }
          else
          {
            printf("record is not duplicate\n");
          }
        }
        else
        {
          $originalRec++;
        }
      }
    }
  }
}

function deleteDup($edfiRecId)
{
  global $dbConn;

  writePublishLog(sprintf("INFO: deleting duplicate rec ID %d", $edfiRecId));
  $sql = "UPDATE edfi2 SET \"EdfiPublishStatus\"= 'D' " .
    "WHERE id_edfi2_entry=" . $edfiRecId;

  $dbResult = pg_query($dbConn, $sql);

  if (!$dbResult)
  {
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }

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

  return ($edfiRec);
}

function getEdfi2RecsForStudent($studentId, $idCounty, $idDistrict)
{
  global $dbConn;

  // get all edfi2 records for this student
  $sql = "SELECT * " .
    "FROM edfi2 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "\"StudentUniqueId\" = " . $studentId . " " .
    "ORDER BY \"BeginDate\", \"EdfiErrorMessage\"";

  $dbResult = pg_query($dbConn, $sql);

  $edfiRecs = [];
  if ($dbResult)
  {
    while ($dbRow = pg_fetch_row($dbResult))
    {
      // retrieve record
      $edfiRecs[] = fillEdfi2Obj($dbRow);
    }
  }
  else
  {
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }
  return ($edfiRecs);
}

function getStudentsWithDuplicates($idCounty, $idDistrict)
{
  global $dbConn;

  $studentIds = [];
  $sql = "SELECT DISTINCT \"StudentUniqueId\" " .
    "FROM edfi2 " .
    // "WHERE \"StudentUniqueId\"=3426120224 AND " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "\"EdfiErrorMessage\" LIKE '603:%' " .
    "ORDER BY \"StudentUniqueId\"";

  $dbResult = pg_query($dbConn, $sql);

  if ($dbResult)
  {
    while ($dbRow = pg_fetch_row($dbResult))
    {
      $studentIds[] = $dbRow[0];
    }
  }
  else
  {
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }
  return($studentIds);
}

function isDup($rec1, $rec2)
{
  // are the two ADVISER records equivalent?

  if ($rec1->ToTakeAlternateAssessment != $rec2->ToTakeAlternateAssessment)
  {
    printf("Alt Assessment doesn't match\n");
    return(false);
  }

  if ($rec1->BeginDate != $rec2->BeginDate)
  {
    printf("BeginDate doesn't match\n");
    return(false);
  }

  if ($rec1->Disability != $rec2->Disability)
  {
    printf("Disability doesn't match\n");
    return(false);
  }

  if ($rec1->EndDate != $rec2->EndDate)
  {
    printf("EndDate doesn't match\n");
    return(false);
  }

  if ($rec1->InitialSpecialEducationEntryDate != $rec2->InitialSpecialEducationEntryDate)
  {
    printf("InitialEntryDate doesn't match\n");
    return(false);
  }

  if ($rec1->PlacementType != $rec2->PlacementType)
  {
    printf("PlacementType doesn't match\n");
    return(false);
  }

  if ($rec1->ReasonExited != $rec2->ReasonExited)
  {
    printf("ReasonExited doesn't match\n");
    return(false);
  }

  if ($rec1->SchoolHoursPerWeek != $rec2->SchoolHoursPerWeek)
  {
    printf("SchoolHoursPerWeek doesn't match\n");
    return(false);
  }

  if ($rec1->SpecialEducationProgramsService != $rec2->SpecialEducationProgramsService)
  {
    printf("SpecialEducationProgramsService doesn't match\n");
    return(false);
  }

  if ($rec1->SpecialEducationHoursPerWeek != $rec2->SpecialEducationHoursPerWeek)
  {
    printf("SpecialEducationHoursPerWeek doesn't match\n");
    return(false);
  }

  if ($rec1->SpecialEducationProgram != $rec2->SpecialEducationProgram)
  {
    printf("SpecialEducationProgram doesn't match\n");
    return(false);
  }

  if ($rec1->SpecialEducationSetting != $rec2->SpecialEducationSetting)
  {
    printf("SpecialEducationSetting doesn't match\n");
    return(false);
  }

  printf("records match\n");
  return (true);
}

function writePublishLog(string $logEntry)
{
  printf("%s: %s\n", date("Y-m-d H:i:s T"), $logEntry);
}

?>
