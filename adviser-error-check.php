<?php

printf("\n\nselect id_county,id_district,name_district from iep_district where " .
  "id_county='%s' and id_district='%s';",
  $argv[1], $argv[2]);
printf("\nselect \"EdfiPublishStatus\",count(*) from edfi2 where id_county='%s'" .
  " and id_district='%s' group by \"EdfiPublishStatus\";",
  $argv[1], $argv[2]);
printf("\nselect \"EdfiErrorMessage\", count(*) from edfi2 where id_county='%s'" .
  " and id_district='%s' and \"EdfiPublishStatus\"='E' group by \"EdfiErrorMessage\";\n\n",
  $argv[1], $argv[2]);

?>
