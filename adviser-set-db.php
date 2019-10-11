<?php

printf("\n\nselect id_school,name_school,minutes_per_week from iep_school where id_county='%s' and id_district='%s';",
  $argv[1], $argv[2]);
printf("\nupdate iep_student set sesis_exit_date=NULL,sesis_exit_code=NULL where status='Active' and (sesis_exit_date is not null or sesis_exit_code is not null) and id_county='%s' and id_district='%s';",
  $argv[1], $argv[2]);
printf("\nupdate iep_district set use_edfi='t' where id_county='%s' and id_district='%s';\n\n",
  $argv[1], $argv[2]);

?>
