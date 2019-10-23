<?php

printf("\n\nselect sc.id_school,sc.name_school,sc.minutes_per_week,count(st.*) " .
  "from iep_school sc join iep_student st on sc.id_county=st.id_county and " .
  "sc.id_district=st.id_district and sc.id_school=st.id_school " .
  "where sc.id_county='%s' and sc.id_district='%s' and st.status='Active' " .
  "group by sc.id_school,sc.name_school,sc.minutes_per_week;",
  $argv[1], $argv[2]);
printf("\nupdate iep_student set sesis_exit_date=NULL,sesis_exit_code=NULL where status='Active' and (sesis_exit_date is not null or sesis_exit_code is not null) and id_county='%s' and id_district='%s';",
  $argv[1], $argv[2]);
printf("\nupdate iep_district set use_edfi='t' where id_county='%s' and id_district='%s';\n\n",
  $argv[1], $argv[2]);

?>
