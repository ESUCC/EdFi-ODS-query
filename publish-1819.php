<?php
//
// publish-1819.php
//
//   SRS main ADVISER publishing process for the 2018-2019 ODS. This runs in a
//   continuous loop, evaluating which districts are set to publish ADVISER SPED
//   data through SRS and then processing records needing to be published for
//   each of those districts.
//
//  9/25 - 10/23/2019 SI
//

// Config
include 'publish-1819-config.php';

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

class edfi1Obj
{
  public $id_edfi1_entry;
  public $EducationOrganizationId;
  public $StudentUniqueId;
  public $EdfiPublishTime;
  public $EdfiPublishStatus;
  public $EdfiErrorMessage;
  public $EdfiResultCode;
  public $ToTakeAlternateAssessment;
  public $BeginDate;
  public $Disability;
  public $EndDate;
  public $PlacementType;
  public $ReasonExited;
  public $SpecialEducationProgram;
  public $SpecialEducationSetting;
  public $SpecialEducationPercentage;
  public $servicebegindate_pt;
  public $servicebegindate_ot;
  public $servicebegindate_slt;
  public $servicedescriptor_pt;
  public $servicedescriptor_ot;
  public $servicedescriptor_slt;
}

// No command-line arguments required -- data comes from the SRS database

date_default_timezone_set('America/Chicago');
printf("\n%s: INFO: publish-1819 starting up\n", date("Y-m-d H:i:s T"));

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

          $edfiRecs = getEdfi1RecsForStudent($sId, $d->id_county,
            $d->id_district, $publishErrors);

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
                // only one record per student
                if ($sRecCount > 1)
                {
                  $rCode = 604;
                  $errorMessage = "There is more than one record for this student. Only 1 is allowed in 18-19 data.";
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

              savePublishResult($edfiRec->id_edfi1_entry, $json, $rCode, $errorMessage);

            }
            else if ($edfiRec->EdfiPublishStatus == 'D')
            {
              // delete Edfi2 record
              deleteEdfi1Rec($edfiRec->id_edfi1_entry);
            }
            else
            {
              // unknown record type
              writePublishLog(sprintf ("WARN: unsure how to process %s record %d for student %d",
                $edfiRec->EdfiPublishStatus, $edfiRec->id_edfi1_entry,
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
  if ($edfiRec->ToTakeAlternateAssessment == "1")
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
      "name": "Special Education",
      "type": "Special Education"
    },
    "studentReference": {
      "studentUniqueId": "' . $edfiRec->StudentUniqueId . '",
    },
    "beginDate": "' . $edfiRec->BeginDate . '",
    "specialEducationSettingDescriptor": "' .
      str_pad(trim($edfiRec->SpecialEducationSetting), 2, "0", STR_PAD_LEFT) . '",
    "levelOfProgramParticipationDescriptor": "' . trim($edfiRec->SpecialEducationProgram) .'",
    "placementTypeDescriptor": "' . $edfiRec->PlacementType .'",
    "specialEducationPercentage": ' . $edfiRec->SpecialEducationPercentage . ',
    "toTakeAlternateAssessment": ' . $alternateAssessment . ',
    "disabilities": [
      {
        "disabilityDescriptor": "' . $edfiRec->Disability . '",
      }
    ],';

  if ($edfiRec->servicedescriptor_ot == 1 || $edfiRec->servicedescriptor_pt == 2 ||
    $edfiRec->servicedescriptor_slt == 3)
  {
    $json .= '
    "services": [';
    if ($edfiRec->servicedescriptor_ot == 1)
    {
      $json .= '
      {
        "serviceDescriptor": "1"';
      if (!empty($edfiRec->servicebegindate_ot))
      {
        $json .= ',
        "serviceBeginDate": "' . $edfiRec->servicebegindate_ot . '"';
      }
      $json .= '
      },';
    }
    if ($edfiRec->servicedescriptor_pt == 2)
    {
      $json .= '
      {
        "serviceDescriptor": "2"';
      if (!empty($edfiRec->servicebegindate_pt))
      {
        $json .= ',
        "serviceBeginDate": "' . $edfiRec->servicebegindate_pt . '"';
      }
      $json .= '
      },';
    }
    if ($edfiRec->servicedescriptor_slt == 3)
    {
      $json .= '
      {
        "serviceDescriptor": "3"';
      if (!empty($edfiRec->servicebegindate_slt))
      {
        $json .= ',
        "serviceBeginDate": "' . $edfiRec->servicebegindate_slt . '"';
      }
      $json .= '
      },';
    }
    $json .= '
    ],';
  }

  if (!empty($edfiRec->EndDate))
  {
    $json .= '
    "endDate": "' . $edfiRec->EndDate . '",
    "reasonExitedDescriptor": "' . $edfiRec->ReasonExited . '",';
  }
  $json .= '
  }';

  return ($json);
}

