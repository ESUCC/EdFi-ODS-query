<?php
//
// publish-1920.php
//
//   SRS main ADVISER publishing process for the 2019-2020 ODS. This runs in a
//   continuous loop, evaluating which districts are set to publish ADVISER SPED
//   data through SRS and then processing records needing to be published for
//   each of those districts.
//
//  9/25 - 10/7/2019 SI
//

// Config
include 'publish-config.php';

// persistent variables
$adviserKey = null;
$adviserSecret = null;
$dbConn = null;
$errorMessage = "";
$errorRetryTime = 0;
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
}

// No command-line arguments required -- data comes from the SRS database

date_default_timezone_set('America/Chicago');
printf("\n%s: INFO: publish-1920 starting up\n", date("Y-m-d H:i:s T"));
// Run forever until stopped
while (true)
{
  // Open log file
  openPublishLog();

  // Connect to DB
  $dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
    " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
  if (!$dbConn)
  {
    writePublishLog ("ERROR: Database connection failed");
  }

  if ($logFile && $dbConn)
  {
    writePublishLog("INFO: starting publishing cycle");

    // re-try publishing errors every hour -- see if it's time for that
    if (time() > $errorRetryTime + 3600)
    {
      $publishErrors = true;
      $errorRetryTime = time();
    }
    else
    {
      $publishErrors = false;
    }

    // find districts which are enabled to use SRS ADVISER publishing
    writePublishLog("INFO: finding districts to publish");
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
      writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
    }

    writePublishLog(sprintf("INFO: %d ADVISER district(s) found", $districtCount));

    // find records to publish for each district
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

        // retrieve list of students who need publishing in this district
        $studentIds = getStudentsToPublish($d->id_county, $d->id_district,
          $publishErrors);

        if (count($studentIds) > 0)
        {
          writePublishLog(sprintf ("INFO: processing %d student(s) for %s (%s-%s)",
            count($studentIds), $d->name_district, $d->id_county, $d->id_district));
        }

        // publish records student by student ID
        foreach ($studentIds as $sId)
        {
          // delete existing ADVISER SPED records for this student
          deleteSPEDAssociations($sId);

          // set the status so all of the student's ADVISER SPED records are republished
          setStudentToRepublish($sId, $d->id_county, $d->id_district);

          $edfiRecs = getEdfi2RecsForStudent($sId, $d->id_county,
            $d->id_district, $publishErrors);

          $prevRecEnd = 1;
          $sRecCount = 0;
          foreach ($edfiRecs as $edfiRec)
          {
            $sRecCount++;
            $rCode = 600;
            $errorMessage = "";
            if ($edfiRec->EdfiPublishStatus == 'W' || $edfiRec->EdfiPublishStatus == 'E')
            {
              // these types are waiting to be published
              // tests for validity
              $invalidList = testAdviserValues($edfiRec);

              $json = "";
              if(empty($invalidList))
              {
                // check for overlapping dates on records
                if (($prevRecEnd == 0) || (strtotime($edfiRec->BeginDate) <= $prevRecEnd))
                {
                  // Either we're trying to publish a second record when the first
                  //  one hasn't ended, or the begin date on this record is earlier
                  //  than the end date on the previous record
                  $rCode = 603;
                  $errorMessage = "The BeginDate overlaps with another record for this student";
                }
                else
                {
                  // the record passed our basic validation, so try to publish it
                  // create JSON
                  $json = createSPEDJson($d->id_county, $d->id_district, $edfiRec);

                  // publish
                  $rCode = publishSPEDAssociation($json);
                  if ($rCode == 401)
                  {
                    getAuthToken();
                    $rCode = publishSPEDAssociation($json);
                  }
                }
              }
              else
              {
                // the record failed our basic validation
                $rCode = 601;
                $errorMessage = sprintf("invalid fields: %s",
                  implode(", ", $invalidList));
              }

              savePublishResult($edfiRec->id_edfi2_entry, $json, $rCode, $errorMessage);
              if (!$edfiRec->EndDate)
              {
                $prevRecEnd = 0;
              }
              else
              {
                $prevRecEnd = strtotime($edfiRec->EndDate);
              }
            }
            else if ($edfiRec->EdfiPublishStatus == 'D')
            {
              // delete Edfi2 record
              deleteEdfi2Rec($edfiRec->id_edfi2_entry);
            }
            else if ($edfiRec->EdfiPublishStatus == 'T')
            {
              // transfer record
              writePublishLog(sprintf ("WARN: unsure how to process T record %d for student %d",
                $edfiRec->id_edfi2_entry, $edfiRec->StudentUniqueId));
            }
            else
            {
              // unknown record type
              writePublishLog(sprintf ("WARN: unsure how to process %s record %d for student %d",
                $edfiRec->EdfiPublishStatus, $edfiRec->id_edfi2_entry,
                $edfiRec->StudentUniqueId));
            }
          }
        }
        updateMissingUniqueIds($d->id_county, $d->id_district);
      }
      $authToken = null;
    }
  }

  // breathe before starting the cycle over again
  pg_close($dbConn);
  writePublishLog("INFO: finished publishing cycle");
  closePublishLog();
  sleep(60);
}

