<?php
// Der eingebaute PHP-Testserver besitzt keine HTTP-Authentifizierung. Dieser
// Router setzt nur im abgeschlossenen Test die Variable, die sonst Apache nach
// erfolgreicher Basic-Auth bereitstellt.
$_SERVER['REMOTE_USER'] = 'testadmin';
return false;