function deleteEdfi1Rec($edfiRecId)
{
  global $dbConn;

  $dbResult = pg_delete ($dbConn, 'edfi1', ['id_edfi1_entry' => $edfiRecId]);

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
        if ($studentId == $s["studentReference"]["studentUniqueId"])
        {
          // the 18-19 ODS has a bug where if we ask for records which don't exist,
          // it will return an undefined set of records. So we can't rely on the
          // actually matching the search criteria and need this test to make sure
          // we only delete SSPA records for the student we asked for.
          $spaIds[] = $s["id"];
        }
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

function fillEdfi1Obj($dbRow)
{
  $edfiRec = new edfi1Obj();
  $edfiRec->id_edfi1_entry = $dbRow[6];
  $edfiRec->EducationOrganizationId = $dbRow[4];
  $edfiRec->StudentUniqueId = $dbRow[8];
  $edfiRec->BeginDate = $dbRow[9];
  $edfiRec->EndDate = $dbRow[10];
  $edfiRec->ReasonExited = $dbRow[11];
  $edfiRec->SpecialEducationSetting = $dbRow[12];
  $edfiRec->SpecialEducationProgram = $dbRow[13];
  $edfiRec->PlacementType = $dbRow[14];
  $edfiRec->SpecialEducationPercentage = $dbRow[15];
  $edfiRec->ToTakeAlternateAssessment = $dbRow[16];
  $edfiRec->servicebegindate_pt = $dbRow[17];
  $edfiRec->servicebegindate_ot = $dbRow[18];
  $edfiRec->servicebegindate_slt = $dbRow[19];
  $edfiRec->Disability = $dbRow[20];
  $edfiRec->servicedescriptor_pt = $dbRow[21];
  $edfiRec->servicedescriptor_ot = $dbRow[22];
  $edfiRec->servicedescriptor_slt = $dbRow[23];
  $edfiRec->EdfiResultCode = $dbRow[24];
  $edfiRec->EdfiErrorMessage = $dbRow[25];
  $edfiRec->EdfiPublishTime = $dbRow[26];
  $edfiRec->EdfiPublishStatus = $dbRow[27];

  return ($edfiRec);
}

function getAuthCode($adviserKey)
{
  // Get ODS authorization code
  global $edfiBaseUrl;
  global $edfiAuthPath;

  $edfiApiCodeUrl = "$edfiBaseUrl$edfiAuthPath/oauth/authorize";
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
      writePublishLog(sprintf("\nERROR getting oAuth code: %s\n", $e->getMessage()));
  }
}