function buildJsonServices ($serviceCode)
{
  $json = '"specialEducationProgramServices": [';
  if ($serviceCode == 1 || $serviceCode == 4 || $serviceCode == 6 || $serviceCode == 7)
  {
    // OT service
    $json .= '
    {
      "specialEducationProgramServiceDescriptor": "uri://education.ne.gov/SpecialEducationProgramServiceDescriptor#1",
      "primaryIndicator": true
    },';
  }
  if ($serviceCode == 2 || $serviceCode == 5 || $serviceCode == 6 || $serviceCode == 7)
  {
    // PT service
    $json .= '
    {
      "specialEducationProgramServiceDescriptor": "uri://education.ne.gov/SpecialEducationProgramServiceDescriptor#2",
      "primaryIndicator": true
    },';
  }
  if ($serviceCode == 3 || $serviceCode == 4 || $serviceCode == 5 || $serviceCode == 6)
  {
    // SLT service
    $json .= '
    {
      "specialEducationProgramServiceDescriptor": "uri://education.ne.gov/SpecialEducationProgramServiceDescriptor#3",
      "primaryIndicator": true
    },';
  }
  $json .= '],';
  return ($json);
}

function closePublishLog()
{
  global $logFile;
  writePublishLog("INFO: closing log file");
  fclose($logFile);
}

function createSPEDJson($id_county, $id_district, $edfiRec)
{

  if ($id_county == '87' && $id_district == '0561')
  {
    // 10/11/19 SI -- hack for Emerson-Hubbard, who seems to have changed counties.
    // SRS has them as 87-0561, but the list from NDE shows 26-0561
    // I'm going to catch and change that here, as the JSON is generated.
    // Our plan for after reporting is to create a new district in SRS
    // with the correct county ID and transfer all the students and staff
    // privileges to the new one.
    $id_county = '26';
  }
  $alternateAssessment = 'false';
  if ($edfiRec->ToTakeAlternateAssessment == 'yes')
  {
    $alternateAssessment = 'true';
  }
  if (substr($id_county,0,1) == "0")
  {
    $id_county = substr($id_county,1,1);
  }
  $json = '{
    "educationOrganizationReference": {
      "educationOrganizationId": ' . $id_county . $id_district . '000,
    },
    "programReference": {
      "educationOrganizationId": ' . $id_county . $id_district . '000,
      "programName": "Special Education",
      "programTypeDescriptor": "uri://ed-fi.org/ProgramTypeDescriptor#Special Education",
    },
    "studentReference": {
      "studentUniqueId": "' . $edfiRec->StudentUniqueId . '",
    },
    "beginDate": "' . $edfiRec->BeginDate . '",
    "schoolHoursPerWeek": ' . $edfiRec->SchoolHoursPerWeek . ',
    "specialEducationHoursPerWeek": ' . $edfiRec->SpecialEducationHoursPerWeek . ',
    "specialEducationSettingDescriptor": "uri://education.ne.gov/SpecialEducationSettingDescriptor#' .
      str_pad(trim($edfiRec->SpecialEducationSetting), 2, "0", STR_PAD_LEFT) . '",
    "_ext": {
      "ne": {
        "placementTypeDescriptor": "uri://education.ne.gov/PlacementTypeDescriptor#' . $edfiRec->PlacementType .'",
        "specialEducationProgramDescriptor": "uri://education.ne.gov/SpecialEducationProgramDescriptor#' . trim($edfiRec->SpecialEducationProgram) .'",
        "initialSpecialEducationEntryDate": "' . $edfiRec->InitialSpecialEducationEntryDate . '",
        "toTakeAlternateAssessment": ' . $alternateAssessment . '
      }
    },
    "disabilities": [
      {
        "disabilityDescriptor": "uri://education.ne.gov/DisabilityDescriptor#' . $edfiRec->Disability . '",
        "orderOfDisability": 0,
        "designations": []
      }
    ],';

  if ($edfiRec->SpecialEducationProgramService > 0)
  {
    $json .= buildJsonServices($edfiRec->SpecialEducationProgramService);
  }

  if (!empty($edfiRec->EndDate))
  {
    $json .= '
      "endDate": "' . $edfiRec->EndDate . '",
      "reasonExitedDescriptor": "uri://education.ne.gov/ReasonExitedDescriptor#' . $edfiRec->ReasonExited . '",
    ';
  }
  $json .= ' }';

  return ($json);
}

