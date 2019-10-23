<?php
//
// get-state-id.php
//
//  This script returns a student's state ID based on the SRS ID input
//
//  10/17/2019 SI

// config
include 'transfer-config.php';

// persistent variables
$dbConn = null;

// Connect to DB
$dbConn = pg_connect("dbname=" . $dbName . " host=" . $dbHost .
  " port=" . $dbPort . " user=" . $dbUser . " connect_timeout=5");
if (!$dbConn)
{
  printf("ERROR: Database connection failed\n");
  exit();
}

while ($srsId = fgets(STDIN))
{
  $stateId = getStateId(trim($srsId));
  printf("%d\n",$stateId);
}

function getStateId($srsId)
{
  global $dbConn;

  $sql = "SELECT unique_id_state " .
    "FROM iep_student " .
    "WHERE id_student = $srsId";

  $dbResult = pg_query($dbConn, $sql);

  $srsId = 0;
  if ($dbResult)
  {
    $dbRow = pg_fetch_row($dbResult);
    if ($dbRow)
    {
      $stateId = $dbRow[0];
    }
  }

  return($stateId);
}