function getAuthToken()
{
  // Get ODS access token
  global $adviserKey;
  global $adviserSecret;
  global $authToken;
  global $edfiBaseUrl;
  global $edfiAuthPath;

  $authCode = getAuthCode($adviserKey);

  $edfiApiTokenUrl = "$edfiBaseUrl$edfiAuthPath/oauth/token";
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

function getEdfi1RecsForStudent($studentId, $idCounty, $idDistrict, $publishErrors)
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
    "FROM edfi1 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "edfipublishstatus IN " . $statuses . " AND " .
    "studentuniqueid = " . $studentId . " " .
    "ORDER BY begindate";

  $dbResult = pg_query($dbConn, $sql);

  $edfiRecs = [];
  if ($dbResult)
  {
    while ($dbRow = pg_fetch_row($dbResult))
    {
      // retrieve record
      $edfiRecs[] = fillEdfi1Obj($dbRow);
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
    $statuses = "('D', 'E', 'W')";
  }
  else
  {
    $statuses = "('D', 'W')";
  }

  $studentIds = [];
  $sql = "SELECT DISTINCT studentuniqueid " .
    "FROM edfi1 " .
    "WHERE id_county = '" . $idCounty . "' AND " .
    "id_district = '" . $idDistrict . "' AND " .
    "edfipublishstatus IN " . $statuses . " AND " .
    "studentuniqueid > 0 " .
    "ORDER BY studentuniqueid";

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
  $logFile = fopen("logs/publish-1819.log", "a");
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

  $sql = "UPDATE edfi1 SET edfipublishstatus = '" . $publishStatus .
    "', edfipublishtime = NOW(), edfierrormessage= '" .
    addslashes($errorMessage) . "', edfiresultcode = " . $rCode . " WHERE id_edfi1_entry=" .
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

  $sql = "UPDATE edfi1 SET edfipublishstatus= 'W' " .
    "WHERE studentuniqueid = " . $studentId . " " .
    "AND id_county = '$idCounty' " .
    "AND id_district = '$idDistrict' " .
    "AND edfipublishstatus = 'S' ";

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

  if (empty($edfiRec->BeginDate) || (strtotime($edfiRec->BeginDate) > strtotime('2019-06-30')))
  {
    $invalidList[] = "BeginDate";
  }

  if (!empty($edfiRec->EndDate))
  {
    $endTimestamp = strtotime($edfiRec->EndDate);
    if ( $endTimestamp < strtotime('2018-07-01') ||
      $endTimestamp > strtotime('2019-06-30') ||
      $endTimestamp < $beginTimestamp)
    {
      $invalidList[] = "EndDate";
    }
    if (empty($edfiRec->ReasonExited))
    {
      $invalidList[] = "ReasonExited";
    }
  }

  if ($edfiRec->SpecialEducationPercentage > 100 || $edfiRec->SpecialEducationPercentage < 0 )
  {
    $invalidList[] = "SpecialEducationPercentage";
  }

  if (empty($edfiRec->SpecialEducationSetting))
  {
    $invalidList[] = "SpecialEducationSetting";
  }

  if (($edfiRec->PlacementType < 0) || ($edfiRec->PlacementType > 4))
  {
    $invalidList[] = "PlacementType";
  }

  if ($edfiRec->SpecialEducationProgram != "05" && $edfiRec->SpecialEducationProgram != "06")
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

  $sql = "UPDATE edfi1 SET edfipublishstatus = 'E', edfipublishtime = NOW(), " .
    "edfierrormessage = 'StudentUniqueId is missing', " .
    "edfiresultcode = '602' " .
    "WHERE id_county='" . $idCounty . "' " .
    "AND id_district='" . $idDistrict . "' " .
    "AND edfipublishstatus = 'W' " .
    "AND (studentuniqueid IS NULL OR studentuniqueid < 1)";

  $dbResult = pg_query($dbConn, $sql);

  if (!$dbResult)
  {
    writePublishLog(sprintf ("ERROR: A database error occurred in updateMissingUniqueIds: %s",
      pg_last_error($dbConn)));
  }

  $sql = "DELETE FROM edfi1 " .
    "WHERE id_county='" . $idCounty . "' " .
    "AND id_district='" . $idDistrict . "' " .
    "AND edfipublishstatus = 'D' " .
    "AND (studentuniqueid IS NULL OR studentuniqueid < 1)";

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
