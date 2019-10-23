<?php
//
// verify-1920.php
//
//  This script reads ADVISER records from the 19-20 ODS and compares them to
//    records in the edfi2 table in the SRS database.
//
//  10/21/2019 SI

// config
$dbHost = "172.16.3.36";
$dbPort = 5434;
$dbUser = "psql-primary";
$dbName = "nebraska_srs";
$edfiBaseUrl = "https://adviserods.nebraskacloud.org";
$edfiAuthPath = "/v3/api";
$edfiApiPath = "/v3/api/data/v3/2020/ed-fi";

// persistent variables
$dbConn = null;

// Object classes

// command-line requires county and district IDs
if ($argc != 3)
{
  printf("\nusage: php ./verify-1920.php <county ID> <district ID>\n");
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

// read district info
$sql = "SELECT id_county, id_district, name_district, edfi_key, edfi_secret " .
  "FROM iep_district " .
  "WHERE use_edfi ='t' AND " .
  "status = 'Active' AND id_county='$argv[1]' AND id_district='$argv[2]' ";

$dbResult = pg_query($dbConn, $sql);

if ($dbResult)
{
  $dbRow = pg_fetch_row($dbResult);
  if ($dbRow)
  {
    $d = new districtObj();
    $d->id_county = $dbRow[0];
    $d->id_district = $dbRow[1];
    $d->name_district = $dbRow[2];
    $d->key = $dbRow[3];
    $d->secret = $dbRow[4];
  }
}
else
{
  printf ("ERROR: A database error occurred: %s\n", pg_last_error($dbConn));
}

if ($d)
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

  // Read ODS SSPA records
  $authToken = getAuthToken($d->key, $d->secret);

}
// sort SSPA records
// step through SSPA and edfi2 records and compare


?>