function deleteEdfi2Rec($edfiRecId)
{
  global $dbConn;

  $dbResult = pg_delete ($dbConn, 'edfi2', ['id_edfi2_entry' => $edfiRecId]);
  // $sql = "DELETE FROM edfi2 WHERE id_edfi2_entry=" . $edfiRecId;
  //
  // $dbResult = pg_query($dbConn, $sql);

  if ($dbResult)
  {
    writePublishLog(sprintf("INFO: D record %d deleted", $edfiRecId));
  }
  else
  {
    writePublishLog(sprintf ("INFO: D record %d was not found/deleted", $edfiRecId));
  }
}

function deleteSPEDAssociations($studentId)
{
  global $authToken;
  global $edfiBaseUrl;
  global $edfiApiPath;


  if (empty($authToken))
  {
    getAuthToken();
  }
  if (!empty($authToken))
  {
    $authorization = "Authorization: Bearer " . $authToken;
    // list studentSpecialEducationProgramAssociations
    $url = $edfiBaseUrl . $edfiApiPath . "/studentSpecialEducationProgramAssociations?offset=0&limit=25&totalCount=false&studentUniqueId=" . $studentId;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
    curl_setopt($curl, CURLOPT_URL, "$url");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($curl);
    $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if ($rCode == 401)
    {
      getAuthToken();
    }
    $arrResult = json_decode($result, true);
    $spaIds = [];

    if ($rCode == 200)
    {
      foreach ($arrResult as $s)
      {
        $spaIds[] = $s["id"];
      }

      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
      foreach($spaIds as $spa)
      {
        $url = $edfiBaseUrl . $edfiApiPath . "/studentSpecialEducationProgramAssociations/" . $spa;
        curl_setopt($curl, CURLOPT_URL, "$url");
        $result = curl_exec($curl);
        $errorMessage = json_decode($result)->message;
        $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($rCode == 401)
        {
          getAuthToken();
        }
        if ($rCode == 204)
        {
          writePublishLog(sprintf("INFO: deleted SSPA %s", $spa));
        }
        else
        {
          writePublishLog(sprintf("WARN: problem deleting SSPA %s, return code: %d, %s",
            $spa, $rCode, $errorMessage));
        }
      }
    }
    else
    {
      writePublishLog(sprintf("WARN: could not locate SSPA records for %d, response: %d, text: %s",
        $studentId, $rCode, $result));
    }
    curl_close($curl);
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

function getAuthToken()
{
  // Get ODS access token
  global $adviserKey;
  global $adviserSecret;
  global $authToken;
  global $edfiBaseUrl;
  global $edfiAuthPath;

  $edfiApiTokenUrl = "$edfiBaseUrl$edfiAuthPath/oauth/token";
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
      $authToken=$jsonResult->access_token;
      if (empty($authToken))
      {
        writePublishLog(sprintf("ERROR: failed to get auth token, return code %d", $rCode));
      }
      curl_close($curl);
      return ($authToken);
  }
  catch(Exception $e) {
      writePublishLog(sprintf("ERROR: failed to get auth token: %s", $e->getMessage()));
      return (null);
  }
}

function getEdfi2RecsForStudent($studentId, $idCounty, $idDistrict, $publishErrors)
{
  global $dbConn;

  if ($publishErrors)
  {
    $statuses = "('D', 'E', 'W', 'T')";
  }
  else
  {
    $statuses = "('D', 'W', 'T')";
  }

  // get all edfi2 records for this student
  $sql = "SELECT * " .
    "FROM edfi2 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "\"EdfiPublishStatus\" IN " . $statuses . " AND " .
    "\"StudentUniqueId\" = " . $studentId . " AND " .
    "edfi_year = 1920 " .
    "ORDER BY \"BeginDate\"";

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

function getStudentsToPublish($idCounty, $idDistrict, $publishErrors)
{
  global $dbConn;

  if ($publishErrors)
  {
    $statuses = "('D', 'E', 'W', 'T')";
  }
  else
  {
    $statuses = "('D', 'W', 'T')";
  }

  $studentIds = [];
  $sql = "SELECT DISTINCT \"StudentUniqueId\" " .
    "FROM edfi2 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "\"EdfiPublishStatus\" IN " . $statuses . " AND " .
    "\"StudentUniqueId\" > 0 AND " .
    "edfi_year = 1920 " .
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

function openPublishLog()
{
  global $logFile;
  $logFile = fopen("logs/publish-1920.log", "a");
  if (!$logFile)
  {
    printf("\n%s: ERROR: Failed to open log file\n", date("Y-m-d H:i:s T"));
  }
  writePublishLog("INFO: Log file opened");
}

function publishSPEDAssociation($data)
{
  global $authToken;
  global $edfiBaseUrl;
  global $edfiApiPath;
  global $errorMessage;

  $authorization = "Authorization: Bearer " . $authToken;
  $url = $edfiBaseUrl . $edfiApiPath . "/studentSpecialEducationProgramAssociations";
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($curl, CURLOPT_URL, "$url");
  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  $payloadLength = 'Content-Length: ' . strlen($data);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json' ,
    $authorization, $payloadLength ));
  curl_setopt($curl, CURLOPT_URL, "$url");
  curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

  $result = curl_exec($curl);
  $errorMessage = json_decode($result)->message;
  $rCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
  curl_close($curl);
  return($rCode);
}

function savePublishResult($edfiRecId, $json, $rCode, $errorMessage)
{
  global $dbConn;

  $publishStatus = "W";
  if ($rCode == 200 || $rCode == 201 || $rCode == 204)
  {
    $publishStatus = "S";
    writePublishLog(sprintf("INFO: rec %d published: %s", $edfiRecId, $json));
  }
  else
  {
    $publishStatus = "E";
    writePublishLog(sprintf("WARN: rec %d failed to publish: %d, %s",
      $edfiRecId, $rCode, $errorMessage));
  }

  if ($rCode == 403)
  {
    $errorMessage = "This student is not associated with your district yet. Publish this student from your SIS.";
  }

  $sql = "UPDATE edfi2 SET \"EdfiPublishStatus\"= '" . $publishStatus .
    "', \"EdfiPublishTime\"= NOW(), \"EdfiErrorMessage\"= '" .
    $rCode . ": " . addslashes($errorMessage) . "' WHERE id_edfi2_entry=" .
    $edfiRecId;

  $dbResult = pg_query($dbConn, $sql);

  if (!$dbResult)
  {
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }
}

function setStudentToRepublish ($studentId, $idCounty, $idDistrict)
{
  global $dbConn;

  $sql = "UPDATE edfi2 SET \"EdfiPublishStatus\"= 'W' " .
    "WHERE \"StudentUniqueId\" = " . $studentId . " " .
    "AND id_county = '$idCounty' " .
    "AND id_district = '$idDistrict' " .
    "AND edfi_year = 1920 " . 
    "AND \"EdfiPublishStatus\"='S' ";

  $dbResult = pg_query($dbConn, $sql);

  if (!$dbResult)
  {
    writePublishLog(sprintf ("ERROR: A database error occurred: %s", pg_last_error($dbConn)));
  }
}

function testAdviserValues($edfiRec)
{
  $invalidList = [];

  if ($edfiRec->StudentUniqueId < 1)
  {
    $invalidList[] = "StudentUniqueId";
  }

  $beginTimestamp = strtotime($edfiRec->BeginDate);
  if ( $beginTimestamp < strtotime('2019-07-01') ||
    $beginTimestamp > strtotime('2020-06-30'))
  {
    $invalidList[] = "BeginDate";
  }

  if (!empty($edfiRec->EndDate))
  {
    $endTimestamp = strtotime($edfiRec->EndDate);
    if ( $endTimestamp < strtotime('2019-07-01') ||
      $endTimestamp > strtotime('2020-06-30') ||
      $endTimestamp < $beginTimestamp)
    {
      $invalidList[] = "EndDate";
    }
    if (empty($edfiRec->ReasonExited))
    {
      $invalidList[] = "ReasonExited";
    }
  }

  if (empty($edfiRec->SchoolHoursPerWeek))
  {
    $invalidList[] = "SchoolHoursPerWeek";
  }

  if (is_null($edfiRec->SpecialEducationHoursPerWeek))
  {
    $invalidList[] = "SpecialEducationHoursPerWeek";
  }

  if (empty($edfiRec->SpecialEducationSetting))
  {
    $invalidList[] = "SpecialEducationSetting";
  }

  if (($edfiRec->PlacementType == 0) ||
    ($edfiRec->PlacementType >= 2 && $edfiRec->PlacementType <= 4))
  {
    // valid
  }
  else
  {
    $invalidList[] = "PlacementType";
  }

  if (empty($edfiRec->SpecialEducationProgram))
  {
    $invalidList[] = "SpecialEducationProgram";
  }

  if (empty($edfiRec->Disability))
  {
    $invalidList[] = "Disability";
  }

  return($invalidList);
}

function updateMissingUniqueIds($idCounty, $idDistrict)
{
  global $dbConn;

  $sql = "UPDATE edfi2 SET \"EdfiPublishStatus\"= 'E', \"EdfiPublishTime\"= NOW(), " .
    "\"EdfiErrorMessage\"= '602: StudentUniqueId is missing' " .
    "WHERE id_county='" . $idCounty . "' " .
    "AND id_district='" . $idDistrict . "' " .
    "AND \"EdfiPublishStatus\" = 'W' " .
    "AND edfi_year = 1920 " .
    "AND (\"StudentUniqueId\" IS NULL OR \"StudentUniqueId\"< 1)";

  $dbResult = pg_query($dbConn, $sql);

  if (!$dbResult)
  {
    writePublishLog(sprintf ("ERROR: A database error occurred in updateMissingUniqueIds: %s",
      pg_last_error($dbConn)));
  }

  $sql = "DELETE FROM edfi2 " .
    "WHERE id_county='" . $idCounty . "' " .
    "AND id_district='" . $idDistrict . "' " .
    "AND \"EdfiPublishStatus\" = 'D' " .
    "AND edfi_year = 1920 " .
    "AND (\"StudentUniqueId\" IS NULL OR \"StudentUniqueId\"< 1)";

  pg_query($dbConn, $sql);

}

function writePublishLog(string $logEntry)
{
  global $logFile;

  printf("\n%s: %s", date("Y-m-d H:i:s T"), $logEntry);
  if (!fwrite($logFile, sprintf("\n%s: %s", date("Y-m-d H:i:s T"), $logEntry)))
  {
    printf("\n%s: ERROR: Failed writing to log file\n", date("Y-m-d H:i:s T"));
  }
}
